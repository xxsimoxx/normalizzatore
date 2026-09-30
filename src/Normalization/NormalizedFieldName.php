<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum NormalizedFieldName: string
{
    case STREET = 'street';
    case HOUSE_NUMBER = 'house_number';
    case CIVIC_DETAILS = 'civic_details';
    case CITY = 'city';
    case PROVINCE = 'province';
}
