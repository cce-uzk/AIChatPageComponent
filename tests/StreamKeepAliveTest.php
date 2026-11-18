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

use ILIAS\Plugin\pcaic\Service\StreamKeepAlive;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Comment lines keep a streamed answer open while the RAG or the AI service is waited for
 */
class StreamKeepAliveTest extends TestCase
{
    protected function tearDown(): void
    {
        StreamKeepAlive::disable();
    }

    private static function silentFor(int $seconds): void
    {
        $last = new ReflectionProperty(StreamKeepAlive::class, 'last_output');
        $last->setAccessible(true);
        $last->setValue(null, microtime(true) - $seconds);
    }

    /**
     * Output of beat(), which flushes the output buffer
     */
    private static function outputOfBeat(): string
    {
        $output = '';
        ob_start(static function (string $buffer) use (&$output): string {
            $output .= $buffer;
            return '';
        });
        StreamKeepAlive::beat();
        ob_end_flush();
        return $output;
    }

    public function testNothingIsSentOutsideAStream(): void
    {
        $this->expectOutputString('');
        StreamKeepAlive::beat();
        StreamKeepAlive::touch();
        StreamKeepAlive::beat();
        $this->assertFalse(StreamKeepAlive::isEnabled());
    }

    public function testCommentLineAfterTheInterval(): void
    {
        StreamKeepAlive::enable();
        self::silentFor(StreamKeepAlive::INTERVAL - 1);

        $this->assertSame('', self::outputOfBeat());

        self::silentFor(StreamKeepAlive::INTERVAL);
        $this->assertSame(": keepalive\n\n", self::outputOfBeat());
    }

    public function testOutputOfTheAnswerResetsTheInterval(): void
    {
        StreamKeepAlive::enable();
        self::silentFor(StreamKeepAlive::INTERVAL + 5);
        StreamKeepAlive::touch();

        $this->assertSame('', self::outputOfBeat());
    }
}
