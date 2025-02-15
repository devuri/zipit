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
use ZipArchive;

class ZipItCommand extends Command
{
    use OutputTrait;

    protected static $defaultName = 'zipit';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a zip file based on the configuration in .zipit-conf.php')
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

        if ('.zipit-conf.php' !== basename($configFilePath)) {
            $io->error("The configuration file must be named .zipit-conf.php.");

            return Command::FAILURE;
        }

        if ( ! file_exists($configFilePath)) {
            $io->error("Configuration file .zipit-conf.php not found at $configFilePath.");

            return Command::FAILURE;
        }

        $getConfig = require $configFilePath;

        // Fix for the double-dollar bug:
        if ( ! \is_array($getConfig) || ! isset($getConfig['files'], $getConfig['baseDir']) || ! \is_array($getConfig['files'])) {
            $io->error("Invalid configuration file. The .zipit-conf.php file must return an array with 'baseDir' and 'files' keys.");

            return Command::FAILURE;
        }

        $config = $this->setOutputConfig($outputTime, $getConfig);

        $baseDir = realpath($config['baseDir']);
        $files   = $config['files'];
        $excludes = array_map('realpath', array_map(fn ($file) => $baseDir . DIRECTORY_SEPARATOR . $file, $config['exclude']));
        $filesystem = new Filesystem();

        $outputDirectory = $config['outputDir'];
        $outputFileName = $config['outputFile'] ?? $outputFileName;
        $outputZipBuild = $outputDirectory . DIRECTORY_SEPARATOR . $outputFileName;
        if ('zip' !== pathinfo($outputZipBuild, PATHINFO_EXTENSION)) {
            $io->error("The output file name must have a .zip extension.");

            return Command::FAILURE;
        }

        $filePath = realpath($outputZipBuild);
        if ( ! $filesystem->exists($filePath)) {
            $io->warning("File or directory does not exist.");
            $filesystem->mkdir($outputDirectory);
        }

        if (file_exists($outputZipBuild)) {
            $filesystem->remove($outputZipBuild);
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($outputZipBuild, ZipArchive::CREATE)) {
            $io->error("Failed to create zip file.");

            return Command::FAILURE;
        }

        $io->title("Creating Zip Archive");
        $io->writeln('<info>Starting to zip the configured files...</info>');

        $progressBar = new ProgressBar($output, \count($files));
        $progressBar->start();

        $filesAdded = [];
        $totalSize  = 0;

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

            $this->addFileToZip($zip, $filePath, $baseDir, $excludes);
            $filesAdded[] = $filePath;
            $totalSize += filesize($filePath);
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();

        $zip->close();

        // Check if the zip file was successfully created and contains files
        if ( ! file_exists($outputZipBuild) || 0 === \count($filesAdded)) {
            $io->error("Failed to create a valid zip file. No files were added to the archive.");

            return Command::FAILURE;
        }

        // Pretty output with useful information
        $io->success("Zip file created successfully.");
        $io->section("Summary");
        $io->listing($filesAdded);

        $io->text([
            "<info>Total files:</info> " . \count($filesAdded),
            "<info>Total size:</info> " . $this->formatSize($totalSize),
            "<info>Zip file location:</info> " . realpath($outputZipBuild),
        ]);

        return Command::SUCCESS;
    }

    private function isExcluded($filePath, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (0 === strpos($filePath, $exclude)) {
                return true;
            }
        }

        return false;
    }

    private function addFileToZip(ZipArchive $zip, string $filePath, string $basePath, array $excludes): void
    {
        if (is_dir($filePath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($filePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if ($this->isExcluded($item->getPathname(), $excludes)) {
                    continue;
                }
                $relativePath = substr($item->getPathname(), \strlen($basePath) + 1);
                $zip->addFile($item->getPathname(), $relativePath);
            }
        } else {
            $relativePath = substr($filePath, \strlen($basePath) + 1);
            $zip->addFile($filePath, $relativePath);
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
