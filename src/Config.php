<?php

declare(strict_types=1);

namespace Leuffen\JekyllRunner;

final readonly class Config
{
    /**
     * Konfiguration für einen Worker und seine drei getrennten Verzeichnisse.
     *
     * Source und Target werden als Volumes bereitgestellt; Build ist temporär.
     * Die Zeitgrenze gilt pro Build, das Ausgabelimit jeweils für stdout/stderr.
     * AMQP-Zugangsdaten werden nicht in Diagnosemeldungen ausgegeben.
     *
     * @param string $sourceRoot Absoluter Basispfad der vorhandenen Quellen.
     * @param string $targetRoot Absoluter Basispfad der veröffentlichten Websites.
     * @param string $buildRoot Absoluter Basispfad temporärer Build-Verzeichnisse.
     * @param int $buildTimeout Maximale Jekyll-Laufzeit in Sekunden.
     * @param int $maxOutputBytes Maximale zurückgegebene Bytes pro Ausgabestrom.
     * @param string $amqpHost RabbitMQ-Hostname.
     * @param int $amqpPort RabbitMQ-Port.
     * @param string $amqpUser RabbitMQ-Benutzer.
     * @param string $amqpPassword RabbitMQ-Passwort.
     * @param string $amqpVhost RabbitMQ-VHost.
     * @param string $amqpExchange Gemeinsamer Topic-Exchange der Build-Worker.
     * @param string $amqpQueue Gemeinsame dauerhafte Request-Queue.
     * @param string $amqpRoutingKey Routing-Key für Build-Requests.
     * @param int $reconnectDelay Pause zwischen Verbindungsversuchen in Sekunden.
     * @throws \InvalidArgumentException Bei ungültigen Pfaden oder Grenzwerten.
     * @example new Config(sourceRoot: '/source', targetRoot: '/target');
     * @see self::fromEnvironment()
     */
    public function __construct(
        public string $sourceRoot = '/source',
        public string $targetRoot = '/target',
        public string $buildRoot = '/tmp/jekyll-runner',
        public int $buildTimeout = 300,
        public int $maxOutputBytes = 65536,
        public string $amqpHost = 'rabbitmq',
        public int $amqpPort = 5672,
        public string $amqpUser = 'runner',
        public string $amqpPassword = 'runner',
        public string $amqpVhost = '/',
        public string $amqpExchange = 'leuffen.jekyll.v1',
        public string $amqpQueue = 'leuffen.jekyll.v1.requests',
        public string $amqpRoutingKey = 'rpc.request',
        public int $reconnectDelay = 30,
    ) {
        foreach (['sourceRoot', 'targetRoot', 'buildRoot'] as $name) {
            $path = $this->$name;
            if (!str_starts_with($path, '/') || str_contains($path, "\0") || trim($path, '/') === '') {
                throw new \InvalidArgumentException("$name must be an absolute non-root path: $path");
            }
        }
        if ($buildTimeout < 1 || $maxOutputBytes < 1 || $maxOutputBytes > 1048576 || $reconnectDelay < 1) {
            throw new \InvalidArgumentException('Build timeout, output limit or reconnect delay is invalid');
        }
        if ($amqpPort < 1 || $amqpPort > 65535 || $amqpHost === '' || $amqpUser === '' || $amqpVhost === '') {
            throw new \InvalidArgumentException('AMQP host, port, user or vhost is invalid');
        }
        foreach ([$amqpExchange, $amqpQueue, $amqpRoutingKey] as $name) {
            if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $name)) {
                throw new \InvalidArgumentException('AMQP exchange, queue or routing key is invalid');
            }
        }
    }

    /**
     * Liest die dokumentierten JEKYLL_* und AMQP_* Container-Einstellungen.
     *
     * Nicht gesetzte Variablen verwenden die Constructor-Defaults. Ein bewusst
     * leeres Passwort bleibt leer; fehlerhafte Zahlen werden nicht still gekürzt.
     * Es werden weder Verzeichnisse angelegt noch Broker-Verbindungen geöffnet.
     *
     * @return self Vollständig validierte Worker-Konfiguration.
     * @throws \InvalidArgumentException Bei ungültigen Environment-Werten.
     * @example $config = Config::fromEnvironment();
     * @see ../README.md
     */
    public static function fromEnvironment(): self
    {
        $defaults = new self();
        $variables = [
            'sourceRoot' => 'JEKYLL_SOURCE_ROOT',
            'targetRoot' => 'JEKYLL_TARGET_ROOT',
            'buildRoot' => 'JEKYLL_BUILD_ROOT',
            'buildTimeout' => 'JEKYLL_BUILD_TIMEOUT',
            'maxOutputBytes' => 'JEKYLL_MAX_OUTPUT_BYTES',
            'amqpHost' => 'AMQP_HOST',
            'amqpPort' => 'AMQP_PORT',
            'amqpUser' => 'AMQP_USER',
            'amqpPassword' => 'AMQP_PASSWORD',
            'amqpVhost' => 'AMQP_VHOST',
            'amqpExchange' => 'AMQP_EXCHANGE',
            'amqpQueue' => 'AMQP_QUEUE',
            'amqpRoutingKey' => 'AMQP_ROUTING_KEY',
            'reconnectDelay' => 'AMQP_RECONNECT_DELAY',
        ];

        // Zahlen vor der Übernahme prüfen, damit falsche Deployments auffallen.
        $values = [];
        foreach ($variables as $property => $variable) {
            $value = getenv($variable);
            if ($value === false) {
                $values[$property] = $defaults->$property;
                continue;
            }
            if (is_int($defaults->$property)) {
                if (!preg_match('/^[1-9][0-9]*$/D', $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException("$variable must be a positive integer");
                }
                $value = (int) $value;
            }
            $values[$property] = $value;
        }
        return new self(...$values);
    }
}
