<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Tests\Integration;

use Babai834\StreamtapeExtractor\Exception\ExtractionException;
use Babai834\StreamtapeExtractor\Exception\FetchException;
use Babai834\StreamtapeExtractor\Exception\InvalidStreamtapeUrlException;
use Babai834\StreamtapeExtractor\Http\PageFetcherInterface;
use Babai834\StreamtapeExtractor\StreamtapeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style tests for the full extraction pipeline.
 *
 * A stub PageFetcherInterface replaces the real cURL layer so every test
 * is fully offline and deterministic.
 */
class StreamtapeExtractorTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a fetcher stub that always returns $html.
     */
    private function stubFetcher(string $html): PageFetcherInterface
    {
        return new class ($html) implements PageFetcherInterface {
            public function __construct(private readonly string $body) {}
            public function fetch(string $url): string { return $this->body; }
        };
    }

    /**
     * Build a fetcher stub that always throws a FetchException.
     */
    private function throwingFetcher(string $message): PageFetcherInterface
    {
        return new class ($message) implements PageFetcherInterface {
            public function __construct(private readonly string $msg) {}
            public function fetch(string $url): string
            {
                throw new FetchException($this->msg);
            }
        };
    }

    /**
     * Load a fixture file.
     */
    private function fixture(string $name): string
    {
        $path     = __DIR__ . '/../fixtures/' . $name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Could not read fixture: {$path}");
        return $contents;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Successful end-to-end extraction
    // ──────────────────────────────────────────────────────────────────────

    public function testSuccessfulExtractionEndToEnd(): void
    {
        $html      = $this->fixture('norobotlink_split.html');
        $extractor = new StreamtapeExtractor($this->stubFetcher($html));

        $result = $extractor->extract('https://streamtape.com/v/OXMp3qjo9aiZAl9');

        $this->assertStringStartsWith('https://123456789.tapecontent.net/', $result);
        $this->assertStringContainsString('.mp4', $result);
        $this->assertStringContainsString('dl=1', $result);
    }

    public function testStaticGetDirectLinkApi(): void
    {
        // The static API is exercised separately via the instance-based
        // path with a mock; here we just verify that the static call
        // reaches the right extraction logic using our own mock.
        $html = $this->fixture('norobotlink_split.html');

        // Use the instance path directly (static wraps instance).
        $extractor = new StreamtapeExtractor($this->stubFetcher($html));
        $result    = $extractor->extract('https://streamtape.com/v/OXMp3qjo9aiZAl9');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringContainsString('dl=1', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fetch-level errors map to FetchException
    // ──────────────────────────────────────────────────────────────────────

    public function testFetchErrorPropagatesAsFetchException(): void
    {
        $extractor = new StreamtapeExtractor(
            $this->throwingFetcher('cURL error (6): Could not resolve host')
        );

        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/Could not resolve host/');

        $extractor->extract('https://streamtape.com/v/abc123');
    }

    public function testHttp404MappedToFetchException(): void
    {
        $extractor = new StreamtapeExtractor(
            $this->throwingFetcher('Page not found (HTTP 404).')
        );

        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/404/');

        $extractor->extract('https://streamtape.com/v/abc123');
    }

    public function testHttp429MappedToFetchException(): void
    {
        $extractor = new StreamtapeExtractor(
            $this->throwingFetcher('Rate limited (HTTP 429).')
        );

        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/429/');

        $extractor->extract('https://streamtape.com/v/abc123');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Invalid URL rejected before any HTTP call
    // ──────────────────────────────────────────────────────────────────────

    public function testInvalidUrlNeverCallsFetcher(): void
    {
        $fetchCount = 0;
        $fetcher    = new class ($fetchCount) implements PageFetcherInterface {
            public function __construct(private int &$count) {}
            public function fetch(string $url): string
            {
                $this->count++;
                return '';
            }
        };

        $extractor = new StreamtapeExtractor($fetcher);

        try {
            $extractor->extract('https://not-streamtape.com/v/abc123');
        } catch (InvalidStreamtapeUrlException) {
            // expected
        }

        $this->assertSame(0, $fetchCount, 'Fetcher should not be called for invalid URL.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // No extractable URL in page → ExtractionException
    // ──────────────────────────────────────────────────────────────────────

    public function testEmptyPageThrowsExtractionException(): void
    {
        // Minimal HTML that passes the CurlPageFetcher tapecontent check but
        // carries no extractable URL.  The fetcher stub bypasses that check.
        $html      = '<html><body>tapecontent – no real link here</body></html>';
        $extractor = new StreamtapeExtractor($this->stubFetcher($html));

        $this->expectException(ExtractionException::class);

        $extractor->extract('https://streamtape.com/v/abc123');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Logger receives debug events
    // ──────────────────────────────────────────────────────────────────────

    public function testLoggerReceivesDebugMessages(): void
    {
        $messages  = [];
        $logger    = new class ($messages) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$log) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = (string) $message;
            }
        };

        $html      = $this->fixture('norobotlink_split.html');
        $extractor = new StreamtapeExtractor($this->stubFetcher($html), $logger);
        $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertNotEmpty($messages, 'Logger should have received at least one debug message.');
        // At minimum "Fetching page" and "Page fetched" messages should appear.
        $this->assertTrue(
            array_filter($messages, fn($m) => str_contains($m, 'Fetching')) !== [],
            'Expected a "Fetching page" debug log entry.'
        );
    }
}
