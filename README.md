# Normalizzatore

Standalone CLI application targeting PHP 8.2 or later to normalize Italian postal addresses contained in CSV files.

The input CSV must include the columns `vianum`, `CAP`, `citta`, and `Provincia`. Additional columns are allowed. The application will preserve the complete original record and append separate normalized values and review information; normalization must never alter the original input data.

The CSV delimiter is configurable, and CSV input/output remains separate from address normalization. CAP values are handled as strings to preserve leading zeroes. Normalization uses the local SQLite copy of the street directory.

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

The importer streams the DBF into SQLite and stores lookup keys made by trimming, collapsing whitespace, Unicode uppercasing, and the explicit supported accent/apostrophe canonicalization below. It preserves all source text columns. The source DBF and generated local SQLite databases are data files and are not part of the repository.

Directory lookup and CAP resolution are separate steps: lookup returns all entries for an exact normalized street/city/province key, then a pure resolver evaluates numeric inclusive civic ranges using the supported parity codes T (all civics), P (even civics), and D (odd civics). Only five-digit CAPs are returned as normalized CAPs. Results distinguish RESOLVED, NO_MATCH, AMBIGUOUS, and INDETERMINATE; unsupported directory data is preserved and can prevent a confident result. This stage does not use aliases or fuzzy matching.

`SqliteAddressDirectory::findTerritorialEntries()` retrieves city-level evidence grouped by the original city, province, and CAP values. On its first territorial lookup per directory connection, it pays a one-time full-directory aggregation cost and builds an indexed SQLite TEMP table in memory; reuse the same directory instance throughout a CSV run. Street-only lookups do not build this table. The persistent database is opened read-only and remains unchanged. The independent `TerritorialResolver` returns `RESOLVED` only when the evidence yields one ordinary five-digit CAP without non-ordinary CAP values; it returns `NO_MATCH`, `AMBIGUOUS`, or `INDETERMINATE` otherwise. Non-ordinary values remain evidence and prevent a unique CAP from being silently accepted. Multiple provinces with the same CAP can resolve; differing CAPs remain ambiguous. TerritorialResolver is now consumed by AddressResolutionOrchestrator for the territorial path. Source CAP verification belongs to a later layer and does not influence this resolution.

## Orchestrazione della risoluzione

AddressResolutionOrchestrator seleziona il percorso STREET_BASED o TERRITORIAL e restituisce un risultato comune tipizzato. Il percorso territoriale conserva il proprio TerritorialResolution; quello stradale conserva per ogni interpretazione il candidato, le righe restituite dalla directory e il relativo CapResolution. CAP differenti restano ambigui; CAP concordanti possono risolversi anche quando derivano da più interpretazioni, segnalando tale circostanza. Le evidenze indeterminate sono mantenute e non esistono fallback automatici fra i percorsi. Il CAP sorgente non influenza la risoluzione. Riutilizzare la stessa directory durante l'elaborazione di un batch permette di riutilizzare la tabella temporanea territoriale.

SourceCapVerifier confronta separatamente il CAP originale con un AddressResolution già calcolato. Accetta come CAP confrontabile solo cinque cifre ASCII, rimuovendo gli eventuali spazi esterni e preservando gli zeri iniziali. I suoi esiti sono MATCH, MISMATCH, SOURCE_MISSING, SOURCE_INVALID e UNVERIFIABLE; un CAP presente fra più candidati non risolve un'ambiguità. Una proposta di correzione è un valore strutturato, prodotto solo quando la risoluzione è univoca, e non modifica mai il dato sorgente né il risultato dell'orchestratore. La pipeline CSV conserva le colonne sorgente e aggiunge colonne separate per valori normalizzati e correzioni suggerite.

`AddressFieldNormalizer` restituisce un esito immutabile per via, civico, dettagli civici, città e provincia. Ogni campo distingue il valore confermato, una trasformazione sintattica, una proposta sostenuta dal repertorio, l'ambiguità, la mancata verificabilità e l'assenza del valore; le correzioni sono oggetti tipizzati e non sostituiscono i dati originali. `AddressParser` conserva tutte le proprie interpretazioni e aggiunge una `AddressSyntaxPreference` esplicita: per un civico numerico finale sintatticamente plausibile può indicare la separazione via/civico preferita, con una diagnostica tipizzata sulla regola applicata o sul motivo dell'astensione. Nei casi con più numeri la preferenza è limitata a indizi sintattici circoscritti, come denominazioni con un mese, una virgola finale con testo intermedio o la forma numerata di strada statale; dettagli numerici complessi e confini incerti restano senza preferenza. Questa preferenza non dipende dalla posizione dei candidati, non elimina alternative e non influenza lookup, CAP o verifica del CAP sorgente. Nel percorso territoriale il normalizzatore usa la preferenza, o un'unica interpretazione non ambigua, per produrre separatamente via, civico e dettagli con origine `SYNTAX` e stato `SYNTAX_NORMALIZED`; il risultato è esportabile ma non dichiara conferma repertoriale. Se la sintassi è complessa o ambigua conserva l'ambiguità. Nel percorso stradale le evidenze già raccolte dalla directory restano distinte e prevalenti per i campi, anche quando divergono dalla preferenza sintattica. La preferenza non risolve mai un'ambiguità CAP. Non sono introdotti alias, correzioni ortografiche o equivalenze toponomastiche. Nel percorso stradale vengono riutilizzati i candidati, le righe di repertorio e i risultati già conservati da `AddressResolution`; nel percorso territoriale il CAP non viene usato per confermare via o civico. Una via del repertorio è proposta solo quando le evidenze la identificano senza ambiguità. Per città e provincia il repertorio non impone la propria grafia: differenze di maiuscole o spaziatura sono gestite solo sintatticamente, mentre una denominazione cittadina lessicalmente diversa o sigle provinciali differenti restano non verificate e producono diagnostiche tipizzate, non correzioni automatiche. Sigle provinciali non valide o multiple restano non verificate. Le interpretazioni sono valutate campo per campo; i candidati completi e le relative evidenze restano conservati. Il normalizzatore non effettua lookup e non dipende da `SourceCapVerifier`.

