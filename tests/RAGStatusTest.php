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

use ai\AIChatPageComponentRAGStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests the retry delays and the mapping of RAG processing states
 */
class RAGStatusTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function retryDelayProvider(): array
    {
        return [
            'no failure' => [0, 300],
            'first failure' => [1, 300],
            'second failure' => [2, 600],
            'fifth failure' => [5, 4800],
            'seventh failure' => [7, 19200],
            'capped' => [8, 21600],
            'large count stays capped' => [1000, 21600],
        ];
    }

    /**
     * @dataProvider retryDelayProvider
     */
    public function testRetryDelay(int $failed_count, int $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAGStatus::retryDelay($failed_count));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function remoteStatusProvider(): array
    {
        return [
            'completed' => ['COMPLETED', AIChatPageComponentRAGStatus::COMPLETED],
            'lower case' => ['completed', AIChatPageComponentRAGStatus::COMPLETED],
            'failed' => ['FAILED', AIChatPageComponentRAGStatus::FAILED],
            'pending' => ['PENDING', AIChatPageComponentRAGStatus::PROCESSING],
            'processing' => ['PROCESSING', AIChatPageComponentRAGStatus::PROCESSING],
            'unknown' => ['SOMETHING_NEW', AIChatPageComponentRAGStatus::PROCESSING],
        ];
    }

    /**
     * @dataProvider remoteStatusProvider
     */
    public function testMapRemoteStatus(string $remote, string $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAGStatus::mapRemoteStatus($remote));
    }
}
