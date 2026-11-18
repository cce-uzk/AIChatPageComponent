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
}
