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
use ReflectionProperty;

/**
 * Text goes to the RAG, the images go back into their messages
 */
class RagImagePartsTest extends TestCase
{
    private AIChatPageComponentLLM $llm;

    protected function setUp(): void
    {
        $this->llm = (new ReflectionClass(AIChatPageComponentRAMSES::class))->newInstanceWithoutConstructor();
        $logger = new ReflectionProperty(AIChatPageComponentLLM::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($this->llm, new \ilLogger());
    }

    private function call(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod(AIChatPageComponentLLM::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->llm, $arguments);
    }

    private static function image(string $name): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $name]];
    }

    /**
     * Earlier question with an image, answer, current question with text attachment and image
     */
    private function conversation(): array
    {
        return [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'What is this?'], self::image('old')]],
            ['role' => 'assistant', 'content' => 'A painting.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Does it fit the script?'],
                ['type' => 'text', 'text' => "[Attached file: notes.txt]\nLight"],
                self::image('new'),
            ]],
        ];
    }

    private function toText(array $messages): array
    {
        $indexes = [];
        $text = $this->call('toTextMessages', [$messages, &$indexes]);
        return [$text, $indexes];
    }

    public function testRagGetsOneTextPerMessage(): void
    {
        [$text, $indexes] = $this->toText($this->conversation());

        $this->assertSame([0, 1, 2], $indexes);
        $this->assertSame("Does it fit the script?\n[Attached file: notes.txt]\nLight", $text[2]['content']);
        foreach ($text as $message) {
            $this->assertIsString($message['content']);
        }
    }

    public function testImageOnlyMessageGetsAPlaceholder(): void
    {
        $messages = [['role' => 'user', 'content' => [self::image('only')]]];
        [$text, $indexes] = $this->toText($messages);

        $this->assertSame([0], $indexes);
        $this->assertNotSame('', trim($text[0]['content']));
    }

    public function testEmptyMessagesWithoutImagesAreLeftOut(): void
    {
        [$text, $indexes] = $this->toText([
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'user', 'content' => 'Question'],
        ]);

        $this->assertSame([1], $indexes);
        $this->assertCount(1, $text);
    }

    public function testImagesGoBackIntoTheirMessages(): void
    {
        $messages = $this->conversation();
        [$text, $indexes] = $this->toText($messages);

        // The RAG replaces the text of the last message only
        $returned = $text;
        $returned[2]['content'] = 'CONTEXT + Does it fit the script?';

        [$answer, $resources] = $this->call('restoreImageParts', [$messages, $indexes, $returned, ['bg']]);

        $this->assertSame(['bg'], $resources);
        $this->assertSame([['type' => 'text', 'text' => 'What is this?'], self::image('old')], $answer[0]['content']);
        $this->assertSame('A painting.', $answer[1]['content']);
        $this->assertSame(
            [['type' => 'text', 'text' => 'CONTEXT + Does it fit the script?'], self::image('new')],
            $answer[2]['content']
        );
    }

    public function testImagesBecomeContextIfTheRagChangedTheMessages(): void
    {
        $messages = $this->conversation();
        [$text, $indexes] = $this->toText($messages);

        // One message less than sent
        $returned = [$text[0], $text[2]];

        [$answer, $resources] = $this->call('restoreImageParts', [$messages, $indexes, $returned, []]);

        $this->assertCount(2, $answer);
        $this->assertIsString($answer[0]['content']);
        $this->assertCount(2, $resources);
        $this->assertSame('image_file', $resources[0]['kind']);
        $this->assertSame('image/jpeg', $resources[0]['mime_type']);
        $this->assertSame('data:image/jpeg;base64,new', $resources[1]['url']);
    }
}
