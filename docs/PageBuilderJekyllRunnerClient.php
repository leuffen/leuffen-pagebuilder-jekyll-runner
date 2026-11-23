<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

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

/** Exception for RabbitMQ connection, reply timeout and protocol failures. */
final class PageBuilderJekyllConnectionException extends RuntimeException
{
}

/** Portable PHP client for the Jekyll Runner (requires php-amqplib ^3.7). */
final class PageBuilderJekyllRunnerClient
{
    private array $connection;

    /**
     * Configure a RabbitMQ URI; the path specifies the RabbitMQ vhost.
     *
     * @param string $uri RabbitMQ URI including URL-encoded credentials.
     * @throws PageBuilderJekyllConnectionException On invalid URI.
     * @example new PageBuilderJekyllRunnerClient('rabbitmq://runner:runner@rabbitmq:5672/');
     * @see build()
     */
    public function __construct(string $uri)
    {
        $parts = parse_url($uri);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['rabbitmq', 'amqp'], true)
            || empty($parts['host']) || !isset($parts['user'], $parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new PageBuilderJekyllConnectionException('Invalid RabbitMQ connection URI');
        }
        $user = rawurldecode($parts['user']);
        $pass = rawurldecode($parts['pass']);
        $vhost = rawurldecode(ltrim($parts['path'] ?? '/', '/')) ?: '/';
        if ($user === '' || preg_match('/[\x00-\x1f\x7f]/', $user . $pass . $vhost)) {
            throw new PageBuilderJekyllConnectionException('Invalid RabbitMQ user or vhost');
        }
        $this->connection = [$parts['host'], $parts['port'] ?? 5672, $user, $pass, $vhost];
    }

    /**
     * Build a site and return the RPC result; failed builds throw with paths.
     *
     * @param string $source Relative directory below the source mount.
     * @param string $target Relative directory below the target mount.
     * @param int $timeout Deadline in seconds, including queue wait and build.
     * @return array<string,mixed> Build result with exitCode, stdout, stderr and paths.
     * @throws PageBuilderJekyllBuildException On failed build or invalid directories.
     * @throws PageBuilderJekyllConnectionException On invalid timeout, transport or protocol errors.
     * @example $result = $client->build('demo/docs', 'demo');
     * @see https://github.com/leuffen/leuffen-pagebuilder-jekyll-runner
     */
    public function build(string $source, string $target, int $timeout = 360): array
    {
        $context = "source: $source; target: $target";
        foreach (['source' => $source, 'target' => $target] as $name => $path) {
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
                || preg_match('~(^|/)(\\.{1,2}|)(/|$)~', $path)) {
                throw new PageBuilderJekyllBuildException("INVALID_REQUEST", "Invalid $name directory", $source, $target);
            }
        }
        if ($timeout < 1) {
            throw new PageBuilderJekyllConnectionException("Invalid timeout ($context)");
        }
        $connection = null;
        $channel = null;
        try {
            $connection = new AMQPStreamConnection(...$this->connection);
            $channel = $connection->channel();
            [$replyQueue] = $channel->queue_declare('', false, false, true, true);
            $id = bin2hex(random_bytes(16));
            $reply = null;

            // Register the reply consumer before publishing the request.
            $channel->basic_consume($replyQueue, '', false, true, false, false,
                static function (AMQPMessage $message) use ($id, &$reply): void {
                    if ($message->get('correlation_id') === $id) {
                        $reply = json_decode($message->getBody(), true, 16, JSON_THROW_ON_ERROR);
                    }
                });
            $request = ['version' => 1, 'id' => $id, 'method' => 'build',
                'params' => ['source' => $source, 'target' => $target]];
            $channel->basic_publish(new AMQPMessage(json_encode($request, JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => 2,
                    'correlation_id' => $id, 'reply_to' => $replyQueue]), 'leuffen.jekyll.v1', 'rpc.request');

            // A deadline prevents an indefinitely blocked caller.
            $deadline = microtime(true) + $timeout;
            while ($reply === null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new PageBuilderJekyllConnectionException("RPC_TIMEOUT after $timeout seconds ($context)");
                }
                try {
                    $channel->wait(null, false, $remaining);
                } catch (AMQPTimeoutException) {
                    throw new PageBuilderJekyllConnectionException("RPC_TIMEOUT after $timeout seconds ($context)");
                }
            }
            if (!is_array($reply) || ($reply['version'] ?? null) !== 1
                || ($reply['id'] ?? null) !== $id || !is_bool($reply['ok'] ?? null)) {
                throw new PageBuilderJekyllConnectionException("INVALID_REPLY ($context)");
            }
            if (!$reply['ok']) {
                $error = $reply['error'] ?? [];
                $code = is_string($error['code'] ?? null) ? $error['code'] : 'UNKNOWN_ERROR';
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
                $details = is_array($error['details'] ?? null) ? $error['details'] : [];
                throw new PageBuilderJekyllBuildException($code, $message, $source, $target, $details);
            }
            if (!is_array($reply['result'] ?? null)) {
                throw new PageBuilderJekyllConnectionException("INVALID_REPLY: no build result ($context)");
            }
            return $reply['result'];
        } catch (PageBuilderJekyllBuildException | PageBuilderJekyllConnectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // Driver exception details may contain credentials: keep the public message safe.
            throw new PageBuilderJekyllConnectionException(
                "RabbitMQ connection or RPC error ($context); verify broker and vhost", 0, $error,
            );
        } finally {
            try {
                $channel?->close();
            } catch (\Throwable) {
            }
            try {
                $connection?->close();
            } catch (\Throwable) {
            }
        }
    }
}
