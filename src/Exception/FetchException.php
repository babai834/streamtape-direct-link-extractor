<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Exception;

/**
 * Thrown when the HTTP layer fails to retrieve the page.
 *
 * Causes include: cURL errors, non-2xx HTTP responses, empty body, the
 * content-domain fragment missing from the returned HTML (geo-block /
 * expired video), or any other network-level failure.
 */
class FetchException extends StreamtapeExtractorException
{
}
