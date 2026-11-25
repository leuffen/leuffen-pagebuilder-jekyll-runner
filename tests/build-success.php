<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;

$client = new PageBuilderJekyllRunnerClient(getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://runner:runner@127.0.0.1:5672/');
$result = $client->build('demo', 'demo');
if ($result['exitCode'] !== 0) {
    throw new RuntimeException('Build failed');
}

$debug = $result['debugInfo'] ?? [];
if (!is_int($debug['timingInfo']['jekyll'] ?? null) || $debug['timingInfo']['jekyll'] < 0
    || !is_array($debug['warnings'] ?? null) || !is_bool($debug['truncated'] ?? null)) {
    throw new RuntimeException('Missing or invalid builder debug metadata in RPC result');
}
