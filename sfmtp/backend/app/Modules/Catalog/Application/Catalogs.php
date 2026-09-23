<?php

namespace App\Modules\Catalog\Application;

use Illuminate\Validation\Rule;

/**
 * Registry of global catalogues. One controller serves all of them; each
 * entry declares its table, optional parent and validation rules.
 */
final class Catalogs
{
    public const CROP_CATEGORIES = ['cereal', 'legume', 'root_tuber', 'vegetable', 'fruit', 'cash_crop', 'fodder', 'tree', 'other'];

    public const BREED_PURPOSES = ['dairy', 'beef', 'dual', 'meat', 'eggs', 'wool', 'draught', 'other'];

    public const UNIT_DIMENSIONS = ['mass', 'volume', 'area', 'count', 'length'];

    public const INVENTORY_KINDS = ['seed', 'fertilizer', 'feed', 'chemical', 'drug', 'tool', 'equipment', 'harvest', 'packaging', 'fuel', 'other'];

    public const ACTIVITY_MODULES = ['crops', 'livestock', 'assets', 'inventory', 'general'];

    /**
     * @return array<string, array{table:string, label:string, parent:?array{column:string, table:string}, rules:callable(?string):array}>
     */
    public static function all(): array
    {
        return [
            'crops' => [
                'table' => 'global_crops', 'label' => 'Crops', 'parent' => null,
                'rules' => fn (?string $id) => [
                    'code' => $id ? ['prohibited'] : self::codeRule('global_crops'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'scientific_name' => ['nullable', 'string', 'max:160'],
                    'category' => [$id ? 'sometimes' : 'required', Rule::in(self::CROP_CATEGORIES)],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'crop-varieties' => [
                'table' => 'global_crop_varieties', 'label' => 'Crop varieties', 'parent' => ['column' => 'crop_id', 'table' => 'global_crops'],
                'rules' => fn (?string $id) => [
                    'crop_id' => [$id ? 'prohibited' : 'required', 'uuid', 'exists:global_crops,id'],
                    'code' => $id ? ['prohibited'] : self::codeRule('global_crop_varieties', 'crop_id'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'maturity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'animal-species' => [
                'table' => 'global_animal_species', 'label' => 'Animal species', 'parent' => null,
                'rules' => fn (?string $id) => [
                    'code' => $id ? ['prohibited'] : self::codeRule('global_animal_species'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'animal-breeds' => [
                'table' => 'global_animal_breeds', 'label' => 'Animal breeds', 'parent' => ['column' => 'species_id', 'table' => 'global_animal_species'],
                'rules' => fn (?string $id) => [
                    'species_id' => [$id ? 'prohibited' : 'required', 'uuid', 'exists:global_animal_species,id'],
                    'code' => $id ? ['prohibited'] : self::codeRule('global_animal_breeds', 'species_id'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'purpose' => ['nullable', Rule::in(self::BREED_PURPOSES)],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'units' => [
                'table' => 'units', 'label' => 'Units', 'parent' => null,
                'rules' => fn (?string $id) => [
                    'code' => $id ? ['prohibited'] : self::codeRule('units'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
                    // Changing a unit's dimension or factor would silently change every stored quantity.
                    'dimension' => [$id ? 'prohibited' : 'required', Rule::in(self::UNIT_DIMENSIONS)],
                    'to_base' => [$id ? 'prohibited' : 'required', 'numeric', 'gt:0', 'max:1000000000'],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'inventory-categories' => [
                'table' => 'global_inventory_categories', 'label' => 'Inventory categories', 'parent' => null,
                'rules' => fn (?string $id) => [
                    'code' => $id ? ['prohibited'] : self::codeRule('global_inventory_categories'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'kind' => [$id ? 'sometimes' : 'required', Rule::in(self::INVENTORY_KINDS)],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
            'activity-types' => [
                'table' => 'global_activity_types', 'label' => 'Activity types', 'parent' => null,
                'rules' => fn (?string $id) => [
                    'code' => $id ? ['prohibited'] : self::codeRule('global_activity_types'),
                    'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
                    'module' => [$id ? 'sometimes' : 'required', Rule::in(self::ACTIVITY_MODULES)],
                    'is_active' => ['sometimes', 'boolean'],
                ],
            ],
        ];
    }

    /**
     * Codes are set once, on create (they are what farms and imports refer to).
     * Codes of child entries are unique within their parent.
     */
    private static function codeRule(string $table, ?string $parentColumn = null): array
    {
        $unique = Rule::unique($table, 'code');
        if ($parentColumn !== null) {
            $unique->where($parentColumn, (string) request()->input($parentColumn));
        }

        return ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', $unique];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
