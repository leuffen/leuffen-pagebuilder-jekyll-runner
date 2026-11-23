<?php

declare(strict_types=1);

use Leuffen\JekyllRunner\BuildRunner;
use Leuffen\JekyllRunner\Config;
use Leuffen\JekyllRunner\Worker;
use Phore\Log\Driver\PhoreConsoleLoggerDriver;
use Phore\Log\PhoreLogger;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config.php';

umask(0022);
$log = new PhoreLogger(new PhoreConsoleLoggerDriver('php://stdout', false));
foreach (['JEKYLL_BUILD_TIMEOUT', 'JEKYLL_MAX_OUTPUT_BYTES'] as $setting) {
    if (!preg_match('/^[1-9][0-9]*$/D', (string) constant($setting))) {
        throw new InvalidArgumentException("$setting must be a positive integer");
    }
}

$config = new Config(
    sourceRoot: JEKYLL_SOURCE_ROOT,
    targetRoot: JEKYLL_TARGET_ROOT,
    buildRoot: JEKYLL_BUILD_ROOT,
    buildTimeout: (int) JEKYLL_BUILD_TIMEOUT,
    maxOutputBytes: (int) JEKYLL_MAX_OUTPUT_BYTES,
    amqpConnection: CONF_AMQ_CONNECTION,
);
(new Worker($config, new BuildRunner($config), $log))->run();
