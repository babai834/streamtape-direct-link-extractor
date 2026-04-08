<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Exception;

/**
 * Base exception for all StreamtapeExtractor domain errors.
 *
 * Catching this type alone is sufficient to handle any failure originating
 * from StreamtapeExtractor; sub-types allow more granular handling.
 */
class StreamtapeExtractorException extends \RuntimeException
{
}
