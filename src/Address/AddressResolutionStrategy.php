<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

enum AddressResolutionStrategy: string
{
    /** Future strategy that uses the street and civic number. */
    case STREET_BASED = 'street_based';

    /** Future strategy that relies on territorial information without street matching. */
    case TERRITORIAL = 'territorial';
}
