<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview\Client;

use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllBuildException;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllConnectionException;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderMessageException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/** Portable PHP client for Preview builds and browser messages. */
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
     * @see https://github.com/leuffen/leuffen-pagebuilder-preview
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
        $reply = $this->request('build', ['source' => $source, 'target' => $target], $timeout, $context);
        if (!$reply['ok']) {
            $error = $reply['error'] ?? [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];
            throw new PageBuilderJekyllBuildException($code, $message, $source, $target, $details);
        }
        return $reply['result'];
    }

    /**
     * Publish a UTF-8 body to browsers subscribed to one Nchan channel.
     *
     * RabbitMQ transports the command to the Preview worker, which posts the
     * body unchanged to its local Nginx publisher. JSON data must be encoded
     * explicitly by the caller. The complete encoded RPC envelope is limited
     * to 65,536 bytes. A successful result confirms Nchan's acceptance, not
     * delivery to a browser. Broker redelivery may publish a message twice.
     *
     * @param string $channel Individual channel name: 1-128 ASCII letters,
     *     digits, underscores or hyphens; one namespace per Nginx instance.
     * @param string $body UTF-8 message body; never interpreted as a command.
     * @param int $timeout Reply deadline in seconds, including queue wait.
     *     Messages may wait behind a build in the existing worker.
     * @return array{channel:string,status:string} Channel and status published.
     * @throws PageBuilderMessageException For invalid channel/body or rejected publication.
     * @throws PageBuilderJekyllConnectionException For an invalid timeout,
     *     oversized RPC envelope, transport failure or invalid reply.
     * @example $client->sendWebSocketMessage('preview-session-42', '{"type":"reload"}');
     * @see https://github.com/leuffen/leuffen-pagebuilder-preview#browser-messages
     */
    public function sendWebSocketMessage(string $channel, string $body, int $timeout = 360): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $channel)) {
            throw new PageBuilderMessageException('INVALID_REQUEST', 'Invalid channel name', $channel);
        }
        if (preg_match('//u', $body) !== 1) {
            throw new PageBuilderMessageException('INVALID_REQUEST', 'Message body must be valid UTF-8', $channel);
        }

        // Der Body bleibt unverändert; nur das RPC-Envelope wird serialisiert.
        $reply = $this->request('publish', ['channel' => $channel, 'body' => $body], $timeout, "channel: $channel");
        if (!$reply['ok']) {
            $error = $reply['error'] ?? [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];
            throw new PageBuilderMessageException($code, $message, $channel, $details);
        }
        if (($reply['result']['channel'] ?? null) !== $channel
            || ($reply['result']['status'] ?? null) !== 'published') {
            throw new PageBuilderJekyllConnectionException("INVALID_REPLY: no publish confirmation (channel: $channel)");
        }
        return $reply['result'];
    }

    private function request(string $method, array $params, int $timeout, string $context): array
    {
        if ($timeout < 1) {
            throw new PageBuilderJekyllConnectionException("Invalid timeout ($context)");
        }
        $connection = null;
        $channel = null;
        try {
            // Größe vor dem Verbindungsaufbau prüfen, einschließlich JSON-Escaping.
            $id = bin2hex(random_bytes(16));
            $request = json_encode(['version' => 1, 'id' => $id, 'method' => $method,
                'params' => $params], JSON_THROW_ON_ERROR);
            if ($method === 'publish' && strlen($request) > 65536) {
                throw new PageBuilderJekyllConnectionException("RPC request exceeds 64 KiB ($context)");
            }
            $connection = new AMQPStreamConnection(...$this->connection);
            $channel = $connection->channel();
            [$replyQueue] = $channel->queue_declare('', false, false, true, true);
            $reply = null;

            // Register the reply consumer before publishing the request.
            $channel->basic_consume($replyQueue, '', false, true, false, false,
                static function (AMQPMessage $message) use ($id, &$reply): void {
                    if ($message->get('correlation_id') === $id) {
                        $reply = json_decode($message->getBody(), true, 16, JSON_THROW_ON_ERROR);
                    }
                });
            $channel->basic_publish(new AMQPMessage($request,
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
            if ($reply['ok'] && !is_array($reply['result'] ?? null)) {
                throw new PageBuilderJekyllConnectionException("INVALID_REPLY: no $method result ($context)");
            }
            return $reply;
        } catch (PageBuilderJekyllConnectionException $error) {
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
