<?php

declare(strict_types=1);

use Leuffen\PagebuilderPreview\BuildRunner;
use Leuffen\PagebuilderPreview\Config;
use Leuffen\PagebuilderPreview\NchanPublisher;
use Leuffen\PagebuilderPreview\Worker;
use Phore\Log\Driver\PhoreConsoleLoggerDriver;
use Phore\Log\PhoreLogger;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config.php';

umask(0022);
$log = new PhoreLogger(new PhoreConsoleLoggerDriver('php://stdout', false));
$config = new Config(
    sourceRoot: PREVIEW_SOURCE_ROOT,
    targetRoot: PREVIEW_TARGET_ROOT,
    builderUrl: PREVIEW_BUILDER_URL,
    amqpConnection: CONF_AMQ_CONNECTION,
);
$publisher = new NchanPublisher('http://127.0.0.1:8081/publish');
(new Worker($config, new BuildRunner($config), $log, $publisher))->run();
