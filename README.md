# Normalizzatore

Standalone CLI application targeting PHP 8.2 or later to normalize Italian postal addresses contained in CSV files.

The input CSV must include the columns `vianum`, `CAP`, `citta`, and `Provincia`. Additional columns are allowed. The application will preserve the complete original record and append separate normalized values and review information; normalization must never alter the original input data.

The CSV delimiter will be configurable, and the reader/writer will remain independent of address normalization. CAP values must be handled as strings to preserve leading zeroes. Normalization will eventually use a local corrected Italian street/address directory as its authoritative source. Its database schema is not yet defined and will not be assumed by the project foundation.

## Requirements

- PHP 8.2 or later
- Composer

PHPUnit 11 is used for development and testing. The application currently has no runtime dependencies.

## Setup and tests

Install the development dependencies and run the test suite:

```sh
composer install
composer test
```

The CLI entry point is `bin/normalizzatore`. Application behavior will be added in later development steps.
