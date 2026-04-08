<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Http;

use Babai834\StreamtapeExtractor\Exception\FetchException;

/**
 * Default cURL-based implementation of PageFetcherInterface.
 *
 * Mirrors the request headers that a real Chrome browser would send so that
 * Streamtape's bot-detection heuristics are less likely to trigger.
 *
 * All tuneable values are exposed as constructor parameters with sensible
 * defaults; no subclassing is required for common customisations.
 */
class CurlPageFetcher implements PageFetcherInterface
{
    /**
     * Content-domain fragment used to sanity-check the fetched HTML.
     * If this string is absent the video is almost certainly expired or
     * geo-blocked.
     */
    private const CONTENT_DOMAIN_FRAGMENT = 'tapecontent';

    /**
     * User-Agent string that mimics a recent Chrome browser.
     * Streamtape may reject requests with clearly bot-like UA strings.
     */
    private const DEFAULT_USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/123.0.0.0 Safari/537.36';

    public function __construct(
        private readonly int    $timeout        = 20,
        private readonly int    $connectTimeout = 10,
        private readonly string $userAgent      = self::DEFAULT_USER_AGENT,
        private readonly bool   $verifySsl      = true,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(string $url): string
    {
        if (!extension_loaded('curl')) {
            throw new FetchException('The cURL PHP extension is required but not loaded.');
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_ENCODING       => '', // Accept all encodings (gzip, br, …).
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Referer: ' . $url,
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== CURLE_OK || $body === false) {
            throw new FetchException("cURL error ({$errno}): {$errMsg}");
        }

        if ($httpCode === 404) {
            throw new FetchException(
                'Page not found (HTTP 404). The video may have been deleted or the URL is wrong.'
            );
        }

        if ($httpCode === 429) {
            throw new FetchException('Rate limited (HTTP 429). Wait a moment and try again.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new FetchException("Unexpected HTTP status code: {$httpCode}.");
        }

        if (empty($body)) {
            throw new FetchException('Received an empty response body.');
        }

        // Quick sanity-check: Streamtape pages always contain the CDN domain
        // fragment.  If it is absent, the video is almost certainly expired or
        // geo-blocked.
        if (stripos($body, self::CONTENT_DOMAIN_FRAGMENT) === false) {
            // Try to extract a user-facing error message from the page.
            if (preg_match('/<div[^>]*class="[^"]*error[^"]*"[^>]*>([^<]*)<\/div>/i', $body, $m)) {
                $msg = trim(strip_tags($m[1]));
                throw new FetchException("Video unavailable: {$msg}");
            }
            throw new FetchException(
                'The fetched page does not appear to contain a video link. '
                . 'The video may have expired, been removed, or is geo-blocked.'
            );
        }

        return (string) $body;
    }
}
