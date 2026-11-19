<?php

/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Files included with a path relative to the including file exist
 *
 * An include of a removed file broke copying chats unnoticed: the error was caught and the
 * copy kept the chat of the original.
 */
class IncludedFilesTest extends TestCase
{
    public function testIncludedFilesExist(): void
    {
        $root = dirname(__DIR__);
        $files = [$root . '/api.php'];
        foreach (['classes', 'src'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $checked = 0;
        foreach ($files as $file) {
            $code = file_get_contents($file);
            preg_match_all(
                '/\b(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1/',
                $code,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                $checked++;
                $this->assertFileExists(
                    dirname($file) . $match[2],
                    'Included in ' . substr($file, strlen($root) + 1)
                );
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
