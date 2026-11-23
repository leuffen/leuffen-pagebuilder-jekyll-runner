<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview\Client\Exception;

use RuntimeException;

/** Exception for RabbitMQ connection, reply timeout and protocol failures. */
final class PageBuilderJekyllConnectionException extends RuntimeException
{
}

