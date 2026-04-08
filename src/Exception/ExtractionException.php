<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Exception;

/**
 * Thrown when all extraction strategies fail to locate a video URL in the
 * fetched page HTML.
 *
 * This usually means Streamtape has changed their page structure.  Running
 * with --debug (or injecting a logger) will dump the raw HTML for analysis.
 */
class ExtractionException extends StreamtapeExtractorException
{
}
