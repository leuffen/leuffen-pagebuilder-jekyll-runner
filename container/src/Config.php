<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview;

final readonly class Config
{
    /**
     * Configuration for the RabbitMQ worker and external HTTP builder.
     *
     * @param string $sourceRoot Root of the read-only source volume.
     * @param string $targetRoot Root of the Nginx CDN target volume.
     * @param string $builderUrl HTTP base URL of the external Jekyll Builder.
     * @param string $amqpConnection AMQP URI including the virtual host.
     * @throws \InvalidArgumentException On invalid settings.
     * @see self::fromEnvironment()
     * @example new Config(builderUrl: 'http://jekyll-builder');
     */
    public function __construct(
        public string $sourceRoot = '/source',
        public string $targetRoot = '/target',
        public string $builderUrl = 'http://jekyll-builder',
        public string $amqpConnection = 'rabbitmq://runner:runner@rabbitmq:5672/',
    ) {
        foreach (['sourceRoot', 'targetRoot'] as $name) {
            $path = $this->$name;
            if (!str_starts_with($path, '/') || str_contains($path, "\0") || trim($path, '/') === '') {
                throw new \InvalidArgumentException("$name must be an absolute non-root path: $path");
            }
        }
        $parts = parse_url($builderUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user'], $parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid PREVIEW_BUILDER_URL');
        }
        self::parseAmqpConnection($amqpConnection);
    }

    /**
     * Parses the RabbitMQ URI including its virtual host.
     *
     * @return array{host:string,port:int,user:string,password:string,vhost:string}
     * @throws \InvalidArgumentException On malformed URI.
     * @see self::fromEnvironment()
     * @example $parts = Config::parseAmqpConnection('rabbitmq://u:p@host:5672/');
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
        if ($user === '' || preg_match('/[\x00-\x1f\x7f]/', $user . $pass . $vhost)) {
            throw new \InvalidArgumentException('Invalid CONF_AMQ_CONNECTION user or vhost');
        }
        return ['host' => $parts['host'], 'port' => $parts['port'] ?? 5672,
            'user' => $user, 'password' => $pass, 'vhost' => $vhost];
    }

    /**
     * Reads Preview and RabbitMQ settings from environment.
     *
     * @return self Validated worker configuration.
     * @throws \InvalidArgumentException On invalid environment values.
     * @see ../README.md
     * @example $config = Config::fromEnvironment();
     */
    public static function fromEnvironment(): self
    {
        return new self(
            sourceRoot: getenv('PREVIEW_SOURCE_ROOT') ?: '/source',
            targetRoot: getenv('PREVIEW_TARGET_ROOT') ?: '/target',
            builderUrl: getenv('PREVIEW_BUILDER_URL') ?: 'http://jekyll-builder',
            amqpConnection: getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://runner:runner@rabbitmq:5672/',
        );
    }
}
