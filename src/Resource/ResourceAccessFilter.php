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
 * Resources a person may not use for an answer
 *
 * A snapshot belongs to the chat, its source to the permissions of ILIAS: a file that is offline
 * or not readable for a person must not reach that person through the chat. Checked for every
 * answer; as each resource has its own collection in the RAG, the resources left out are simply
 * not searched.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ResourceAccessFilter
{
    public function __construct(private ResourceProviderRegistry $registry)
    {
    }

    /**
     * @param ChatResource[] $resources
     * @return int[] IDs of the resources the person may not use; also those of unknown kinds
     */
    public function excludedIds(array $resources, int $user_id): array
    {
        $excluded = [];
        foreach ($resources as $resource) {
            $provider = $this->registry->get($resource->getType());
            if ($provider === null || !$provider->isAccessible($resource, $user_id)) {
                $excluded[] = (int) $resource->getId();
            }
        }
        return $excluded;
    }

    /**
     * @return int[]
     */
    public function excludedIdsOfChat(string $chat_id, int $user_id): array
    {
        return $this->excludedIds(ChatResource::getByChatId($chat_id), $user_id);
    }
}
