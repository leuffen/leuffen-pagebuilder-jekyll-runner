<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tests/messages.php CHANNEL < message-body\n");
    exit(64);
}

try {
    $body = stream_get_contents(STDIN);
    if ($body === false) {
        throw new RuntimeException('Cannot read message body from stdin');
    }
    $client = new PageBuilderJekyllRunnerClient(
        getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://runner:runner@127.0.0.1:5672/',
    );
    $result = $client->sendWebSocketMessage($argv[1], $body);
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
