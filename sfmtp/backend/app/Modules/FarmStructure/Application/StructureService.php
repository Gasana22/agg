<?php

namespace App\Modules\FarmStructure\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\FarmStructure\Domain\Geometry;
use App\Modules\FarmStructure\Domain\Models\Block;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Domain\Models\Section;
use App\Modules\FarmStructure\Domain\Models\StructureNode;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creating, editing and archiving blocks, sections, plots and locations.
 *
 * Geometry problems that come from imprecise GPS (a plot poking outside its
 * section, two plots overlapping) are warnings returned with the result,
 * never failures (docs/03 §3). Broken geometry is a 422.
 */
class StructureService
{
    /** @var array<string, class-string<StructureNode>> */
    public const TYPES = [
        'block' => Block::class,
        'section' => Section::class,
        'plot' => Plot::class,
        'location' => Location::class,
    ];

    private const CODE_PREFIX = ['block' => 'B', 'section' => 'S', 'plot' => 'P', 'location' => 'L'];

    private const PARENT_KEY = ['section' => 'block_id', 'plot' => 'section_id', 'location' => 'plot_id'];

    private const PARENT_TYPE = ['section' => Block::class, 'plot' => Section::class, 'location' => Plot::class];

    private const AUDITED = ['code', 'name', 'description', 'declared_area_ha', 'area_ha', 'block_id', 'section_id', 'plot_id', 'land_use', 'irrigation', 'kind', 'latitude', 'longitude'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string,mixed>  $data  validated input; `boundary` is GeoJSON or null
     * @return array{0: StructureNode, 1: array<int,array<string,mixed>>} the node and its warnings
     */
    public function create(string $type, array $data): array
    {
        $class = self::TYPES[$type];
        $this->assertParent($type, $data);

        $node = DB::transaction(function () use ($class, $type, $data) {
            /** @var StructureNode $node */
            $node = new $class;
            $node->fill(array_diff_key($data, ['boundary' => true, 'code' => true]));
            $node->code = $this->assertCodeFree($class, $data['code'] ?? null, null) ?? $this->nextCode($class, $type);
            $node->created_by = Auth::id();
            $node->setBoundary($data['boundary'] ?? null);
            $this->alignPoint($node, $data);
            $node->save();

            $this->audit->record("structure.{$type}.created", $node, null, $this->audited($node));

            return $node;
        });

        return [$node->refresh(), $this->warnings($node)];
    }

    /**
     * @param  array<string,mixed>  $data  validated input; keys absent are left unchanged
     * @return array{0: StructureNode, 1: array<int,array<string,mixed>>}
     */
    public function update(StructureNode $node, array $data): array
    {
        $type = $node->nodeType();
        $this->assertParent($type, $data);
        if (array_key_exists('code', $data)) {
            $this->assertCodeFree($node::class, $data['code'], $node->id);
        }

        $before = $this->audited($node);
        $boundaryChanged = array_key_exists('boundary', $data);

        DB::transaction(function () use ($node, $data, $type, $before, $boundaryChanged) {
            $node->fill(array_diff_key($data, ['boundary' => true]));
            if ($boundaryChanged) {
                $node->setBoundary($data['boundary']);
            }
            $this->alignPoint($node, $data);
            if (! $node->isDirty()) {
                return;
            }
            $node->save();

            $after = $this->audited($node);
            $this->audit->record("structure.{$type}.updated", $node,
                array_intersect_key($before, array_diff_assoc($after, $before)),
                array_diff_assoc($after, $before) + ($boundaryChanged ? ['boundary_changed' => true] : []));
        });

        return [$node->refresh(), $this->warnings($node)];
    }

    /** Archive (soft delete). Children must be archived or moved first. */
    public function archive(StructureNode $node): void
    {
        $children = match (true) {
            $node instanceof Block => $node->sections()->count(),
            $node instanceof Section => $node->plots()->count(),
            $node instanceof Plot => $node->locations()->count(),
            default => 0,
        };
        if ($children > 0) {
            throw ApiException::conflict('has_active_children', "Archive or move the {$children} item(s) inside this {$node->nodeType()} first.");
        }

        $node->delete();
        $this->audit->record("structure.{$node->nodeType()}.archived", $node, $this->audited($node), null);
    }

    /** @param  array<string,mixed>  $profile */
    public function recordSoil(Plot $plot, array $profile): Plot
    {
        $before = $plot->soil_profile;
        $plot->forceFill([
            'soil_profile' => $profile,
            'soil_updated_at' => now(),
            'soil_updated_by' => Auth::id(),
        ])->save();

        $this->audit->record('structure.plot.soil_recorded', $plot, $before === null ? null : ['soil_profile' => $before], ['soil_profile' => $profile]);

        return $plot->refresh();
    }

    /**
     * Soft problems with a node's geometry.
     *
     * @return array<int,array{code:string, message:string, related:array{type:string,id:string,code:string}}>
     */
    public function warnings(StructureNode $node): array
    {
        $warnings = [];
        $related = fn (StructureNode $n) => ['type' => $n->nodeType(), 'id' => $n->id, 'code' => $n->code];

        $parent = $this->parentOf($node);
        if ($parent?->boundary !== null) {
            $outside = match (true) {
                $node->boundary !== null => ! Geometry::within($node->boundary, $parent->boundary),
                $node instanceof Location && $node->latitude !== null => ! Geometry::pointInRing(
                    [(float) $node->longitude, (float) $node->latitude], $parent->boundary['coordinates'][0]),
                default => false,
            };
            if ($outside) {
                $warnings[] = [
                    'code' => 'outside_parent',
                    'message' => "This {$node->nodeType()} extends outside {$parent->nodeType()} {$parent->code}.",
                    'related' => $related($parent),
                ];
            }
        }

        if ($node->boundary !== null && ! $node instanceof Location) {
            $candidates = $node::query()
                ->whereKeyNot($node->id)
                ->whereNotNull('boundary')
                ->where('bbox_min_lng', '<', $node->bbox_max_lng)
                ->where('bbox_max_lng', '>', $node->bbox_min_lng)
                ->where('bbox_min_lat', '<', $node->bbox_max_lat)
                ->where('bbox_max_lat', '>', $node->bbox_min_lat)
                ->limit(200)
                ->get();

            foreach ($candidates as $other) {
                if (Geometry::overlaps($node->boundary, $other->boundary)) {
                    $warnings[] = [
                        'code' => 'overlaps_sibling',
                        'message' => "This {$node->nodeType()} overlaps {$other->nodeType()} {$other->code}.",
                        'related' => $related($other),
                    ];
                }
            }
        }

        return $warnings;
    }

    private function parentOf(StructureNode $node): ?StructureNode
    {
        return match (true) {
            $node instanceof Section => $node->block,
            $node instanceof Plot => $node->section,
            $node instanceof Location => $node->plot,
            default => null,
        };
    }

    /** The parent must be an active record of this farm (the farm scope hides others). */
    private function assertParent(string $type, array $data): void
    {
        $key = self::PARENT_KEY[$type] ?? null;
        if ($key === null || ! isset($data[$key])) {
            return;
        }

        if (! (self::PARENT_TYPE[$type])::query()->whereKey($data[$key])->exists()) {
            $parent = class_basename(self::PARENT_TYPE[$type]);
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                $key => ["The selected {$parent} does not exist in this farm."],
            ]);
        }
    }

    /** Codes are never reused within a farm, archived records included. */
    private function assertCodeFree(string $class, ?string $code, ?string $exceptId): ?string
    {
        if ($code === null) {
            return null;
        }

        $taken = $class::withTrashed()
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                'code' => ['This code is already used in this farm (archived records keep their codes).'],
            ]);
        }

        return strtoupper($code);
    }

    private function nextCode(string $class, string $type): string
    {
        $n = $class::withTrashed()->count() + 1;
        do {
            $code = self::CODE_PREFIX[$type].str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while ($class::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** A location drawn as an area but given no point is pinned at its centroid. */
    private function alignPoint(StructureNode $node, array $data): void
    {
        if ($node instanceof Location && $node->boundary !== null && ! isset($data['latitude']) && $node->latitude === null) {
            $node->latitude = $node->centroid_lat;
            $node->longitude = $node->centroid_lng;
        }
    }

    /** @return array<string,mixed> */
    private function audited(StructureNode $node): array
    {
        $values = [];
        foreach (self::AUDITED as $key) {
            if (array_key_exists($key, $node->getAttributes())) {
                $value = $node->getAttribute($key);
                $values[$key] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        return $values;
    }
}
