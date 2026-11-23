<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview\Client\Exception;

use RuntimeException;

/**
 * Exception for a failed Jekyll build or a rejected build request.
 *
 * The complete output received from the worker is exposed in getStdout()
 * and getStderr(), in addition to the diagnostic exception message.
 */
final class PageBuilderJekyllBuildException extends RuntimeException
{
    /**
     * @param string $errorCode RPC error code such as BUILD_FAILED.
     * @param string $message Remote error message.
     * @param string $source Relative source directory.
     * @param string $target Relative target directory.
     * @param array<string, mixed> $details Complete RPC details, including command output.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $source,
        public readonly string $target,
        public readonly array $details = [],
    ) {
        parent::__construct("$errorCode: $message (source: $source; target: $target)"
            . "\nJekyll stdout:\n" . $this->getStdout()
            . "\nJekyll stderr:\n" . $this->getStderr());
    }

    /** Return the full Jekyll stdout stream provided by the worker. */
    public function getStdout(): string
    {
        return (string) ($this->details['stdout'] ?? '');
    }

    /** Return the full Jekyll stderr stream provided by the worker. */
    public function getStderr(): string
    {
        return (string) ($this->details['stderr'] ?? '');
    }
}

