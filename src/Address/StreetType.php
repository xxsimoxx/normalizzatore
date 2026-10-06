<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

/** Street-type prefixes observed often enough in the directory for fuzzy v1. */
enum StreetType: string
{
    case VIA = 'VIA';
    case LOCALITA_APOSTROPHE = "LOCALITA'";
    case STRADA = 'STRADA';
    case CONTRADA = 'CONTRADA';
    case VICO = 'VICO';
    case VICOLO = 'VICOLO';
    case PIAZZA = 'PIAZZA';
    case TRAVERSA = 'TRAVERSA';
    case VIALE = 'VIALE';
    case LARGO = 'LARGO';
    case CASCINA = 'CASCINA';
    case FRAZIONE = 'FRAZIONE';
    case VOCABOLO = 'VOCABOLO';
    case PODERE = 'PODERE';
    case CORTILE = 'CORTILE';
    case PIAZZALE = 'PIAZZALE';
    case REGIONE = 'REGIONE';
    case CASE = 'CASE';
    case PIAZZETTA = 'PIAZZETTA';
    case BORGATA = 'BORGATA';
    case SALITA = 'SALITA';
    case CALLE = 'CALLE';
    case ALPE = 'ALPE';
    case CORTE = 'CORTE';
    case CORSO = 'CORSO';
    case RONCO = 'RONCO';
    case BORGO = 'BORGO';
    case ROTONDA = 'ROTONDA';
    case RIONE = 'RIONE';
    case VILLAGGIO = 'VILLAGGIO';
    case VICOLETTO = 'VICOLETTO';
    case RAMO = 'RAMO';
    case SOTTOPORTICO = 'SOTTOPORTICO';
    case PONTE = 'PONTE';
    case NUCLEO = 'NUCLEO';
    case ZONA = 'ZONA';
    case RAMPA = 'RAMPA';
    case DISCESA = 'DISCESA';
    case FONDAMENTA = 'FONDAMENTA';
    case SCALINATA = 'SCALINATA';
}
