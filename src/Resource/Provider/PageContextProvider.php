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

namespace ILIAS\Plugin\pcaic\Resource\Provider;

use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Resource\PageTextExtractor;
use ILIAS\Plugin\pcaic\Resource\ResourceContext;
use ILIAS\Plugin\pcaic\Resource\ResourceProvider;
use ILIAS\Plugin\pcaic\Resource\SnapshotDocument;

/**
 * The content of the page containing the chat, as one text file
 *
 * Available without RAG. Whoever can read the chat can read its page, so no further check.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class PageContextProvider implements ResourceProvider
{
    public function __construct(private ?PageTextExtractor $extractor = null)
    {
    }

    public function getType(): string
    {
        return ChatResource::TYPE_PAGE_CONTEXT;
    }

    public function requiresRag(): bool
    {
        return false;
    }

    public function getCandidates(ResourceContext $context): array
    {
        // Added with its own action, at most once per chat
        return [];
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        $text = $this->extractor()->textOfChatPage($context->getChat());
        return trim($text) === '' ? [] : [SnapshotDocument::ofText(ChatResource::PAGE_CONTEXT_FILENAME, $text)];
    }

    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
    {
        return $this->extractor()->lastChangeOfChatPage($context->getChat());
    }

    public function isAccessible(ChatResource $resource, int $user_id): bool
    {
        return true;
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        return null;
    }

    private function extractor(): PageTextExtractor
    {
        return $this->extractor ??= new PageTextExtractor();
    }

    /**
     * The title of the resource ("Content of this page") instead of the technical file name
     */
    public function getSourceName(ChatResource $resource, string $filename): string
    {
        return $resource->getTitle() !== '' ? $resource->getTitle() : $filename;
    }

    public function getSourceKind(): ?string
    {
        return 'page';
    }

    /**
     * Icon of the object containing the page, e.g. of the course
     */
    public function getSourceIcon(ChatResource $resource): ?string
    {
        try {
            $chat = new \ILIAS\Plugin\pcaic\Model\ChatConfig($resource->getChatId());
            return \ILIAS\Plugin\pcaic\Resource\EnvironmentObjects::iconOf((int) $chat->getParentId());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
