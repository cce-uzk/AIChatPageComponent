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
 * Compares the sources of resources with their snapshots, when the resources tab is opened
 *
 * A changed source is only shown ("new state available"); the snapshot is never updated on
 * its own. A source that is gone or no longer in the environment of the chat is not used.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class SourceStateService
{
    public function __construct(private ResourceProviderRegistry $registry)
    {
    }

    /**
     * State of the source of a resource
     *
     * @param string|null $current_version Fingerprint of the source now; null if it is gone or has none
     * @param int|null $current_env_ref_id Environment of the chat now; null if unknown
     * @return string|null One of ChatResource::STATE_; null for resources without a source (uploads)
     */
    public static function determine(ChatResource $resource, ?string $current_version, ?int $current_env_ref_id): ?string
    {
        $has_object = $resource->getSourceRefId() !== null;
        if ($has_object) {
            if ($current_version === null) {
                return ChatResource::STATE_MISSING;
            }
            if ($resource->getEnvRefId() !== null && $current_env_ref_id !== null
                && $resource->getEnvRefId() !== $current_env_ref_id) {
                return ChatResource::STATE_OUTSIDE;
            }
        }
        if ($current_version !== null && $resource->getSourceVersion() !== null
            && $current_version !== $resource->getSourceVersion()) {
            return ChatResource::STATE_CHANGED;
        }
        return $has_object || $resource->getSourceVersion() !== null ? ChatResource::STATE_OK : null;
    }

    /**
     * Update and store the states of the resources of a chat
     *
     * @param ChatResource[] $resources
     */
    public function refresh(ResourceContext $context, array $resources): void
    {
        foreach ($resources as $resource) {
            $provider = $this->registry->get($resource->getType());
            if ($provider === null) {
                continue;
            }
            try {
                $version = $provider->getSourceVersion($context, $resource);
            } catch (\Throwable $e) {
                $version = null;
            }
            $state = self::determine($resource, $version, $context->getEnvRefId());
            if ($state !== $resource->getSourceState()) {
                $resource->setSourceState($state);
                $resource->save();
            }
        }
    }
}
