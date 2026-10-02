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

use ai\AIChatPageComponentLLM;
use ai\AIChatPageComponentRAMSES;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests the citation rules added to the system prompt in RAG mode
 */
class RagSystemPromptTest extends TestCase
{
    private function ragSystemPrompt(?string $prompt): string
    {
        $llm = (new ReflectionClass(AIChatPageComponentRAMSES::class))->newInstanceWithoutConstructor();
        $llm->setPrompt($prompt);
        $method = new ReflectionMethod(AIChatPageComponentLLM::class, 'getRagSystemPrompt');
        $method->setAccessible(true);
        return $method->invoke($llm);
    }

    public function testRulesAreAppendedToTheChatPrompt(): void
    {
        $this->assertSame(
            "You are a tutor.\n\n" . AIChatPageComponentLLM::RAG_CITATION_INSTRUCTION,
            $this->ragSystemPrompt("You are a tutor.\n")
        );
    }

    public function testRulesWithoutChatPrompt(): void
    {
        $this->assertSame(AIChatPageComponentLLM::RAG_CITATION_INSTRUCTION, $this->ragSystemPrompt(null));
        $this->assertSame(AIChatPageComponentLLM::RAG_CITATION_INSTRUCTION, $this->ragSystemPrompt('  '));
    }
}
