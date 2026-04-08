<?php

declare(strict_types=1);

/**
 * StreamtapeExtractor – Extract direct .mp4 download URLs from Streamtape watch pages.
 *
 * Usage (CLI):
 *   php StreamtapeExtractor.php https://streamtape.com/v/OXMp3qjo9aiZAl9
 *   php StreamtapeExtractor.php --debug https://streamtape.com/v/OXMp3qjo9aiZAl9
 *
 * On success  → prints only the direct .mp4 URL (ready to wget/curl/axel).
 * On failure  → prints a line starting with "ERROR:" and exits with code 1.
 *
 * Supported URL variants:
 *   https://streamtape.com/v/<id>
 *   https://streamtape.com/e/<id>
 *   https://streamtape.to/v/<id>  (alias domain)
 *
 * NOTE: Streamtape may change their frontend at any time.  Each regex is
 * isolated in its own helper method to make updates surgical and easy to
 * spot.  If extraction starts failing, check the raw HTML returned in
 * --debug mode and update the relevant private method.
 *
 * PHP version: 8.0+
 * Coding style: PSR-12
 */

/**
 * Extracts the direct playable .mp4 download URL from a Streamtape watch page.
 *
 * Streamtape obfuscates its video links by splitting them across two separate
 * JavaScript snippets in the page HTML:
 *
 *   1. A "base path" injected via `document.getElementById('norobotlink').innerHTML`
 *      – contains everything up to (and sometimes slightly past) the `?` separator.
 *   2. A "token" substring appended via a second `getElementById('norobotlink').innerHTML +=`
 *      statement.  Concatenating the two and prepending `https:` gives the full URL.
 *
 * The approach is intentionally pure-string / regex – no JS engine is needed.
 */
