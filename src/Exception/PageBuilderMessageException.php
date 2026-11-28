<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview\Client\Exception;

use RuntimeException;

/** A rejected browser-message request or failed local Nchan publication. */
final class PageBuilderMessageException extends RuntimeException
{
    /**
     * Preserve the publication failure and channel without exposing the body.
     *
     * @param string $errorCode RPC error code, such as INVALID_REQUEST or PUBLISH_ERROR.
     * @param string $message Diagnostic message from validation or the worker.
     * @param string $channel Requested Nchan channel.
     * @param array<string,mixed> $details Additional RPC diagnostics, if any.
     * @example throw new PageBuilderMessageException('PUBLISH_ERROR', 'HTTP 503', 'preview-42');
     * @see \Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient::sendWebSocketMessage()
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $channel,
        public readonly array $details = [],
    ) {
        $displayChannel = json_encode($channel, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        parent::__construct("$errorCode: $message (channel: $displayChannel)");
    }
}
