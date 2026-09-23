<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Support\Http\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CatalogService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<int,array<string,mixed>> */
    public function list(string $catalog, bool $includeInactive, ?string $search, ?string $parentId): array
    {
        $def = $this->definition($catalog);

        return DB::table($def['table'])
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->when($parentId && $def['parent'], fn ($q) => $q->where($def['parent']['column'], $parentId))
            ->when($search, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])
                ->orWhere('code', 'like', '%'.mb_strtolower($v).'%')))
            ->orderBy('name')
            ->limit(1000)
            ->get()
            ->map(fn ($row) => $this->present($catalog, (array) $row))
            ->all();
    }

    public function create(string $catalog, array $input): array
    {
        $def = $this->definition($catalog);
        $data = $this->validate($def, $input, null);
        $row = ['id' => (string) Str::uuid7(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()] + $data;
        DB::table($def['table'])->insert($row);
        $this->audit->record('admin.catalog_created', ['type' => $def['table'], 'id' => $row['id']], null, $data, ['farm_id' => null]);

        return $this->find($catalog, $row['id']);
    }

    public function update(string $catalog, string $id, array $input): array
    {
        $def = $this->definition($catalog);
        $before = $this->find($catalog, $id);
        $data = $this->validate($def, $input, $id);
        DB::table($def['table'])->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->audit->record('admin.catalog_updated', ['type' => $def['table'], 'id' => $id], array_intersect_key($before, $data), $data, ['farm_id' => null]);

        return $this->find($catalog, $id);
    }

    public function find(string $catalog, string $id): array
    {
        $row = Str::isUuid($id) ? DB::table($this->definition($catalog)['table'])->where('id', $id)->first() : null;

        return $row ? $this->present($catalog, (array) $row) : throw ApiException::notFound();
    }

    private function validate(array $def, array $input, ?string $id): array
    {
        $validator = Validator::make($input, ($def['rules'])($id));
        if ($validator->fails()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function present(string $catalog, array $row): array
    {
        $row['type'] = $catalog;
        $row['is_active'] = (bool) $row['is_active'];
        foreach (['created_at', 'updated_at'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = Carbon::parse($row[$k])->toIso8601ZuluString();
            }
        }
        if (isset($row['to_base'])) {
            $row['to_base'] = rtrim(rtrim((string) $row['to_base'], '0'), '.');
        }

        return $row;
    }

    private function definition(string $catalog): array
    {
        return Catalogs::all()[$catalog] ?? throw ApiException::notFound();
    }
}
