<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum AddressFieldDiagnostic: string
{
    case NO_PARSER_INTERPRETATION = 'no_parser_interpretation';
    case MULTIPLE_PARSER_INTERPRETATIONS = 'multiple_parser_interpretations';
    case MULTIPLE_DIRECTORY_SPELLINGS = 'multiple_directory_spellings';
    case NO_DIRECTORY_EVIDENCE = 'no_directory_evidence';
    case MULTIPLE_DIRECTORY_PROVINCES = 'multiple_directory_provinces';
    case INVALID_DIRECTORY_PROVINCE = 'invalid_directory_province';
    case CITY_NAME_DIFFERS_FROM_DIRECTORY = 'city_name_differs_from_directory';
    case PROVINCE_SIGLA_DIFFERS_FROM_DIRECTORY = 'province_sigla_differs_from_directory';
    case PROVINCE_SOURCE_MISSING = 'province_source_missing';
    case PROVINCE_SOURCE_INVALID = 'province_source_invalid';
    case FUZZY_CITY_AMBIGUOUS = 'fuzzy_city_ambiguous';
}
