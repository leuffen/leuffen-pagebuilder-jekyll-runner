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
        public string $amqpConnection = 'rabbitmq://runner:runner@rabbitmq:5672/',
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
        self::parseAmqpConnection($amqpConnection);
    }

    /**
     * Decodes the single RabbitMQ URI, including the virtual host.
     *
     * @return array{host:string,port:int,user:string,password:string,vhost:string}
     * @throws \InvalidArgumentException On malformed URI.
     * @example $parts = Config::parseAmqpConnection('rabbitmq://u:p@host:5672/');
     * @see self::fromEnvironment()
     */
    public static function parseAmqpConnection(string $uri): array
    {
        $parts = parse_url($uri);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['rabbitmq', 'amqp'], true)
            || empty($parts['host']) || !isset($parts['user'], $parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid CONF_AMQ_CONNECTION URI');
        }
        $user = rawurldecode($parts['user']);
        $pass = rawurldecode($parts['pass']);
        $vhost = rawurldecode(ltrim($parts['path'] ?? '/', '/')) ?: '/';
        if ($user === '' || preg_match('/[\\x00-\\x1f\\x7f]/', $user . $pass . $vhost)) {
            throw new \InvalidArgumentException('Invalid CONF_AMQ_CONNECTION user or vhost');
        }
        return ['host' => $parts['host'], 'port' => $parts['port'] ?? 5672,
            'user' => $user, 'password' => $pass, 'vhost' => $vhost];
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
            'amqpConnection' => 'CONF_AMQ_CONNECTION',
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
