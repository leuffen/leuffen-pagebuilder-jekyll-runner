<?php

declare(strict_types=1);

require '/opt/vendor/autoload.php';
require '/opt/docs/PageBuilderJekyllRunnerClient.php';

$client = new PageBuilderJekyllRunnerClient(getenv('CONF_AMQ_CONNECTION'));
$result = $client->build('demo', 'demo');
if ($result['exitCode'] !== 0) {
    throw new RuntimeException('Build failed');
}
