<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor\Exception;

/**
 * Thrown when the caller supplies a URL that is not a valid Streamtape watch
 * or embed URL (wrong host, wrong path format, completely malformed, etc.).
 *
 * Extends \InvalidArgumentException so callers that currently catch the base
 * PHP type continue to work without modification.
 */
class InvalidStreamtapeUrlException extends \InvalidArgumentException
{
}
