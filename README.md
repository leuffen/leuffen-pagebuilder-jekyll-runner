# Leuffen PageBuilder Preview

This repository contains two separately installable/deployable components:

- **Root Composer package** `leuffen/pagebuilder-preview-client`: the
  RabbitMQ RPC client in `src/` (no Apache or builder dependencies).
- **`container/`**: standalone worker and Apache CDN service, with its own
  `composer.json`, `.kick.yml`, `Dockerfile`, `bin/` and `src/`.

Install the client with:

```bash
composer require leuffen/pagebuilder-preview-client:dev-main
```

Build the container from the repository root:

```bash
docker build -f container/Dockerfile -t leuffen-pagebuilder-preview .
```

Only `container/` is copied into `/opt` in the Docker image. Server dependencies
include `leuffen/jekyll-builder-client:dev-main` from the separate Builder
repository; the root client package does not depend on that HTTP builder.

Preview combines a **RabbitMQ worker** and an **Apache CDN on port 80** in one
container. Only the external
[Leuffen Jekyll Builder](https://github.com/leuffen/leuffen-jekyll-builder)
compiles the sources over HTTP. There is no local Jekyll installation.

## Build and publication

1. The RabbitMQ worker accepts a request containing relative `source` and
   `target` paths.
2. The external Builder client zips `/source/<source>` and posts the archive
   to `PREVIEW_BUILDER_URL/build`.
3. The builder returns a ZIP containing the generated HTML and assets.
4. The client extracts that ZIP into an empty staging directory on the target
   volume. Only successful builds atomically replace `/target/<target>`.
5. Apache immediately serves the published files.

The worker keeps per-target locks to avoid concurrent replacement of
overlapping sites. An unsuccessful build never replaces the existing site.

- `/source`: read-only mount with project source files
- `/target`: writable volume for published sites and temporary staging

## Environment

| Variable | Default | Meaning |
| --- | --- | --- |
| `CONF_AMQ_CONNECTION` | `rabbitmq://runner:runner@rabbitmq:5672/` | AMQP URI with vhost |
| `PREVIEW_SOURCE_ROOT` | `/source` | Read-only source root |
| `PREVIEW_TARGET_ROOT` | `/target` | Apache CDN target root |
| `PREVIEW_BUILDER_URL` | `http://jekyll-builder` | External HTTP builder URL |
| `CDN_HOST_PATTERN` | `*.localhost` | Apache wildcard hostname |

Kickstart renders `config.php` from `container/config.php.dist`.

## Apache CDN

The Apache vhost in `container/.kicker/` uses `CDN_HOST_PATTERN` and
`VirtualDocumentRoot /target/%1`. The first subdomain selects a directory:

| Host pattern | Request | Directory |
| --- | --- | --- |
| `*.localhost` | `http://kunde.localhost/` | `/target/kunde` |
| `*.preview.example.com` | `http://kunde.preview.example.com/` | `/target/kunde` |

Provide wildcard DNS (or a reverse proxy) retaining the Host header.
Terminate TLS at a reverse proxy if required.

## Docker stack example

```yaml
services:
  preview:
    image: ghcr.io/leuffen/leuffen-pagebuilder-preview:unstable
    ports:
      - "80:80"
    environment:
      CONF_AMQ_CONNECTION: rabbitmq://runner:runner@rabbitmq:5672/
      PREVIEW_BUILDER_URL: http://jekyll-builder
      CDN_HOST_PATTERN: "*.preview.example.com"
    volumes:
      - /srv/pagebuilder/source:/source:ro
      - /srv/pagebuilder/target:/target
    networks: [builder-network]

networks:
  builder-network:
    external: true
    name: pagebuilder-internal
```

Deploy the external builder on the same trusted internal Docker network,
reachable at `http://jekyll-builder/build`. It has no built-in authentication
and must not be exposed publicly. Make the target volume writable by the
preview worker.

## RabbitMQ interface

The existing version-1 RPC envelope remains compatible with existing clients:
exchange `leuffen.jekyll.v1`, queue `leuffen.jekyll.v1.requests`, routing
key `rpc.request`. Supply `params.source` and `params.target` relative to
the mounted roots. Each worker handles at most one build at a time, and
acknowledges the request after publishing the response.

Build failures respond with `BUILD_FAILED` and diagnostic details including
`exitCode`, `stdout`, `stderr`, `file`, `line`, `excerpt`, and
`cause` when available. Connectivity and filesystem errors report
`BUILD_ERROR`. The existing `PageBuilderJekyllRunnerClient` API remains
available through the root Composer package and Composer classmap.

## Builder dependency

The container imports `Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient`
from `leuffen/jekyll-builder-client:dev-main`. That package is provided by
`leuffen/leuffen-jekyll-builder` once its Composer client restructure is
merged. The Preview client itself only requires `php-amqplib/php-amqplib`.
