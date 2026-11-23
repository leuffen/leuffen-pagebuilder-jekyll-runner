<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;

$client = new PageBuilderJekyllRunnerClient(getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://runner:runner@127.0.0.1:5672/');
$result = $client->build('demo', 'demo');
if ($result['exitCode'] !== 0) {
    throw new RuntimeException('Build failed');
}
