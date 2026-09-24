<?php

namespace App\Modules\Livestock\Application;

use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Livestock\Http\Resources\BreedingResource;
use App\Modules\Livestock\Http\Resources\RecordResource;
use App\Modules\Livestock\Http\Resources\SaleResource;
use Illuminate\Http\Request;

/**
 * One animal's history in one list, newest first: its own records, records
 * for the group it is in, breedings (as dam) and sale requests. Voided
 * records stay in the list, marked as voided.
 */
class Timeline
{
    /** @return array<int, array{kind:string, at:string, data:array}> */
    public function for(Animal $animal, Request $request, int $limit = 200): array
    {
        $items = [];
        foreach (AnimalRecords::TYPES as $kind => $class) {
            $dateColumn = match ($kind) {
                'health' => 'given_on', 'feeding' => 'fed_on', 'weight' => 'weighed_on', 'production' => 'produced_on', default => 'moved_at',
            };
            $records = $class::with(['animal', 'group', 'recorder', 'void'])
                ->where(fn ($q) => $q->where('animal_id', $animal->id)->when($animal->group_id, fn ($q2) => $q2->orWhere('group_id', $animal->group_id)))
                ->orderByDesc($dateColumn)
                ->limit($limit)
                ->get();
            foreach ($records as $r) {
                $at = $r->{$dateColumn};
                $items[] = ['kind' => $kind, 'at' => $at->toDateString().'T'.($r->created_at?->format('H:i:s.u') ?? '00:00:00'), 'data' => (new RecordResource($r))->resolve($request)];
            }
        }
        foreach (Breeding::with(['dam', 'sire'])->where('dam_id', $animal->id)->get() as $b) {
            $items[] = ['kind' => 'breeding', 'at' => $b->served_on->toDateString().'T00:00:00', 'data' => (new BreedingResource($b))->resolve($request)];
        }
        foreach (SaleRequest::with(['animal', 'requester'])->where('animal_id', $animal->id)->get() as $s) {
            $items[] = ['kind' => 'sale', 'at' => $s->created_at->toDateString().'T'.$s->created_at->format('H:i:s'), 'data' => (new SaleResource($s))->resolve($request)];
        }

        usort($items, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, $limit);
    }
}
