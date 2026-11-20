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

    /** Folders below the container that are searched for files */
    private const MAX_DEPTH = 5;

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

        $added = [];
        foreach (ChatResource::getByChatId($context->getChat()->getChatId()) as $resource) {
            if ($resource->getType() === self::TYPE && $resource->getSourceRefId() !== null) {
                $added[$resource->getSourceRefId()] = true;
            }
        }

        $policy = FileTypePolicy::forService(
            \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($context->getChat()->getAiService())
        );
        $candidates = [];
        $this->collect($env_ref_id, [], 0, $context->getUserId(), $policy, $added, $candidates);
        usort($candidates, fn(Candidate $a, Candidate $b): int => strnatcasecmp($a->getTitle(), $b->getTitle()));
        return $candidates;
    }

    /**
     * Files of a container and its folders that the person may read and the RAG takes
     *
     * @param string[] $path Titles of the folders down to this container
     * @param array<int, bool> $added Reference IDs already added to the chat
     * @param Candidate[] $candidates
     */
    private function collect(
        int $ref_id,
        array $path,
        int $depth,
        int $user_id,
        FileTypePolicy $policy,
        array $added,
        array &$candidates
    ): void {
        global $DIC;
        foreach ($DIC->repositoryTree()->getChildsByTypeFilter($ref_id, ['file', 'fold']) as $child) {
            $child_ref = (int) $child['child'];
            if (!$DIC->access()->checkAccessOfUser($user_id, 'read', '', $child_ref)) {
                continue;
            }
            if ($child['type'] === 'fold') {
                if ($depth < self::MAX_DEPTH) {
                    $this->collect($child_ref, [...$path, (string) $child['title']], $depth + 1, $user_id, $policy, $added, $candidates);
                }
                continue;
            }
            try {
                $file = new \ilObjFile($child_ref, true);
                $suffix = (string) $file->getFileExtension();
                $has_content = self::hasContent((string) $file->getResourceId());
            } catch (\Throwable $e) {
                continue;
            }
            // Files without content in the Resource Storage (e.g. failed uploads) give no snapshot
            if (!$has_content || !$policy->isRagType($suffix)) {
                continue;
            }
            $candidates[] = new Candidate(
                self::TYPE,
                $child_ref,
                (int) $child['obj_id'],
                self::label($path, (string) $child['title']),
                isset($added[$child_ref])
            );
        }
    }

    /**
     * Title of a candidate with the folders it is in, e.g. "Week 1 › Slides.pdf"
     *
     * @param string[] $path
     */
    public static function label(array $path, string $title): string
    {
        return implode(' › ', [...$path, $title]);
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !$this->exists($ref_id)) {
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
        if ($ref_id === null || !$this->exists($ref_id)) {
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
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !$this->exists($ref_id)) {
            return false;
        }
        global $DIC;
        return $DIC->access()->checkAccessOfUser($user_id, 'read', '', $ref_id);
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        return $ref_id === null ? null : \ilLink::_getStaticLink($ref_id, 'file');
    }

    /**
     * The reference is in the repository tree and not in the trash
     */
    private function exists(int $ref_id): bool
    {
        global $DIC;
        return $DIC->repositoryTree()->isInTree($ref_id) && !\ilObject::_isInTrash($ref_id);
    }
}
