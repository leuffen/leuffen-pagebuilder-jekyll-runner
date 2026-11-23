# Leuffen Page Builder Jekyll Runner

Der Worker baut Jekyll-Sources aus `/source` und veröffentlicht das Ergebnis
unter `/target`. Das optionale Apache-CDN liefert statische Projektseiten.

## Konfiguration

Wie bei MICX Sec VCS wird RabbitMQ über einen Connection-String konfiguriert:

```yaml
environment:
  CONF_AMQ_CONNECTION: rabbitmq://runner:runner@rabbitmq:5672/
```

`rabbitmq://` und `amqp://` werden unterstützt. Der URI-Pfad ist der
**RabbitMQ-VHost** (Standard `/`). Ein eigener VHost wird beispielsweise
als `rabbitmq://user:pass@host:5672/pagebuilder` angegeben. Sonderzeichen
in Credentials und VHost müssen URL-encoded sein. `amqps://` wird noch
nicht unterstützt. Exchange `leuffen.jekyll.v1`, Request-Queue
`leuffen.jekyll.v1.requests` und Routing-Key `rpc.request` sind fest.

Die Build-Settings heißen `JEKYLL_SOURCE_ROOT` (Standard `/source`),
`JEKYLL_TARGET_ROOT` (`/target`), `JEKYLL_BUILD_ROOT`
(`/tmp/jekyll-runner`), `JEKYLL_BUILD_TIMEOUT` (300 Sekunden) und
`JEKYLL_MAX_OUTPUT_BYTES` (65536).

## Verwendung

```bash
mkdir -p data/source/demo data/target
printf 'title: Demo\n' > data/source/demo/_config.yml
printf '%s\n' '---' '---' '<h1>{{ site.title }}</h1>' > data/source/demo/index.html
docker compose up --detach --build
```

Der kopierbare PHP-Client ist `docs/PageBuilderJekyllRunnerClient.php`.
Er benötigt `php-amqplib/php-amqplib:^3.7` und Composer-Autoloading:

```php
<?php
require 'vendor/autoload.php';
require 'docs/PageBuilderJekyllRunnerClient.php';

$client = new PageBuilderJekyllRunnerClient(
    'rabbitmq://runner:runner@rabbitmq:5672/'
);
$result = $client->build('demo', 'demo');
echo $result['exitCode'];
```

Fehler werfen `RuntimeException` mit Fehlercode, Source-/Target-Pfaden
und bei Jekyll-Fehlern zusätzlich stderr. Clients können optional den
Reply-Timeout als drittes Argument von `build()` setzen.

Das optionale CDN wird mit `docker compose --profile cdn up -d` gestartet.
`demo.localhost:8080` zeigt danach auf `/target/demo`.

Grundlage: [MICX Page Builder Runner](https://github.com/micx-io/micx-pagebuilder-runner),
MIT-Lizenz.
