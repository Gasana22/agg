<?php

namespace App\Enums;

/**
 * The chain-of-custody stages a batch can pass through. Produced is
 * always the first event, auto-created when the batch is registered from
 * a CropHarvest or AnimalProductionRecord. Recalled is terminal — see
 * TraceBatch::isRecalled().
 */
enum TraceEventType: string
{
    case Produced = 'produced';
    case Processed = 'processed';
    case Packaged = 'packaged';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Sold = 'sold';
    case Recalled = 'recalled';
}
