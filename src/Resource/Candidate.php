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

/**
 * An object of the environment that can be added as resource
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class Candidate
{
    public function __construct(
        private string $provider_type,
        private int $ref_id,
        private int $obj_id,
        private string $title,
        private bool $added = false
    ) {
    }

    public function getProviderType(): string
    {
        return $this->provider_type;
    }

    public function getRefId(): int
    {
        return $this->ref_id;
    }

    public function getObjId(): int
    {
        return $this->obj_id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Already a resource of the chat
     */
    public function isAdded(): bool
    {
        return $this->added;
    }
}
