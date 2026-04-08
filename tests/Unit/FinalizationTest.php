<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Tests\Unit;

use Babai834\StreamtapeExtractor\Exception\FinalizationException;
use Babai834\StreamtapeExtractor\StreamtapeExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the URL finalization logic.
 *
 * Exercises StreamtapeExtractor::finalizeUrl() in isolation:
 *   - protocol-relative → absolute https
 *   - http → https upgrade
 *   - ?dl=1 append / fix
 *   - rejection of non-CDN or non-URL values
 */
class FinalizationTest extends TestCase
{
    private StreamtapeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new StreamtapeExtractor();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Success cases
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function successProvider(): array
    {
        $base = '//123456789.tapecontent.net/radosgw/get/f/stream.mp4';

        return [
            'protocol-relative, no qs'    => [
                $base,
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
            'protocol-relative, has ?dl=1' => [
                $base . '?dl=1',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
            'protocol-relative, dl=0 fixed' => [
                $base . '?dl=0',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
            'protocol-relative, qs without dl' => [
                $base . '?token=abc',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?token=abc&dl=1',
            ],
            'http scheme upgraded'        => [
                'http://123456789.tapecontent.net/radosgw/get/f/stream.mp4',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
            'already https, no qs'        => [
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
            'whitespace stripped'         => [
                '  //123456789.tapecontent.net/radosgw/get/f/stream.mp4  ',
                'https://123456789.tapecontent.net/radosgw/get/f/stream.mp4?dl=1',
            ],
        ];
    }

    #[DataProvider('successProvider')]
    public function testFinalizationProducesCorrectUrl(string $raw, string $expected): void
    {
        $result = $this->extractor->finalizeUrl($raw);
        $this->assertSame($expected, $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Failure cases
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function failureProvider(): array
    {
        return [
            'not a URL'                  => ['not-a-url-at-all'],
            'wrong domain'               => ['https://example.com/video.mp4'],
            'ftp scheme'                 => ['ftp://123456789.tapecontent.net/video.mp4'],
            'empty string'               => [''],
            'relative path only'         => ['/radosgw/get/f/stream.mp4'],
        ];
    }

    #[DataProvider('failureProvider')]
    public function testInvalidRawValueThrowsFinalizationException(string $raw): void
    {
        $this->expectException(FinalizationException::class);
        $this->extractor->finalizeUrl($raw);
    }
}
