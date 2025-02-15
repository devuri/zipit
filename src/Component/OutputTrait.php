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

trait OutputTrait
{
    public function setOutputConfig(string $outputTime, array $getConfig): array
    {
        return array_merge([
            'baseDir' => getcwd(),
            'files'   => [],
            'exclude' => [],
            'outputDir' => getcwd() . "/zipit",
            'outputFile' => "/project-archive-$outputTime.zip",
        ], $getConfig);
    }
}
