<?php

namespace App\Modules\Crops\Application;

use Illuminate\Support\Facades\DB;

/** Unit conversion through the global units catalogue (to_base per dimension). */
class Units
{
    /** @var array<string, object{code:string,dimension:string,to_base:string}>|null */
    private ?array $units = null;

    public function convert(float $quantity, string $from, string $to): ?float
    {
        $all = $this->all();
        $a = $all[$from] ?? null;
        $b = $all[$to] ?? null;
        if ($a === null || $b === null || $a->dimension !== $b->dimension) {
            return null;
        }

        return $quantity * (float) $a->to_base / (float) $b->to_base;
    }

    /** Kilograms for a mass quantity, null for other dimensions. */
    public function toKg(float $quantity, string $unit): ?float
    {
        return $this->convert($quantity, $unit, 'kg');
    }

    private function all(): array
    {
        return $this->units ??= DB::table('units')->get(['code', 'dimension', 'to_base'])->keyBy('code')->all();
    }
}
