<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview;

use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient;
use Leuffen\PagebuilderPreview\Helper\Directories;

final class BuildRunner
{
    /**
     * Configures the external builder while retaining local CDN publication.
     *
     * @param Config $config Fixed source/target roots and builder URL.
     * @see self::build()
     * @example $runner = new BuildRunner(Config::fromEnvironment());
     */
    public function __construct(private readonly Config $config) {}

    /**
     * Builds remotely and atomically replaces a successful CDN publication.
     *
     * The HTTP client zips the source directory and extracts the resulting
     * website into a private staging directory. Failed builds preserve the
     * previous target; overlapping targets are serialized with a file lock.
     *
     * @param string $source Relative source directory.
     * @param string $target Relative target directory.
     * @return array<string,mixed> RPC result, including remote build diagnostics.
     * @throws \InvalidArgumentException For unsafe directory paths.
     * @throws \RuntimeException For network, storage or publication failures.
     * @see Worker
     * @example $result = $runner->build('customer/docs', 'customer');
     */
    public function build(string $source, string $target): array
    {
        $started = hrtime(true);
        $sourceRoot = Directories::root($this->config->sourceRoot);
        $targetRoot = Directories::root($this->config->targetRoot);
        if ($sourceRoot === $targetRoot || str_starts_with($sourceRoot, $targetRoot . '/')
            || str_starts_with($targetRoot, $sourceRoot . '/')) {
            throw new \RuntimeException('Source and target roots must not overlap');
        }
        $sourcePath = Directories::resolve($sourceRoot, $source, true);
        Directories::resolve($targetRoot, $target, false);

        // A target-level lock covers simultaneous workers and nested sites.
        $lockRoot = $targetRoot . '/.runner-locks';
        Directories::create($lockRoot, 0700);
        $lockPath = $lockRoot . '/' . hash('sha256', explode('/', $target)[0]) . '.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException("Lock path must not be a symbolic link: $lockPath");
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open target lock: $lockPath");
        }

        $staging = null;
        $published = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot acquire target lock: $lockPath");
            }
            $targetPath = Directories::resolve($targetRoot, $target, false);
            $stageRoot = $targetRoot . '/.runner-work';
            Directories::create($stageRoot, 0700);
            $staging = $stageRoot . '/' . bin2hex(random_bytes(16));
            Directories::create($staging, 0700);
            $outputPath = $staging . '/site';

            // The official HTTP client creates the upload ZIP and extracts the response.
            try {
                (new LeuffenJekyllBuilderClient($this->config->builderUrl))->build($sourcePath, $outputPath);
            } catch (JekyllBuilderBuildException $error) {
                return [
                    'source' => $source, 'target' => $target,
                    'exitCode' => $error->exitCode ?: 1,
                    'stdout' => $error->stdout, 'stderr' => $error->stderr,
                    'file' => $error->sourceFile, 'line' => $error->sourceLine,
                    'cause' => $error->cause, 'excerpt' => $error->excerpt,
                    'httpStatus' => $error->httpStatus,
                    'durationMs' => (int) ((hrtime(true) - $started) / 1000000),
                ];
            }

            // CDN permissions and the previous publication are changed only on success.
            Directories::prepareOutput($outputPath);
            Directories::resolve($targetRoot, $target, false);
            $this->publish($outputPath, $targetPath, $staging . '/previous');
            $published = true;
            return [
                'source' => $source, 'target' => $target, 'exitCode' => 0,
                'stdout' => '', 'stderr' => '',
                'durationMs' => (int) ((hrtime(true) - $started) / 1000000),
            ];
        } finally {
            try {
                if ($staging !== null && !(!$published && is_dir($staging . '/previous'))) {
                    Directories::remove($staging);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function publish(string $output, string $target, string $backup): void
    {
        Directories::create(dirname($target));
        $hadPrevious = is_dir($target);
        if ($hadPrevious && !rename($target, $backup)) {
            throw new \RuntimeException("Cannot move previous target: $target");
        }
        try {
            if (!rename($output, $target)) {
                throw new \RuntimeException("Cannot publish target: $target");
            }
        } catch (\Throwable $error) {
            if ($hadPrevious && !is_dir($target) && !rename($backup, $target)) {
                throw new \RuntimeException("Cannot restore target $target; previous output remains at $backup", 0, $error);
            }
            throw $error;
        }
    }
}
