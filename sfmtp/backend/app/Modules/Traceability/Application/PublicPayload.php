<?php

namespace App\Modules\Traceability\Application;

use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Carbon\CarbonImmutable;

/**
 * What a QR scan may show (docs/07 §5). Each public field is built here
 * from an allow-list of values; nothing is copied through from payloads.
 * Prices, costs, worker and user names, stock levels, quantities and GPS
 * never appear, whatever is approved. Places stop at the plot code and the
 * district.
 */
class PublicPayload
{
    /** Field key => what the approver is told it shows. */
    public const FIELDS = [
        'product' => 'Product name and kind',
        'batch_code' => 'Batch code',
        'farm' => 'Farm name',
        'region' => 'District and country (never the exact location)',
        'origin' => 'Plot codes it grew on',
        'crop' => 'Crop and variety',
        'dates' => 'Planting, harvest, processing and packing dates',
        'seed_source' => 'Seed and input lots: supplier and lot number',
        'inputs' => 'Fertilisers, chemicals, feeds and drugs applied, with their withholding periods',
        'processing' => 'Processing and packing steps',
        'certifications' => 'Certifications and inspections recorded on the batch',
        'journey' => 'The steps from source to this product (kinds, codes and dates)',
    ];

    public const DEFAULT_FIELDS = ['product', 'batch_code', 'farm', 'region', 'crop', 'dates', 'processing'];

    private const CERTIFICATION_EVENTS = ['certification', 'inspection'];

    public function __construct(
        private readonly JourneyViews $views,
        private readonly JourneyService $journeys,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function build(TraceBatch $batch, array $fields): array
    {
        $fields = array_values(array_intersect(array_keys(self::FIELDS), $fields));
        $farm = $this->context->farm();
        $events = collect($this->views->timeline($batch, 'backward')['events']);
        $lineage = TraceBatch::whereIn('id', $this->views->lineage($batch, 'backward'))->get()->keyBy('id');
        $day = fn (?string $at) => $at ? CarbonImmutable::parse($at)->setTimezone($farm->timezone)->toDateString() : null;
        $label = fn (?string $kind) => $kind ? ucfirst(str_replace('_', ' ', $kind)) : null;
        $created = $events->where('event_type', 'created');

        $out = [];
        foreach ($fields as $field) {
            $out[$field] = match ($field) {
                'product' => ['name' => $batch->name, 'kind' => $label($batch->kind->value)],
                'batch_code' => $batch->batch_code,
                'farm' => $farm->name,
                'region' => ['district' => $farm->district, 'country' => $farm->country],
                'origin' => Plot::whereIn('id', $lineage->pluck('origin_plot_id')->filter()->unique())->orderBy('code')->pluck('code')->values()->all(),
                'crop' => $events->where('event_type', 'planted')->pluck('payload.crop')->filter()->unique()->values()->all(),
                'dates' => array_filter([
                    'planted' => $day($events->firstWhere('event_type', 'planted')['occurred_at'] ?? null),
                    'harvested' => $day($events->where('event_type', 'harvested')->last()['occurred_at'] ?? null),
                    'processed' => $day($created->where('payload.operation', 'process')->last()['occurred_at'] ?? null),
                    'packed' => $day($created->where('payload.operation', 'package')->last()['occurred_at'] ?? null),
                ]),
                'seed_source' => collect($this->views->inputs($batch)['lots'])->where('role', 'source')->map(fn ($l) => array_filter([
                    'name' => $l['batch']['name'],
                    'lot_number' => $l['lot_number'],
                    'supplier' => $l['supplier'],
                ]))->values()->all(),
                'inputs' => $events->whereIn('event_type', JourneyViews::INPUT_EVENTS)->map(fn ($e) => array_filter([
                    'product' => self::text($e['payload']['product'] ?? $e['payload']['feed'] ?? null),
                    'type' => str_replace('_', ' ', $e['event_type']),
                    'date' => $day($e['occurred_at']),
                    'withholding_days' => self::int($e['payload']['withholding_days'] ?? null),
                    'meat_withdrawal_days' => self::int($e['payload']['meat_withdrawal_days'] ?? null),
                    'milk_withdrawal_days' => self::int($e['payload']['milk_withdrawal_days'] ?? null),
                ]))->values()->all(),
                'processing' => $created->filter(fn ($e) => in_array($e['payload']['operation'] ?? null, ['process', 'package', 'merge'], true))->map(fn ($e) => array_filter([
                    'step' => ['process' => 'Processed', 'package' => 'Packed', 'merge' => 'Combined'][$e['payload']['operation']],
                    'product' => self::text($e['payload']['name'] ?? null),
                    'method' => self::text($e['payload']['method'] ?? null),
                    'packages' => self::int($e['payload']['package_count'] ?? null),
                    'package_size' => self::text($e['payload']['package_size'] ?? null),
                    'date' => $day($e['occurred_at']),
                ]))->values()->all(),
                'certifications' => $events->whereIn('event_type', self::CERTIFICATION_EVENTS)->map(fn ($e) => array_filter([
                    'type' => $e['event_type'],
                    'note' => self::text($e['payload']['note'] ?? null),
                    'date' => $day($e['occurred_at']),
                ]))->values()->all(),
                'journey' => $this->steps($batch, $lineage, $created, $day, $label),
            };
        }

        return $out;
    }

    /** @return array<int, array{kind: ?string, batch_code: string, date: ?string}> sources first */
    private function steps(TraceBatch $batch, $lineage, $created, callable $day, callable $label): array
    {
        $depth = collect($this->journeys->walk($batch, 'backward')['nodes'])->pluck('depth', 'id');
        $when = $created->mapWithKeys(fn ($e) => [$e['batch']['id'] => $e['occurred_at']]);

        return $lineage->reject(fn (TraceBatch $b) => in_array($b->kind, [BatchKind::InputLot], true))
            ->sortByDesc(fn (TraceBatch $b) => $b->id === $batch->id ? -1 : ($depth[$b->id] ?? 0))
            ->map(fn (TraceBatch $b) => ['kind' => $label($b->kind->value), 'batch_code' => $b->batch_code, 'date' => $day($when[$b->id] ?? null)])
            ->values()->all();
    }

    private static function text(mixed $v): ?string
    {
        return is_scalar($v) && $v !== '' ? mb_substr((string) $v, 0, 200) : null;
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }
}
