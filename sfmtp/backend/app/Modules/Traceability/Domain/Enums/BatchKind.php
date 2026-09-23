<?php

namespace App\Modules\Traceability\Domain\Enums;

/** Node types in the batch graph (docs/07 §1). */
enum BatchKind: string
{
    case SeedLot = 'seed_lot';
    case InputLot = 'input_lot';
    case Nursery = 'nursery';
    case CropLot = 'crop_lot';
    case Harvest = 'harvest';
    case Animal = 'animal';
    case AnimalProduct = 'animal_product';
    case Processed = 'processed';
    case Packaged = 'packaged';
    case Shipment = 'shipment';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Kinds a user may create by hand. The others are created by their
     * domain modules (crops, livestock, procurement, sales) as work happens.
     *
     * @return array<int,string>
     */
    public static function manual(): array
    {
        return [self::SeedLot->value, self::InputLot->value, self::Processed->value, self::Packaged->value];
    }
}
