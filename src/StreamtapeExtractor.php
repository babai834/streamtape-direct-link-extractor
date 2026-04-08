<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor;

use Babai834\StreamtapeExtractor\Exception\ExtractionException;
use Babai834\StreamtapeExtractor\Exception\FinalizationException;
use Babai834\StreamtapeExtractor\Exception\FetchException;
use Babai834\StreamtapeExtractor\Exception\InvalidStreamtapeUrlException;
use Babai834\StreamtapeExtractor\Exception\StreamtapeExtractorException;
use Babai834\StreamtapeExtractor\Http\CurlPageFetcher;
use Babai834\StreamtapeExtractor\Http\PageFetcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extracts the direct playable .mp4 download URL from a Streamtape watch page.
 *
 * ## Quick start (static, backward-compatible API)
 *
 *   $url = StreamtapeExtractor::getDirectLink('https://streamtape.com/v/OXMp3qjo9aiZAl9');
 *
 * ## Instantiated API (injectable dependencies)
 *
 *   $extractor = new StreamtapeExtractor($myFetcher, $myLogger);
 *   $url       = $extractor->extract('https://streamtape.com/v/OXMp3qjo9aiZAl9');
 *
 * Streamtape obfuscates its video links by splitting them across two separate
 * JavaScript snippets in the page HTML:
 *
 *   1. A "base path" injected via `document.getElementById('norobotlink').innerHTML`
 *      – contains everything up to (and sometimes slightly past) the `?` separator.
 *   2. A "token" substring appended via a second `.innerHTML +=` statement.
 *      Concatenating the two and prepending `https:` gives the full URL.
 *
 * The approach is intentionally pure-string / regex – no JS engine is needed.
 *
 * @see PageFetcherInterface  to swap out the HTTP layer (e.g. for tests).
 * @see LoggerInterface       for PSR-3 structured logging.
 *
 * PHP version: 8.0+
 * Coding style: PSR-12
 */
class StreamtapeExtractor
{
    // ──────────────────────────────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Allowed Streamtape host names (lower-cased, without www.).
     */
    public const ALLOWED_HOSTS = [
        'streamtape.com',
        'streamtape.to',
        'streamtape.net',
        'streamtape.xyz',
        'streamtape.ca',
        'streamtape.cc',
        'streamtape.site',
        'streamtape.link',
    ];

    /**
     * Fragment used to detect the CDN domain in the fetched HTML and URLs.
     * Extracted as a constant so a future CDN migration requires a single edit.
     */
    public const CONTENT_DOMAIN_FRAGMENT = 'tapecontent';

    /**
     * Full CDN domain expected in the final direct link.
     */
    public const CONTENT_DOMAIN = 'tapecontent.net';

    /**
     * The HTML element ID that Streamtape uses to hold (parts of) the video URL.
     */
    private const NOROBOTLINK_ELEMENT_ID = 'norobotlink';

    // ──────────────────────────────────────────────────────────────────────
    // Instance state
    // ──────────────────────────────────────────────────────────────────────

    private readonly PageFetcherInterface $fetcher;
    private readonly LoggerInterface $logger;

