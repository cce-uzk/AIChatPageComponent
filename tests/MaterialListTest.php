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

use ai\AIChatPageComponentRAGStatus as Rag;
use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Resource\MaterialList;
use ILIAS\Plugin\pcaic\Resource\Provider\PageContextProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\UploadedFileProvider;
use ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Which resources learners see in the material list of a chat
 */
class MaterialListTest extends TestCase
{
    private static function file(int $id, string $display = 'full', bool $enabled = true): ChatResource
    {
        return ChatResource::fromRow([
            'id' => $id, 'chat_id' => 'c', 'type' => ChatResource::TYPE_FILE,
            'source_display' => $display, 'enabled' => $enabled ? 1 : 0,
        ]);
    }

    private static function ids(array $entries): array
    {
        return array_map(static fn(array $entry): int => (int) $entry['resource']->getId(), $entries);
    }

    private static function list(): MaterialList
    {
        return new MaterialList(new ResourceProviderRegistry([new UploadedFileProvider(), new PageContextProvider()]));
    }

    public function testListedFromNameOnlyHiddenNever(): void
    {
        $resources = [self::file(1, 'full'), self::file(2, 'name'), self::file(3, 'hidden')];
        $entries = self::list()->entries($resources, [], true, false, static fn(): array => []);
        $this->assertSame([1, 2], self::ids($entries));
    }

    public function testNothingIsListedIfTheChatShowsNoSources(): void
    {
        $entries = self::list()->entries([self::file(1)], [], false, false, static fn(): array => []);
        $this->assertSame([], $entries);
    }

    public function testResourcesThePersonMayNotUseOrThatAreOffAreLeftOut(): void
    {
        $resources = [self::file(1), self::file(2), self::file(3, 'full', false)];
        $entries = self::list()->entries($resources, [2], true, false, static fn(): array => []);
        $this->assertSame([1], self::ids($entries));
    }

    public function testThePageContentIsNotListed(): void
    {
        $page = ChatResource::fromRow([
            'id' => 9, 'chat_id' => 'c', 'type' => ChatResource::TYPE_PAGE_CONTEXT,
            'source_display' => 'name', 'enabled' => 1,
        ]);
        $this->assertSame([], self::list()->entries([$page], [], true, false, static fn(): array => []));
    }

    public function testWithTheRagTheStateOfTheFilesDecides(): void
    {
        $states = [1 => [Rag::COMPLETED], 2 => [Rag::PROCESSING], 3 => [Rag::REJECTED, Rag::EMPTY], 4 => [Rag::FAILED, Rag::COMPLETED]];
        $resources = [self::file(1), self::file(2), self::file(3), self::file(4)];
        $entries = self::list()->entries($resources, [], true, true, static fn(ChatResource $r): array => $states[$r->getId()]);

        $this->assertSame([1, 2, 4], self::ids($entries));
        $this->assertSame(
            [MaterialList::READY, MaterialList::PROCESSING, MaterialList::READY],
            array_column($entries, 'state')
        );
    }

    public function testWithoutTheRagEverythingIsReady(): void
    {
        $entries = self::list()->entries([self::file(1)], [], true, false, static fn(): array => [Rag::REJECTED]);
        $this->assertSame([MaterialList::READY], array_column($entries, 'state'));
    }

    public function testStateOfFiles(): void
    {
        $this->assertSame(MaterialList::READY, MaterialList::stateOfFiles([Rag::SCANNED]));
        $this->assertSame(MaterialList::READY, MaterialList::stateOfFiles([Rag::SKIPPED]));
        $this->assertSame(MaterialList::PROCESSING, MaterialList::stateOfFiles([null]));
        $this->assertSame(MaterialList::PROCESSING, MaterialList::stateOfFiles([Rag::FAILED]));
        $this->assertNull(MaterialList::stateOfFiles([Rag::REJECTED]));
        $this->assertNull(MaterialList::stateOfFiles([]));
    }
}
