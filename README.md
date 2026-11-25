# Leuffen PageBuilder Preview

This repository provides **two complementary components**:

| Component | Usage | Location |
| --- | --- | --- |
| **Docker container (Preview Worker + CDN)** | Consumes RabbitMQ build requests, calls the external Jekyll Builder over HTTP, and serves generated sites via Apache | `container/` |
| **PHP Composer client** | Sends build requests to the Preview worker through RabbitMQ and receives results or typed errors | Root `composer.json` and `src/` |

The PHP client runs in the calling application; **it does not build Jekyll
sites locally** and does not need Apache or the external HTTP Builder client.
The Docker container includes the RabbitMQ worker and Apache CDN on port 80,
but delegates Jekyll compilation to the
[external Jekyll Builder](https://github.com/leuffen/leuffen-jekyll-builder).

## PHP client: installation and quick start

Install the standalone Composer package in the PHP application that needs to
request Preview builds:

```bash
composer require leuffen/pagebuilder-preview-client:dev-main
```

The package includes `php-amqplib/php-amqplib`. The classes are loaded through
Composer's PSR-4 autoloader; no manual `require` of the client file is necessary.
The client is `Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient`,
and its exceptions live in the `Client\Exception` namespace.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllBuildException;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllConnectionException;

$client = new PageBuilderJekyllRunnerClient(
    'rabbitmq://runner:runner@rabbitmq:5672/'
);

try {
    // Both paths are relative to the worker's /source and /target mounts.
    $result = $client->build('kunde/docs', 'kunde', 360);

    echo "Build successful\n";
    echo $result['stdout'] ?? '';
} catch (PageBuilderJekyllBuildException $e) {
    // RPC rejected the request or the remote Jekyll build failed.
    echo $e->errorCode . ': ' . $e->getMessage();
    echo $e->getStderr();
} catch (PageBuilderJekyllConnectionException $e) {
    // RabbitMQ connection, RPC protocol or request timeout failed.
    echo 'Preview unavailable: ' . $e->getMessage();
}
```

`build($source, $target, $timeout = 360)` submits one RabbitMQ RPC request and
waits for the reply. Here `kunde/docs` refers to the **container's**
`/source/kunde/docs` and `kunde` to its `/target/kunde`: these paths do
**not** refer to the client application's local filesystem. The third argument
is the overall timeout in seconds, including time spent waiting in the queue.
The successful response is an array containing the source, target, `exitCode`,
and available diagnostics. A failed build exposes `errorCode`, `source`,
`target` and `details` on `PageBuilderJekyllBuildException`, plus
`getStdout()` and `getStderr()`.

Successful builds include `debugInfo` in the result. Failed Jekyll builds
include the same field in `$e->details`. It is forwarded from the external
builder, for example:

```json
{"timingInfo":{"jekyll":1234},"warnings":[],"truncated":false}
```

Process durations are measured in milliseconds; `durationMs` remains the
overall preview worker duration. Warnings contain explicit Jekyll warning
lines and may be incomplete when `truncated` is `true`.

The client connects directly to RabbitMQ, **not** to the HTTP Jekyll Builder.
The client application's RabbitMQ URI must point to the same broker and vhost
as the Preview worker's `CONF_AMQ_CONNECTION`. Keep credentials private.

## Docker container: build and deployment

The runtime lives exclusively in `container/`, with its own
`composer.json`, `Dockerfile`, `.kick.yml`, `bin/` and `src/`.
Build the image from the repository root:

```bash
docker build -f container/Dockerfile -t leuffen-pagebuilder-preview .
```

Only `container/` is copied into `/opt` in the image. The container installs
`leuffen/jekyll-builder-client:dev-main` to upload source ZIPs over HTTP; this
dependency is unrelated to the PHP Preview RPC client package. The runtime
requires reachable RabbitMQ and a separately deployed Jekyll Builder service.

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
| `PREVIEW_RELOAD_JS` | empty (disabled) | Custom JavaScript source for the preview reload hook |

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

### Optional preview reload script

Set `PREVIEW_RELOAD_JS` to your JavaScript source (including multiline
code if needed). On startup, Kickstart makes the script available at the
reserved same-origin path `/__preview_reload.js`. Apache `mod_substitute`
inserts a script tag immediately before the closing `</body>` tag in
`text/html` responses (case-insensitive). Static files on the target
volume are not changed; non-HTML responses are not filtered.

When the variable is unset or empty, both the filter and the script
endpoint are disabled. Recreate the container to change the setting.
The script is shared by all preview hosts and is public to their
visitors; configure only trusted JavaScript and do not include secrets.
The application's content security policy must allow the same-origin
script.

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
`cause` when available, plus `debugInfo` from the builder. Successful
RPC results also include `debugInfo`. Connectivity and filesystem errors report
`BUILD_ERROR`. The existing `PageBuilderJekyllRunnerClient` API remains
available through the root Composer package and PSR-4 autoloading.

## Builder dependency

The container imports `Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient`
from `leuffen/jekyll-builder-client:dev-main`. That package is provided by
`leuffen/leuffen-jekyll-builder`. Deploy a builder image that includes the
`X-Debug-Info` header and use its updated Composer client before running the
Preview integration tests. The Preview client itself only requires
`php-amqplib/php-amqplib`.
