# streamtape-direct-link-extractor

A lightweight utility that extracts the direct `.mp4` video download URL from any public Streamtape page.  Input a Streamtape watch URL; receive a ready-to-download direct link.

---

## Quick start

```bash
# Install dependencies (first time only)
composer install

# Extract a direct link
php StreamtapeExtractor.php https://streamtape.com/v/OXMp3qjo9aiZAl9

# With debug output on STDERR
php StreamtapeExtractor.php --debug https://streamtape.com/v/OXMp3qjo9aiZAl9

# Pipe straight into a downloader
php StreamtapeExtractor.php https://streamtape.com/v/OXMp3qjo9aiZAl9 | wget -i -
```

On success the **only** thing printed to STDOUT is the direct `.mp4` URL (exit code `0`).  
On failure a line starting with `ERROR:` is printed to STDERR (exit code `1`).

---

## CLI usage

```
php StreamtapeExtractor.php [OPTIONS] <streamtape-url>

OPTIONS
  --debug, -d   Print debug information (intermediate values) to STDERR.
  --help,  -h   Show full usage text and exit.
```

Supported URL formats:

```
https://streamtape.com/v/<id>
https://streamtape.com/e/<id>
https://streamtape.to/v/<id>    (alias domain)
```

---

## Architecture

```
streamtape-direct-link-extractor/
├── StreamtapeExtractor.php          ← CLI entry point (thin wrapper, runs composer autoload)
├── src/
│   ├── StreamtapeExtractor.php      ← Main extractor class (PSR-4 autoloaded)
│   ├── StderrLogger.php             ← Minimal PSR-3 logger for --debug mode
│   ├── Exception/
│   │   ├── StreamtapeExtractorException.php   ← Base domain exception
│   │   ├── InvalidStreamtapeUrlException.php  ← Bad/wrong-host URL
│   │   ├── FetchException.php                 ← HTTP / cURL failure
│   │   ├── ExtractionException.php            ← No video URL found in HTML
│   │   └── FinalizationException.php          ← Extracted value is not a valid URL
│   └── Http/
│       ├── PageFetcherInterface.php  ← Contract for the HTTP layer
│       └── CurlPageFetcher.php       ← Default cURL implementation
├── tests/
│   ├── fixtures/                    ← Static HTML fixtures (no network needed)
│   ├── Unit/
│   │   ├── UrlValidationTest.php
│   │   ├── FinalizationTest.php
│   │   └── ExtractionStrategyTest.php
│   └── Integration/
│       └── StreamtapeExtractorTest.php
├── composer.json
└── phpunit.xml
```

### Extraction pipeline

1. **URL validation / normalization** – accepts `streamtape.com`, `streamtape.to`, and other alias domains; enforces `/v/<id>` or `/e/<id>` path format; always returns canonical HTTPS.
2. **HTTP page fetching** – delegates to `PageFetcherInterface`; default is `CurlPageFetcher` which mirrors browser headers and handles 404/429/empty-body errors.
3. **Extraction strategies** (tried in order):
   - **Primary** – reads `getElementById('norobotlink').innerHTML = …` and `.innerHTML += …` (split assignment, optionally using template literals or a variable reference).
   - **Fallback 1** – looks for a named JS variable (`videoUrl`, `file`, `src`, …).
   - **Fallback 2** – blind scan for any `tapecontent.net/*.mp4` URL in the HTML.
4. **URL finalization** – converts protocol-relative (`//`) or HTTP URLs to HTTPS; appends `?dl=1` (or fixes `dl=0`); validates the result.

---

## Library usage

```php
require 'vendor/autoload.php';

use Babai834\StreamtapeExtractor\StreamtapeExtractor;

// Static API (backward-compatible)
$url = StreamtapeExtractor::getDirectLink('https://streamtape.com/v/OXMp3qjo9aiZAl9');

// Instance API with custom dependencies
use Babai834\StreamtapeExtractor\Http\CurlPageFetcher;
use Monolog\Logger;

$logger    = new Logger('streamtape');
$fetcher   = new CurlPageFetcher(timeout: 30);
$extractor = new StreamtapeExtractor($fetcher, $logger);

$url = $extractor->extract('https://streamtape.com/v/OXMp3qjo9aiZAl9');
```

### Injecting a custom fetcher (e.g. for tests)

```php
use Babai834\StreamtapeExtractor\Http\PageFetcherInterface;
use Babai834\StreamtapeExtractor\Exception\FetchException;

$fetcher = new class implements PageFetcherInterface {
    public function fetch(string $url): string {
        return file_get_contents('/path/to/fixture.html');
    }
};

$extractor = new StreamtapeExtractor($fetcher);
$url = $extractor->extract('https://streamtape.com/v/abc123');
```

### Exception hierarchy

| Exception class                   | Thrown when …                                              |
|-----------------------------------|------------------------------------------------------------|
| `StreamtapeExtractorException`    | Base class; catch this to handle any extractor failure.    |
| `InvalidStreamtapeUrlException`   | The URL is not a valid Streamtape watch/embed URL.         |
| `FetchException`                  | HTTP or cURL error; non-2xx status; empty/unavailable page.|
| `ExtractionException`             | All extraction strategies failed to find a video URL.      |
| `FinalizationException`           | Extracted value cannot be turned into a valid CDN URL.     |

`InvalidStreamtapeUrlException` extends `\InvalidArgumentException` for backward compatibility.  All others extend `StreamtapeExtractorException` (`\RuntimeException`).

---

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

The entire test suite is offline – no real network calls are made.

---

## Requirements

- PHP 8.0+
- `ext-curl` (for the default `CurlPageFetcher`; not required if you inject a custom fetcher)
- Composer (for autoloading and dev dependencies)

