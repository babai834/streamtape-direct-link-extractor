<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Tests\Unit;

use Babai834\StreamtapeExtractor\Exception\InvalidStreamtapeUrlException;
use Babai834\StreamtapeExtractor\StreamtapeExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for URL validation and normalization logic.
 *
 * These tests exercise StreamtapeExtractor::validateAndNormalizeUrl() in
 * isolation – no HTTP calls are made.
 */
class UrlValidationTest extends TestCase
{
    private StreamtapeExtractor $extractor;

    protected function setUp(): void
    {
        // Use the default constructor; no fetcher/logger needed for these tests.
        $this->extractor = new StreamtapeExtractor();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Acceptance cases
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function validUrlProvider(): array
    {
        return [
            'scheme + .com /v/'           => ['https://streamtape.com/v/OXMp3qjo9aiZAl9',    'https://streamtape.com/v/OXMp3qjo9aiZAl9'],
            'scheme + .com /e/'           => ['https://streamtape.com/e/OXMp3qjo9aiZAl9',    'https://streamtape.com/e/OXMp3qjo9aiZAl9'],
            'no-scheme /v/'               => ['streamtape.com/v/OXMp3qjo9aiZAl9',             'https://streamtape.com/v/OXMp3qjo9aiZAl9'],
            'www. prefix stripped'        => ['https://www.streamtape.com/v/abc123',           'https://streamtape.com/v/abc123'],
            '.to alias domain'            => ['https://streamtape.to/v/abc123',                'https://streamtape.to/v/abc123'],
            '.net alias domain'           => ['https://streamtape.net/v/abc123',               'https://streamtape.net/v/abc123'],
            '.xyz alias domain'           => ['https://streamtape.xyz/v/abc123',               'https://streamtape.xyz/v/abc123'],
            '.ca alias domain'            => ['https://streamtape.ca/v/abc123',                'https://streamtape.ca/v/abc123'],
            '.cc alias domain'            => ['https://streamtape.cc/v/abc123',                'https://streamtape.cc/v/abc123'],
            '.site alias domain'          => ['https://streamtape.site/v/abc123',              'https://streamtape.site/v/abc123'],
            '.link alias domain'          => ['https://streamtape.link/v/abc123',              'https://streamtape.link/v/abc123'],
            'http scheme upgraded'        => ['http://streamtape.com/v/abc123',                'https://streamtape.com/v/abc123'],
            'path with underscores'       => ['https://streamtape.com/v/abc_def-123',          'https://streamtape.com/v/abc_def-123'],
            'path with extra segments passed through' => ['https://streamtape.com/v/abc123/extra', 'https://streamtape.com/v/abc123/extra'],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function testValidUrlIsAcceptedAndNormalized(string $input, string $expected): void
    {
        $result = $this->extractor->validateAndNormalizeUrl($input);
        $this->assertSame($expected, $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rejection cases
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'wrong host'            => ['https://example.com/v/abc123'],
            'youtube URL'           => ['https://youtube.com/watch?v=abc123'],
            'no path'               => ['https://streamtape.com/'],
            'wrong path prefix /w/' => ['https://streamtape.com/w/abc123'],
            'bare /v/ no id'        => ['https://streamtape.com/v/'],
            'empty string'          => [''],
        ];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testInvalidUrlThrowsException(string $input): void
    {
        $this->expectException(InvalidStreamtapeUrlException::class);
        $this->extractor->validateAndNormalizeUrl($input);
    }
}
