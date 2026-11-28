<?php

declare(strict_types=1);

use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllConnectionException;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderMessageException;
use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;
use Leuffen\PagebuilderPreview\NchanPublisher;

require dirname(__DIR__) . '/container/src/NchanPublisher.php';
require dirname(__DIR__) . '/src/Exception/PageBuilderJekyllConnectionException.php';
require dirname(__DIR__) . '/src/Exception/PageBuilderMessageException.php';
require dirname(__DIR__) . '/src/PageBuilderJekyllRunnerClient.php';

$expectException = static function (callable $action, string $type, string $message): void {
    try {
        $action();
    } catch (Throwable $error) {
        if (!$error instanceof $type || !str_contains($error->getMessage(), $message)) {
            throw new RuntimeException('Unexpected publication error: ' . $error->getMessage(), 0, $error);
        }
        return;
    }
    throw new RuntimeException("Expected $type containing $message");
};

// Lokale Eingabefehler müssen ohne Broker-Verbindung erkennbar sein.
$client = new PageBuilderJekyllRunnerClient('rabbitmq://test:test@127.0.0.1:1/');
foreach (['', 'a,b', '../other', "line\nbreak", str_repeat('a', 129)] as $channel) {
    $expectException(
        static fn () => $client->sendWebSocketMessage($channel, 'body'),
        PageBuilderMessageException::class,
        'INVALID_REQUEST',
    );
}
$expectException(
    static fn () => $client->sendWebSocketMessage('valid', "\xff"),
    PageBuilderMessageException::class,
    'UTF-8',
);
$expectException(
    static fn () => $client->sendWebSocketMessage('valid', str_repeat("\0", 12000)),
    PageBuilderJekyllConnectionException::class,
    'exceeds 64 KiB',
);
$expectException(
    static fn () => $client->sendWebSocketMessage('valid', 'body', 0),
    PageBuilderJekyllConnectionException::class,
    'Invalid timeout',
);

// Ein echter HTTP-Server prüft Raw-Body, Header und Annahme-/Fehlerstatus.
$directory = sys_get_temp_dir() . '/preview-publisher-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException("Cannot create publisher test directory $directory");
}
$server = null;
$proxy = getenv('http_proxy');
$noProxy = getenv('no_proxy');
try {
    $router = <<<'PHP'
<?php
$channel = $_GET['channel'] ?? '';
$record = ['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? '', 'body' => file_get_contents('php://input')];
file_put_contents(__DIR__ . '/requests.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$status = preg_match('/^status_(\d{3})$/D', $channel, $match) ? (int) $match[1] : 201;
http_response_code($status);
if ($status === 302) {
    header('Location: /publish?channel=status_201');
}
echo '{}';
PHP;
    if (file_put_contents($directory . '/router.php', $router) === false) {
        throw new RuntimeException("Cannot write HTTP test router in $directory");
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $reason);
    if ($socket === false) {
        throw new RuntimeException("Cannot reserve HTTP test port: $reason ($code)");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false) {
        throw new RuntimeException('Cannot read reserved HTTP test address');
    }
    $server = proc_open(
        [PHP_BINARY, '-S', $address, $directory . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
        $pipes,
        $directory,
    );
    if (!is_resource($server)) {
        throw new RuntimeException("Cannot start PHP HTTP test server at $address");
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        $probe = @stream_socket_client('tcp://' . $address, $code, $reason, 0.1);
        if ($probe !== false) {
            fclose($probe);
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("HTTP test server at $address did not start: $reason ($code)");
        }
        usleep(10000);
    } while (true);

    // Proxy-Umgebungsvariablen dürfen den lokalen Trigger nicht umleiten.
    putenv('http_proxy=http://127.0.0.1:1');
    putenv('no_proxy=');
    $publisher = new NchanPublisher('http://' . $address . '/publish');
    $body = "{\"type\":\"reload\",\"text\":\"Grüße & + %\"}\nsecond line";
    foreach ([201, 202] as $status) {
        $channel = 'status_' . $status;
        $result = $publisher->publish($channel, $body);
        if ($result !== ['channel' => $channel, 'status' => 'published']) {
            throw new RuntimeException("Unexpected result for Nchan HTTP $status");
        }
    }
    $records = file($directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
    if ($records === false || count($records) !== 2) {
        throw new RuntimeException('Expected exactly two successful HTTP publications');
    }
    foreach ($records as $index => $record) {
        $request = json_decode($record, true, 16, JSON_THROW_ON_ERROR);
        if ($request['method'] !== 'POST' || $request['body'] !== $body
            || $request['contentType'] !== 'text/plain; charset=utf-8'
            || $request['uri'] !== '/publish?channel=status_' . (201 + $index)) {
            throw new RuntimeException('Publisher changed the raw body, HTTP method or channel');
        }
    }

    // Auch 200/204 sind keine Nchan-Publish-Bestätigung; Redirects nicht verfolgen.
    foreach ([200, 204, 302, 400, 503] as $status) {
        $expectException(
            static fn () => $publisher->publish('status_' . $status, $body),
            RuntimeException::class,
            'HTTP ' . $status,
        );
    }
    foreach (['', 'a,b', '../other', str_repeat('a', 129)] as $channel) {
        $expectException(
            static fn () => $publisher->publish($channel, $body),
            InvalidArgumentException::class,
            'Invalid Nchan channel',
        );
    }
    $records = file($directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
    if ($records === false || count($records) !== 7) {
        throw new RuntimeException('Publisher followed a redirect or sent an invalid channel');
    }

    // Ein ausgefallener Publisher darf nicht als erfolgreiche Veröffentlichung gelten.
    proc_terminate($server);
    proc_close($server);
    $server = null;
    $expectException(
        static fn () => $publisher->publish('unavailable', $body),
        RuntimeException::class,
        'cURL',
    );
    echo "Publisher boundary tests passed\n";
} finally {
    putenv($proxy === false ? 'http_proxy' : 'http_proxy=' . $proxy);
    putenv($noProxy === false ? 'no_proxy' : 'no_proxy=' . $noProxy);
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
