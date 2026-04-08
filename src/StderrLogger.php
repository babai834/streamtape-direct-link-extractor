<?php

declare(strict_types=1);

namespace Babai834\StreamtapeExtractor;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Minimal PSR-3 logger that writes to STDERR.
 *
 * Only debug-level messages are forwarded (all higher levels are silently
 * dropped), because the CLI --debug flag maps to a single verbosity level.
 * Higher severity messages are also written so that unexpected warnings/errors
 * are not lost when running interactively.
 *
 * Each line is prefixed with `[DEBUG]` (for debug) or `[<LEVEL>]` for others
 * to match the pre-refactor output format CLI users already expect.
 *
 * @internal Used only by StreamtapeExtractor::getDirectLink() when $debug=true.
 */
class StderrLogger extends AbstractLogger
{
    /**
     * {@inheritDoc}
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $prefix = strtoupper((string) $level);
        $line   = "[{$prefix}] {$message}";

        // Append any context key=value pairs for easy reading in the terminal.
        if (!empty($context)) {
            $pairs = [];
            foreach ($context as $key => $value) {
                $pairs[] = "{$key}=" . (is_scalar($value) ? (string) $value : json_encode($value));
            }
            $line .= ' | ' . implode(', ', $pairs);
        }

        fwrite(STDERR, $line . PHP_EOL);
    }
}
