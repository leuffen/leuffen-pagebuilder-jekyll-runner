# Leuffen PageBuilder Jekyll Runner

Ein Container mit **Apache auf Port 80** und einem PHP-Worker, der Jekyll-Builds über RabbitMQ ausführt. Kickstart startet Apache automatisch und den Worker über `.kick.yml`.

## Verzeichnisse

- `/source`: vorhandene Jekyll-Quellen; wird **nur gelesen** (read-only mount).
- `/target`: veröffentlichte Websites; muss für den Worker **beschreibbar** sein.

Ein Build von `source: kunde/docs` nach `target: kunde` liest
`/source/kunde/docs` und veröffentlicht unter `/target/kunde`.

## Apache und virtuelle Hosts

Apache verwendet `CDN_HOST_PATTERN` als Wildcard-Host, standardmäßig
`*.localhost`. Der VHost in
`.kicker/conf/etc/apache2/sites-available/000-default.conf` enthält:

```apache
ServerAlias ${CDN_HOST_PATTERN}
UseCanonicalName Off
VirtualDocumentRoot /target/%1
```

Dabei wird **die erste Subdomain** (`%1`) zum Verzeichnis unter `/target`.
Die separate `RewriteRule` weist ungültig formatierte Hostnamen ab; die
Verzeichniszuordnung erfolgt durch `VirtualDocumentRoot`.

| Einstellung | Aufruf | Ausgeliefertes Verzeichnis |
|---|---|---|
| `CDN_HOST_PATTERN=*.localhost` | `http://kunde.localhost/` | `/target/kunde` |
| `CDN_HOST_PATTERN=*.preview.example.com` | `http://kunde.preview.example.com/` | `/target/kunde` |

Bei der echten Domain muss ein Wildcard-DNS-Eintrag (oder ein Reverse Proxy)
die Anfragen an den Container auf Port **80** leiten und den Host-Header
erhalten. TLS lässt sich davor über einen Reverse Proxy bereitstellen.

## RabbitMQ-Worker

Der Worker lauscht auf der Queue `leuffen.jekyll.v1.requests` am Topic-Exchange
`leuffen.jekyll.v1` mit Routing-Key `rpc.request`. Ein Build-Auftrag
enthält die relativen Verzeichnisnamen `source` und `target`; die Antwort
enthält das Ergebnis und die Jekyll-Ausgabe.

Die Verbindung wird als einzige URI mit `CONF_AMQ_CONNECTION` gesetzt:

```text
rabbitmq://user:passwort@rabbitmq:5672/
```

Der Pfad hinter dem Host bezeichnet den RabbitMQ-VHost (hier `/`).
`config.php.dist` enthält die Standardwerte; Kickstart erzeugt daraus
`config.php`, die der Worker einliest.

## Docker-Stack-Beispiel

```yaml
services:
  rabbitmq:
    image: rabbitmq:4
    environment:
      RABBITMQ_DEFAULT_USER: runner
      RABBITMQ_DEFAULT_PASS: runner

  jekyll-runner:
    image: ghcr.io/leuffen/leuffen-pagebuilder-jekyll-runner:unstable
    ports:
      - "80:80"
    environment:
      CONF_AMQ_CONNECTION: rabbitmq://runner:runner@rabbitmq:5672/
      CDN_HOST_PATTERN: "*.preview.example.com"
    volumes:
      - /srv/pagebuilder/source:/source:ro
      - /srv/pagebuilder/target:/target
```

Für lokale Aufrufe stattdessen `CDN_HOST_PATTERN: "*.localhost"` verwenden.
Auf dem Host muss `/srv/pagebuilder/target` für den Container-Worker
beschreibbar sein. Das Image startet **Apache und Worker gemeinsam**.

## PHP-Demo-Client

Der eigenständig verwendbare Client liegt in
[`docs/PageBuilderJekyllRunnerClient.php`](docs/PageBuilderJekyllRunnerClient.php).
In der PHP-Anwendung wird `php-amqplib/php-amqplib:^3.7` benötigt:

```bash
composer require php-amqplib/php-amqplib:^3.7
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/PageBuilderJekyllRunnerClient.php';

$client = new PageBuilderJekyllRunnerClient(
    'rabbitmq://runner:runner@rabbitmq:5672/'
);

try {
    $result = $client->build('kunde/docs', 'kunde');
    echo $result['stdout'];
} catch (PageBuilderJekyllBuildException $e) {
    echo $e->getMessage(); // Einschließlich Jekyll stdout/stderr
} catch (PageBuilderJekyllConnectionException $e) {
    echo 'RabbitMQ-Fehler: ' . $e->getMessage();
}
```

Die Pfade im `build()`-Aufruf sind immer relativ zu `/source` bzw. `/target`.

## Protokoll: Request → Build → Response

1. Der PHP-Client sendet einen `build`-Request mit `source`, `target`,
   `correlation_id` und `reply_to` an RabbitMQ.
2. Ein freier Worker verarbeitet den Request **direkt**, ohne zusätzlichen
   Start-Delay. Ist er beschäftigt, wartet der Request in der Queue; pro
   Worker läuft höchstens ein Build gleichzeitig.
3. Der Worker führt Jekyll aus und sendet das Ergebnis (`ok: true`) oder
   den Fehler (`ok: false` mit Jekyll-Ausgabe) an die Reply-Queue.
   Nach Bestätigung des Response-Publish bestätigt er den Request (ACK).

**Timeouts:** `JEKYLL_BUILD_TIMEOUT=300` Sekunden pro Build-/Snapshot-Prozess;
der PHP-Client wartet standardmäßig höchstens **360 Sekunden insgesamt**,
einschließlich Queue-Wartezeit (`build($source, $target, $timeout)`).
Die Broker-Bestätigung der Response hat ein Limit von **10 Sekunden**.
Bei Broker-Ausfall versucht der Worker die Verbindung nach **30 Sekunden**
erneut. Ein Client-Timeout bricht einen bereits laufenden Build nicht ab.
