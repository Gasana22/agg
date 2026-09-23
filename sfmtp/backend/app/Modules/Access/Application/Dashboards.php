<?php

namespace App\Modules\Access\Application;

/**
 * Which dashboards a set of permissions opens, in precedence order
 * (docs/05-dashboard-architecture.md §1: Owner > Manager > Accountant >
 * Agronomist / Livestock / Store > Field Worker).
 */
final class Dashboards
{
    public const FARM_ORDER = ['owner', 'manager', 'accountant', 'agronomist', 'livestock', 'store', 'worker'];

    /**
     * @param  array<string,mixed>  $permissions  key => scope
     * @return array<int,string>
     */
    public static function available(array $permissions): array
    {
        return array_values(array_filter(self::FARM_ORDER, fn ($d) => isset($permissions["dashboard.{$d}.view"])));
    }
}