class StreamtapeExtractor
{
    /**
     * Allowed Streamtape host names (lower-cased).
     */
    private const ALLOWED_HOSTS = [
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
    private const CONTENT_DOMAIN_FRAGMENT = 'tapecontent';

    /**
     * Full CDN domain expected in the final direct link.
     */
    private const CONTENT_DOMAIN = 'tapecontent.net';

    /**
     * The HTML element ID that Streamtape uses to hold (parts of) the video URL.
     * Extracted here so every regex that references it can be updated in one place.
     */
    private const NOROBOTLINK_ELEMENT_ID = 'norobotlink';

    /**
     * HTTP request timeout in seconds.
     */
    private const TIMEOUT = 20;

    /**
     * User-Agent string that mimics a recent Chrome browser.
     * Streamtape may reject requests with clearly bot-like UA strings.
     */
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/123.0.0.0 Safari/537.36';

    /**
     * Extract the direct .mp4 download URL from a Streamtape watch page.
     *
     * @param  string $url   Full Streamtape watch URL (/v/ or /e/ variant).
     * @param  bool   $debug When true, dumps intermediate values to STDERR.
     * @return string        The direct tapecontent.net .mp4 URL with ?dl=1.
     *
     * @throws \InvalidArgumentException When the supplied URL is not a valid Streamtape URL.
     * @throws \RuntimeException         When the page cannot be fetched or the link cannot be parsed.
     */
    public static function getDirectLink(string $url, bool $debug = false): string
    {
        $normalizedUrl = self::validateAndNormalizeUrl($url);

        if ($debug) {
            fwrite(STDERR, "[DEBUG] Fetching: {$normalizedUrl}\n");
        }

        $html = self::fetchPage($normalizedUrl);

        if ($debug) {
            fwrite(STDERR, '[DEBUG] HTML length: ' . strlen($html) . " bytes\n");
        }

        // Primary extraction strategy ─────────────────────────────────────
        // Streamtape splits the link across two JS lines:
        //   document.getElementById('norobotlink').innerHTML = '//subdomain.tapecontent.net/...'
        //   document.getElementById('norobotlink').innerHTML += 'token-suffix'
        // Concatenating the two (and prepending https:) yields the full URL.
        try {
            $directLink = self::extractViaNorobotlink($html, $debug);
            return self::finalizeUrl($directLink);
        } catch (\RuntimeException $e) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] Primary strategy failed: {$e->getMessage()}\n");
            }
        }

        // Fallback 1 ───────────────────────────────────────────────────────
        // Some page variants expose a single `videoUrl` or `file` variable.
        try {
            $directLink = self::extractViaVideoVariable($html, $debug);
            return self::finalizeUrl($directLink);
        } catch (\RuntimeException $e) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] Fallback 1 failed: {$e->getMessage()}\n");
            }
        }

        // Fallback 2 ───────────────────────────────────────────────────────
        // Last resort: scan the HTML for any tapecontent.net URL that ends
        // in .mp4 (with or without query string).
        try {
            $directLink = self::extractViaTapecontentUrl($html, $debug);
            return self::finalizeUrl($directLink);
        } catch (\RuntimeException $e) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] Fallback 2 failed: {$e->getMessage()}\n");
            }
        }

        throw new \RuntimeException(
            'Could not extract direct link. The page structure may have changed. '
            . 'Re-run with --debug to inspect the raw HTML.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Validate the user-supplied URL and return its canonical HTTPS form.
     *
     * Accepts:
     *   - streamtape.com/v/<id>       (with or without scheme)
     *   - streamtape.com/e/<id>       (embed variant)
     *   - Any domain listed in ALLOWED_HOSTS
     *
     * @throws \InvalidArgumentException
     */
    private static function validateAndNormalizeUrl(string $url): string
    {
        // Prepend scheme if missing so parse_url works correctly.
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException("Malformed URL: {$url}");
        }

        $host = strtolower($parts['host']);

        // Strip leading "www." for comparison.
        $bareHost = preg_replace('/^www\./i', '', $host);

        if (!in_array($bareHost, self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException(
                "URL host '{$host}' is not a known Streamtape domain. "
                . 'Expected one of: ' . implode(', ', self::ALLOWED_HOSTS)
            );
        }

        $path = $parts['path'] ?? '';

        // Accept /v/<id> and /e/<id>; reject everything else.
        if (!preg_match('#^/(v|e)/[A-Za-z0-9_-]+#', $path)) {
            throw new \InvalidArgumentException(
                "URL path '{$path}' does not look like a Streamtape watch or embed path. "
                . 'Expected format: /v/<id> or /e/<id>.'
            );
        }

        // Always use HTTPS and the canonical host.
        return 'https://' . $bareHost . $path;
    }

    /**
     * Fetch the full HTML of the given URL via cURL.
     *
     * Headers mirror what a real browser would send so that Streamtape's
     * bot-detection heuristics are less likely to trigger.
     *
     * @throws \RuntimeException On cURL error or non-200 HTTP response.
     */
    private static function fetchPage(string $url): string
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The cURL PHP extension is required but not loaded.');
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '', // Accept all encodings (gzip, br, …).
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Referer: ' . $url,
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== CURLE_OK || $body === false) {
            throw new \RuntimeException("cURL error ({$errno}): {$errMsg}");
        }

        if ($httpCode === 404) {
            throw new \RuntimeException('Page not found (HTTP 404). The video may have been deleted or the URL is wrong.');
        }

        if ($httpCode === 429) {
            throw new \RuntimeException('Rate limited (HTTP 429). Wait a moment and try again.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("Unexpected HTTP status code: {$httpCode}.");
        }

        if (empty($body)) {
            throw new \RuntimeException('Received an empty response body.');
        }

        // Quick sanity-check: Streamtape pages always contain the CDN domain fragment.
        // If it is absent the video is almost certainly expired or geo-blocked.
        if (stripos($body, self::CONTENT_DOMAIN_FRAGMENT) === false) {
            // Try to extract a user-facing error message from the page.
            if (preg_match('/<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/div>/is', $body, $m)) {
                $msg = trim(strip_tags($m[1]));
                throw new \RuntimeException("Video unavailable: {$msg}");
            }
            throw new \RuntimeException(
                'The fetched page does not appear to contain a video link. '
                . 'The video may have expired, been removed, or is geo-blocked.'
            );
        }

        return (string) $body;
    }

    /**
     * PRIMARY STRATEGY
     *
     * Streamtape splits the final URL across two JavaScript assignments:
     *
     *   document.getElementById('norobotlink').innerHTML = '//sub.tapecontent.net/radosgw/…'
     *   …
     *   document.getElementById('norobotlink').innerHTML += 'continuation-token'
     *
     * This method captures both parts and concatenates them.
     *
     * Why two separate statements?  Streamtape uses this split to frustrate
     * simple single-line scrapers; the second fragment is intentionally
     * short (and harmless-looking) so that a scraper that only finds the
     * first assignment will end up with a broken URL.
     *
     * Regex notes:
     *   [\s\S]*? – lazy match allowing the two assignments to be anywhere in
     *              the HTML, potentially separated by many lines.
     *   ['"]{1}  – handle both single- and double-quoted JS strings.
     *
     * @throws \RuntimeException When neither part can be found.
     */
    private static function extractViaNorobotlink(string $html, bool $debug): string
    {
        // Capture the base path (first assignment).
        // The value typically looks like //861134127.tapecontent.net/radosgw/…
        $basePath = self::extractNorobotlinkBase($html, $debug);

        // Capture the token suffix (+= assignment).
        $token = self::extractNorobotlinkToken($html, $debug);

        $combined = $basePath . $token;

        if ($debug) {
            fwrite(STDERR, "[DEBUG] Combined norobotlink value: {$combined}\n");
        }

        return $combined;
    }

    /**
     * Extract the base-path part of the norobotlink value.
     *
     * Matches lines like:
     *   document.getElementById('norobotlink').innerHTML = '//sub.tapecontent.net/...'
     *   document.getElementById("norobotlink").innerHTML = "//sub.tapecontent.net/..."
     *
     * @throws \RuntimeException
     */
    private static function extractNorobotlinkBase(string $html, bool $debug): string
    {
        // Pattern 1 – classic single-line assignment.
        $elementId = preg_quote(self::NOROBOTLINK_ELEMENT_ID, '/');
        $pattern1 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*=\s*[\'"]([^\'"]+)[\'"]/i';

        if (preg_match($pattern1, $html, $m)) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] norobotlink base (pattern 1): {$m[1]}\n");
            }
            return $m[1];
        }

        // Pattern 2 – the value may be wrapped in a template literal (backtick string).
        $pattern2 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*=\s*`([^`]+)`/i';

        if (preg_match($pattern2, $html, $m)) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] norobotlink base (pattern 2): {$m[1]}\n");
            }
            return $m[1];
        }

        throw new \RuntimeException('Could not find norobotlink base path in HTML.');
    }

    /**
     * Extract the token suffix from the `+=` assignment for norobotlink.
     *
     * Matches lines like:
     *   document.getElementById('norobotlink').innerHTML += 'SUFFIX'
     *
     * The suffix is typically a short alphanumeric/punctuation string that
     * completes the URL (e.g., a signed query-string parameter).
     *
     * @throws \RuntimeException
     */
    private static function extractNorobotlinkToken(string $html, bool $debug): string
    {
        // Pattern 1 – standard += with quoted string.
        $elementId = preg_quote(self::NOROBOTLINK_ELEMENT_ID, '/');
        $pattern1 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*[\'"]([^\'"]+)[\'"]/i';

        if (preg_match($pattern1, $html, $m)) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] norobotlink token (pattern 1): {$m[1]}\n");
            }
            return $m[1];
        }

        // Pattern 2 – template literal.
        $pattern2 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*`([^`]+)`/i';

        if (preg_match($pattern2, $html, $m)) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] norobotlink token (pattern 2): {$m[1]}\n");
            }
            return $m[1];
        }

        // Pattern 3 – Streamtape sometimes uses a variable reference.
        // e.g.:  var tok = 'abc'; ... .innerHTML += tok;
        // First find the += variable name, then look up its value.
        $pattern3 = '/getElementById\s*\(\s*[\'"]' . $elementId . '[\'"]\s*\)\s*\.innerHTML\s*\+=\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*;/i';

        if (preg_match($pattern3, $html, $varMatch)) {
            $varName = preg_quote($varMatch[1], '/');
            $pattern3val = '/(?:var|let|const)\s+' . $varName . '\s*=\s*[\'"]([^\'"]+)[\'"]/i';

            if (preg_match($pattern3val, $html, $valMatch)) {
                if ($debug) {
                    fwrite(STDERR, "[DEBUG] norobotlink token (pattern 3, var={$varMatch[1]}): {$valMatch[1]}\n");
                }
                return $valMatch[1];
            }
        }

        throw new \RuntimeException("Could not find norobotlink token ('+=' assignment) in HTML.");
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
     * @throws \RuntimeException
     */
    private static function extractViaVideoVariable(string $html, bool $debug): string
    {
        // Pattern for JS variable assignments.
        $frag = self::CONTENT_DOMAIN_FRAGMENT;
        $patterns = [
            '/(?:var|let|const)\s+(?:videoUrl|video_url|fileUrl|file_url|srcUrl|src_url)\s*=\s*[\'"]([^\'"]+' . $frag . '[^\'"]+)[\'"]/',
            '/"(?:file|src|url|videoUrl|source)"\s*:\s*"([^"]+' . $frag . '[^"]+)"/',
            "/\"(?:file|src|url|videoUrl|source)\"\s*:\s*'([^']+" . $frag . "[^']+)'/",
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                if ($debug) {
                    fwrite(STDERR, '[DEBUG] videoVariable pattern ' . ($i + 1) . ": {$m[1]}\n");
                }
                return $m[1];
            }
        }

        throw new \RuntimeException(
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
     * @throws \RuntimeException
     */
    private static function extractViaTapecontentUrl(string $html, bool $debug): string
    {
        // Match full https?://… or protocol-relative //… tapecontent URLs.
        $domain = preg_quote(self::CONTENT_DOMAIN, '~');
        $pattern = '~((?:https?:)?//[A-Za-z0-9._-]*' . $domain . '/[^\s\'"<>"]+\.mp4(?:\?[^\s\'"<>"]*)?)~i';

        if (preg_match($pattern, $html, $m)) {
            if ($debug) {
                fwrite(STDERR, "[DEBUG] tapecontent URL (blind scan): {$m[1]}\n");
            }
            return $m[1];
        }

        throw new \RuntimeException(
            'Could not find any ' . self::CONTENT_DOMAIN . ' .mp4 URL in the page HTML.'
        );
    }

    /**
     * Ensure the extracted link is a well-formed absolute HTTPS URL with ?dl=1.
     *
     * Handles:
     *   - Protocol-relative URLs (//sub.tapecontent.net/…) → prepend https:
     *   - Absent ?dl=1 → append it
     *   - Existing ?dl=0 → corrected to ?dl=1 (Streamtape sometimes emits this)
     *
     * @throws \RuntimeException When the value is not a recognisable URL.
     */
    private static function finalizeUrl(string $raw): string
    {
        $url = trim($raw);

        // Convert protocol-relative to absolute.
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        // Ensure the scheme is https.
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException("Extracted value does not look like a URL: {$url}");
        }

        // Must point to the CDN domain.
        if (stripos($url, self::CONTENT_DOMAIN) === false) {
            throw new \RuntimeException('Extracted URL does not point to ' . self::CONTENT_DOMAIN . ": {$url}");
        }

        // Append or fix the ?dl=1 download flag.
        if (strpos($url, '?') === false) {
            $url .= '?dl=1';
        } elseif (!preg_match('/[?&]dl=1/', $url)) {
            // Replace dl=0 if present, otherwise append dl=1.
            if (preg_match('/[?&]dl=\d/', $url)) {
                $url = preg_replace('/([?&]dl)=\d/', '$1=1', $url);
            } else {
                $url .= '&dl=1';
            }
        }

        return $url;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CLI entry-point
// ══════════════════════════════════════════════════════════════════════════════

// Only run the CLI handler when this file is executed directly (not included).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    // Parse CLI arguments.
    $debug  = false;
    $rawUrl = null;

    $args = array_slice($argv, 1); // Remove script name.

    foreach ($args as $arg) {
        if ($arg === '--debug' || $arg === '-d') {
            $debug = true;
        } elseif ($rawUrl === null && $arg !== '') {
            $rawUrl = $arg;
        }
    }

    if ($rawUrl === null) {
        fwrite(STDERR, "Usage: php StreamtapeExtractor.php [--debug] <streamtape-url>\n");
        fwrite(STDERR, "Example: php StreamtapeExtractor.php https://streamtape.com/v/OXMp3qjo9aiZAl9\n");
        exit(1);
    }

    try {
        $directLink = StreamtapeExtractor::getDirectLink($rawUrl, $debug);
        echo $directLink . PHP_EOL;
        exit(0);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
