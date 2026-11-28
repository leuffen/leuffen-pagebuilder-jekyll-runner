# Leuffen PageBuilder Preview

This repository provides **two complementary components**:

| Component | Usage | Location |
| --- | --- | --- |
| **Docker container (Preview Worker + CDN)** | Consumes RabbitMQ requests, calls the external Jekyll Builder, serves generated sites via Nginx, and broadcasts browser messages with Nchan | `container/` |
| **PHP Composer client** | Sends build and browser-message requests through RabbitMQ and receives results or typed errors | Root `composer.json` and `src/` |

The PHP client runs in the calling application; **it does not build Jekyll
sites locally** and does not need Nginx or the external HTTP Builder client.
The Docker container includes the RabbitMQ worker and Nginx/Nchan on port 80,
but delegates Jekyll compilation to the
[external Jekyll Builder](https://github.com/leuffen/leuffen-jekyll-builder).
Nchan distributes messages between Nginx workers in this one container.
**Redis is not used.**

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
5. Nginx immediately serves the published files.

The worker keeps per-target locks to avoid concurrent replacement of
overlapping sites. An unsuccessful build never replaces the existing site.

- `/source`: read-only mount with project source files
- `/target`: writable volume for published sites and temporary staging

## Environment

| Variable | Default | Meaning |
| --- | --- | --- |
| `CONF_AMQ_CONNECTION` | `rabbitmq://runner:runner@rabbitmq:5672/` | AMQP URI with vhost |
| `PREVIEW_SOURCE_ROOT` | `/source` | Read-only source root |
| `PREVIEW_TARGET_ROOT` | `/target` | Worker target root; Nginx serves the standard `/target` mount |
| `PREVIEW_BUILDER_URL` | `http://jekyll-builder` | External HTTP builder URL |
| `CDN_HOST_PATTERN` | `*.localhost` | Accepted hostname pattern; whitespace-separated patterns and the existing `*`/`?` wildcards are supported |
| `PREVIEW_RELOAD_JS_URL` | empty (disabled) | Absolute HTTP(S) URL of an externally hosted reload script |

Kickstart renders `config.php` from `container/config.php.dist`.

## Nginx CDN

The Nginx template at `container/.kicker/conf/etc/nginx/nginx.conf` uses
`CDN_HOST_PATTERN` and maps the first hostname label to `/target/<label>`,
preserving the former Apache `VirtualDocumentRoot /target/%1` mapping:

| Host pattern | Request | Directory |
| --- | --- | --- |
| `*.localhost` | `http://kunde.localhost/` | `/target/kunde` |
| `*.preview.example.com` | `http://kunde.preview.example.com/` | `/target/kunde` |

Provide wildcard DNS (or a reverse proxy) retaining the Host header.
Terminate TLS at a reverse proxy if required.
Unknown hosts, hidden paths, symlinks, and static methods other than GET/HEAD
remain forbidden. All responses retain `Cache-Control: no-store, no-cache,
must-revalidate, max-age=0`, `Pragma: no-cache`, and `Expires: 0`, including
redirects and errors. The target volume and existing preview URLs are unchanged.

Kickstart renders the Nginx configuration before its start hook runs `nginx -t`.
The image installs the distribution's `nginx` and `libnginx-mod-nchan` packages
and replaces the inherited Apache start/stop hooks. There is one webserver and
the existing PHP RabbitMQ worker; no additional messaging daemon is needed.

### GitHub Pages-compatible Jekyll routing

**Compatibility requirement:** Nginx must resolve published Jekyll URLs like
GitHub Pages so that preview links behave the same as on the deployed site.
Preserve this routing contract when changing Nginx modules, virtual hosts or
output filters. Jekyll writes static output files; both flat HTML files and
directory-style permalinks must work without changing the published files.
The URL is resolved relative to the current project's virtual document root.
This is a static URL-routing contract, not a promise to replicate every GitHub
Pages HTTP header, CDN feature or error-page design.

| Generated file | Request path | Expected result |
| --- | --- | --- |
| `index.html` | `/` | 200, index page |
| `online-termin.html` | `/online-termin.html` or `/online-termin` | 200, same HTML, no redirect |
| `online-termin.html` | `/online-termin/` | 200, same HTML (preview-specific fallback) |
| `online-termin.html` and `online-termin.css` | `/online-termin` | 200, HTML, independent of Accept |
| `online-termin` and `online-termin.html` | `/online-termin` | 200, exact file takes precedence |
| `leistungen.html` and `leistungen/beratung.html` | `/leistungen` or `/leistungen/` | 200, `leistungen.html`, no redirect |
| `leistungen.html` and `leistungen/index.html` | `/leistungen` | 200, `leistungen.html`, no redirect |
| `leistungen.html` and `leistungen/index.html` | `/leistungen/` | 200, `leistungen/index.html` |
| `leistungen/beratung.html` | `/leistungen/beratung` | 200, nested HTML |
| `kontakt/index.html` | `/kontakt/` | 200, directory index |
| `kontakt/index.html` | `/kontakt` | 301 to `/kontakt/` |
| `assets/site.css` | `/assets/site.css` | 200, unchanged asset |
| `404.html`, no matching requested file | `/missing` or `/nested/missing` | 404, project's custom error page |
| `404.html` | `/404.html` | 200, direct file request |
| No matching file or directory and no `404.html` | `/missing` | 404, Nginx fallback error page |
| `index.htm` only, or an empty directory | `/directory/` | 403; no directory listing and no additional index filename |

The only directory index is `index.html`. There is no content negotiation:
an Accept header cannot select CSS instead of the matching HTML file. For a
URL without a trailing slash, an exact regular file takes precedence,
followed by the corresponding `.html` file. A directory
with the same name must not suppress this HTML fallback: Jekyll can produce
both `leistungen.html` and a `leistungen/` directory containing subpages.
Allowing the directory redirect to run first would send the browser
to a directory without an index and can produce a forbidden or missing-page
response. Only when no flat HTML match exists does normal directory handling
apply, including a 301 redirect to the trailing slash. Redirects retain the
query string and use a relative Location, preserving the browser's HTTPS
scheme and external port behind a reverse proxy.

For URLs ending in a slash, a real `index.html` takes precedence. Otherwise,
if the sibling `.html` exists, serve it internally without redirecting.
This is an intentional preview-specific extension: the public GitHub Pages
test site returns 404 for a trailing slash appended to a flat HTML URL.
The fallback also avoids redirect loops when a browser has cached an old
directory redirect. Prefer slashless links to flat HTML files because relative
asset URLs resolve differently with a trailing slash. The exact collision
precedence on GitHub Pages has not been independently verified; the table
defines the required Preview behavior.

Both HTML fallbacks apply only to extensionless final path segments and only
when the target HTML file exists. The Nginx template uses a URI map and
`try_files`, followed by native static handling for directory redirects,
access denials and missing files. The existing access restrictions and optional
HTML reload filter remain in force for normal pages.

#### Custom 404 pages

The project virtual host sets `error_page 404 /404.html`. GitHub Pages
supports a custom `404.html` at the published site root (Jekyll can also
generate it from a `404.md` with the permalink `/404.html`). Nginx resolves
this local URL through the same project's root, preserving
the original request URL and HTTP 404 status. Do not redirect to another host
or serve the homepage with status 200 for missing URLs.

If the site has no `404.html`, Nginx returns its built-in 404 response. Its
appearance differs from Apache's and from the default GitHub Pages error page;
the routing and status contract stays the same. Access denials remain 403;
the error document does not bypass the host or hidden-file restrictions.
Preview reload injection is intended for normal HTML pages.

References:
[GitHub Pages custom 404 pages](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-custom-404-page-for-your-github-pages-site),
[GitHub Pages extensionless URL demonstration](https://rsp.github.io/gh-pages-no-extension/test1),
[Jekyll permalinks](https://jekyllrb.com/docs/permalinks/),
[Nginx try_files](https://nginx.org/en/docs/http/ngx_http_core_module.html#try_files),
[Nginx error_page](https://nginx.org/en/docs/http/ngx_http_core_module.html#error_page).

### Optional preview reload script

Set `PREVIEW_RELOAD_JS_URL` to the URL string that should appear in the
script tag of the HTML response, for example:

```yaml
environment:
  PREVIEW_RELOAD_JS_URL: "https://preview-tools.example.com/reload.js"
```

Nginx injects the following tag immediately before the closing `</body>`
tag in `text/html` responses (case-insensitive):

```html
<script src="https://preview-tools.example.com/reload.js"></script>
```

The setting is a URL string, not JavaScript source or a local file path.
The browser loads the script directly from the specified server. The preview
container does not serve or proxy the script. Use HTTPS for HTTPS previews
and ensure the external script is trusted and allowed by the site's Content
Security Policy.

Kickstart renders the Nginx configuration on container startup. If the
variable is unset or empty, no substitution filter is configured. Static
files on the target volume and non-HTML responses are unchanged. Recreate
the container to change the setting.
The substitution is limited to static HTML locations; it never rewrites
messages delivered by Nchan. Existing browser-to-browser reload scripts can
use the new subscription endpoint without changing published Jekyll files.

## Browser messages

Use the same `$client` from the PHP quick start to send a message after a
successful build, or independently for navigation and other interactions:

```php
// Allocate once per project/session and provide this same value to its browsers.
$channel = bin2hex(random_bytes(32));

$client->build('kunde/docs', 'kunde');
$result = $client->sendWebSocketMessage(
    $channel,
    json_encode(['type' => 'reload'], JSON_THROW_ON_ERROR),
);
// $result === ['channel' => $channel, 'status' => 'published']

// The same API also transports application messages without triggering a build.
$client->sendWebSocketMessage(
    $channel,
    json_encode(['type' => 'navigate', 'path' => '/kontakt/'], JSON_THROW_ON_ERROR),
);
```

The browser must already be subscribed when the event is sent. Publishing a
build does not automatically publish an event: the application chooses the
channel, message body and timing. The existing external reload script can
forward received messages to its current reload or interaction handlers.

`sendWebSocketMessage(string $channel, string $body, int $timeout = 360)` sends
the body through RabbitMQ to the existing Preview worker. The worker posts
that **unchanged UTF-8 string** to the local Nchan publisher using PHP cURL.
The body is not a shell command and is not interpreted or wrapped by the worker.
Encode structured data with `json_encode()` yourself. The complete JSON RPC
envelope, including escaped characters and metadata, must fit in **65,536
bytes**; oversized requests are rejected before opening a broker connection.

The method name also applies to SSE and Long Polling: the channel carries the
same body regardless of the subscriber's chosen transport. A successful result
means that Nchan accepted the POST (HTTP 201 with subscribers, or 202 without
subscribers), not that every browser processed it. Invalid channel/body and
remote publish failures throw `Client\Exception\PageBuilderMessageException`,
with `errorCode`, `channel` and `details`. Timeouts, oversized envelopes and
transport/protocol errors use `PageBuilderJekyllConnectionException`.
WebSocket and Long Polling preserve the body bytes. EventSource uses SSE text
framing and line-ending normalization; JSON messages work with all three.

### Individual channel names

Channel names are **individual to your project/session**. They consist of
1–128 ASCII letters, digits, underscores or hyphens and are case-sensitive.
One call publishes to one channel. The name is not automatically derived from
the hostname, build target, user or browser; all vhosts share the same channel
namespace within this Nginx instance. Generate a random value once and share
it only with the intended browsers. Two clients using the same channel name
subscribe to the same broadcast, even when their preview hostnames differ.

The public endpoint is read-only and has no user authentication: anyone who
knows the channel can subscribe. A unique name avoids accidental collisions;
it is not a replacement for authorization for confidential data. Browser
WebSocket data frames cannot publish messages. Publishing requires the
existing private RabbitMQ access; the HTTP trigger is bound exclusively to
`127.0.0.1:8081` inside the Preview container and is not exposed on port 80.

### Reading in the browser

The read endpoint is `/__preview/events?channel=<channel>` on any accepted
preview hostname. Supply the channel created by the application; do not create
a different random value in each browser.

WebSocket example, using that application's `channel` variable:

```javascript
const endpoint = new URL('/__preview/events', window.location.href);
endpoint.protocol = endpoint.protocol === 'https:' ? 'wss:' : 'ws:';
endpoint.searchParams.set('channel', channel);

const messages = new WebSocket(endpoint);
messages.addEventListener('message', event => {
    const data = JSON.parse(event.data);
    if (data.type === 'reload') window.location.reload();
    if (data.type === 'navigate') window.location.assign(data.path);
});
```

Alternatively, SSE keeps a read connection open and handles reconnection through
the browser's `EventSource` API. This replaces the WebSocket example:

```javascript
const events = new EventSource('/__preview/events?channel=' + encodeURIComponent(channel));
events.onmessage = event => {
    const data = JSON.parse(event.data);
    if (data.type === 'reload') window.location.reload();
};
```

An ordinary HTTP GET without an EventSource Accept header uses Long Polling;
one message ends the response. Issue another GET to wait for the next event:

```bash
curl --no-buffer 'http://kunde.localhost/__preview/events?channel=YOUR_CHANNEL'
```

New subscriptions wait for the next message (`newest`), so opening a page does
not immediately replay an old reload event. WebSocket and SSE remain open
across messages, with 30-second keepalives. A WebSocket application must handle
reconnection itself. Keep normal Nchan message buffering enabled to allow
delivery during bursts; no Redis backend is configured. These transient
messages are not a durable event log, and reconnects or container restarts can
lose events. RabbitMQ redelivery can also publish a message twice; include an
application event ID or revision when duplicate handling matters.

### Connection limit and reverse proxies

At most **five simultaneous subscriber connections per IP** are allowed across
all preview hostnames and Nginx workers. Further subscriptions return HTTP 429.
This applies only to `/__preview/events`, not to normal pages or assets. Tabs
and users behind the same NAT share the limit. HTTP/2 streams count as separate
active requests at Nginx.

Nginx uses the actual TCP peer address by default. When a reverse proxy is in
front, configure trusted real-IP forwarding in your deployment; otherwise all
browsers behind that proxy share its five connections. For example, add this
inside `http {}` **only when `10.0.0.10` is your trusted proxy**:

```nginx
set_real_ip_from 10.0.0.10;
real_ip_header X-Forwarded-For;
real_ip_recursive on;
```

Do not trust client-supplied forwarding headers from arbitrary IP addresses.
The proxy must preserve Host, forward WebSocket Upgrade/Connection headers,
disable buffering for SSE, and allow streaming responses long enough for the
keepalives. TLS/WSS termination remains the reverse proxy's responsibility.

### Single-instance operation

Run **one Preview container for this RabbitMQ RPC queue**. Nchan broadcasts
through shared memory to the Nginx workers in that container. Multiple Preview
containers consuming the same queue would compete for the publish command;
only the selected container's browsers would receive it. This configuration
intentionally does not add cross-instance distribution or Redis.

Builds and message commands share the existing sequential PHP worker. A publish
waits behind an active build; the client's timeout includes that queue wait.
Once handled, the local HTTP request has a one-second connection timeout and
a five-second total timeout. HTTP failures produce `PUBLISH_ERROR`, never a
false success. This keeps the runtime small and is suitable when messages
follow completed builds or occasional interactions tolerate that wait.
A client timeout stops waiting but does not cancel an already queued command;
that message may still be published later.

References: [Nchan configuration and protocols](https://nchan.io/),
[Nginx connection limits](https://nginx.org/en/docs/http/ngx_http_limit_conn_module.html),
[Nginx real-IP configuration](https://nginx.org/en/docs/http/ngx_http_realip_module.html).

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
      PREVIEW_RELOAD_JS_URL: "https://preview-tools.example.com/reload.js"
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
key `rpc.request`. Method `build` accepts `params.source` and `params.target`
relative to the mounted roots. Method `publish` accepts `params.channel` and
`params.body` as strings. Each worker handles one command at a time and
acknowledges the request after publishing the response. Example publish request:

```json
{"version":1,"id":"0123456789abcdef","method":"publish","params":{"channel":"preview-session-42","body":"{\"type\":\"reload\"}"}}
```

The matching AMQP `correlation_id` and an existing `reply_to` queue are required,
as for builds. Successful publication returns
`{"version":1,"id":"0123456789abcdef","ok":true,"result":{"channel":"preview-session-42","status":"published"}}`.
An invalid publish request returns `INVALID_REQUEST`; an unsuccessful local
HTTP publication returns `PUBLISH_ERROR`. Existing build responses are unchanged.

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
