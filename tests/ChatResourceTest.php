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
use ILIAS\Plugin\pcaic\Model\Attachment;
use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Table\ResourceTableRetrieval;
use PHPUnit\Framework\TestCase;

/**
 * Resources: own collection per resource, state shown to editors, rows of the table
 */
class ChatResourceTest extends TestCase
{
    public function testEachResourceHasItsOwnCollection(): void
    {
        $this->assertSame('res_12', Attachment::backgroundEntityId('chat_1', 12));
        // Background files from before resources existed keep the collection of the chat
        $this->assertSame('chat_1', Attachment::backgroundEntityId('chat_1', null));
    }

    public function testStateShowsWhatStillNeedsAttention(): void
    {
        $this->assertSame(
            AIChatPageComponentRAGStatus::PROCESSING,
            ChatResource::combineStates([AIChatPageComponentRAGStatus::COMPLETED, AIChatPageComponentRAGStatus::PROCESSING])
        );
        $this->assertSame(
            AIChatPageComponentRAGStatus::FAILED,
            ChatResource::combineStates([AIChatPageComponentRAGStatus::COMPLETED, AIChatPageComponentRAGStatus::FAILED])
        );
        $this->assertSame(AIChatPageComponentRAGStatus::COMPLETED, ChatResource::combineStates([AIChatPageComponentRAGStatus::COMPLETED]));
        $this->assertNull(ChatResource::combineStates([]));
        $this->assertNull(ChatResource::combineStates([null]));
    }

    public function testExcludedResourcesAreNotUsable(): void
    {
        $condition = ChatResource::usableAttachmentCondition('a.');
        $this->assertStringContainsString('a.resource_ref IS NULL', $condition);
        $this->assertStringContainsString('enabled = 1', $condition);
    }

    public function testTableRowsAndDisabledActionsWorkWithEveryIliasSignature(): void
    {
        $retrieval = new ResourceTableRetrieval(['1' => ['title' => 'A'], '2' => ['title' => 'B']]);
        // ILIAS 9 passes two further parameters, ILIAS 10 and later three
        $this->assertSame(2, $retrieval->getTotalRowCount(null, null));
        $this->assertSame(2, $retrieval->getTotalRowCount(null, null, null));
    }

    private static function resource(int $id, string $type, string $display, bool $download = true, bool $enabled = true): ChatResource
    {
        return ChatResource::fromRow([
            'id' => $id, 'chat_id' => 'chat_1', 'type' => $type, 'title' => 'R' . $id,
            'enabled' => $enabled ? 1 : 0, 'source_display' => $display, 'allow_download' => $download ? 1 : 0,
        ]);
    }

    public function testSourceDisplayAndDownloadOnlyRestrictTheChat(): void
    {
        $download = \ILIAS\Plugin\pcaic\Resource\ResourceProvider::OFFER_DOWNLOAD;
        $link = \ILIAS\Plugin\pcaic\Resource\ResourceProvider::OFFER_LINK;
        $none = \ILIAS\Plugin\pcaic\Resource\ResourceProvider::OFFER_NONE;

        $full = self::resource(1, ChatResource::TYPE_FILE, ChatResource::SOURCE_FULL);
        $this->assertTrue($full->isOffered(true, $download));
        $this->assertTrue($full->isOffered(true, $link));
        $this->assertFalse($full->isOffered(false, $download), 'the chat does not allow downloads');
        $this->assertFalse($full->isOffered(true, $none), 'the kind offers nothing');
        $this->assertSame(ChatResource::SOURCE_FULL, $full->getEffectiveSourceDisplay(true));
        $this->assertNull($full->getEffectiveSourceDisplay(false), 'the chat shows no sources');

        // Nothing offered: denied, name only, hidden, excluded
        $this->assertFalse(self::resource(2, ChatResource::TYPE_FILE, ChatResource::SOURCE_FULL, false)->isOffered(true, $download));
        $this->assertFalse(self::resource(3, ChatResource::TYPE_FILE, ChatResource::SOURCE_NAME)->isOffered(true, $download));
        $this->assertFalse(self::resource(4, ChatResource::TYPE_FILE, ChatResource::SOURCE_HIDDEN)->isOffered(true, $link));
        $this->assertFalse(self::resource(6, ChatResource::TYPE_FILE, ChatResource::SOURCE_FULL, true, false)->isOffered(true, $download));

        // Unknown values (e.g. a manipulated import) fall back to the behaviour of the chat
        $this->assertSame(ChatResource::SOURCE_FULL, self::resource(7, ChatResource::TYPE_FILE, 'everything')->getSourceDisplay());
    }

    public function testSourcesAreRestrictedPerResourceWithoutChangingTheirNumbers(): void
    {
        $index = [
            'files' => [
                'f-full' => self::resource(1, ChatResource::TYPE_FILE, ChatResource::SOURCE_FULL),
                'f-name' => self::resource(2, ChatResource::TYPE_FILE, ChatResource::SOURCE_NAME),
                'f-hidden' => self::resource(3, ChatResource::TYPE_FILE, ChatResource::SOURCE_HIDDEN),
            ],
            // Collection of resource 3, also found for passages of an earlier snapshot
            'collections' => ['c-3' => self::resource(3, ChatResource::TYPE_FILE, ChatResource::SOURCE_HIDDEN)],
        ];
        $passage = fn(string $file, string $collection, string $text): array => [
            'filename' => $file . '.pdf', 'page_numbers' => [2], 'text' => $text,
            'file_id' => $file, 'collection_id' => $collection,
        ];

        $sources = ChatResource::restrictSources([
            $passage('f-full', 'c-1', 'Full text'),
            $passage('f-name', 'c-2', 'Secret solution'),
            $passage('f-hidden', 'c-3', 'Hidden text'),
            $passage('f-old', 'c-3', 'Earlier snapshot of the hidden resource'),
            $passage('f-upload', 'c-session', 'Chat upload'),
        ], $index);

        $this->assertCount(5, $sources, 'source N stays citation [N]');
        $this->assertSame('Full text', $sources[0]['text']);
        $this->assertSame(1, $sources[0]['resource_id']);

        $this->assertSame('', $sources[1]['text'], 'name only: no passage');
        $this->assertSame('f-name.pdf', $sources[1]['filename']);
        $this->assertSame([2], $sources[1]['page_numbers']);

        $this->assertSame(['hidden' => true], $sources[2]);
        $this->assertSame(['hidden' => true], $sources[3], 'assigned via the collection of the resource');

        // Chat uploads are not resources: as set for the chat
        $this->assertSame('Chat upload', $sources[4]['text']);
        $this->assertNull($sources[4]['resource_id']);

        // In the format of the browser
        $formatted = \ILIAS\Plugin\pcaic\Model\ChatMessage::formatSources($sources);
        $this->assertSame(['hidden' => true], $formatted[2]);
        $this->assertNull($formatted[1]['text']);
        $this->assertNull($formatted[1]['excerpt']);
        $this->assertSame('Full text', $formatted[0]['text']);
    }
}
