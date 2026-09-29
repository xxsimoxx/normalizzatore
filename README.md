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

The importer decodes DBF text as CP850, consistent with the file's Language Driver ID `0x02` and the Italian characters verified in its records. Since the file identifies as dBASE III (`0x03`), that byte is not necessarily a normative encoding declaration for every reader. Some source values contain apparently anomalous sequences; the importer preserves them after decoding and applies no heuristic corrections.
