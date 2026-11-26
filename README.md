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
| `PREVIEW_RELOAD_JS_URL` | empty (disabled) | Absolute HTTP(S) URL of an externally hosted reload script |

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

### GitHub Pages-compatible Jekyll routing

**Compatibility requirement:** Apache must resolve published Jekyll URLs like
GitHub Pages so that preview links behave the same as on the deployed site.
Preserve this routing contract when changing Apache modules, virtual hosts or
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
| No matching file or directory and no `404.html` | `/missing` | 404, Apache fallback error page |

Keep `DirectoryIndex index.html` and the default `DirectorySlash On` behavior
of `mod_dir`. Disable `MultiViews` in the existing `<Directory /target>`
options: content negotiation can select a different extension (such as CSS)
or return 406 depending on the request's Accept header. Instead, use
`mod_rewrite` in the project virtual host after its existing host validation:

```apache
    # Flat HTML wins over a same-named directory for slashless URLs.
    RewriteCond %{IS_SUBREQ} =false
    RewriteCond %{LA-U:REQUEST_FILENAME} ^(/target/.*)$
    RewriteCond %1 !-f
    RewriteCond %1.html -f
    RewriteRule ^(/(?:.*/)?[^/.]+)$ $1.html [PT,L]

    # A slash prefers a real directory index; otherwise serve sibling HTML.
    RewriteCond %{IS_SUBREQ} =false
    RewriteCond %{LA-U:REQUEST_FILENAME} ^(/target/.*[^/])/?$
    RewriteCond %1/index.html !-f
    RewriteCond %1.html -f
    RewriteRule ^(/(?:.*/)?[^/.]+)/$ $1.html [PT,L]
```

The URL-based lookahead resolves the filesystem path through
`VirtualDocumentRoot /target/%1`; the ordinary `REQUEST_FILENAME` is not yet
mapped at this stage. For a URL without a trailing slash, an exact regular
file takes precedence, followed by the corresponding `.html` file. A directory
with the same name must not suppress this HTML fallback: Jekyll can produce
both `leistungen.html` and a `leistungen/` directory containing subpages.
Allowing Apache's directory redirect to run first would send the browser
to a directory without an index and can produce a forbidden or missing-page
response. Only when no flat HTML match exists does normal directory handling
apply, including the redirect to the trailing slash for directory indexes.

For URLs ending in a slash, a real `index.html` takes precedence. Otherwise,
if the sibling `.html` exists, serve it internally without redirecting.
This is an intentional preview-specific extension: the public GitHub Pages
test site returns 404 for a trailing slash appended to a flat HTML URL.
The fallback also avoids redirect loops when a browser has cached an old
directory redirect. Prefer slashless links to flat HTML files because relative
asset URLs resolve differently with a trailing slash. The exact collision
precedence on GitHub Pages has not been independently verified; the table
defines the required Preview behavior.

Both rewrites apply only to extensionless final path segments and only when
the target HTML file exists. Subrequests are excluded to prevent recursive
lookups. The existing access restrictions and optional HTML reload filter
remain in force for normal pages.

#### Custom 404 pages

Set `ErrorDocument 404 /404.html` in the project virtual host. GitHub Pages
supports a custom `404.html` at the published site root (Jekyll can also
generate it from a `404.md` with the permalink `/404.html`). Apache resolves
this local URL through the same project's `VirtualDocumentRoot`, preserving
the original request URL and HTTP 404 status. Do not redirect to another host
or serve the homepage with status 200 for missing URLs.

If the site has no `404.html`, Apache returns its built-in 404 response,
including a note that the custom error document could not be found. Its
appearance differs from the default GitHub Pages error page. Access denials
remain 403; the error document does not bypass the existing host or hidden-file
restrictions. Apache's HTML substitution filter is not guaranteed to run on
error responses; preview reload injection is intended for normal HTML pages.

References:
[GitHub Pages custom 404 pages](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-custom-404-page-for-your-github-pages-site),
[GitHub Pages extensionless URL demonstration](https://rsp.github.io/gh-pages-no-extension/test1),
[Apache ErrorDocument](https://httpd.apache.org/docs/2.4/mod/core.html#errordocument), [Jekyll permalinks](https://jekyllrb.com/docs/permalinks/),
[Apache mod_dir](https://httpd.apache.org/docs/2.4/mod/mod_dir.html),
[Apache mod_rewrite](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html),
[Apache MultiViews](https://httpd.apache.org/docs/2.4/mod/mod_negotiation.html#multiviews).

### Optional preview reload script

Set `PREVIEW_RELOAD_JS_URL` to the URL string that should appear in the
script tag of the HTML response, for example:

```yaml
environment:
  PREVIEW_RELOAD_JS_URL: "https://preview-tools.example.com/reload.js"
```

Apache injects the following tag immediately before the closing `</body>`
tag in `text/html` responses (case-insensitive):

```html
<script src="https://preview-tools.example.com/reload.js"></script>
```

The setting is a URL string, not JavaScript source or a local file path.
The browser loads the script directly from the specified server. The preview
container does not serve or proxy the script. Use HTTPS for HTTPS previews
and ensure the external script is trusted and allowed by the site's Content
Security Policy.

Kickstart renders the Apache virtual host on container startup. If the
variable is unset or empty, no substitution filter is configured. Static
files on the target volume and non-HTML responses are unchanged. Recreate
the container to change the setting.

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
