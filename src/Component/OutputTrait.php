<?php

/*
 * This file is part of the WPframework package.
 *
 * (c) Uriel Wilson
 *
 * The full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Urisoft;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

trait OutputTrait
{
    public function setOutputConfig(string $outputTime, array $getConfig): array
    {
        return array_merge([
            'baseDir'     => getcwd(),
            'files'       => [],
            'exclude'     => [],
            'outputDir'   => getcwd() . '/zipit',
            'outputFile'  => "project-archive-$outputTime.zip",
            'archiveRoot' => '',
        ], $getConfig);
    }

    /**
     * @throws RuntimeException
     */
    private function loadBuildConfig(?string $configFilePath): array
    {
        $configFilePath = $configFilePath ?: getcwd() . '/.zipit-conf.php';
        if ('.zipit-conf.php' !== basename($configFilePath)) {
            throw new RuntimeException('The configuration file must be named .zipit-conf.php.');
        }
        if ( ! is_file($configFilePath) || ! is_readable($configFilePath)) {
            throw new RuntimeException("Configuration file not found or unreadable: $configFilePath");
        }

        $raw = require $configFilePath;
        if ( ! \is_array($raw) || ! isset($raw['baseDir'], $raw['files']) ||
            ! \is_string($raw['baseDir']) || ! \is_array($raw['files']) || [] === $raw['files']) {
            throw new RuntimeException("Config requires a valid 'baseDir' and nonempty 'files' array.");
        }

        $config = $this->setOutputConfig((string) time(), $raw);
        $baseDir = realpath($config['baseDir']);
        if (false === $baseDir || ! is_dir($baseDir) || ! is_readable($baseDir)) {
            throw new RuntimeException("baseDir must be an existing readable directory.");
        }
        $config['baseDir'] = rtrim($baseDir, DIRECTORY_SEPARATOR);
        if ('' === $config['baseDir']) {
            $config['baseDir'] = DIRECTORY_SEPARATOR;
        }

        if ( ! \is_array($config['exclude'])) {
            throw new RuntimeException("exclude must be an array.");
        }
        if ( ! \is_string($config['outputFile']) ||
            '' === $config['outputFile'] ||
            basename($config['outputFile']) !== $config['outputFile'] ||
            str_contains($config['outputFile'], '\\') ||
            ! preg_match('/^[^\x00-\x1f\/\\\\]+\.zip$/', $config['outputFile']) ||
            '..zip' === $config['outputFile'] || '.zip' === $config['outputFile']) {
            throw new RuntimeException("outputFile must be a plain filename ending in .zip.");
        }
        if ( ! \is_string($config['outputDir']) || '' === trim($config['outputDir'])) {
            throw new RuntimeException('outputDir must be a nonempty directory path.');
        }
        $config['outputDir'] = $this->absolutePath($config['outputDir']);
        if ( ! \is_string($config['archiveRoot'])) {
            throw new RuntimeException('archiveRoot must be a relative directory name.');
        }
        $config['archiveRoot'] = $this->relativePath($config['archiveRoot'], 'archiveRoot', true);

        return $config;
    }

    /**
     * Keep source and destination paths relative, with no traversal or drive letters.
     */
    private function relativePath(string $input, string $label, bool $allowEmpty = false): string
    {
        if (str_contains($input, "\0") || preg_match('/[\x00-\x1f]/', $input) ||
            str_starts_with($input, '/') || str_starts_with($input, '\\') ||
            preg_match('/^[a-zA-Z]:/', $input)) {
            throw new RuntimeException("Unsafe $label path: $input");
        }
        $input = str_replace('\\', '/', $input);
        $parts = [];
        foreach (explode('/', $input) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part || str_contains($part, ':')) {
                throw new RuntimeException("Unsafe $label path: $input");
            }
            $parts[] = $part;
        }
        if ( ! $allowEmpty && ! $parts) {
            throw new RuntimeException("Empty $label path is not allowed.");
        }

        return implode('/', $parts);
    }

    /**
     * Normalize an output directory without requiring it to exist yet.
     */
    private function absolutePath(string $input): string
    {
        $input = str_replace('\\', '/', $input);
        if ( ! str_starts_with($input, '/') && ! preg_match('/^[a-zA-Z]:\//', $input)) {
            $input = str_replace('\\', '/', getcwd()) . '/' . $input;
        }
        $prefix = '/';
        if (preg_match('/^[a-zA-Z]:\//', $input)) {
            $prefix = substr($input, 0, 3);
            $input = substr($input, 3);
        }
        $parts = [];
        foreach (explode('/', $input) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $prefix . implode('/', $parts));
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ('' === $root) {
            $root = '/';
        }

        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private function isExcludedPath(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if ($this->isWithin($path, $exclude)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare a full manifest BEFORE creating or removing any output.
     * Directories are preserved, including empty directories; remapping applies only to files.
     *
     * @return list<array{source:string, entry:string, directory:bool, size:int}>
     */
    private function buildManifest(array $config, bool $forZip): array
    {
        $baseDir = $config['baseDir'];
        $excludes = [];
        foreach ($config['exclude'] as $exclude) {
            if ( ! \is_string($exclude)) {
                throw new RuntimeException('Exclude entries must be strings.');
            }
            $excludes[] = $baseDir . '/' . $this->relativePath($exclude, 'exclude');
        }

        $manifest = [];
        $usedEntries = [];
        $root = $forZip && '' !== $config['archiveRoot'] ? $config['archiveRoot'] . '/' : '';

        $append = function (string $source, string $destination, bool $directory) use (&$manifest, &$usedEntries, $root): void {
            $entry = $root . $destination . ($directory ? '/' : '');
            if (isset($usedEntries[$entry])) {
                throw new RuntimeException("Duplicate destination in output: $entry");
            }
            $usedEntries[$entry] = true;
            if ( ! is_readable($source)) {
                throw new RuntimeException("Unreadable source: $source");
            }
            $manifest[] = [
                'source' => $source,
                'entry' => $entry,
                'directory' => $directory,
                'size' => $directory ? 0 : (int) filesize($source),
            ];
        };

        $missing = [];
        foreach ($config['files'] as $key => $value) {
            $remap = \is_string($key);
            $sourceInput = $remap ? $key : $value;
            if ( ! \is_string($sourceInput) || ($remap && ! \is_string($value))) {
                throw new RuntimeException('Each files entry must be a string or source => destination pair.');
            }
            $sourceRel = $this->relativePath($sourceInput, 'source');
            $source = $baseDir . '/' . $sourceRel;
            if ($this->isExcludedPath($source, $excludes)) {
                continue;
            }
            if ( ! file_exists($source) && ! is_link($source)) {
                $missing[] = $sourceInput;

                continue;
            }
            if (is_link($source)) {
                throw new RuntimeException("Source symlinks are not supported: $sourceInput");
            }
            $resolved = realpath($source);
            if (false === $resolved || ! $this->isWithin($resolved, $baseDir)) {
                throw new RuntimeException("Source escapes baseDir: $sourceInput");
            }
            if (is_dir($resolved)) {
                // As documented, remapping a directory does not change its relative layout.
                $append($resolved, $sourceRel, true);
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    $path = $item->getPathname();
                    if ($this->isExcludedPath($path, $excludes)) {
                        continue;
                    }
                    if ($item->isLink()) {
                        throw new RuntimeException("Source symlinks are not supported: $path");
                    }
                    $real = $item->getRealPath();
                    if (false === $real || ! $this->isWithin($real, $baseDir)) {
                        throw new RuntimeException("Source escapes baseDir: $path");
                    }
                    $entry = substr($path, \strlen($baseDir) + 1);
                    if ( ! $item->isDir() && ! $item->isFile()) {
                        throw new RuntimeException("Unsupported source file type: $path");
                    }
                    $append($real, str_replace('\\', '/', $entry), $item->isDir());
                }
            } elseif (is_file($resolved)) {
                $dest = $remap ? $this->relativePath($value, 'destination') : $sourceRel;
                $append($resolved, $dest, false);
            } else {
                throw new RuntimeException("Unsupported source file type: $sourceInput");
            }
        }
        if ($missing) {
            throw new RuntimeException('Missing configured entries: ' . implode(', ', $missing));
        }
        if ([] === $manifest) {
            throw new RuntimeException('No files or directories selected for output.');
        }

        return $manifest;
    }

    /**
     * Refuse output paths that are included in the source tree or overwrite source files.
     */
    private function assertSafeOutput(string $target, array $manifest, string $baseDir, bool $directory): void
    {
        if ('/' === $target || ($directory && $this->isWithin($baseDir, $target))) {
            throw new RuntimeException('Output would overwrite the source directory or one of its ancestors.');
        }
        if (is_link($target)) {
            throw new RuntimeException('Refusing to replace a symlink output.');
        }
        foreach ($manifest as $item) {
            if ($item['directory'] && $this->isWithin($target, $item['source'])) {
                throw new RuntimeException("Output is inside an included source directory: {$item['source']}");
            }
            if ( ! $item['directory'] && $target === $item['source']) {
                throw new RuntimeException('Output would replace an included source file.');
            }
            if ($directory && $this->isWithin($item['source'], $target)) {
                throw new RuntimeException('Output directory would replace included source files.');
            }
        }
    }

    /**
     * Stage output first; only swap after it has been fully constructed and checked.
     */
    private function publishOutput(string $staging, string $target, Filesystem $filesystem): void
    {
        $backup = null;
        if (file_exists($target) || is_link($target)) {
            if (is_link($target)) {
                throw new RuntimeException('Refusing to replace a symlink output.');
            }
            $backup = tempnam(\dirname($target), '.zipit-prev-');
            if (false === $backup || ! unlink($backup) || ! rename($target, $backup)) {
                throw new RuntimeException("Cannot preserve previous output: $target");
            }
        }
        if ( ! rename($staging, $target)) {
            if (null !== $backup && ! rename($backup, $target)) {
                throw new RuntimeException("Publishing failed AND previous output could not be restored: $backup");
            }

            throw new RuntimeException("Cannot publish new output: $target");
        }
        if (null !== $backup) {
            $filesystem->remove($backup);
        }
    }

    private function formatSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $number = (float) $size;
        $index = 0;
        while ($number >= 1024 && $index < \count($units) - 1) {
            $number /= 1024;
            $index++;
        }

        return round($number, 2) . ' ' . $units[$index];
    }
}
