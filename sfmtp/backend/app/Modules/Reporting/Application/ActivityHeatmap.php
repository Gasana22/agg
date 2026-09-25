<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Activity heat map (docs/05 §6, ADR-0017): where work happened in a
 * period, as counts on a square grid. Each layer is a kind of located
 * record and needs the permission that shows those records; layers the
 * member may not see are left out and reported as such. Only counts per
 * cell leave the server, never who or what.
 */
class ActivityHeatmap
{
    public const LAYERS = [
        'gps' => ['label' => 'Worker GPS points', 'permission' => 'attendance.approve|workers.view'],
        'tasks' => ['label' => 'Task check-ins and photos', 'permission' => 'tasks.view'],
        'attendance' => ['label' => 'Attendance check-ins', 'permission' => 'attendance.view|attendance.approve'],
        'operations' => ['label' => 'Crop operations', 'permission' => 'crops.operations.view'],
        'observations' => ['label' => 'Pest and disease reports', 'permission' => 'crops.operations.view'],
        'trace' => ['label' => 'Traceability events', 'permission' => 'trace.batches.view'],
    ];

    /** Points read per layer at most; a denser layer is sampled evenly. */
    public const MAX_POINTS = 50000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly FarmPermissions $permissions,
    ) {}

    /**
     * @param  array<int,string>|null  $layers  null for every layer the member may see
     */
    public function build(Period $period, ?array $layers, int $cellMetres): array
    {
        $wanted = $layers ?? array_keys(self::LAYERS);
        $shown = array_values(array_filter($wanted, fn ($l) => isset(self::LAYERS[$l]) && $this->can(self::LAYERS[$l]['permission'])));
        $hidden = array_values(array_diff(array_intersect($wanted, array_keys(self::LAYERS)), $shown));

        $points = [];
        $totals = [];
        foreach ($shown as $layer) {
            $rows = $this->points($layer, $period);
            $totals[$layer] = count($rows);
            foreach ($rows as $r) {
                $points[] = [(float) $r->lat, (float) $r->lng, $layer];
            }
        }

        if ($points === []) {
            return $this->result($period, $shown, $hidden, $cellMetres, $totals, [], null);
        }

        // Grid: cell height fixed in latitude, width corrected for the farm's latitude.
        $meanLat = array_sum(array_column($points, 0)) / count($points);
        $dLat = $cellMetres / 111320;
        $dLng = $cellMetres / (111320 * max(0.01, cos(deg2rad($meanLat))));
        $cells = [];
        [$minLat, $minLng, $maxLat, $maxLng] = [INF, INF, -INF, -INF];
        foreach ($points as [$lat, $lng, $layer]) {
            [$i, $j] = [(int) floor($lat / $dLat), (int) floor($lng / $dLng)];
            $key = "{$i}:{$j}";
            $cells[$key] ??= ['i' => $i, 'j' => $j, 'count' => 0, 'layers' => []];
            $cells[$key]['count']++;
            $cells[$key]['layers'][$layer] = ($cells[$key]['layers'][$layer] ?? 0) + 1;
            [$minLat, $minLng, $maxLat, $maxLng] = [min($minLat, $lat), min($minLng, $lng), max($maxLat, $lat), max($maxLng, $lng)];
        }

        $out = [];
        foreach ($cells as $c) {
            ksort($c['layers']);
            $out[] = [
                'lat' => round(($c['i'] + 0.5) * $dLat, 6),
                'lng' => round(($c['j'] + 0.5) * $dLng, 6),
                'south' => round($c['i'] * $dLat, 6), 'west' => round($c['j'] * $dLng, 6),
                'north' => round(($c['i'] + 1) * $dLat, 6), 'east' => round(($c['j'] + 1) * $dLng, 6),
                'count' => $c['count'],
                'layers' => $c['layers'],
            ];
        }
        usort($out, fn ($a, $b) => [$b['count'], $a['lat'], $a['lng']] <=> [$a['count'], $b['lat'], $b['lng']]);

        return $this->result($period, $shown, $hidden, $cellMetres, $totals, $out,
            ['south' => round($minLat, 6), 'west' => round($minLng, 6), 'north' => round($maxLat, 6), 'east' => round($maxLng, 6)]);
    }

    private function result(Period $period, array $shown, array $hidden, int $cell, array $totals, array $cells, ?array $bbox): array
    {
        return [
            'period' => $period->toArray($this->context->farm()->timezone),
            'cell_m' => $cell,
            'layers' => array_map(fn ($l) => ['key' => $l, 'label' => self::LAYERS[$l]['label'], 'points' => $totals[$l] ?? 0], $shown),
            'hidden_layers' => $hidden,
            'bbox' => $bbox,
            'max' => $cells === [] ? 0 : max(array_column($cells, 'count')),
            'cells' => $cells,
        ];
    }

    private function points(string $layer, Period $period)
    {
        $farm = $this->context->farmId();
        [$from, $to] = [$period->from->toDateTimeString(), $period->to->format('Y-m-d H:i:s.u')];
        $q = fn (string $table, string $lat, string $lng, string $at) => DB::table($table)->where('farm_id', $farm)
            ->whereNotNull($lat)->whereBetween($at, [$from, $to])->select(DB::raw("{$lat} AS lat"), DB::raw("{$lng} AS lng"));

        $query = match ($layer) {
            'gps' => $q('worker_gps_points', 'lat', 'lng', 'recorded_at'),
            'tasks' => $q('worker_task_logs', 'lat', 'lng', 'occurred_at')->unionAll($q('worker_task_photos', 'lat', 'lng', 'taken_at')),
            'attendance' => $q('worker_attendance', 'check_in_lat', 'check_in_lng', 'check_in_at'),
            'operations' => $q('crop_operations', 'latitude', 'longitude', 'occurred_at'),
            'observations' => $q('crop_observations', 'latitude', 'longitude', 'observed_at'),
            'trace' => $q('trace_events', 'latitude', 'longitude', 'occurred_at'),
        };

        $count = DB::query()->fromSub($query, 'p')->count();
        if ($count <= self::MAX_POINTS) {
            return DB::query()->fromSub($query, 'p')->get();
        }
        // Sample evenly: keep every n-th point.
        $step = (int) ceil($count / self::MAX_POINTS);

        return DB::query()->fromSub($query, 'p')->get()->filter(fn ($r, $i) => $i % $step === 0)->values();
    }

    private function can(string $permission): bool
    {
        foreach (explode('|', $permission) as $p) {
            if ($this->permissions->allows($p)) {
                return true;
            }
        }

        return false;
    }
}
