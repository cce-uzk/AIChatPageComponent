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

use ILIAS\Plugin\pcaic\Model\ChatConfig;

/**
 * The chat, its environment and the person a provider works for
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class ResourceContext
{
    /**
     * @param int|null $env_ref_id Reference of the object containing the page of the chat, as called;
     *                             null where it is not known (cron job, answers)
     */
    public function __construct(
        private ChatConfig $chat,
        private ?int $env_ref_id,
        private int $user_id
    ) {
    }

    public function getChat(): ChatConfig
    {
        return $this->chat;
    }

    public function getEnvRefId(): ?int
    {
        return $this->env_ref_id;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }
}
