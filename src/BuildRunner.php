<?php

declare(strict_types=1);

namespace Leuffen\JekyllRunner;

use Leuffen\JekyllRunner\Helper\Command;
use Leuffen\JekyllRunner\Helper\Directories;

final class BuildRunner
{
    /**
     * Erstellt einen Runner für zwei Volumes und einen temporären Build-Pfad.
     *
     * Der Constructor führt keine Dateioperationen aus. Mounts und Zugriffsrechte
     * werden beim jeweiligen Build geprüft; Quellen werden nur gelesen.
     *
     * @param Config $config Feste Wurzelverzeichnisse und Build-Limits.
     * @example $runner = new BuildRunner(Config::fromEnvironment());
     * @see self::build()
     */
    public function __construct(private readonly Config $config) {}

    /**
     * Baut eine Jekyll-Site und veröffentlicht ausschließlich erfolgreiche Builds.
     *
     * Relative Pfade beziehen sich auf sourceRoot bzw. targetRoot. Ein Snapshot
     * der Quellen liegt in buildRoot, die Ausgabe zunächst verborgen im Target-
     * Volume. Erst nach exitCode 0 wird das Ziel ersetzt. Builds mit demselben
     * obersten Target-Verzeichnis teilen eine Dateisperre, auch über Worker hinweg.
     *
     * @param string $source Vorhandener Quellpfad, beispielsweise customer/docs.
     * @param string $target Zielpfad, beispielsweise customer oder customer/preview.
     * @return array{source:string,target:string,exitCode:int,stdout:string,stderr:string,
     *     durationMs:int,outputTruncated:bool,timedOut:bool} Jekyll-Ergebnis.
     * @throws \InvalidArgumentException Bei ungültigen oder fehlenden Quellpfaden.
     * @throws \RuntimeException Bei Storage-, Snapshot- oder Publish-Fehlern.
     * @example $result = $runner->build('customer/docs', 'customer');
     * @see Worker
     * @see https://jekyllrb.com/docs/configuration/options/
     */
    public function build(string $source, string $target): array
    {
        $started = hrtime(true);
        $sourceRoot = Directories::root($this->config->sourceRoot);
        $targetRoot = Directories::root($this->config->targetRoot);
        $buildRoot = Directories::root($this->config->buildRoot, true);
        $this->assertSeparateRoots([$sourceRoot, $targetRoot, $buildRoot]);
        $sourcePath = Directories::resolve($sourceRoot, $source, true);
        Directories::resolve($targetRoot, $target, false);

        // Die Sperre liegt im geteilten Volume und schützt auch überlappende Targets.
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
        $workspace = null;
        $staging = null;
        $published = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot acquire target lock: $lockPath");
            }
            $targetPath = Directories::resolve($targetRoot, $target, false);
            $job = bin2hex(random_bytes(16));
            $workspace = $buildRoot . '/' . $job;
            Directories::create($workspace, 0700);
            $snapshot = $workspace . '/source';
            Directories::create($snapshot);
            $stageRoot = $targetRoot . '/.runner-work';
            Directories::create($stageRoot, 0700);
            $staging = $stageRoot . '/' . $job;
            Directories::create($staging, 0700);
            $outputPath = $staging . '/site';
            Directories::create($outputPath);

            // Git-Metadaten und vorherige Builds gehören nicht in den Source-Snapshot.
            $copy = Command::run([
                'rsync', '-rlt', '--links', '--safe-links',
                '--exclude=.git', '--exclude=.jekyll-cache',
                '--exclude=.jekyll-metadata', '--exclude=_site',
                '--', $sourcePath . '/', $snapshot . '/',
            ], $workspace, $this->config->buildTimeout, $this->config->maxOutputBytes);
            if ($copy['exitCode'] !== 0) {
                throw new \RuntimeException("Cannot snapshot source $sourcePath: " . $copy['stderr']);
            }
            $result = Command::run([
                'jekyll', 'build', '--source', $snapshot,
                '--destination', $outputPath, '--disable-disk-cache', '--trace',
            ], $snapshot, $this->config->buildTimeout, 0);

            // Fehlgeschlagene Jekyll-Builds verändern die bisherige Veröffentlichung nicht.
            if ($result['exitCode'] === 0) {
                Directories::prepareOutput($outputPath);
                Directories::resolve($targetRoot, $target, false);
                $this->publish($outputPath, $targetPath, $staging . '/previous');
                $published = true;
            }
            return [
                'source' => $source,
                'target' => $target,
                ...$result,
                'durationMs' => (int) ((hrtime(true) - $started) / 1000000),
            ];
        } finally {
            try {
                if ($workspace !== null) {
                    Directories::remove($workspace);
                }
                // Ein nicht wiederherstellbares Backup niemals beim Aufräumen löschen.
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
            // Bei einem Publish-Fehler die vorherige Ausgabe sofort zurückstellen.
            if ($hadPrevious && !is_dir($target) && !rename($backup, $target)) {
                throw new \RuntimeException("Cannot restore target $target; previous output remains at $backup", 0, $error);
            }
            throw $error;
        }
    }

    private function assertSeparateRoots(array $roots): void
    {
        foreach ($roots as $leftIndex => $left) {
            foreach ($roots as $rightIndex => $right) {
                if ($leftIndex !== $rightIndex && ($left === $right || str_starts_with($left, $right . '/'))) {
                    throw new \RuntimeException("Source, target and build roots must not overlap: $left and $right");
                }
            }
        }
    }
}
