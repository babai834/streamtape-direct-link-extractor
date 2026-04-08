<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Exception;

/**
 * Thrown when an extracted raw string cannot be turned into a valid, absolute
 * HTTPS URL pointing to the expected CDN domain.
 *
 * Causes include: the extracted value is not URL-like, or it does not point
 * to the expected content domain after normalization.
 */
class FinalizationException extends StreamtapeExtractorException
{
}
