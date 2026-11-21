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
use ILIAS\Plugin\pcaic\Resource\ResourceContext;
use ILIAS\Plugin\pcaic\Resource\ResourceProvider;

/**
 * A file an editor uploaded for the chat
 *
 * The upload itself is the snapshot: there is no source to take a new one from or to compare,
 * and whoever can read the chat may use it. Available without RAG.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class UploadedFileProvider implements ResourceProvider
{
    public function getType(): string
    {
        return ChatResource::TYPE_FILE;
    }

    public function requiresRag(): bool
    {
        return false;
    }

    public function getCandidates(ResourceContext $context): array
    {
        // Added by upload
        return [];
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        // No source to take a new snapshot from
        return [];
    }

    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
    {
        return null;
    }

    public function isAccessible(ChatResource $resource, int $user_id): bool
    {
        return true;
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        return null;
    }

    public function getSourceName(ChatResource $resource, string $filename): string
    {
        return $filename;
    }

    public function getSourceKind(): ?string
    {
        return null;
    }
}
