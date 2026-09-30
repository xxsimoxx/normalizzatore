# Normalizzatore

Standalone CLI application targeting PHP 8.2 or later to normalize Italian postal addresses contained in CSV files.

The input CSV must include the columns `vianum`, `CAP`, `citta`, and `Provincia`. Additional columns are allowed. The application will preserve the complete original record and append separate normalized values and review information; normalization must never alter the original input data.

The CSV delimiter will be configurable, and the reader/writer will remain independent of address normalization. CAP values must be handled as strings to preserve leading zeroes. Normalization will eventually use a local corrected Italian street/address directory as its authoritative source. Its database schema is not yet defined and will not be assumed by the project foundation.

## Requirements

- PHP 8.2 or later
- Composer
- `pdo_sqlite` PHP extension to build the local directory database
- `mbstring` PHP extension for Unicode-aware directory keys

PHPUnit 11 is used for development and testing. There are no Composer runtime packages; the importer and lookup keys use PHP's `iconv`, `pdo_sqlite`, and `mbstring` extensions.

## Setup and tests

Install the development dependencies and run the test suite:

```sh
composer install
composer test
```

The CLI entry point is `bin/normalizzatore`. A local SQLite copy of the street directory can be built with:

```sh
php bin/import-directory archi_cap.dbf var/archi_cap.sqlite
```

The importer streams the DBF into SQLite and stores conservative internal lookup keys made by trimming, collapsing whitespace, and Unicode uppercasing. It preserves punctuation and accents. The source DBF and generated local SQLite databases are data files and are not part of the repository.

Directory lookup and CAP resolution are separate steps: lookup returns all entries for an exact normalized street/city/province key, then a pure resolver evaluates numeric inclusive civic ranges using the supported parity codes T (all civics), P (even civics), and D (odd civics). Only five-digit CAPs are returned as normalized CAPs. Results distinguish RESOLVED, NO_MATCH, AMBIGUOUS, and INDETERMINATE; unsupported directory data is preserved and can prevent a confident result. This stage does not use aliases or fuzzy matching.

`SqliteAddressDirectory::findTerritorialEntries()` retrieves city-level evidence grouped by the original city, province, and CAP values. On its first territorial lookup per directory connection, it pays a one-time full-directory aggregation cost and builds an indexed SQLite TEMP table in memory; reuse the same directory instance throughout a CSV run. Street-only lookups do not build this table. The persistent database is opened read-only and remains unchanged. The independent `TerritorialResolver` returns `RESOLVED` only when the evidence yields one ordinary five-digit CAP without non-ordinary CAP values; it returns `NO_MATCH`, `AMBIGUOUS`, or `INDETERMINATE` otherwise. Non-ordinary values remain evidence and prevent a unique CAP from being silently accepted. Multiple provinces with the same CAP can resolve; differing CAPs remain ambiguous. TerritorialResolver is now consumed by AddressResolutionOrchestrator for the territorial path. Source CAP verification belongs to a later layer and does not influence this resolution.

## Orchestrazione della risoluzione

AddressResolutionOrchestrator seleziona il percorso STREET_BASED o TERRITORIAL e restituisce un risultato comune tipizzato. Il percorso territoriale conserva il proprio TerritorialResolution; quello stradale conserva per ogni interpretazione il candidato, le righe restituite dalla directory e il relativo CapResolution. CAP differenti restano ambigui; CAP concordanti possono risolversi anche quando derivano da più interpretazioni, segnalando tale circostanza. Le evidenze indeterminate sono mantenute e non esistono fallback automatici fra i percorsi. Il CAP sorgente non influenza la risoluzione. Riutilizzare la stessa directory durante l'elaborazione di un batch permette di riutilizzare la tabella temporanea territoriale. La pipeline CSV completa e l'esportazione non sono ancora implementate.

