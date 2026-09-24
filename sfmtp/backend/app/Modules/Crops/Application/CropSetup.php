<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Models\Crop;
use App\Modules\Crops\Domain\Models\Season;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** The farm's crop list and its seasons. */
class CropSetup
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{global_crop_id?:?string, global_variety_id?:?string, name?:?string, variety?:?string, maturity_days?:?int, yield_unit?:string}  $data */
    public function addCrop(array $data): Crop
    {
        // Picked from the catalogue: names and maturity default from it.
        if (! empty($data['global_variety_id'])) {
            $variety = DB::table('global_crop_varieties')->where('id', $data['global_variety_id'])->first();
            if ($variety === null || (! empty($data['global_crop_id']) && $variety->crop_id !== $data['global_crop_id'])) {
                throw $this->invalid('global_variety_id', 'Choose a variety of the selected crop.');
            }
            $data['global_crop_id'] = $variety->crop_id;
            $data['variety'] ??= $variety->name;
            $data['maturity_days'] ??= $variety->maturity_days;
        }
        if (! empty($data['global_crop_id'])) {
            $data['name'] ??= DB::table('global_crops')->where('id', $data['global_crop_id'])->value('name');
        }
        if (empty($data['name'])) {
            throw $this->invalid('name', 'Name the crop, or pick it from the catalogue.');
        }

        $duplicate = Crop::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->when($data['variety'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(variety) = ?', [mb_strtolower($v)]), fn ($q) => $q->whereNull('variety'))
            ->exists();
        if ($duplicate) {
            throw ApiException::conflict('duplicate', 'This crop and variety are already on the farm list.');
        }

        $crop = Crop::create($data);
        $this->audit->record('crops.crop.added', $crop, null, $crop->only(['name', 'variety', 'yield_unit']));

        return $crop->refresh();
    }

    public function updateCrop(Crop $crop, array $data): Crop
    {
        $before = $crop->only(array_keys($data));
        $crop->fill($data)->save();
        if ($crop->wasChanged()) {
            $this->audit->record('crops.crop.updated', $crop, $before, $crop->only(array_keys($data)));
        }

        return $crop;
    }

    public function addSeason(array $data): Season
    {
        $this->assertNameFree($data['name'], null);
        $season = Season::create($data);
        $this->audit->record('crops.season.created', $season, null, ['name' => $season->name]);

        return $season;
    }

    public function updateSeason(Season $season, array $data): Season
    {
        if (isset($data['name'])) {
            $this->assertNameFree($data['name'], $season->id);
        }
        $season->fill($data);
        if ($season->ends_on < $season->starts_on) {
            throw $this->invalid('ends_on', 'The season must end on or after its start.');
        }
        $season->save();

        return $season;
    }

    private function assertNameFree(string $name, ?string $exceptId): void
    {
        if (Season::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw $this->invalid('name', 'A season with this name already exists.');
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
