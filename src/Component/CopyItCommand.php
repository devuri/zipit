<?php

/*
 * This file is part of the WPframework package.
 *
 * The full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Urisoft;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class CopyItCommand extends Command
{
    use OutputTrait;

    protected static $defaultName = 'copy';

    protected function configure(): void
    {
        $this
            ->setDescription('Copies files based on the configuration in .zipit-conf.php to a specified output directory')
            ->addArgument('config', InputArgument::OPTIONAL, 'Path to the configuration file (must be .zipit-conf.php)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $configFilePath = $input->getArgument('config');
        $outputTime = (string) time();

        if ( ! $configFilePath) {
            $configFilePath = getcwd() . '/.zipit-conf.php';
        }

        if ('.zipit-conf.php' !== basename($configFilePath)) {
            $io->error("The configuration file must be named .zipit-conf.php.");

            return Command::FAILURE;
        }

        if ( ! file_exists($configFilePath)) {
            $io->error("Configuration file .zipit-conf.php not found at $configFilePath.");

            return Command::FAILURE;
        }

        $getConfig = require $configFilePath;

        if ( ! \is_array($getConfig) || ! isset($getConfig['files'], $getConfig['baseDir']) || ! \is_array($getConfig['files'])) {
            $io->error("Invalid configuration file. The .zipit-conf.php file must return an array with 'baseDir' and 'files' keys.");

            return Command::FAILURE;
        }

        $config = $this->setOutputConfig($outputTime, $getConfig);

        $baseDir = realpath($config['baseDir']);

        // Bug fix: filter out false values from realpath() calls on non-existent exclude paths.
        $excludes = array_values(array_filter(
            array_map(
                'realpath',
                array_map(fn ($file) => $baseDir . DIRECTORY_SEPARATOR . $file, $config['exclude'])
            )
        ));

        $files      = $config['files'];
        $filesystem = new Filesystem();

        $outputDirectory = self::getOutputDirectory($config);

        if (file_exists($outputDirectory)) {
            $filesystem->remove($outputDirectory);
            $io->writeln('<info>Clear the output directory...</info>');
        }

        $filesystem->mkdir($outputDirectory);

        $io->title("Copying Files");
        $io->writeln('<info>Starting to copy the configured files...</info>');

        $progressBar = new ProgressBar($output, \count($files));
        $progressBar->start();

        $filesCopied  = [];
        $missingFiles = [];
        $totalSize    = 0;

        // Supports two entry formats:
        //   'path/to/file.php'                  — plain string, destination mirrors source path
        //   'path/to/source.php' => 'dest.php'  — key=>value, destination is remapped in output dir
        foreach ($files as $source => $dest) {
            if (\is_int($source)) {
                // Plain string entry: source and destination path are the same.
                $source       = $dest;
                $destOverride = null;
            } else {
                // Mapped entry: $source is the file to read, $dest is where it lands in the output.
                $destOverride = $dest;
            }

            // Bug fix: check file_exists() before realpath() so missing files are tracked
            // and the command returns FAILURE rather than silently succeeding.
            $rawPath  = $baseDir . DIRECTORY_SEPARATOR . $source;
            $filePath = file_exists($rawPath) ? realpath($rawPath) : false;

            if (false === $filePath || ! $filesystem->exists($filePath)) {
                $io->warning("File or directory '$source' does not exist and will be skipped.");
                $missingFiles[] = $source;
                $progressBar->advance();

                continue;
            }

            if ($this->isExcluded($filePath, $excludes)) {
                $io->note("Skipping excluded file or directory: '$source'");
                $progressBar->advance();

                continue;
            }

            $this->copyFileOrDirectory($filesystem, $filePath, $baseDir, $outputDirectory, $excludes, $filesCopied, $totalSize, $destOverride);

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();

        if (0 === \count($filesCopied)) {
            $io->warning("No files were copied. Please check your configuration.");

            return Command::FAILURE;
        }

        $io->success("Files copied successfully.");
        $io->section("Summary");
        $io->listing($filesCopied);

        $io->text([
            "<info>Total files:</info> " . \count($filesCopied),
            "<info>Total size:</info> " . $this->formatSize($totalSize),
            "<info>Output directory:</info> " . realpath($outputDirectory),
        ]);

        // Bug fix: surface missing files and fail if any were not found.
        if ( ! empty($missingFiles)) {
            $io->warning("The following configured entries were not found and were skipped:");
            $io->listing($missingFiles);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected static function getOutputDirectory($config, $defaultDir = 'copyOut'): string
    {
        $outputDirectory = $config['outputDir'] ?? $defaultDir;

        $directory  = explode('.', $outputDirectory);
        $outputFile = explode('.', $config['outputFile']);

        return DIRECTORY_SEPARATOR . $directory[0] . DIRECTORY_SEPARATOR . $outputFile[0];
    }

    private function isExcluded(string $filePath, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (0 === strpos($filePath, $exclude)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively copies files/directories from $filePath into $outputDirectory.
     * Also tracks copied files in $filesCopied and accumulates total sizes in $totalSize.
     *
     * @param string|null $destOverride When set, the file is placed at this path inside
     *                                  $outputDirectory instead of its path relative to $basePath.
     *                                  Only applies to single files; directories always use their
     *                                  relative path.
     */
    private function copyFileOrDirectory(
        Filesystem $filesystem,
        string $filePath,
        string $basePath,
        string $outputDirectory,
        array $excludes,
        array &$filesCopied,
        int &$totalSize,
        ?string $destOverride = null
    ): void {
        if (is_dir($filePath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($filePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $currentPath = $item->getPathname();
                if ($this->isExcluded($currentPath, $excludes)) {
                    continue;
                }

                $relativePath = substr($currentPath, \strlen($basePath) + 1);
                $destination  = $outputDirectory . DIRECTORY_SEPARATOR . $relativePath;

                if ($item->isDir()) {
                    $filesystem->mkdir($destination);
                } else {
                    $filesystem->copy($currentPath, $destination, true);
                    $filesCopied[] = $currentPath;

                    // Bug fix: only call filesize() on actual files, not directories.
                    $totalSize += filesize($currentPath);
                }
            }
        } else {
            $relativePath = $destOverride ?? substr($filePath, \strlen($basePath) + 1);
            $destination  = $outputDirectory . DIRECTORY_SEPARATOR . $relativePath;

            $filesystem->mkdir(\dirname($destination));
            $filesystem->copy($filePath, $destination, true);

            $filesCopied[] = $destOverride ? "$filePath -> $destination" : $filePath;
            $totalSize += filesize($filePath);
        }
    }

    private function formatSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($size >= 1024 && $unit < \count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}
