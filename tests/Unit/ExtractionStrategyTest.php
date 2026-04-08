<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Tests\Unit;

use Babai834\StreamtapeExtractor\Exception\ExtractionException;
use Babai834\StreamtapeExtractor\StreamtapeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three HTML extraction strategies.
 *
 * Each test loads a fixture HTML file and verifies that the appropriate
 * strategy produces the expected raw (pre-finalization) URL fragment, by
 * exercising the full extraction pipeline via a mock fetcher.
 *
 * All tests are fully offline – no real HTTP requests are made.
 */
class ExtractionStrategyTest extends TestCase
{
    /**
     * Helper: load a fixture file and return its contents.
     */
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/' . $name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Could not read fixture: {$path}");
        return $contents;
    }

    /**
     * Helper: build an extractor that returns $html when fetch() is called.
     */
    private function extractorWithHtml(string $html): StreamtapeExtractor
    {
        $fetcher = new class ($html) implements \Babai834\StreamtapeExtractor\Http\PageFetcherInterface {
            public function __construct(private readonly string $body) {}
            public function fetch(string $url): string { return $this->body; }
        };

        return new StreamtapeExtractor($fetcher);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Primary strategy – classic split norobotlink
    // ──────────────────────────────────────────────────────────────────────

    public function testNorobotlinkSplitExtraction(): void
    {
        $html      = $this->fixture('norobotlink_split.html');
        $extractor = $this->extractorWithHtml($html);
        $result    = $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringStartsWith('https://', $result);
        $this->assertStringContainsString('dl=1', $result);
        // Verify base + token were concatenated correctly.
        $this->assertStringContainsString('stream.mp4', $result);
        $this->assertStringContainsString('expires=9999999999', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Primary strategy – template literal variant
    // ──────────────────────────────────────────────────────────────────────

    public function testTemplateLiteralExtraction(): void
    {
        $html      = $this->fixture('template_literal.html');
        $extractor = $this->extractorWithHtml($html);
        $result    = $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringStartsWith('https://', $result);
        $this->assertStringContainsString('dl=1', $result);
        $this->assertStringContainsString('expires=8888888888', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Primary strategy – variable-based token
    // ──────────────────────────────────────────────────────────────────────

    public function testVariableTokenExtraction(): void
    {
        $html      = $this->fixture('variable_token.html');
        $extractor = $this->extractorWithHtml($html);
        $result    = $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringStartsWith('https://', $result);
        $this->assertStringContainsString('dl=1', $result);
        $this->assertStringContainsString('expires=7777777777', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fallback 1 – video variable
    // ──────────────────────────────────────────────────────────────────────

    public function testVideoVariableFallback(): void
    {
        $html      = $this->fixture('video_variable.html');
        $extractor = $this->extractorWithHtml($html);
        $result    = $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringStartsWith('https://', $result);
        $this->assertStringContainsString('dl=1', $result);
        $this->assertStringContainsString('expires=6666666666', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fallback 2 – blind scan
    // ──────────────────────────────────────────────────────────────────────

    public function testBlindScanFallback(): void
    {
        $html      = $this->fixture('blind_scan.html');
        $extractor = $this->extractorWithHtml($html);
        $result    = $extractor->extract('https://streamtape.com/v/abc123');

        $this->assertStringContainsString('tapecontent.net', $result);
        $this->assertStringStartsWith('https://', $result);
        $this->assertStringContainsString('dl=1', $result);
        $this->assertStringContainsString('stream.mp4', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // All strategies fail
    // ──────────────────────────────────────────────────────────────────────

    public function testExtractionExceptionWhenNoStrategySucceeds(): void
    {
        // Build HTML that contains tapecontent domain (so fetch passes) but
        // has no extractable URL pattern.
        $html = '<html><body>tapecontent placeholder – no video URL here</body></html>';

        $extractor = $this->extractorWithHtml($html);

        $this->expectException(ExtractionException::class);
        $extractor->extract('https://streamtape.com/v/abc123');
    }
}
