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

class CopyItCommand extends Command
{
    use OutputTrait;

    protected static $defaultName = 'copy';

    protected function configure(): void
    {
        $this->setDescription('Copies files based on .zipit-conf.php to an output directory')
            ->addArgument('config', InputArgument::OPTIONAL, 'Path to .zipit-conf.php', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $staging = null;
        $filesystem = new Filesystem();

        try {
            $config = $this->loadBuildConfig($input->getArgument('config'));
            // archiveRoot applies to ZIP archives only; copy destinations retain prior layout.
            $manifest = $this->buildManifest($config, false);
            $outputDir = $config['outputDir'];
            $target = self::getOutputDirectory($config);
            $this->assertSafeOutput($target, $manifest, $config['baseDir'], true);
            if (is_link($outputDir) || is_link($target)) {
                throw new RuntimeException('Output directory cannot be a symlink.');
            }
            if (file_exists($target) && ! is_dir($target)) {
                throw new RuntimeException('Copy destination exists but is not a directory.');
            }
            $filesystem->mkdir($outputDir);
            // Resolve symlinked ancestors before accepting an output location.
            $realOutputDir = realpath($outputDir);
            if (false === $realOutputDir) {
                throw new RuntimeException('Cannot resolve outputDir after creating it.');
            }
            $target = $realOutputDir . DIRECTORY_SEPARATOR . pathinfo($config['outputFile'], PATHINFO_FILENAME);
            $this->assertSafeOutput($target, $manifest, $config['baseDir'], true);
            if (is_link($target) || (file_exists($target) && ! is_dir($target))) {
                throw new RuntimeException('Unsafe copy destination.');
            }
            $staging = tempnam($realOutputDir, '.zipit-copy-');
            if (false === $staging || ! unlink($staging)) {
                throw new RuntimeException('Cannot allocate temporary copy directory.');
            }
            $filesystem->mkdir($staging);
            $io->title('Copying Files');
            $progress = new ProgressBar($output, \count($manifest));
            $progress->start();
            $count = 0;
            $totalSize = 0;

            foreach ($manifest as $item) {
                $destination = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['entry']);
                if ($item['directory']) {
                    $filesystem->mkdir($destination);
                } else {
                    $filesystem->mkdir(\dirname($destination));
                    $filesystem->copy($item['source'], $destination, true);
                    if ( ! is_file($destination) ||
                        filesize($destination) !== $item['size'] ||
                        hash_file('sha256', $destination) !== hash_file('sha256', $item['source'])) {
                        throw new RuntimeException('Copy verification failed: ' . $item['entry']);
                    }
                    $count++;
                    $totalSize += $item['size'];
                }
                $progress->advance();
            }
            $progress->finish();
            $io->newLine();
            $this->publishOutput($staging, $target, $filesystem);
            $staging = null;

            $io->success('Files copied and verified.');
            $io->section('Summary');
            $io->text([
                '<info>Files:</info> ' . $count,
                '<info>Size:</info> ' . $this->formatSize($totalSize),
                '<info>Output directory:</info> ' . $target,
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (null !== $staging && file_exists($staging)) {
                $filesystem->remove($staging);
            }
        }
    }

    /**
     * outputDir/outputFile-stem; never split at the first dot in an absolute path.
     */
    protected static function getOutputDirectory(array $config, string $defaultDir = 'copyOut'): string
    {
        return rtrim($config['outputDir'] ?? $defaultDir, DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR . pathinfo($config['outputFile'], PATHINFO_FILENAME);
    }
}
