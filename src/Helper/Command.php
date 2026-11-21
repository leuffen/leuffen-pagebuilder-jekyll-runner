<?php

declare(strict_types=1);

namespace Leuffen\JekyllRunner\Helper;

/** @internal Bounded child-process execution; arguments never pass through a shell. */
final class Command
{
    public static function run(array $arguments, string $cwd, int $timeout, int $outputLimit): array
    {
        // GNU timeout beendet bei Überschreitung auch die Prozessgruppe des Builds.
        $process = proc_open(
            ['timeout', '--signal=TERM', '--kill-after=5', $timeout . 's', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start command in ' . $cwd . ': ' . $arguments[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = [1 => '', 2 => ''];
        $truncated = false;
        try {
            // Beide Pipes gleichzeitig entleeren, damit stderr den Build nicht blockiert.
            do {
                foreach ([1, 2] as $stream) {
                    $chunk = fread($pipes[$stream], 8192);
                    if ($chunk === false) {
                        throw new \RuntimeException('Cannot read command output in ' . $cwd);
                    }
                    self::append($output[$stream], $chunk, $outputLimit, $truncated);
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    usleep(10000);
                }
            } while ($status['running']);

            foreach ([1, 2] as $stream) {
                while (($chunk = fread($pipes[$stream], 8192)) !== false && $chunk !== '') {
                    self::append($output[$stream], $chunk, $outputLimit, $truncated);
                }
            }
            $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : 128 + $status['termsig'];
        } finally {
            // Auch bei einer verlorenen AMQP-Verbindung keinen Child-Prozess zurücklassen.
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => $output[1],
            'stderr' => $output[2],
            'outputTruncated' => $truncated,
            'timedOut' => in_array($exitCode, [124, 137], true),
        ];
    }

    private static function append(string &$output, string $chunk, int $limit, bool &$truncated): void
    {
        $output .= $chunk;
        if (strlen($output) > $limit) {
            $output = substr($output, -$limit);
            $truncated = true;
        }
    }
}
