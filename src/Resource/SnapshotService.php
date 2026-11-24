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
 * Stores the documents of a snapshot as the files of a resource, for every kind alike
 *
 * A new snapshot replaces the previous one, also in the RAG: the old files are deleted (and
 * removed from the RAG), the new ones are uploaded into the same collection of the resource.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class SnapshotService
{
    /**
     * @param SnapshotDocument[] $documents
     * @param string|null $source_version Fingerprint of the source, see ResourceProvider::getSourceVersion()
     * @return bool False if there was nothing to store; the resource is then left unchanged
     */
    public function store(ChatResource $resource, array $documents, int $user_id, ?string $source_version = null): bool
    {
        if ($documents === []) {
            return false;
        }

        // Store the new files first: if that fails, the resource keeps its previous snapshot
        $stored_ids = [];
        foreach ($documents as $document) {
            $stored_ids[] = $document->getStoredId()
                ?? ChatResource::storeText((string) $document->getText(), $document->getFilename());
        }

        if ($resource->getId() !== null) {
            foreach ($resource->getAttachments() as $attachment) {
                $attachment->delete();
            }
        }

        $resource->setSnapshotAt(date('Y-m-d H:i:s'));
        $resource->setSourceVersion($source_version);
        if ($resource->getSourceState() !== null || $source_version !== null) {
            $resource->setSourceState(ChatResource::STATE_OK);
        }
        $resource->save();

        foreach ($stored_ids as $stored_id) {
            $resource->addFile($stored_id, $user_id);
        }
        return true;
    }
}
