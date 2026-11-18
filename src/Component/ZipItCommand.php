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

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use ZipArchive;

class ZipItCommand extends Command
{
    use OutputTrait;

    protected static $defaultName = 'zipit';

    protected function configure(): void
    {
        $this->setDescription('Creates a zip file based on .zipit-conf.php')
            ->addArgument('config', InputArgument::OPTIONAL, 'Path to .zipit-conf.php', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $tempPath = null;
        $openZip = null;

        try {
            $config = $this->loadBuildConfig($input->getArgument('config'));
            $manifest = $this->buildManifest($config, true);
            $outputDir = $config['outputDir'];
            $target = $outputDir . DIRECTORY_SEPARATOR . $config['outputFile'];
            $this->assertSafeOutput($target, $manifest, $config['baseDir'], false);
            if (is_dir($target)) {
                throw new RuntimeException('Output ZIP target is a directory.');
            }
            if (is_link($outputDir)) {
                throw new RuntimeException('outputDir must not be a symlink.');
            }
            $filesystem = new Filesystem();
            $filesystem->mkdir($outputDir);
            // Resolve symlinked ancestors too, not just the final outputDir component.
            $realOutputDir = realpath($outputDir);
            if (false === $realOutputDir) {
                throw new RuntimeException('Cannot resolve outputDir after creating it.');
            }
            $target = $realOutputDir . DIRECTORY_SEPARATOR . $config['outputFile'];
            $this->assertSafeOutput($target, $manifest, $config['baseDir'], false);
            $tempPath = tempnam($realOutputDir, '.zipit-new-');
            if (false === $tempPath) {
                throw new RuntimeException('Unable to allocate a temporary ZIP file.');
            }

            if ( ! class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP extension ext-zip is required.');
            }
            $zip = new ZipArchive();
            $openResult = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if (true !== $openResult) {
                throw new RuntimeException('ZipArchive::open failed (code ' . $openResult . ').');
            }
            $openZip = $zip;
            $io->title('Creating Zip Archive');
            $progress = new ProgressBar($output, \count($manifest));
            $progress->start();
            $expected = [];
            $totalSize = 0;

            if ('' !== $config['archiveRoot']) {
                $rootName = $config['archiveRoot'] . '/';
                if ( ! $zip->addEmptyDir($config['archiveRoot'])) {
                    throw new RuntimeException("Cannot add archive root: $rootName");
                }
                $expected[] = ['entry' => $rootName, 'directory' => true, 'size' => 0];
            }
            foreach ($manifest as $item) {
                if ($item['directory']) {
                    $ok = $zip->addEmptyDir(rtrim($item['entry'], '/'));
                } else {
                    $ok = $zip->addFile($item['source'], $item['entry']);
                }
                if ( ! $ok) {
                    throw new RuntimeException('Failed to add ZIP entry: ' . $item['entry']);
                }
                $expected[] = $item;
                $totalSize += $item['size'];
                $progress->advance();
            }
            $progress->finish();
            $io->newLine();

            $openZip = null;
            if ( ! $zip->close()) {
                throw new RuntimeException('ZipArchive::close failed; output was not published.');
            }
            $this->verifyArchive($tempPath, $expected);
            @chmod($tempPath, 0644);
            $this->publishOutput($tempPath, $target, $filesystem);
            $tempPath = null;

            $fileCount = \count(array_filter($manifest, static fn ($item) => ! $item['directory']));
            $io->success('ZIP created and verified.');
            $io->section('Summary');
            $io->text([
                '<info>Files:</info> ' . $fileCount,
                '<info>Size before compression:</info> ' . $this->formatSize($totalSize),
                '<info>ZIP location:</info> ' . $target,
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (null !== $openZip) {
                $openZip->close();
            }
            if (null !== $tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Validate entry names, uncompressed sizes, and CRCs by reading the whole ZIP.
     */
    private function verifyArchive(string $path, array $expected): void
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::CHECKCONS)) {
            throw new RuntimeException('Cannot reopen the completed ZIP for verification.');
        }

        try {
            if ($zip->numFiles !== \count($expected)) {
                throw new RuntimeException('ZIP entry count differs from the build manifest.');
            }
            foreach ($expected as $item) {
                $entry = $item['entry'];
                $stat = $zip->statName($entry);
                if (false === $stat) {
                    throw new RuntimeException("Missing ZIP entry: $entry");
                }
                if ($item['directory']) {
                    continue;
                }
                $stream = $zip->getStream($entry);
                if (false === $stream) {
                    throw new RuntimeException("Cannot read ZIP entry: $entry");
                }
                $hash = hash_init('crc32b');
                $size = 0;

                try {
                    while ( ! feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if (false === $chunk || ('' === $chunk && ! feof($stream))) {
                            throw new RuntimeException("ZIP stream read failed: $entry");
                        }
                        $size += \strlen($chunk);
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }
                if ($size !== $item['size'] || $size !== (int) $stat['size'] ||
                    hash_final($hash) !== \sprintf('%08x', $stat['crc'] & 0xffffffff)) {
                    throw new RuntimeException("ZIP integrity check failed: $entry");
                }
            }
        } finally {
            $zip->close();
        }
    }
}
