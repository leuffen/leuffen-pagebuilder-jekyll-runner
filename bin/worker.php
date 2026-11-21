<?php

declare(strict_types=1);

use Leuffen\JekyllRunner\BuildRunner;
use Leuffen\JekyllRunner\Config;
use Leuffen\JekyllRunner\Worker;
use Phore\Log\Driver\PhoreConsoleLoggerDriver;
use Phore\Log\PhoreLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

umask(0022);
$log = new PhoreLogger(new PhoreConsoleLoggerDriver('php://stdout', false));
$config = Config::fromEnvironment();
(new Worker($config, new BuildRunner($config), $log))->run();