    /**
     * @param PageFetcherInterface|null $fetcher Custom HTTP fetcher; defaults to CurlPageFetcher.
     * @param LoggerInterface|null      $logger  PSR-3 logger; defaults to NullLogger (silent).
     */
    public function __construct(
        ?PageFetcherInterface $fetcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->fetcher = $fetcher ?? new CurlPageFetcher();
        $this->logger  = $logger  ?? new NullLogger();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Extract the direct .mp4 download URL from a Streamtape watch page.
     *
     * This is the instance-based entry point.  For the static convenience
     * method (backward-compatible) see {@see getDirectLink()}.
     *
     * @param  string $url Streamtape watch/embed URL (/v/ or /e/ variant).
     * @return string      Absolute HTTPS .mp4 URL with ?dl=1 appended.
     *
     * @throws InvalidStreamtapeUrlException When the URL is not a valid Streamtape URL.
     * @throws FetchException                When the page cannot be fetched.
     * @throws ExtractionException           When no video URL is found in the HTML.
     * @throws FinalizationException         When the extracted value cannot be turned into a valid URL.
     */
    public function extract(string $url): string
    {
        $normalizedUrl = $this->validateAndNormalizeUrl($url);
        $this->logger->debug('Fetching page', ['url' => $normalizedUrl]);

        $html = $this->fetcher->fetch($normalizedUrl);
        $this->logger->debug('Page fetched', ['bytes' => strlen($html)]);

        // Primary extraction strategy ──────────────────────────────────────
        // Streamtape splits the link across two JS lines:
        //   document.getElementById('norobotlink').innerHTML = '//sub.tapecontent.net/...'
        //   document.getElementById('norobotlink').innerHTML += 'token-suffix'
        try {
            $raw = $this->extractViaNorobotlink($html);
            return $this->finalizeUrl($raw);
        } catch (StreamtapeExtractorException $e) {
            $this->logger->debug('Primary strategy failed', ['reason' => $e->getMessage()]);
        }

        // Fallback 1 ───────────────────────────────────────────────────────
        // Some page variants expose a single `videoUrl` or `file` variable.
        try {
            $raw = $this->extractViaVideoVariable($html);
            return $this->finalizeUrl($raw);
        } catch (StreamtapeExtractorException $e) {
            $this->logger->debug('Fallback 1 failed', ['reason' => $e->getMessage()]);
        }

        // Fallback 2 ───────────────────────────────────────────────────────
        // Last resort: scan the HTML for any tapecontent.net URL ending in .mp4.
        try {
            $raw = $this->extractViaTapecontentUrl($html);
            return $this->finalizeUrl($raw);
        } catch (StreamtapeExtractorException $e) {
            $this->logger->debug('Fallback 2 failed', ['reason' => $e->getMessage()]);
        }

        throw new ExtractionException(
            'Could not extract direct link. The page structure may have changed. '
            . 'Enable debug logging or run with --debug to inspect the raw HTML.'
        );
    }

    /**
     * Static convenience wrapper that mirrors the original API.
     *
     * Backward-compatible entry point:
     *
     *   StreamtapeExtractor::getDirectLink($url, $debug);
     *
     * When $debug is true, a lightweight STDERR logger is wired in automatically
     * so debug lines still appear at the terminal, identical to pre-refactor
     * behaviour.
     *
     * @param  string $url   Full Streamtape watch URL (/v/ or /e/ variant).
     * @param  bool   $debug When true, dumps intermediate values to STDERR.
     * @return string        The direct tapecontent.net .mp4 URL with ?dl=1.
     *
     * @throws InvalidStreamtapeUrlException When the URL is not a valid Streamtape URL.
     * @throws FetchException                When the page cannot be fetched.
     * @throws ExtractionException           When no video URL is found in the HTML.
     * @throws FinalizationException         When the extracted value cannot be turned into a valid URL.
     */
    public static function getDirectLink(string $url, bool $debug = false): string
    {
        $logger = $debug ? new StderrLogger() : new NullLogger();
        return (new self(null, $logger))->extract($url);
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL validation / normalization
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Validate the user-supplied URL and return its canonical HTTPS form.
     *
     * Accepts:
     *   - streamtape.com/v/<id>   (with or without scheme)
     *   - streamtape.com/e/<id>   (embed variant)
     *   - Any domain listed in ALLOWED_HOSTS
     *
     * @throws InvalidStreamtapeUrlException
     */
    public function validateAndNormalizeUrl(string $url): string
    {
        // Prepend scheme if missing so parse_url works correctly.
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new InvalidStreamtapeUrlException("Malformed URL: {$url}");
        }

        $host = strtolower($parts['host']);

        // Strip leading "www." for comparison.
        $bareHost = preg_replace('/^www\./i', '', $host) ?? $host;

        if (!in_array($bareHost, self::ALLOWED_HOSTS, true)) {
            throw new InvalidStreamtapeUrlException(
                "URL host '{$host}' is not a known Streamtape domain. "
                . 'Expected one of: ' . implode(', ', self::ALLOWED_HOSTS)
            );
        }

        $path = $parts['path'] ?? '';

        // Accept /v/<id> and /e/<id>; reject everything else.
        if (!preg_match('#^/(v|e)/[A-Za-z0-9_-]+#', $path)) {
            throw new InvalidStreamtapeUrlException(
                "URL path '{$path}' does not look like a Streamtape watch or embed path. "
                . 'Expected format: /v/<id> or /e/<id>.'
            );
        }

        // Always use HTTPS and the canonical bare host.
        return 'https://' . $bareHost . $path;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Extraction strategies
    // ──────────────────────────────────────────────────────────────────────

    /**
     * PRIMARY STRATEGY
     *
     * Streamtape splits the final URL across two JavaScript assignments:
     *
     *   document.getElementById('norobotlink').innerHTML  = '//sub.tapecontent.net/radosgw/…'
     *   …
     *   document.getElementById('norobotlink').innerHTML += 'continuation-token'
     *
     * This method captures both parts and concatenates them.
     *
     * @throws ExtractionException When neither part can be found.
     */
    private function extractViaNorobotlink(string $html): string
    {
        $basePath = $this->extractNorobotlinkBase($html);
        $token    = $this->extractNorobotlinkToken($html);
        $combined = $basePath . $token;

        $this->logger->debug('Combined norobotlink value', ['value' => $combined]);

        return $combined;
    }

    /**
     * Extract the base-path part of the norobotlink assignment.
     *
     * Matches lines like:
     *   document.getElementById('norobotlink').innerHTML = '//sub.tapecontent.net/...'
     *   document.getElementById("norobotlink").innerHTML = `//sub.tapecontent.net/...`
     *
     * @throws ExtractionException
     */
    private function extractNorobotlinkBase(string $html): string
    {
        $elementId = preg_quote(self::NOROBOTLINK_ELEMENT_ID, '/');

        // Pattern 1 – classic single-line assignment with single or double quotes.
        $pattern1 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*=\s*[\'"]([^\'"]+)[\'"]/i';

        if (preg_match($pattern1, $html, $m)) {
            $this->logger->debug('norobotlink base via pattern 1', ['value' => $m[1]]);
            return $m[1];
        }

        // Pattern 2 – template literal (backtick string).
        $pattern2 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*=\s*`([^`]+)`/i';

        if (preg_match($pattern2, $html, $m)) {
            $this->logger->debug('norobotlink base via pattern 2 (template literal)', ['value' => $m[1]]);
            return $m[1];
        }

        throw new ExtractionException('Could not find norobotlink base path in HTML.');
    }

    /**
     * Extract the token suffix from the `+=` norobotlink assignment.
     *
     * Matches lines like:
     *   document.getElementById('norobotlink').innerHTML += 'SUFFIX'
     *
     * The suffix is typically a short alphanumeric/punctuation string that
     * completes the URL (e.g., a signed query-string parameter).
     *
     * @throws ExtractionException
     */
    private function extractNorobotlinkToken(string $html): string
    {
        $elementId = preg_quote(self::NOROBOTLINK_ELEMENT_ID, '/');

        // Pattern 1 – standard += with single or double quoted string.
        $pattern1 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*[\'"]([^\'"]+)[\'"]/i';

        if (preg_match($pattern1, $html, $m)) {
            $this->logger->debug('norobotlink token via pattern 1', ['value' => $m[1]]);
            return $m[1];
        }

        // Pattern 2 – template literal.
        $pattern2 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*`([^`]+)`/i';

        if (preg_match($pattern2, $html, $m)) {
            $this->logger->debug('norobotlink token via pattern 2 (template literal)', ['value' => $m[1]]);
            return $m[1];
        }

        // Pattern 3 – Streamtape sometimes uses a variable reference.
        // e.g.:  var tok = 'abc'; ... .innerHTML += tok;
        // First find the += variable name, then look up its value.
        $pattern3 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*;/i';

        if (preg_match($pattern3, $html, $varMatch)) {
            $varName    = preg_quote($varMatch[1], '/');
            $pattern3val = '/(?:var|let|const)\s+' . $varName . '\s*=\s*[\'"]([^\'"]+)[\'"]/i';

            if (preg_match($pattern3val, $html, $valMatch)) {
                $this->logger->debug(
                    'norobotlink token via pattern 3 (variable)',
                    ['var' => $varMatch[1], 'value' => $valMatch[1]]
                );
                return $valMatch[1];
            }
        }

        throw new ExtractionException("Could not find norobotlink token ('+=' assignment) in HTML.");
    }

    /**
     * FALLBACK STRATEGY 1
     *
     * Some Streamtape page variants expose the video URL in a JavaScript
     * variable named `videoUrl`, `file`, or similar.
     *
     * Example snippets this targets:
     *   var videoUrl = "//sub.tapecontent.net/radosgw/…?dl=1";
     *   "file":"//sub.tapecontent.net/radosgw/…?dl=1"
     *
     * @throws ExtractionException
     */
    private function extractViaVideoVariable(string $html): string
    {
        $frag     = self::CONTENT_DOMAIN_FRAGMENT;
        $patterns = [
            '/(?:var|let|const)\s+(?:videoUrl|video_url|fileUrl|file_url|srcUrl|src_url)\s*=\s*[\'"]([^\'"]+' . $frag . '[^\'"]+)[\'"]/',
            '/"(?:file|src|url|videoUrl|source)"\s*:\s*"([^"]+' . $frag . '[^"]+)"/',
            "/\"(?:file|src|url|videoUrl|source)\"\s*:\s*'([^']+" . $frag . "[^']+)'/",
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $this->logger->debug(
                    'videoVariable match',
                    ['pattern' => $i + 1, 'value' => $m[1]]
                );
                return $m[1];
            }
        }

        throw new ExtractionException(
            'Could not find ' . self::CONTENT_DOMAIN_FRAGMENT . ' URL via video-variable patterns.'
        );
    }

    /**
     * FALLBACK STRATEGY 2
     *
     * Blindly scan the entire HTML for any URL that:
     *   - is on the tapecontent.net domain (or a numeric-subdomain thereof)
     *   - ends with .mp4 (optionally followed by a query string)
     *
     * This is the most permissive pattern and is tried last so that it does
     * not interfere with the more targeted strategies above.
     *
     * @throws ExtractionException
     */
    private function extractViaTapecontentUrl(string $html): string
    {
        $domain  = preg_quote(self::CONTENT_DOMAIN, '~');
        $pattern = '~((?:https?:)?//[A-Za-z0-9._-]*' . $domain . '/[^\s\'"<>"]+\.mp4(?:\?[^\s\'"<>"]*)?)~i';

        if (preg_match($pattern, $html, $m)) {
            $this->logger->debug('tapecontent URL via blind scan', ['value' => $m[1]]);
            return $m[1];
        }

        throw new ExtractionException(
            'Could not find any ' . self::CONTENT_DOMAIN . ' .mp4 URL in the page HTML.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL finalization
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ensure the extracted link is a well-formed absolute HTTPS URL with ?dl=1.
     *
     * Handles:
     *   - Protocol-relative URLs (//sub.tapecontent.net/…) → prepend https:
     *   - http:// scheme → upgrade to https://
     *   - Absent ?dl=1   → append it
     *   - Existing ?dl=0 → corrected to ?dl=1 (Streamtape sometimes emits this)
     *
     * @throws FinalizationException When the value is not a recognisable URL.
     */
    public function finalizeUrl(string $raw): string
    {
        $url = trim($raw);

        // Convert protocol-relative to absolute.
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        // Upgrade HTTP to HTTPS.
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        if (!str_starts_with($url, 'https://')) {
            throw new FinalizationException("Extracted value does not look like a URL: {$url}");
        }

        // Must point to the CDN domain.
        if (stripos($url, self::CONTENT_DOMAIN) === false) {
            throw new FinalizationException(
                'Extracted URL does not point to ' . self::CONTENT_DOMAIN . ": {$url}"
            );
        }

        // Append or fix the ?dl=1 download flag.
        if (!str_contains($url, '?')) {
            $url .= '?dl=1';
        } elseif (!preg_match('/[?&]dl=1/', $url)) {
            if (preg_match('/[?&]dl=\d/', $url)) {
                // Replace existing dl=<digit> with dl=1.
                $url = preg_replace('/([?&]dl)=\d/', '$1=1', $url) ?? $url;
            } else {
                $url .= '&dl=1';
            }
        }

        // Strip non-printable characters (e.g. null bytes) and validate.
        // This guards against injection if the caller passes the string to a
        // shell or another unsafe context.
        $url = (string) preg_replace('/[^\x20-\x7E]/', '', $url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new FinalizationException("Extracted value failed URL validation: {$url}");
        }

        return $url;
    }
}
