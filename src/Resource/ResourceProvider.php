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
 * One kind of resource: where its content comes from, how a change of its source is noticed
 * and who may use it
 *
 * The core (resources tab, snapshots, RAG, sources) knows no kind of resource; it works only with
 * what the providers return. A new kind is a new provider registered in ResourceProviderRegistry.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
interface ResourceProvider
{
    /** The source list offers the file of the snapshot for download */
    public const OFFER_DOWNLOAD = 'download';
    /** The source list offers a link to the source in ILIAS, see getSourceLink() */
    public const OFFER_LINK = 'link';
    /** The source list offers nothing, e.g. for the content of the page of the chat */
    public const OFFER_NONE = 'none';

    /**
     * Stored in pcaic_resources.type, e.g. "file", "page_context", later "obj_wiki"
     */
    public function getType(): string;

    /**
     * Only usable while the chat uses the RAG (objects of the environment, wikis, ...)
     */
    public function requiresRag(): bool;

    /**
     * Objects of the environment that the person of the context may add
     *
     * @return Candidate[]
     */
    public function getCandidates(ResourceContext $context): array;

    /**
     * Documents of a new snapshot of the source of the resource
     *
     * @return SnapshotDocument[] Empty if the source has no content (nothing is stored then)
     */
    public function createSnapshot(ResourceContext $context, ChatResource $resource): array;

    /**
     * Cheap fingerprint of the current source, compared with the one stored at the snapshot
     *
     * @return string|null Null if the kind has no source to compare (uploaded files)
     */
    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string;

    /**
     * Whether this person may use the resource now, checked for every answer and source list
     */
    public function isAccessible(ChatResource $resource, int $user_id): bool;

    /**
     * What the source list offers for a source of this kind, if the chat and the resource allow it
     *
     * @return string One of the OFFER_ constants
     */
    public function getSourceOffer(): string;

    /**
     * Link to the source in ILIAS, for the table and the source list
     */
    public function getSourceLink(ChatResource $resource): ?string;

    /**
     * Name of a passage's source in the source list and the citation chips
     *
     * @param string $filename Name of the file of the snapshot the passage comes from
     */
    public function getSourceName(ChatResource $resource, string $filename): string;

    /**
     * Kind of source for its icon in the source list, e.g. "wiki" or "page"
     *
     * @return string|null Null: the icon follows the file name (PDF, image, ...)
     */
    public function getSourceKind(): ?string;

    /**
     * Icon of the source in the source list and the citation chips, the one ILIAS shows for it
     *
     * @return string|null URL of the icon; null: the icon follows the kind or the file name
     */
    public function getSourceIcon(ChatResource $resource): ?string;
}
