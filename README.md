# Leuffen Page Builder Jekyll Runner

Dieser Container baut vorhandene Jekyll-Quellen auf Auftrag über RabbitMQ und
antwortet mit dem Build-Ergebnis oder den Jekyll-Fehlermeldungen. Zwei Volumes
enthalten Quellen und veröffentlichte Dateien. Ein optionaler Apache-Container
liefert die Ergebnisse je Subdomain als statische Website aus.

Die Grundlage ist der
[MICX Page Builder Runner](https://github.com/micx-io/micx-pagebuilder-runner).
Die neue Fassung übernimmt Jekyll und die Zuordnung der ersten Subdomain zum
Ausgabeverzeichnis. Die frühere Redis-Subscription wird durch RabbitMQ-RPC
ersetzt. Die MIT-Lizenz des Originals bleibt erhalten.

## 1. Ersten Build ausführen

Voraussetzung: Docker mit Compose. Der Beispiel-Stack enthält bereits RabbitMQ.
Die Zugangsdaten `runner`/`runner` sind lokale Beispielwerte. Die gesamte
AMQP-Verbindung wird mit `CONF_AMQ_CONNECTION` als URI konfiguriert.

```bash
mkdir -p data/source/demo/docs data/target
export RUNNER_UID="$(id -u)" RUNNER_GID="$(id -g)"
printf 'title: Meine Demo\n' > data/source/demo/docs/_config.yml
printf '%s\n' '---' '---' '<h1>{{ site.title }}</h1>' \
  > data/source/demo/docs/index.html
docker compose up --detach --build
docker compose logs --follow worker
```

Sobald `Jekyll worker ready` erscheint, den Log-Aufruf mit Ctrl+C beenden.
Der Worker läuft weiter. Diesen RPC-Auftrag in einem zweiten Aufruf senden:

Der PHP-Client kann aus `docs/PageBuilderJekyllRunnerClient.php` kopiert werden.
Er benötigt `php-amqplib/php-amqplib:^3.7` und Composer-Autoloading.
Mit der Runner-Container-Dependency kann er so aufgerufen werden:

```bash
docker compose exec -T worker php -r '
require "/app/vendor/autoload.php";
require "/app/docs/PageBuilderJekyllRunnerClient.php";
$client = new PageBuilderJekyllRunnerClient(getenv("CONF_AMQ_CONNECTION"));
try {
    $result = $client->build("demo/docs", "demo");
    echo $result["exitCode"], PHP_EOL;
} catch (PageBuilderJekyllBuildException $e) {
    echo $e->getMessage(), PHP_EOL; // Vollständige Ausgabe und Verzeichnisse.
    echo $e->getStdout(), PHP_EOL;
    echo $e->getStderr(), PHP_EOL;
} catch (PageBuilderJekyllConnectionException $e) {
    echo "RabbitMQ connection error: ", $e->getMessage(), PHP_EOL;
}
'
```

Erwartetes Ergebnis: `ok: true`, `result.exitCode: 0` und die Datei
`data/target/demo/index.html` mit `<h1>Meine Demo</h1>`. Derselbe Source kann mit
einem anderen `target` zusätzlich in ein anderes Ziel gebaut werden.

## 2. Verzeichnisse und Build-Verhalten

| Containerpfad | Inhalt | Mount |
|---|---|---|
| `/source` | Bereitgestellte Git-Checkouts und deren Jekyll-Quellen | read-only |
| `/target` | Ausgaben, nach Projekt/Domain unterteilt | read-write |
| `/tmp/jekyll-runner` | Source-Snapshots und Jekyll-Arbeitsdaten | temporär |

`source: "kunde/docs"` liest `/source/kunde/docs`; `target: "kunde"` schreibt
`/target/kunde`. Beide Werte müssen nichtleere relative Verzeichnispfade sein.
Absolute Pfade, `.`/`..`, versteckte Pfadbestandteile, Steuerzeichen, Backslashes
und Symlinks in diesen Pfaden werden abgelehnt. Source, Target und Build-Root
müssen getrennte, nicht ineinanderliegende Verzeichnisse sein.

Der Runner kopiert die gewählten Quellen in ein temporäres Arbeitsverzeichnis.
Git-Metadaten, `_site` und Jekyll-Caches werden ausgelassen. Er führt dort
`jekyll build` mit explizitem Source und Destination aus; das Quell-Volume wird
nicht verändert. Es werden keine Repositories geklont oder aktualisiert.
Ein zusätzliches persistentes Build-Volume wird nicht benötigt.

Die neue Ausgabe entsteht zunächst unter `/target/.runner-work/`. Erst nach
einem erfolgreichen Jekyll-Prozess wird das bisherige Ziel ersetzt. Nicht mehr
erzeugte Dateien verschwinden damit beim nächsten erfolgreichen Build.
Fehlgeschlagene Jekyll-Builds lassen das bisherige Ziel unverändert.

Beim Ersetzen werden Verzeichnisse innerhalb desselben Target-Volumes
umbenannt. Zwischen den beiden Renames kann kurzzeitig ein HTTP-404 auftreten;
dies ist kein unterbrechungsfreier Dateisystem-Snapshot. Nach einem harten
Prozessabbruch während der Veröffentlichung kann die vorherige Ausgabe unter
`/target/.runner-work/<job>/previous` liegen und muss gegebenenfalls
wiederhergestellt werden. Nach normalen Abläufen werden Arbeitsdaten entfernt.

Eine Dateisperre unter `/target/.runner-locks/` serialisiert Builds mit
demselben ersten Target-Pfadbestandteil, auch bei mehreren Workern und bei
verschachtelten Zielen. Die Lock-Dateien bleiben zur gemeinsamen Sperrung
bestehen. Das gemeinsame Target-Dateisystem muss `flock` unterstützen.

Im Image enthalten sind Jekyll, Sass, optional-front-matter, sitemap, SEO-tag,
feed und remote-theme. Eigene Ruby-Plugins in `_plugins` werden ausgeführt.
Ein Projekt-Gemfile führt keine automatische Installation aus; zusätzliche
Gems gehören in ein abgeleitetes Image. `JEKYLL_NO_BUNDLER_REQUIRE=true` nutzt
die im Image installierten Gems. Das bisherige CoffeeScript-Paket ist nicht
enthalten. Der Dienst ist für vertrauenswürdige Projektquellen und Plugins
vorgesehen; er stellt keine Sandbox für fremden Ruby-Code bereit.

## 3. RabbitMQ-Vertrag

| Einstellung | Standard |
|---|---|
| Exchange | `leuffen.jekyll.v1`, `topic`, durable |
| Queue | `leuffen.jekyll.v1.requests`, durable |
| Routing-Key | `rpc.request` |
| Consumer | manuelle ACKs, Prefetch `1` |
| Heartbeat | 60 Sekunden, auch während langer Builds |
| Nachricht | JSON, maximal 64 KiB |

Mehrere Worker konsumieren dieselbe Queue; jeder bearbeitet höchstens einen
Auftrag gleichzeitig. Ohne erreichbaren Broker bleibt der Worker aktiv,
meldet den Verbindungsfehler und versucht es nach jeweils 30 Sekunden erneut.

### Request

AMQP-Properties: `content_type=application/json`, `delivery_mode=2`,
`correlation_id=build-demo-0001` und `reply_to=<Replyqueue des Clients>`.
Die Replyqueue muss vor dem Publish existieren und bis zur Antwort offenbleiben.

```json
{
  "version": 1,
  "id": "build-demo-0001",
  "method": "build",
  "params": {
    "source": "demo/docs",
    "target": "demo"
  }
}
```

`id` muss mit `correlation_id` übereinstimmen und aus 8–128 Buchstaben,
Ziffern, Unterstrichen oder Bindestrichen bestehen. Der Reply wird über den
Standardexchange an `reply_to` gesendet und übernimmt `correlation_id`.
Das Envelope entspricht dem Grundmuster von MICX Sec VCS; dessen SDK ist
weiterhin auf VCS-Aufträge spezialisiert. Die spätere Einbindung in den
Leuffen Page Builder erfolgt über diesen Build-Vertrag.

### Erfolgreicher Build

```json
{
  "version": 1,
  "id": "build-demo-0001",
  "ok": true,
  "result": {
    "source": "demo/docs",
    "target": "demo",
    "exitCode": 0,
    "stdout": "Jekyll-Ausgabe ...",
    "stderr": "",
    "outputTruncated": false,
    "timedOut": false,
    "durationMs": 420
  }
}
```

Ausgabe und Dauer sind Beispielwerte. `ok: true` bedeutet, dass der Build
erfolgreich veröffentlicht wurde.

### Fehler

Bei einem Jekyll-Fehler ist `ok: false`; `error.code` ist `BUILD_FAILED`.
`error.details` enthält dieselben Ergebnisfelder wie oben, einschließlich
Exit-Code, stdout und stderr. Bei Überschreitung der Build-Zeitgrenze ist
`timedOut: true`; GNU timeout liefert dann normalerweise Exit-Code `124`.
Jekyll-stdout und -stderr werden bei einem fehlgeschlagenen Build
vollständig im RPC-Reply übertragen. `PageBuilderJekyllBuildException`
enthält beides in der Message und getrennt über `getStdout()` und
`getStderr()`; `details` enthält die übrigen Ergebnisfelder. Für
Connection-, Timeout- und RPC-Protokollfehler wird ausschließlich
`PageBuilderJekyllConnectionException` verwendet. Beide
Exception-Klassen stehen zusammen mit dem Client in derselben PHP-Datei.
Bei sehr großen Ausgaben können dadurch große RabbitMQ-Nachrichten
und ein entsprechend höherer Speicherverbrauch entstehen.

Weitere Fehlercodes: `INVALID_REQUEST` für ungültige Nachrichten/Pfade,
`UNKNOWN_METHOD` für andere Methoden als `build` und `BUILD_ERROR` für
Filesystem-, Snapshot- oder Publish-Probleme. Fehler enthalten eine konkrete
`error.message`; Requests ohne gültigen Rückkanal werden ohne Build verworfen.

Der Worker bestätigt einen Auftrag erst nach dem bestätigten Reply-Publish.
Bei Verbindungs- oder Worker-Ausfällen kann RabbitMQ einen Auftrag erneut
zustellen und der Build erneut laufen. Es gibt keine persistente
Request-Deduplizierung. Ist eine temporäre Replyqueue bereits verschwunden,
wird dies protokolliert und der erledigte Auftrag bestätigt, um endlose
Neubuilds zu vermeiden. Clients müssen ihre Replyqueue daher lange genug
offenhalten und ihren Timeout zur Warteschlangenlast passend wählen.

## 4. Optionales statisches Apache-CDN

Die folgende Ergänzung verwendet die bereits erzeugte Demo-Ausgabe:

```bash
docker compose --profile cdn up --detach --build
curl -H 'Host: demo.localhost' http://localhost:8080/
```

`http://demo.localhost:8080/` liefert `/target/demo/index.html` aus.
Allgemein gilt: `<projekt>.<domain>` zeigt auf `/target/<projekt>`.
Nested Targets sind über den entsprechenden URL-Pfad erreichbar.

Für eine eigene Domain:

```bash
CDN_HOST_PATTERN='*.preview.example.com' CDN_PORT=8080 \
  docker compose --profile cdn up --detach --build
```

Wildcard-DNS beziehungsweise ein Reverse Proxy muss die gewünschten Hostnamen
auf den CDN-Service leiten und den Host-Header erhalten. TLS kann der Reverse
Proxy übernehmen. `CDN_HOST_PATTERN` beschränkt die akzeptierten Hostnamen;
die erste Host-Komponente bestimmt weiterhin das Zielverzeichnis.

Das CDN-Image enthält ausschließlich Apache und bekommt nur das Target-Volume
read-only. Es lädt weder PHP noch CGI und erlaubt nur GET/HEAD. Verzeichnislisten,
`.htaccess`, Symlink-Zugriffe und versteckte Dateien/Verzeichnisse sind deaktiviert.
MultiViews ermöglicht die bestehende Auslieferung von HTML ohne `.html`-Suffix.

## 5. Eigenen RabbitMQ-Server und eigene Volumes verwenden

Ohne den lokalen Compose-Broker kann der Worker direkt gestartet werden:

```bash
docker build --target worker -t leuffen-jekyll-runner .
docker run --rm --init --name jekyll-runner \
  --read-only --tmpfs /tmp --stop-timeout 660 \
  --user "$(id -u):$(id -g)" \
  --mount type=bind,src=/srv/pagebuilder/sources,dst=/source,readonly \
  --mount type=bind,src=/srv/pagebuilder/output,dst=/target \
  --env CONF_AMQ_CONNECTION \
  leuffen-jekyll-runner
```

Die beiden Hostverzeichnisse müssen existieren. Der konfigurierte UID/GID
benötigt Leserechte für Sources und Schreibrechte für Target. Das Image
verwendet standardmäßig UID/GID `1000:1000`; Compose lässt dies über
`RUNNER_UID`/`RUNNER_GID` anpassen. Setze zuvor `CONF_AMQ_CONNECTION` auf die vollständige Broker-URI.

| Variable | Standard | Bedeutung |
|---|---|---|
| `CONF_AMQ_CONNECTION` | `rabbitmq://runner:runner@rabbitmq:5672/` | Broker, Port, User, Passwort und RabbitMQ-VHost |
| `JEKYLL_SOURCE_ROOT` | `/source` | Source-Mount |
| `JEKYLL_TARGET_ROOT` | `/target` | Target-Mount |
| `JEKYLL_BUILD_ROOT` | `/tmp/jekyll-runner` | Temporäre Arbeitsdaten |
| `JEKYLL_BUILD_TIMEOUT` | `300` | Sekunden je Jekyll-/Snapshot-Prozess |
| `JEKYLL_MAX_OUTPUT_BYTES` | `65536` | Limit für Snapshot-Diagnosen; Jekyll-Ausgaben werden vollständig übertragen |
| `CDN_HOST_PATTERN` | `*.localhost` | Zulässige CDN-Hosts |
| `CDN_PORT` | `8080` | Host-Port des Compose-CDNs |
| `RUNNER_STOP_GRACE_PERIOD` | `11m` | Compose-Wartezeit beim Stop |

Die URI akzeptiert `rabbitmq://` und `amqp://`. Der URI-Pfad steht für den
**RabbitMQ-VHost** (Standard `/`), nicht für den Exchange. Ein eigener VHost
kann etwa als `rabbitmq://user:pass@host:5672/pagebuilder` angegeben
werden. Credentials und VHost bei Sonderzeichen URL-encoden.
`amqps://` wird derzeit nicht unterstützt. Exchange
`leuffen.jekyll.v1`, Request-Queue `leuffen.jekyll.v1.requests` und
Routing-Key `rpc.request` sind fest. Der Worker versucht bei
Verbindungsverlust nach 30 Sekunden erneut, ohne gesonderte AMQ-ENV.

Der Client liefert bei Fehlern eine `RuntimeException` mit Fehlercode,
Source- und Target-Verzeichnis sowie Jekyll-stderr, sofern vorhanden.
Auch ungültige Verzeichnisse und Reply-Timeouts nennen beide Pfade.

SIGTERM/SIGINT stoppen die Annahme weiterer Aufträge; der aktuelle Build darf
auslaufen. Erhöhe beim Anheben der Build-Zeitgrenze auch die Stop-Wartezeit.
Im Beispiel-Stack besitzt RabbitMQ kein persistentes Volume; die beiden
Anwendungsverzeichnisse sind die einzigen persistenten Host-Mounts. Für
dauerhafte Queue-Daten einen entsprechend betriebenen externen Broker nutzen.

## Container-Basis und Jekyll

Der Worker basiert auf `ghcr.io/nfra-project/kickstart-flavor-php:8.4`,
wie im Leuffen PageBuilder. PHP CLI und die üblichen PHP-Module kommen aus dem Kickstart-Image.
Composer 2 wird wegen der Unterstützung aktueller GitHub-Tokenformate aus
dem offiziellen Composer-Image übernommen. Da der Runner PHP mindestens 8.5 benötigt,
prüft der Image-Build diese Runtime-Version ausdrücklich. Jekyll und die
zusätzlichen Ruby-Pakete werden über Ubuntu-`apt` installiert.

`jekyll-remote-theme` wird **nicht** installiert. Jekyll-Projekte müssen
Layouts und Themes lokal oder als anderweitig verfügbare Jekyll-Themes
bereitstellen. Das separate CDN verwendet `ubuntu:26.04` und installiert
Apache mit `apt`, ohne PHP. System-Pakete werden beim Image-Build
aktualisiert, nicht während der Container-Laufzeit.

## Container-Build und Veröffentlichung

Der Workflow baut bei Pull Requests beide Images und prüft Syntax,
RabbitMQ-Build/Fehlerantwort und statische Auslieferung. Nach einem erfolgreichen
Push auf `main` veröffentlicht er:

- `ghcr.io/leuffen/leuffen-pagebuilder-jekyll-runner:unstable`
- `ghcr.io/leuffen/leuffen-pagebuilder-jekyll-runner:cdn-unstable`

Vor dem ersten erfolgreichen `main`-Lauf sind die Images lokal zu bauen.
Die GHCR-Paket-Sichtbarkeit kann anschließend in GitHub auf öffentlich gestellt
werden, wenn anonymer Pull gewünscht ist. Versionsreleases werden nicht erzeugt.
Die PHP-Abhängigkeiten werden mit Composer im Container installiert;
`phore/log` verwendet derzeit dessen Development-Branch `dev-master`.
