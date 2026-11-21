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
use ILIAS\Plugin\pcaic\Resource\Candidate;
use ILIAS\Plugin\pcaic\Resource\EnvironmentObjects;
use ILIAS\Plugin\pcaic\Resource\ResourceContext;
use ILIAS\Plugin\pcaic\Resource\ResourceProvider;
use ILIAS\Plugin\pcaic\Resource\SnapshotDocument;
use ILIAS\Plugin\pcaic\Storage\ResourceStakeholder;
use ILIAS\Plugin\pcaic\Validation\FileTypePolicy;

/**
 * A file object (type "file") in the container of the chat or its folders
 *
 * The snapshot is a copy of the current version of the file. Only with RAG, and only files of
 * the RAG file types. Who may not read the file object in ILIAS does not get its content.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ContainerFileProvider implements ResourceProvider
{
    public const TYPE = 'obj_file';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function requiresRag(): bool
    {
        return true;
    }

    public function getCandidates(ResourceContext $context): array
    {
        $env_ref_id = $context->getEnvRefId();
        if ($env_ref_id === null) {
            return [];
        }

        $added = self::addedRefIds($context, self::TYPE);
        $policy = FileTypePolicy::forService(
            \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($context->getChat()->getAiService())
        );
        $candidates = [];
        foreach ((new EnvironmentObjects())->find($env_ref_id, 'file', $context->getUserId()) as $object) {
            try {
                $file = new \ilObjFile($object['ref_id'], true);
                $suffix = (string) $file->getFileExtension();
                $has_content = self::hasContent((string) $file->getResourceId());
            } catch (\Throwable $e) {
                continue;
            }
            // Files without content in the Resource Storage (e.g. failed uploads) give no snapshot
            if (!$has_content || !$policy->isRagType($suffix)) {
                continue;
            }
            $candidates[] = new Candidate(self::TYPE, $object['ref_id'], $object['obj_id'], $object['label'], isset($added[$object['ref_id']]));
        }
        return $candidates;
    }

    /**
     * References already added to the chat as resources of a kind
     *
     * @return array<int, bool>
     */
    public static function addedRefIds(ResourceContext $context, string $type): array
    {
        $added = [];
        foreach (ChatResource::getByChatId($context->getChat()->getChatId()) as $resource) {
            if ($resource->getType() === $type && $resource->getSourceRefId() !== null) {
                $added[$resource->getSourceRefId()] = true;
            }
        }
        return $added;
    }

    /**
     * Title of a candidate with the folders it is in, e.g. "Week 1 › Slides.pdf"
     *
     * @param string[] $path
     */
    public static function label(array $path, string $title): string
    {
        return EnvironmentObjects::label($path, $title);
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return [];
        }

        global $DIC;
        try {
            $file = new \ilObjFile($ref_id, true);
            if (!self::hasContent((string) $file->getResourceId())) {
                return [];
            }
            $irss = $DIC->resourceStorage();
            $identification = $irss->manage()->find($file->getResourceId());
            if ($identification === null) {
                return [];
            }
            // A copy owned by the plugin: the snapshot stays when the file object changes
            $copy = $irss->manage()->clone($identification, new ResourceStakeholder());
            return [SnapshotDocument::ofStoredFile((string) $file->getFileName(), $copy->serialize())];
        } catch (\Throwable $e) {
            $DIC->logger()->pcaic()->warning("Snapshot of file object $ref_id failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Whether a file object has content in the Resource Storage: ILIAS returns "" or "-" otherwise
     */
    public static function hasContent(string $resource_id): bool
    {
        return $resource_id !== '' && $resource_id !== '-';
    }

    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return null;
        }
        try {
            return 'v' . (new \ilObjFile($ref_id, true))->getVersion();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isAccessible(ChatResource $resource, int $user_id): bool
    {
        return EnvironmentObjects::isReadable($resource->getSourceRefId(), $user_id);
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        return $ref_id === null ? null : \ilLink::_getStaticLink($ref_id, 'file');
    }

    public function getSourceName(ChatResource $resource, string $filename): string
    {
        return $filename;
    }

    public function getSourceKind(): ?string
    {
        return null;
    }

    public function getSourceIcon(ChatResource $resource): ?string
    {
        // Files: the icon of their extension says more than the general file icon
        return null;
    }

    /**
     * The file object in ILIAS, which checks the permission itself; never the copy of the snapshot
     */
    public function getSourceOffer(): string
    {
        return self::OFFER_LINK;
    }
}
