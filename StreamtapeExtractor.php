<?php

declare(strict_types=1);

/**
 * StreamtapeExtractor – CLI entry point.
 *
 * Usage:
 *   php StreamtapeExtractor.php <streamtape-url>
 *   php StreamtapeExtractor.php --debug <streamtape-url>
 *   php StreamtapeExtractor.php --help
 *
 * On success  → prints only the direct .mp4 URL to STDOUT (ready for wget/curl/axel) and exits 0.
 * On failure  → prints a line starting with "ERROR:" to STDERR and exits 1.
 *
 * This file is the CLI wrapper.  All extraction logic lives in src/ and is
 * autoloaded via Composer.  See README.md for library usage and architecture notes.
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}

use Babai834\StreamtapeExtractor\Exception\InvalidStreamtapeUrlException;
use Babai834\StreamtapeExtractor\Exception\StreamtapeExtractorException;
use Babai834\StreamtapeExtractor\StreamtapeExtractor;

// ══════════════════════════════════════════════════════════════════════════════
// Backward-compatibility shim
// ══════════════════════════════════════════════════════════════════════════════
// Provide the global class name so any legacy code doing
//   StreamtapeExtractor::getDirectLink($url)
// continues to work without changes.
if (!class_exists('StreamtapeExtractor', false)) {
    class_alias(StreamtapeExtractor::class, 'StreamtapeExtractor');
}

// ══════════════════════════════════════════════════════════════════════════════
// CLI entry-point
// ══════════════════════════════════════════════════════════════════════════════

// Only run when executed directly (not included/required as a library).
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

/** @var string[] $argv */
$debug  = false;
$rawUrl = null;
$args   = array_slice($argv, 1);

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, <<<'HELP'
streamtape-direct-link-extractor

USAGE
  php StreamtapeExtractor.php [OPTIONS] <streamtape-url>

OPTIONS
  --debug, -d   Print debug information (intermediate values) to STDERR.
  --help,  -h   Show this help text and exit.

ARGUMENTS
  <streamtape-url>
    A Streamtape watch or embed URL.  Supported formats:
      https://streamtape.com/v/<id>
      https://streamtape.com/e/<id>
      https://streamtape.to/v/<id>    (alias domain)

OUTPUT
  On success : prints only the direct .mp4 URL to STDOUT.  Exit code 0.
  On failure : prints "ERROR: <reason>" to STDERR.           Exit code 1.

EXAMPLES
  php StreamtapeExtractor.php https://streamtape.com/v/OXMp3qjo9aiZAl9
  php StreamtapeExtractor.php --debug https://streamtape.com/v/OXMp3qjo9aiZAl9
  php StreamtapeExtractor.php https://streamtape.to/v/OXMp3qjo9aiZAl9 | wget -i -

HELP);
        exit(0);
    } elseif ($arg === '--debug' || $arg === '-d') {
        $debug = true;
    } elseif ($rawUrl === null && $arg !== '') {
        $rawUrl = $arg;
    }
}

if ($rawUrl === null) {
    fwrite(STDERR, "Usage: php StreamtapeExtractor.php [--debug] <streamtape-url>\n");
    fwrite(STDERR, "       php StreamtapeExtractor.php --help  (for full usage)\n");
    exit(1);
}

try {
    $directLink = StreamtapeExtractor::getDirectLink($rawUrl, $debug);
    echo $directLink . PHP_EOL;
    exit(0);
} catch (InvalidStreamtapeUrlException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (StreamtapeExtractorException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: Unexpected error – ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

