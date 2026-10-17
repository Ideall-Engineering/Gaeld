<?php

namespace App\Domains\Projects\Enums;

/**
 * Where an acquired article stands; a returned one charges nobody.
 */
enum AcquisitionStatus: string
{
    case Ordered = 'ordered';
    case Delivered = 'delivered';
    case Returned = 'returned';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
