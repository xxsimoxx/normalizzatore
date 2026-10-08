<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

enum FrazioneTypeGroup: string
{
    case CENTRO_ABITATO = 'Centro abitato';
    case NUCLEO_ABITATO = 'Nucleo abitato';
    case MIXED = 'MISTO';
    case UNKNOWN = 'TIPO sconosciuto';
    case NONE = 'nessun candidato catalogo';
}
