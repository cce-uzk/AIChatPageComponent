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

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\UI\LimitedTextarea;
use PHPUnit\Framework\TestCase;

/**
 * Line breaks of long texts and the counter of remaining characters
 */
class LimitedTextareaTest extends TestCase
{
    public function testLineBreaksAreSavedAsTheBrowserCountsThem(): void
    {
        $this->assertSame("Eins\nZwei\nDrei\n", LimitedTextarea::normalizeLineBreaks("Eins\r\nZwei\rDrei\n"));
    }

    public function testPromptOfTheFullLengthWithUmlautsAndLineBreaksFits(): void
    {
        // As submitted by the browser: 12000 characters, line breaks as \r\n
        $line = str_repeat('ä', 99);
        $submitted = implode("\r\n", array_fill(0, 120, $line));
        $config = (new ReflectionClass(ChatConfig::class))->newInstanceWithoutConstructor();
        $config->setSystemPrompt($submitted);
        $this->assertSame(ChatConfig::MAX_SYSTEM_PROMPT_LENGTH - 1, mb_strlen($config->getSystemPrompt()));
        $this->assertStringNotContainsString("\r", $config->getSystemPrompt());
    }

    public function testCounterScriptQuotesTheId(): void
    {
        $script = LimitedTextarea::remainderScript('il_ui_fw_"x');
        $this->assertStringContainsString('getElementById("il_ui_fw_\"x")', $script);
        $this->assertStringContainsString('field.maxLength - field.value.length', $script);
    }
}
