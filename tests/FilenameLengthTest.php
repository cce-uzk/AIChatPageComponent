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

use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Resource\Provider\PageObjectProvider;
use PHPUnit\Framework\TestCase;

/**
 * File names of stored pages fit the title of a revision in the Resource Storage
 */
class FilenameLengthTest extends TestCase
{
    public function testShortNamesStay(): void
    {
        $name = 'Lernmodul › Kapitel 1 › Einführung.txt';
        $this->assertSame($name, ChatResource::fitFilename($name));
    }

    public function testChaptersAreLeftOutFirst(): void
    {
        $chapters = array_map(static fn(int $i): string => 'Kapitel ' . $i . ' ' . str_repeat('lang ', 10), range(1, 8));
        $name = PageObjectProvider::filename(['Seitengestaltung in ILIAS', ...$chapters, 'Absätze formatieren']);
        $this->assertGreaterThan(ChatResource::MAX_FILENAME_LENGTH, mb_strlen($name));

        $fitted = ChatResource::fitFilename($name);
        $this->assertLessThanOrEqual(ChatResource::MAX_FILENAME_LENGTH, mb_strlen($fitted));
        $this->assertStringStartsWith('Seitengestaltung in ILIAS › … › ', $fitted);
        $this->assertStringEndsWith(' › Absätze formatieren.txt', $fitted);
    }

    public function testLongObjectAndPageAreCut(): void
    {
        $name = str_repeat('Ä', 200) . ' › ' . str_repeat('ö', 200) . '.txt';
        $fitted = ChatResource::fitFilename($name);
        $this->assertSame(ChatResource::MAX_FILENAME_LENGTH, mb_strlen($fitted));
        $this->assertStringEndsWith('….txt', $fitted);
        $this->assertStringContainsString(' › ', $fitted);
    }

    public function testSingleLongNameKeepsSuffix(): void
    {
        $fitted = ChatResource::fitFilename(str_repeat('x', 400) . '.txt');
        $this->assertSame(ChatResource::MAX_FILENAME_LENGTH, mb_strlen($fitted));
        $this->assertStringEndsWith('….txt', $fitted);
    }

    public function testMultibyteNamesCountCharacters(): void
    {
        // 255 characters fit, although they are more bytes
        $name = str_repeat('ü', 251) . '.txt';
        $this->assertSame($name, ChatResource::fitFilename($name));
    }
}
