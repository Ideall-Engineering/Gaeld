<?php

namespace App\Domains\Projects\Enums;

/**
 * How an allocation came to be: typed in, carried over from a line that was
 * rebuilt, set for many entries at once, written through the API, taken
 * over from the old free-text cost centre column, or taken from the project
 * of the invoice the entry books.
 */
enum AllocationSource: string
{
    case Manual = 'manual';
    case Carried = 'carried';
    case Bulk = 'bulk';
    case Api = 'api';
    case Legacy = 'legacy';
    case Invoice = 'invoice';
}
