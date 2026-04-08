<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Http;

use Babai834\StreamtapeExtractor\Exception\FetchException;

/**
 * Contract for objects that can retrieve the full HTML body of a URL.
 *
 * Implementing this interface allows the HTTP layer to be replaced with a
 * test double or an alternative HTTP client without modifying extractor logic.
 */
interface PageFetcherInterface
{
    /**
     * Fetch the HTML body of the given URL.
     *
     * @param  string $url Absolute HTTPS URL to retrieve.
     * @return string      Full response body.
     *
     * @throws FetchException On any network or HTTP-level error.
     */
    public function fetch(string $url): string;
}
