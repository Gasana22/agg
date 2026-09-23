<?php

namespace App\Modules\Catalog\Database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter catalogue for Ugandan farms (product-owner decision: Uganda first).
 * Idempotent: upserts by code, never overwrites admin edits to names.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $crops = [
            'maize' => ['Maize', 'Zea mays', 'cereal', ['longe_5' => ['Longe 5', 115], 'longe_10h' => ['Longe 10H', 120], 'dk_777' => ['DK 777', 125]]],
            'beans' => ['Beans', 'Phaseolus vulgaris', 'legume', ['nabe_15' => ['NABE 15', 75], 'nabe_16' => ['NABE 16', 80]]],
            'coffee_robusta' => ['Coffee (Robusta)', 'Coffea canephora', 'cash_crop', ['kr_7' => ['KR7 (wilt resistant)', null]]],
            'coffee_arabica' => ['Coffee (Arabica)', 'Coffea arabica', 'cash_crop', ['sl_14' => ['SL14', null]]],
            'matooke' => ['Banana (Matooke)', 'Musa acuminata', 'fruit', ['mbwazirume' => ['Mbwazirume', null], 'kisansa' => ['Kisansa', null]]],
            'cassava' => ['Cassava', 'Manihot esculenta', 'root_tuber', ['narocass_1' => ['NAROCASS 1', 365]]],
            'sweet_potato' => ['Sweet potato', 'Ipomoea batatas', 'root_tuber', ['naspot_8' => ['NASPOT 8', 120]]],
            'groundnuts' => ['Groundnuts', 'Arachis hypogaea', 'legume', ['serenut_14r' => ['Serenut 14R', 90]]],
            'soybean' => ['Soybean', 'Glycine max', 'legume', []],
            'sorghum' => ['Sorghum', 'Sorghum bicolor', 'cereal', []],
            'rice' => ['Rice', 'Oryza sativa', 'cereal', ['nerica_4' => ['NERICA 4', 110]]],
            'sunflower' => ['Sunflower', 'Helianthus annuus', 'cash_crop', []],
            'tomato' => ['Tomato', 'Solanum lycopersicum', 'vegetable', []],
            'cabbage' => ['Cabbage', 'Brassica oleracea', 'vegetable', []],
            'onion' => ['Onion', 'Allium cepa', 'vegetable', []],
            'napier_grass' => ['Napier grass', 'Pennisetum purpureum', 'fodder', []],
            'eucalyptus' => ['Eucalyptus', 'Eucalyptus grandis', 'tree', []],
            'tea' => ['Tea', 'Camellia sinensis', 'cash_crop', []],
        ];
        foreach ($crops as $code => [$name, $scientific, $category, $varieties]) {
            $cropId = $this->upsert('global_crops', ['code' => $code], ['name' => $name, 'scientific_name' => $scientific, 'category' => $category]);
            foreach ($varieties as $vCode => [$vName, $days]) {
                $this->upsert('global_crop_varieties', ['crop_id' => $cropId, 'code' => $vCode], ['name' => $vName, 'maturity_days' => $days]);
            }
        }

        $species = [
            'cattle' => ['Cattle', ['ankole' => ['Ankole Longhorn', 'dual'], 'boran' => ['Boran', 'beef'], 'friesian' => ['Holstein-Friesian', 'dairy'], 'jersey' => ['Jersey', 'dairy'], 'zebu' => ['East African Zebu', 'dual']]],
            'goat' => ['Goat', ['boer' => ['Boer', 'meat'], 'mubende' => ['Mubende', 'meat'], 'saanen' => ['Saanen', 'dairy'], 'toggenburg' => ['Toggenburg', 'dairy']]],
            'sheep' => ['Sheep', ['dorper' => ['Dorper', 'meat'], 'merino' => ['Merino', 'wool']]],
            'pig' => ['Pig', ['large_white' => ['Large White', 'meat'], 'landrace' => ['Landrace', 'meat']]],
            'chicken' => ['Chicken', ['kuroiler' => ['Kuroiler', 'dual'], 'broiler' => ['Broiler', 'meat'], 'layer' => ['Layer', 'eggs'], 'local' => ['Local (Kienyeji)', 'dual']]],
            'rabbit' => ['Rabbit', []],
            'fish_tilapia' => ['Fish (Tilapia)', []],
        ];
        foreach ($species as $code => [$name, $breeds]) {
            $speciesId = $this->upsert('global_animal_species', ['code' => $code], ['name' => $name]);
            foreach ($breeds as $bCode => [$bName, $purpose]) {
                $this->upsert('global_animal_breeds', ['species_id' => $speciesId, 'code' => $bCode], ['name' => $bName, 'purpose' => $purpose]);
            }
        }

        $units = [
            'kg' => ['Kilogram', 'mass', 1], 'g' => ['Gram', 'mass', 0.001], 't' => ['Tonne', 'mass', 1000],
            'bag_50kg' => ['Bag (50 kg)', 'mass', 50], 'bag_100kg' => ['Bag (100 kg)', 'mass', 100],
            'l' => ['Litre', 'volume', 1], 'ml' => ['Millilitre', 'volume', 0.001], 'jerrycan_20l' => ['Jerrycan (20 L)', 'volume', 20],
            'ha' => ['Hectare', 'area', 1], 'acre' => ['Acre', 'area', 0.40468564], 'm2' => ['Square metre', 'area', 0.0001],
            'pcs' => ['Piece', 'count', 1], 'head' => ['Head (animal)', 'count', 1], 'tray_30' => ['Tray (30 eggs)', 'count', 30], 'bunch' => ['Bunch', 'count', 1],
            'm' => ['Metre', 'length', 1], 'km' => ['Kilometre', 'length', 1000],
        ];
        foreach ($units as $code => [$name, $dimension, $toBase]) {
            $this->upsert('units', ['code' => $code], ['name' => $name, 'dimension' => $dimension, 'to_base' => $toBase]);
        }

        $categories = [
            'seeds' => ['Seeds & planting material', 'seed'], 'fertilizers' => ['Fertilizers', 'fertilizer'], 'animal_feed' => ['Animal feed', 'feed'],
            'agrochemicals' => ['Pesticides & herbicides', 'chemical'], 'veterinary_drugs' => ['Veterinary drugs & vaccines', 'drug'],
            'hand_tools' => ['Hand tools', 'tool'], 'equipment' => ['Equipment & spares', 'equipment'], 'harvest' => ['Harvested produce', 'harvest'],
            'packaging' => ['Packaging', 'packaging'], 'fuel' => ['Fuel & lubricants', 'fuel'],
        ];
        foreach ($categories as $code => [$name, $kind]) {
            $this->upsert('global_inventory_categories', ['code' => $code], ['name' => $name, 'kind' => $kind]);
        }

        $activities = [
            'land_preparation' => ['Land preparation', 'crops'], 'planting' => ['Planting', 'crops'], 'weeding' => ['Weeding', 'crops'],
            'fertilizer_application' => ['Fertilizer application', 'crops'], 'spraying' => ['Spraying', 'crops'], 'irrigation' => ['Irrigation', 'crops'],
            'scouting' => ['Pest & disease scouting', 'crops'], 'harvesting' => ['Harvesting', 'crops'], 'pruning' => ['Pruning', 'crops'],
            'feeding' => ['Feeding', 'livestock'], 'milking' => ['Milking', 'livestock'], 'vaccination' => ['Vaccination', 'livestock'],
            'deworming' => ['Deworming', 'livestock'], 'spraying_dipping' => ['Spraying / dipping', 'livestock'], 'weighing' => ['Weighing', 'livestock'],
            'herding' => ['Herding', 'livestock'], 'cleaning_housing' => ['Cleaning animal housing', 'livestock'],
            'maintenance' => ['Maintenance', 'assets'], 'fencing' => ['Fencing', 'assets'],
            'stock_count' => ['Stock count', 'inventory'], 'general_labour' => ['General labour', 'general'],
        ];
        foreach ($activities as $code => [$name, $module]) {
            $this->upsert('global_activity_types', ['code' => $code], ['name' => $name, 'module' => $module]);
        }
    }

    /** Insert if missing; returns the row id. Existing rows are left as the admin edited them. */
    private function upsert(string $table, array $key, array $values): string
    {
        $existing = DB::table($table)->where($key)->value('id');
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid7();
        DB::table($table)->insert(['id' => $id] + $key + $values + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
