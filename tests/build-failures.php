<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Leuffen\PagebuilderPreview\Client\PageBuilderJekyllRunnerClient;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllBuildException;
use Leuffen\PagebuilderPreview\Client\Exception\PageBuilderJekyllConnectionException;

$client = new PageBuilderJekyllRunnerClient(getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://runner:runner@127.0.0.1:5672/');

try {
    $client->build('demo', 'demo');
    throw new LogicException('Missing build exception');
} catch (PageBuilderJekyllBuildException $e) {
    if ($e->errorCode !== 'BUILD_FAILED'
        || !str_contains($e->getMessage(), 'source: demo; target: demo')
        || !str_contains($e->getStderr(), 'definitely_not_a_liquid_tag')
        || !str_contains($e->getMessage(), $e->getStderr())
        || !array_key_exists('stdout', $e->details)
        || !array_key_exists('stderr', $e->details)) {
        throw $e;
    }
    $debug = $e->details['debugInfo'] ?? [];
    if (!is_int($debug['timingInfo']['jekyll'] ?? null) || $debug['timingInfo']['jekyll'] < 0
        || !is_array($debug['warnings'] ?? null) || !is_bool($debug['truncated'] ?? null)) {
        throw new RuntimeException('Missing or invalid builder debug metadata in RPC error', 0, $e);
    }
}

try {
    $client->build('missing-source', 'missing-target');
    throw new LogicException('Missing directory exception');
} catch (PageBuilderJekyllBuildException $e) {
    if (!str_contains($e->getMessage(), 'missing-source')
        || !str_contains($e->getMessage(), 'missing-target')) {
        throw $e;
    }
}

try {
    new PageBuilderJekyllRunnerClient('not-a-uri');
    throw new LogicException('Missing connection exception');
} catch (PageBuilderJekyllConnectionException $e) {
    if (!str_contains($e->getMessage(), 'URI')) {
        throw $e;
    }
}
