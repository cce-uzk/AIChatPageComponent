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

namespace ILIAS\Plugin\pcaic\Resource;

use ILIAS\Plugin\pcaic\Model\ChatResource;

/**
 * Resources of a chat shown to learners as the material the chat knows
 *
 * A resource is listed if the person may use it for answers (ResourceAccessFilter), the chat
 * shows sources, the resource is shown as source at least by name, its kind lists it and it can
 * contribute to answers. Hidden resources are never listed but still used. Whether a resource can
 * be opened is decided by its offer, as for the source list of answers, not here.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class MaterialList
{
    /** The resource contributes to answers */
    public const READY = 'ready';
    /** Its files are still being processed for the RAG */
    public const PROCESSING = 'processing';

    public function __construct(private ResourceProviderRegistry $registry)
    {
    }

    /**
     * @param ChatResource[] $resources Resources of the chat
     * @param int[] $excluded IDs of resources the person may not use, see ResourceAccessFilter
     * @param bool $rag_active The chat uses the RAG: the state of the files decides
     * @param callable(ChatResource): array<string|null> $file_states RAG states of the files of a resource
     * @return array<int, array{resource: ChatResource, provider: ResourceProvider, state: string}>
     */
    public function entries(array $resources, array $excluded, bool $chat_shows_sources, bool $rag_active, callable $file_states): array
    {
        $entries = [];
        foreach ($resources as $resource) {
            $provider = $this->registry->get($resource->getType());
            if ($provider === null || in_array((int) $resource->getId(), $excluded, true)
                || !self::isShown($resource, $provider, $chat_shows_sources)) {
                continue;
            }
            $state = $rag_active ? self::stateOfFiles($file_states($resource)) : self::READY;
            if ($state !== null) {
                $entries[] = ['resource' => $resource, 'provider' => $provider, 'state' => $state];
            }
        }
        return $entries;
    }

    /**
     * Included, listed by its kind and shown as source at least by name
     */
    public static function isShown(ChatResource $resource, ResourceProvider $provider, bool $chat_shows_sources): bool
    {
        $display = $resource->getEffectiveSourceDisplay($chat_shows_sources);
        return $resource->isEnabled()
            && $provider->isListedForLearners()
            && in_array($display, [ChatResource::SOURCE_FULL, ChatResource::SOURCE_NAME], true);
    }

    /**
     * State of a resource for learners from the RAG states of its files
     *
     * Ready as soon as one file can be searched (a wiki with one failed page still answers);
     * processing while files are on their way; null if no file can ever be searched.
     *
     * @param array<string|null> $states
     */
    public static function stateOfFiles(array $states): ?string
    {
        $ready = [
            \ai\AIChatPageComponentRAGStatus::COMPLETED,
            \ai\AIChatPageComponentRAGStatus::SCANNED,
            \ai\AIChatPageComponentRAGStatus::SKIPPED,
        ];
        // A failed file is tried again
        $on_the_way = [
            \ai\AIChatPageComponentRAGStatus::PENDING,
            \ai\AIChatPageComponentRAGStatus::PROCESSING,
            \ai\AIChatPageComponentRAGStatus::FAILED,
            null,
        ];
        foreach ($states as $state) {
            if (in_array($state, $ready, true)) {
                return self::READY;
            }
        }
        foreach ($states as $state) {
            if (in_array($state, $on_the_way, true)) {
                return self::PROCESSING;
            }
        }
        return null;
    }
}
