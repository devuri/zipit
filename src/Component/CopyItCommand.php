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
        $outputTime = time();

        if ( ! $configFilePath) {
            $configFilePath = getcwd() . '/.zipit-conf.php';
        }

        // Validation checks
        if ('.zipit-conf.php' !== basename($configFilePath)) {
            $io->error("The configuration file must be named .zipit-conf.php.");

            return Command::FAILURE;
        }

        if ( ! file_exists($configFilePath)) {
            $io->error("Configuration file .zipit-conf.php not found at $configFilePath.");

            return Command::FAILURE;
        }

        // Load configuration
        $getConfig = require $configFilePath;
        if ( ! \is_array($getConfig) || ! isset($getConfig['files'], $getConfig['baseDir']) || ! \is_array($getConfig['files'])) {
            $io->error("Invalid configuration file. The .zipit-conf.php file must return an array with 'baseDir' and 'files' keys.");

            return Command::FAILURE;
        }

        // Merge with defaults
        $config = $this->setOutputConfig($outputTime, $getConfig);

        $baseDir = realpath($config['baseDir']);
        $files   = $config['files'];
        $excludes = array_map('realpath', array_map(fn ($file) => $baseDir . DIRECTORY_SEPARATOR . $file, $config['exclude']));

        $filesystem = new Filesystem();

        $outputDirectory = self::getOutputDirectory($config, $outputDir);

        // Create or clear the output directory
        if (file_exists($outputDirectory)) {
            $filesystem->remove($outputDirectory);
            $io->writeln('<info>Clear the output directory...</info>');
        }

        $filesystem->mkdir($outputDirectory);

        $io->title("Copying Files");
        $io->writeln('<info>Starting to copy the configured files...</info>');

        $progressBar = new ProgressBar($output, \count($files));
        $progressBar->start();

        $filesCopied = [];
        $totalSize   = 0;

        foreach ($files as $file) {
            $filePath = realpath($baseDir . DIRECTORY_SEPARATOR . $file);
            if ( ! $filePath || ! $filesystem->exists($filePath)) {
                $io->warning("File or directory '$file' does not exist.");

                continue;
            }

            if ($this->isExcluded($filePath, $excludes)) {
                $io->note("Skipping excluded file or directory: '$file'");

                continue;
            }

            // Copy the file/directory
            $this->copyFileOrDirectory($filesystem, $filePath, $baseDir, $outputDirectory, $excludes, $filesCopied, $totalSize);

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();

        // If no files were actually copied
        if (0 === \count($filesCopied)) {
            $io->warning("No files were copied. Please check your configuration.");

            return Command::FAILURE;
        }

        // Pretty output with useful information
        $io->success("Files copied successfully.");
        $io->section("Summary");
        $io->listing($filesCopied);

        $io->text([
            "<info>Total files:</info> " . \count($filesCopied),
            "<info>Total size:</info> " . $this->formatSize($totalSize),
            "<info>Output directory:</info> " . realpath($outputDirectory),
        ]);

        return Command::SUCCESS;
    }

    protected static function getOutputDirectory($config, $defaultDir = 'copyOut'): string
    {
        $outputDirectory = $config['outputDir'] ?? $defaultDir;

        $directory = explode('.', $outputDirectory);
        $outputFile = explode('.', $config['outputFile']);

        return DIRECTORY_SEPARATOR . $directory[0] . DIRECTORY_SEPARATOR . $outputFile[0];
    }

    private function isExcluded($filePath, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            // If the file path begins with the excluded path
            if (0 === strpos($filePath, $exclude)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively copies files/directories from $filePath into $outputDirectory.
     * Also tracks copied files in $filesCopied and accumulates total sizes in $totalSize.
     */
    private function copyFileOrDirectory(
        Filesystem $filesystem,
        string $filePath,
        string $basePath,
        string $outputDirectory,
        array $excludes,
        array &$filesCopied,
        int &$totalSize
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

                // Build the destination path
                $relativePath = substr($currentPath, \strlen($basePath) + 1);
                $destination  = $outputDirectory . DIRECTORY_SEPARATOR . $relativePath;

                if ($item->isDir()) {
                    $filesystem->mkdir($destination);
                } else {
                    $filesystem->copy($currentPath, $destination, true);
                    $filesCopied[] = $currentPath;
                    $totalSize += filesize($currentPath);
                }
            }
        } else {
            // Single file
            $relativePath = substr($filePath, \strlen($basePath) + 1);
            $destination  = $outputDirectory . DIRECTORY_SEPARATOR . $relativePath;

            $filesystem->mkdir(\dirname($destination));  // Ensure the directory exists
            $filesystem->copy($filePath, $destination, true);

            $filesCopied[] = $filePath;
            $totalSize += filesize($filePath);
        }
    }

    private function formatSize($size): string
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
