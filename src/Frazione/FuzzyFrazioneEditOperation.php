<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

enum FuzzyFrazioneEditOperation: string
{
    case INSERTION = 'INSERTION';
    case DELETION = 'DELETION';
    case SUBSTITUTION = 'SUBSTITUTION';
    case TRANSPOSITION = 'TRANSPOSITION';
}
