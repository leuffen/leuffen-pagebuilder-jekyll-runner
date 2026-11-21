<?php

declare(strict_types=1);

namespace Leuffen\JekyllRunner;

use Phore\Log\PhoreLogger;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPProtocolException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

final class Worker
{
    private bool $stopping = false;

    /**
     * Verbindet einen BuildRunner mit einem RabbitMQ-RPC-Consumer.
     *
     * Der Constructor öffnet keine Verbindung. Ein Worker führt jeweils einen
     * Build aus; mehrere Container teilen sich die konfigurierte Request-Queue.
     * BuildRunner schützt gemeinsame Targets gegen gleichzeitige Änderungen.
     *
     * @param Config $config Verzeichnisse, Limits und AMQP-Verbindungsdaten.
     * @param BuildRunner $builder Ausführung und Veröffentlichung der Builds.
     * @param PhoreLogger $log Logger für Start, Ergebnis und Reconnect-Diagnosen.
     * @example new Worker($config, new BuildRunner($config), $log);
     * @see self::run()
     */
    public function __construct(
        private readonly Config $config,
        private readonly BuildRunner $builder,
        private readonly PhoreLogger $log,
    ) {}

    /**
     * Verarbeitet RPC-Nachrichten bis SIGTERM oder SIGINT empfangen wird.
     *
     * Broker-Ausfälle werden protokolliert und nach reconnectDelay Sekunden
     * erneut versucht. Der aktuelle Build darf bei einem Stop-Signal auslaufen.
     * Replies werden vor dem Request-ACK vom Broker bestätigt. Nach Abbruch kann
     * ein Build erneut zugestellt werden; eine Exactly-once-Garantie besteht nicht.
     *
     * @return void Kehrt nach dem geordneten Stop zurück.
     * @example (new Worker($config, new BuildRunner($config), $log))->run();
     * @see ../README.md
     */
    public function run(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopping = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stopping = true;
        });

        while (!$this->stopping) {
            $connection = null;
            $channel = null;
            $heartbeat = null;
            try {
                // Heartbeats laufen auch während eines längeren Jekyll-Prozesses.
                $connection = new AMQPStreamConnection(
                    $this->config->amqpHost,
                    $this->config->amqpPort,
                    $this->config->amqpUser,
                    $this->config->amqpPassword,
                    $this->config->amqpVhost,
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    5,
                    130,
                    null,
                    false,
                    60,
                );
                $heartbeat = new PCNTLHeartbeatSender($connection);
                $heartbeat->register();
                $channel = $connection->channel();
                $channel->exchange_declare($this->config->amqpExchange, 'topic', false, true, false);
                $channel->queue_declare($this->config->amqpQueue, false, true, false, false);
                $channel->queue_bind($this->config->amqpQueue, $this->config->amqpExchange, $this->config->amqpRoutingKey);
                $channel->basic_qos(0, 1, false);
                $channel->confirm_select();

                // Genau eine Response ist gleichzeitig unterwegs; Nacks verhindern ACKs.
                $responseNacked = false;
                $responseReturned = false;
                $channel->set_nack_handler(static function () use (&$responseNacked): void {
                    $responseNacked = true;
                });
                $channel->set_return_listener(static function () use (&$responseReturned): void {
                    $responseReturned = true;
                });
                $channel->basic_consume(
                    $this->config->amqpQueue,
                    '',
                    false,
                    false,
                    false,
                    false,
                    function (AMQPMessage $message) use ($channel, &$responseNacked, &$responseReturned): void {
                        if ($this->stopping) {
                            $message->nack(true);
                            return;
                        }

                        // Ungültige Rückkanäle nicht zum Ausführen von Builds verwenden.
                        $replyTo = $message->has('reply_to') ? $message->get('reply_to') : '';
                        $id = $message->has('correlation_id') ? $message->get('correlation_id') : '';
                        if (!is_string($replyTo) || !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $replyTo)
                            || $replyTo === $this->config->amqpQueue
                            || !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $id)) {
                            $this->log->warning('Rejected request: invalid reply_to or correlation_id');
                            $message->reject(false);
                            return;
                        }

                        $response = $this->handleRequest($message->getBody(), $id);
                        $responseNacked = false;
                        $responseReturned = false;
                        try {
                            $channel->basic_publish(new AMQPMessage(
                                json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                                ['content_type' => 'application/json', 'correlation_id' => $id, 'expiration' => '300000'],
                            ), '', $replyTo, true);
                            $channel->wait_for_pending_acks_returns(10);
                        } catch (AMQPTimeoutException $error) {
                            // Dieser Timeout darf nicht als harmloser Idle-Poll gelten.
                            throw new AMQPRuntimeException('Reply confirmation timed out', 0, $error);
                        }
                        if ($responseNacked) {
                            throw new AMQPRuntimeException('Reply was not confirmed');
                        }
                        if ($responseReturned) {
                            // Ein verschwundener Client darf keine endlose Build-Schleife auslösen.
                            $this->log->warning('Reply queue is unavailable for request {}; result was not delivered', [$id]);
                        }
                        $message->ack();
                    },
                );
                $this->log->info('Jekyll worker ready; listening on {}', [$this->config->amqpQueue]);

                // Kurze Idle-Polls machen den geordneten Stop zeitnah möglich.
                while (!$this->stopping && $channel->is_consuming()) {
                    try {
                        $channel->wait(null, false, 1);
                    } catch (AMQPTimeoutException) {
                        // Ohne Nachricht lediglich das Stop-Flag erneut prüfen.
                    }
                }
            } catch (\Throwable $error) {
                if (!$this->stopping) {
                    $reason = match (true) {
                        $error instanceof AMQPProtocolChannelException => 'broker rejected channel; check queue settings and permissions',
                        $error instanceof AMQPProtocolException => 'broker rejected connection; check credentials and vhost permissions',
                        $error instanceof AMQPExceptionInterface => 'broker is unavailable or connection was lost; check DNS and network',
                        default => 'worker failed; check configuration and build storage',
                    };
                    $this->log->error('RabbitMQ: {}; retry in {} seconds', [$reason, $this->config->reconnectDelay]);
                }
            } finally {
                $heartbeat?->unregister();
                try {
                    $channel?->close();
                } catch (\Throwable) {
                    // Eine bereits verlorene Verbindung braucht keine zweite Diagnose.
                }
                try {
                    $connection?->close();
                } catch (\Throwable) {
                    // Der nächste Versuch öffnet eine neue Verbindung.
                }
            }

            $retryAt = microtime(true) + $this->config->reconnectDelay;
            while (!$this->stopping && microtime(true) < $retryAt) {
                usleep(200000);
            }
        }
        $this->log->info('Jekyll worker stopped');
    }

    private function handleRequest(string $body, string $id): array
    {
        $response = ['version' => 1, 'id' => $id, 'ok' => false];
        try {
            // Envelope und relative Pfade prüfen, bevor Dateiarbeit beginnt.
            if (strlen($body) > 65536) {
                throw new \InvalidArgumentException('Request exceeds 64 KiB');
            }
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($request) || ($request['version'] ?? null) !== 1
                || ($request['id'] ?? null) !== $id || !is_string($request['method'] ?? null)
                || !is_array($request['params'] ?? null)) {
                throw new \InvalidArgumentException('Expected a version 1 RPC request matching correlation_id');
            }
            if ($request['method'] !== 'build') {
                $response['error'] = ['code' => 'UNKNOWN_METHOD', 'message' => 'Only method build is supported', 'details' => new \stdClass()];
                return $response;
            }
            $source = $request['params']['source'] ?? null;
            $target = $request['params']['target'] ?? null;
            if (!is_string($source) || !is_string($target)) {
                throw new \InvalidArgumentException('params.source and params.target must be relative directory strings');
            }

            // Jekyll-Diagnosen gehören sowohl zum Ergebnis als auch in die Logs.
            $this->log->info('Build {}: {} -> {}', [$id, $source, $target]);
            $result = $this->builder->build($source, $target);
            if ($result['exitCode'] !== 0) {
                $response['error'] = ['code' => 'BUILD_FAILED', 'message' => 'Jekyll build failed', 'details' => $result];
                $this->log->error('Build {} failed with exit code {}', [$id, $result['exitCode']]);
                $this->log->error('Jekyll stderr: {:full}', [$result['stderr']]);
                $this->log->error('Jekyll stdout: {:full}', [$result['stdout']]);
                return $response;
            }
            $this->log->info('Build {} completed in {} ms', [$id, $result['durationMs']]);
            return ['version' => 1, 'id' => $id, 'ok' => true, 'result' => $result];
        } catch (\JsonException | \InvalidArgumentException $error) {
            $response['error'] = ['code' => 'INVALID_REQUEST', 'message' => $error->getMessage(), 'details' => new \stdClass()];
        } catch (\RuntimeException $error) {
            $response['error'] = ['code' => 'BUILD_ERROR', 'message' => $error->getMessage(), 'details' => new \stdClass()];
        }
        $this->log->error('Request {} failed: {}', [$id, $response['error']['message']]);
        return $response;
    }
}