`Application\AddressProcessor` integra l'elaborazione di una singola riga: esegue una volta `AddressResolutionOrchestrator`, verifica il CAP sorgente con quella stessa risoluzione e normalizza i campi riutilizzando le stesse evidenze. `AddressProcessingResult` conserva l'input e gli oggetti completi di risoluzione, verifica e normalizzazione; non li appiattisce in uno stato complessivo o in messaggi. Il CAP normalizzato deriva solo da una risoluzione `RESOLVED`; un CAP sorgente valido ma non verificabile resta nell'input e nella verifica senza diventare un valore di fallback. Le correzioni dei campi e del CAP restano tipizzate e separate, così come le diagnostiche.

## Pipeline CSV

Eseguire `bin/normalizzatore normalize input.csv output.csv [--delimiter=";"]`. Il delimitatore predefinito è `;`; sono supportati anche virgola e tab. Sono richiesti gli header esatti `vianum`, `CAP`, `citta` e `Provincia`. Tutte le colonne originali sono mantenute nello stesso ordine e con gli stessi valori; dieci colonne di normalizzazione e verifica sono aggiunte in coda. Un output già esistente o una collisione con uno degli header generati causa un errore. La pipeline elabora il file in streaming con una singola istanza della directory e scrive su un temporaneo nella destinazione, pubblicandolo solo al completamento.

Le colonne dei campi normalizzati sono valorizzate per stati `CONFIRMED`, `SYNTAX_NORMALIZED` e `DIRECTORY_CORRECTION`; campi ambigui, non verificabili o mancanti restano vuoti. `SYNTAX_NORMALIZED` indica che il parser ha determinato con sufficiente affidabilità la forma del campo, ma non implica conferma della directory. `CONFIRMED` conserva evidenza repertoriale concordante e `DIRECTORY_CORRECTION` una proposta sostenuta dal repertorio. La separazione strutturale di via e civico non è una correzione suggerita. `cap_normalizzato` deriva esclusivamente da una risoluzione `RESOLVED`, senza fallback al CAP sorgente. `stato_risoluzione` e `verifica_cap` riportano i nomi degli enum. `correzioni_suggerite` serializza soltanto correzioni tipizzate di CAP e campi; i valori sono racchiusi tra virgolette e i caratteri delimitanti interni sono sottoposti a escaping. `diagnostica` conserva il codice e il contesto di origine in ordine stabile. Al termine, il comando stampa un breve riepilogo con righe elaborate, risolte, ambigue, non risolte, righe con correzioni e tempo totale.

## Città capizzate

The catalog in `resources/capizzated-cities.tsv` contains the 42 capizzated cities. Membership depends only on the city name, compared after trimming, collapsing whitespace, Unicode uppercasing, and the explicit Italian accent/apostrophe equivalences described below; it does not add aliases. The province in the source list is metadata only. MESTRE and VENEZIA remain separate entries. `AddressStrategyClassifier` classifies an input as STREET_BASED or TERRITORIAL; the orchestrator executes the corresponding existing resolution path.

`Text\OrthographyNormalizer` provides deterministic canonicalization for lookup keys and exported street/city values. It maps the supported Italian grave/acute vowels to `A'`, `E'`, `I'`, `O'`, or `U'`, and the apostrophe variants U+2019, U+2018, U+00B4, and U+0060 to ASCII `'`; supported decomposed vowel sequences are handled without `intl`. It uppercases Unicode and applies the existing whitespace collapse. Other diacritics such as `â`, `ê`, `ô`, and `ç` are not accent-folded; quotation marks, civic numbers, details, province, CAP, and mojibake are not converted. This is explicit orthographic equivalence, not punctuation removal, transliteration, or fuzzy matching.

The persistent SQLite schema and stored keys are unchanged. To query an existing database built with the previous key format, `SqliteAddressDirectory` lazily prepares connection-local indexed TEMP structures: territorial evidence is aggregated by canonical city key on the first territorial lookup, while the street TEMP index stores only rows whose old key contains a supported orthographic form that needs conversion. Other street matches continue to use the persistent composite index, and all matching source rows remain evidence. The TEMP structures are prepared at most once per directory connection and do not alter the database file. Reuse one directory instance for a batch.

The importer decodes DBF text as CP850, consistent with the file's Language Driver ID `0x02` and the Italian characters verified in its records. Since the file identifies as dBASE III (`0x03`), that byte is not necessarily a normative encoding declaration for every reader. Some source values contain apparently anomalous sequences; the importer preserves them after decoding and applies no heuristic corrections.
