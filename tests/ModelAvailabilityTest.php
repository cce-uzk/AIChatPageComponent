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
use ai\AIChatPageComponentOpenAI;
use ai\AIChatPageComponentRAMSES;
use PHPUnit\Framework\TestCase;
use platform\AIChatPageComponentConfig;
use ReflectionMethod;

/**
 * Tests the selection of models offered to editors
 */
class ModelAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        AIChatPageComponentConfig::reset([
            'cached_models' => [
                'mistral-small' => 'Mistral Small',
                'e5-mistral-7b-instruct' => 'E5',
                'qwen3-embedding-8b' => 'Qwen Embedding',
                'gpt-oss-120b' => 'GPT OSS',
            ],
        ]);
    }

    /**
     * @dataProvider chatModelProvider
     */
    public function testChatModelsAreRecognised(string $model_id): void
    {
        $this->assertFalse(AIChatPageComponentLLM::looksLikeNonChatModel($model_id));
    }

    public static function chatModelProvider(): array
    {
        return [
            ['mistral-small-3-2-24b-instruct-2506'],
            ['openai-gpt-oss-120b'],
            ['qwen3-8-27b'],
            ['gpt-4o'],
            ['o3-mini'],
        ];
    }

    /**
     * @dataProvider nonChatModelProvider
     */
    public function testNonChatModelsAreRecognised(string $model_id): void
    {
        $this->assertTrue(AIChatPageComponentLLM::looksLikeNonChatModel($model_id));
    }

    public static function nonChatModelProvider(): array
    {
        return [
            ['e5-mistral-7b-instruct'],
            ['qwen3-embedding-8b'],
            ['text-embedding-3-large'],
            ['bge-m3'],
            ['whisper-1'],
            ['tts-1'],
            ['dall-e-3'],
            ['gpt-image-1'],
            ['gpt-4o-realtime-preview'],
            ['gpt-4o-transcribe'],
            ['omni-moderation-latest'],
            ['gpt-3.5-turbo-instruct'],
            ['gpt-5.1-codex'],
        ];
    }

    public function testWithoutSelectionNonChatModelsAreHidden(): void
    {
        $this->assertSame(['mistral-small', 'gpt-oss-120b'], array_keys(AIChatPageComponentRAMSES::getAvailableModels()));
    }

    public function testSavedSelectionIsApplied(): void
    {
        AIChatPageComponentConfig::set('ramses_available_models', ['gpt-oss-120b', 'unknown-model']);

        $this->assertSame(['gpt-oss-120b'], array_keys(AIChatPageComponentRAMSES::getAvailableModels()));
    }

    public function testDefaultModelIsAlwaysAvailable(): void
    {
        $normalize = new ReflectionMethod(AIChatPageComponentLLM::class, 'normalizeAvailableModels');
        $normalize->setAccessible(true);

        $this->assertSame(['gpt-oss-120b', 'mistral-small'], $normalize->invoke(null, ['gpt-oss-120b'], 'mistral-small'));
        $this->assertSame(['mistral-small'], $normalize->invoke(null, ['mistral-small'], 'mistral-small'));
        $this->assertSame([], $normalize->invoke(null, null, ''));
    }

    public function testRefreshKeepsSelectionAndAddsNewChatModelsOnly(): void
    {
        AIChatPageComponentConfig::set('ramses_available_models', ['mistral-small', 'e5-mistral-7b-instruct']);

        $this->storeRefreshedModels(AIChatPageComponentRAMSES::class, [
            'mistral-small' => 'Mistral Small',
            'e5-mistral-7b-instruct' => 'E5',
            'new-chat-model' => 'New',
            'new-embedding-model' => 'New Embedding',
        ]);

        // gpt-oss-120b was removed from the API, the manually enabled e5 model stays
        $this->assertSame(
            ['mistral-small', 'e5-mistral-7b-instruct', 'new-chat-model'],
            AIChatPageComponentConfig::get('ramses_available_models')
        );
    }

    public function testRefreshWithoutSavedSelectionKeepsNameBasedDefault(): void
    {
        $this->storeRefreshedModels(AIChatPageComponentRAMSES::class, ['mistral-small' => 'Mistral Small']);

        $this->assertNull(AIChatPageComponentConfig::get('ramses_available_models'));
    }

    public function testServicesUseSeparateModelLists(): void
    {
        $this->storeRefreshedModels(AIChatPageComponentOpenAI::class, [
            'gpt-4o' => 'GPT-4o',
            'gpt-image-1' => 'GPT Image',
        ]);

        $this->assertSame(['gpt-4o'], array_keys(AIChatPageComponentOpenAI::getAvailableModels()));
        $this->assertArrayHasKey('gpt-4o', AIChatPageComponentConfig::get('openai_cached_models'));
        $this->assertArrayNotHasKey('gpt-4o', AIChatPageComponentConfig::get('cached_models'));
    }

    /**
     * @param class-string<AIChatPageComponentLLM> $service_class
     */
    private function storeRefreshedModels(string $service_class, array $models): void
    {
        $store = new ReflectionMethod($service_class, 'storeRefreshedModels');
        $store->setAccessible(true);
        $store->invoke(null, $models);
    }
}
