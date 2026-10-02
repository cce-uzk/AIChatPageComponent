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
use platform\AIChatPageComponentConfig;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests the limit for image data per request (max_image_data_mb)
 */
class ImageDataBudgetTest extends TestCase
{
    private AIChatPageComponentLLM $llm;

    protected function setUp(): void
    {
        AIChatPageComponentConfig::reset(['max_image_data_mb' => 1]);

        $this->llm = (new ReflectionClass(AIChatPageComponentRAMSES::class))->newInstanceWithoutConstructor();
        $logger = new ReflectionProperty(AIChatPageComponentLLM::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($this->llm, new \ilLogger());

        $this->call('resetImageDataBudget');
    }

    public function testImagesAreAcceptedUntilTheLimitIsReached(): void
    {
        $half = str_repeat('a', 600 * 1024);

        $this->assertTrue($this->call('consumeImageDataBudget', $half, 'first.png'));
        $this->assertFalse($this->call('consumeImageDataBudget', $half, 'second.png'));
        $this->assertTrue($this->call('consumeImageDataBudget', str_repeat('a', 100 * 1024), 'small.png'));
    }

    public function testResetRestoresTheBudget(): void
    {
        $this->assertFalse($this->call('consumeImageDataBudget', str_repeat('a', 2 * 1024 * 1024), 'large.png'));

        $this->call('resetImageDataBudget');
        $messages = [['role' => 'user', 'content' => 'Question']];
        $this->callWithReference($messages);

        $this->assertSame('Question', $messages[0]['content']);
    }

    public function testNoteListsOmittedImagesInTheLastMessage(): void
    {
        $this->call('consumeImageDataBudget', str_repeat('a', 2 * 1024 * 1024), 'scan.pdf');

        $text_messages = [['role' => 'user', 'content' => 'Question']];
        $this->callWithReference($text_messages);
        $this->assertStringContainsString('scan.pdf', $text_messages[0]['content']);

        $multimodal_messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Question']]]];
        $this->callWithReference($multimodal_messages);
        $this->assertSame('text', $multimodal_messages[0]['content'][1]['type']);
        $this->assertStringContainsString('scan.pdf', $multimodal_messages[0]['content'][1]['text']);
    }

    /**
     * @return mixed
     */
    private function call(string $method, ...$arguments)
    {
        $reflection = new ReflectionMethod(AIChatPageComponentLLM::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->llm, ...$arguments);
    }

    private function callWithReference(array &$messages): void
    {
        $reflection = new ReflectionMethod(AIChatPageComponentLLM::class, 'addOmittedImagesNote');
        $reflection->setAccessible(true);
        $reflection->invokeArgs($this->llm, [&$messages]);
    }
}