SourceCapVerifier confronta separatamente il CAP originale con un AddressResolution già calcolato. Accetta come CAP confrontabile solo cinque cifre ASCII, rimuovendo gli eventuali spazi esterni e preservando gli zeri iniziali. I suoi esiti sono MATCH, MISMATCH, SOURCE_MISSING, SOURCE_INVALID e UNVERIFIABLE; un CAP presente fra più candidati non risolve un'ambiguità. Una proposta di correzione è un valore strutturato, prodotto solo quando la risoluzione è univoca, e non modifica mai il dato sorgente né il risultato dell'orchestratore. La futura esportazione CSV conserverà i valori originali e aggiungerà colonne separate per valori normalizzati e correzioni suggerite; l'esportazione non è ancora implementata.

`AddressFieldNormalizer` restituisce un esito immutabile per via, civico, dettagli civici, città e provincia. Ogni campo distingue il valore confermato, una trasformazione sintattica, una proposta sostenuta dal repertorio, l'ambiguità, la mancata verificabilità e l'assenza del valore; le correzioni sono oggetti tipizzati e non sostituiscono i dati originali. `AddressParser` conserva tutte le proprie interpretazioni e aggiunge una `AddressSyntaxPreference` esplicita: per un civico numerico finale sintatticamente plausibile può indicare la separazione via/civico preferita, con una diagnostica tipizzata sulla regola applicata o sul motivo dell'astensione. Nei casi con più numeri la preferenza è limitata a indizi sintattici circoscritti, come denominazioni con un mese, una virgola finale con testo intermedio o la forma numerata di strada statale; dettagli numerici complessi e confini incerti restano senza preferenza. Questa preferenza non dipende dalla posizione dei candidati, non elimina alternative e non influenza lookup, CAP o verifica del CAP sorgente. Nel percorso territoriale il normalizzatore usa la preferenza per produrre separatamente via, civico e dettagli con origine `SYNTAX`, mantenendoli non verificati dal repertorio; se la sintassi è complessa o ambigua conserva l'ambiguità. Nel percorso stradale le evidenze già raccolte dalla directory restano distinte e prevalenti per i campi, anche quando divergono dalla preferenza sintattica. La preferenza non risolve mai un'ambiguità CAP. Non sono introdotti alias, correzioni ortografiche o equivalenze toponomastiche. Nel percorso stradale vengono riutilizzati i candidati, le righe di repertorio e i risultati già conservati da `AddressResolution`; nel percorso territoriale il CAP non viene usato per confermare via o civico. Una via del repertorio è proposta solo quando le evidenze la identificano senza ambiguità. Per città e provincia il repertorio non impone la propria grafia: differenze di maiuscole o spaziatura sono gestite solo sintatticamente, mentre una denominazione cittadina lessicalmente diversa o sigle provinciali differenti restano non verificate e producono diagnostiche tipizzate, non correzioni automatiche. Sigle provinciali non valide o multiple restano non verificate. Le interpretazioni sono valutate campo per campo; i candidati completi e le relative evidenze restano conservati. Il normalizzatore non effettua lookup e non dipende da `SourceCapVerifier`. La pipeline CSV e l'esportazione sono ancora da implementare.

## Città capizzate

The catalog in `resources/capizzated-cities.tsv` contains the 42 capizzated cities. Membership depends only on the city name, compared after trimming, collapsing whitespace, and Unicode uppercasing; punctuation and accents are preserved. The province in the source list is metadata only. MESTRE and VENEZIA remain separate entries. `AddressStrategyClassifier` now classifies an input for a future street-based or territorial strategy; this classification does not resolve a CAP or execute either strategy.

The importer decodes DBF text as CP850, consistent with the file's Language Driver ID `0x02` and the Italian characters verified in its records. Since the file identifies as dBASE III (`0x03`), that byte is not necessarily a normative encoding declaration for every reader. Some source values contain apparently anomalous sequences; the importer preserves them after decoding and applies no heuristic corrections.
