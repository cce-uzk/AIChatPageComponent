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

namespace ILIAS\Plugin\pcaic\Model;

use ILIAS\Plugin\pcaic\Storage\ResourceStakeholder;

/**
 * Something a chat may use besides the conversation: an uploaded file or the content of its page
 *
 * A resource is a snapshot from the time it was added or updated: changes of its source only
 * reach the chat when the resource is updated. Its content is stored as background file(s) in
 * pcaic_attachments (resource_ref), which have a collection of their own in the RAG service, so
 * that a resource can be excluded without removing it from the RAG.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatResource
{
    public const TYPE_FILE = 'file';
    public const TYPE_PAGE_CONTEXT = 'page_context';

    /** File name of the snapshot of the page content */
    public const PAGE_CONTEXT_FILENAME = 'page_content.txt';

    /** Shown as source with name, pages and passages */
    public const SOURCE_FULL = 'full';
    /** Shown as source with its name only: no passages, no download */
    public const SOURCE_NAME = 'name';
    /** Not shown as source, its citations are removed from the answer */
    public const SOURCE_HIDDEN = 'hidden';
    public const SOURCE_DISPLAYS = [self::SOURCE_FULL, self::SOURCE_NAME, self::SOURCE_HIDDEN];

    private ?int $id = null;
    private string $chat_id = '';
    private string $type = self::TYPE_FILE;
    private string $title = '';
    private ?int $source_ref_id = null;
    private bool $enabled = true;
    private ?string $snapshot_at = null;
    private ?int $created_by = null;
    private ?string $created_at = null;
    private string $source_display = self::SOURCE_FULL;
    private bool $allow_download = true;

    public static function fromRow(array $row): self
    {
        $resource = new self();
        $resource->id = (int) $row['id'];
        $resource->chat_id = (string) $row['chat_id'];
        $resource->type = (string) $row['type'];
        $resource->title = (string) ($row['title'] ?? '');
        $resource->source_ref_id = isset($row['source_ref_id']) ? (int) $row['source_ref_id'] : null;
        $resource->enabled = (int) ($row['enabled'] ?? 1) === 1;
        $resource->snapshot_at = $row['snapshot_at'] ?? null;
        $resource->created_by = isset($row['created_by']) ? (int) $row['created_by'] : null;
        $resource->created_at = $row['created_at'] ?? null;
        $resource->setSourceDisplay((string) ($row['source_display'] ?? self::SOURCE_FULL));
        $resource->allow_download = (int) ($row['allow_download'] ?? 1) === 1;
        return $resource;
    }

    public static function find(int $id): ?self
    {
        $db = self::db();
        $row = $db->fetchAssoc($db->query("SELECT * FROM pcaic_resources WHERE id = " . $db->quote($id, 'integer')));
        return $row ? self::fromRow($row) : null;
    }

    /**
     * @return self[] Page content first, then in the order they were added
     */
    public static function getByChatId(string $chat_id): array
    {
        $db = self::db();
        $result = $db->query(
            "SELECT * FROM pcaic_resources WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " ORDER BY CASE WHEN type = " . $db->quote(self::TYPE_PAGE_CONTEXT, 'text') . " THEN 0 ELSE 1 END, id"
        );
        $resources = [];
        while ($row = $db->fetchAssoc($result)) {
            $resources[] = self::fromRow($row);
        }
        return $resources;
    }

    public static function findPageContext(string $chat_id): ?self
    {
        foreach (self::getByChatId($chat_id) as $resource) {
            if ($resource->getType() === self::TYPE_PAGE_CONTEXT) {
                return $resource;
            }
        }
        return null;
    }

    /**
     * SQL condition for attachment rows that may be used: chat uploads, background files from before
     * resources existed and files of included resources
     *
     * @param string $alias Table alias of pcaic_attachments with dot, e.g. "a."
     */
    public static function usableAttachmentCondition(string $alias = ''): string
    {
        return "(" . $alias . "resource_ref IS NULL OR " . $alias . "resource_ref IN"
            . " (SELECT id FROM pcaic_resources WHERE enabled = 1))";
    }

    public function save(): void
    {
        $db = self::db();
        $values = [
            'chat_id' => ['text', $this->chat_id],
            'type' => ['text', $this->type],
            'title' => ['text', mb_substr($this->title, 0, 255)],
            'source_ref_id' => ['integer', $this->source_ref_id],
            'enabled' => ['integer', $this->enabled ? 1 : 0],
            'snapshot_at' => ['timestamp', $this->snapshot_at],
            'created_by' => ['integer', $this->created_by],
            'source_display' => ['text', $this->source_display],
            'allow_download' => ['integer', $this->allow_download ? 1 : 0],
        ];

        if ($this->id === null) {
            $this->id = $db->nextId('pcaic_resources');
            $this->created_at = date('Y-m-d H:i:s');
            $db->insert('pcaic_resources', $values + [
                'id' => ['integer', $this->id],
                'created_at' => ['timestamp', $this->created_at],
            ]);
            return;
        }

        $db->update('pcaic_resources', $values, ['id' => ['integer', $this->id]]);
    }

    /**
     * Remove the resource with its files from the RAG, the Resource Storage and the database
     */
    public function delete(): void
    {
        if ($this->id === null) {
            return;
        }
        foreach ($this->getAttachments() as $attachment) {
            $attachment->delete();
        }
        $db = self::db();
        $db->manipulate("DELETE FROM pcaic_resources WHERE id = " . $db->quote($this->id, 'integer'));
        $this->id = null;
    }

    /**
     * Add a file of the Resource Storage as the content of this resource
     */
    public function addFile(string $irss_id, int $user_id): Attachment
    {
        $attachment = new Attachment();
        $attachment->setMessageId(null);
        $attachment->setBackgroundFile(true);
        $attachment->setChatId($this->chat_id);
        $attachment->setUserId($user_id);
        $attachment->setResourceId($irss_id);
        $attachment->setResourceRef($this->id);
        $attachment->setTimestamp(date('Y-m-d H:i:s'));
        $attachment->save();
        return $attachment;
    }

    /**
     * Store a text as a file in the Resource Storage
     *
     * @return string Identification of the new resource
     */
    public static function storeText(string $text, string $filename): string
    {
        global $DIC;
        $stream = \ILIAS\Filesystem\Stream\Streams::ofString($text);
        return $DIC->resourceStorage()->manage()->stream($stream, new ResourceStakeholder(), $filename)->serialize();
    }

    /**
     * @return Attachment[]
     */
    public function getAttachments(): array
    {
        if ($this->id === null) {
            return [];
        }
        $db = self::db();
        $result = $db->query("SELECT id FROM pcaic_attachments WHERE resource_ref = " . $db->quote($this->id, 'integer') . " ORDER BY id");
        $attachments = [];
        while ($row = $db->fetchAssoc($result)) {
            $attachment = Attachment::loadById((int) $row['id']);
            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }
        return $attachments;
    }

    /**
     * State of the resource in the RAG service shown to editors: the least advanced state of its
     * files, see AIChatPageComponentRAGStatus::displayState()
     *
     * @return string|null Null if no file of the resource is handled by the RAG
     */
    public function getDisplayState(): ?string
    {
        if ($this->id === null) {
            return null;
        }
        $db = self::db();
        $result = $db->query(
            "SELECT rag_status, rag_retry_at FROM pcaic_attachments WHERE resource_ref = " . $db->quote($this->id, 'integer')
        );
        $states = [];
        while ($row = $db->fetchAssoc($result)) {
            $states[] = \ai\AIChatPageComponentRAGStatus::displayState(
                $row['rag_status'] !== null ? (string) $row['rag_status'] : null,
                $row['rag_retry_at'] ?? null
            );
        }
        return self::combineStates($states);
    }

    /**
     * The state that matters most to an editor of several states of files
     *
     * @param array<string|null> $states
     */
    public static function combineStates(array $states): ?string
    {
        $order = [
            \ai\AIChatPageComponentRAGStatus::PENDING,
            \ai\AIChatPageComponentRAGStatus::PROCESSING,
            \ai\AIChatPageComponentRAGStatus::FAILED,
            \ai\AIChatPageComponentRAGStatus::REJECTED,
            \ai\AIChatPageComponentRAGStatus::EMPTY,
            \ai\AIChatPageComponentRAGStatus::SCANNED,
            \ai\AIChatPageComponentRAGStatus::COMPLETED,
            \ai\AIChatPageComponentRAGStatus::SKIPPED,
        ];
        foreach ($order as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }
        return null;
    }

    private static function db(): \ilDBInterface
    {
        global $DIC;
        return $DIC->database();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatId(): string
    {
        return $this->chat_id;
    }

    public function setChatId(string $chat_id): void
    {
        $this->chat_id = $chat_id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * Title as stored; resources migrated from background files take the name of their file
     */
    public function getTitle(): string
    {
        if ($this->title === '' && $this->type === self::TYPE_FILE) {
            foreach ($this->getAttachments() as $attachment) {
                return $attachment->getTitle();
            }
        }
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getSourceRefId(): ?int
    {
        return $this->source_ref_id;
    }

    public function setSourceRefId(?int $source_ref_id): void
    {
        $this->source_ref_id = $source_ref_id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getSnapshotAt(): ?string
    {
        return $this->snapshot_at;
    }

    public function setSnapshotAt(?string $snapshot_at): void
    {
        $this->snapshot_at = $snapshot_at;
    }

    public function getCreatedBy(): ?int
    {
        return $this->created_by;
    }

    public function setCreatedBy(?int $created_by): void
    {
        $this->created_by = $created_by;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    /**
     * How the resource is shown as source, as set for the resource (see getEffectiveSourceDisplay())
     */
    public function getSourceDisplay(): string
    {
        return $this->source_display;
    }

    public function setSourceDisplay(string $source_display): void
    {
        $this->source_display = in_array($source_display, self::SOURCE_DISPLAYS, true) ? $source_display : self::SOURCE_FULL;
    }

    /**
     * Download allowed as set for the resource (see isDownloadable())
     */
    public function isAllowDownload(): bool
    {
        return $this->allow_download;
    }

    public function setAllowDownload(bool $allow_download): void
    {
        $this->allow_download = $allow_download;
    }

    /**
     * Whether a resource of this type can be offered for download at all: the content of the
     * page is no file of its own
     */
    public function canBeDownloaded(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    /**
     * How the resource is shown as source within the settings of its chat
     *
     * @return string|null One of the SOURCE_ constants; null if the chat shows no sources
     */
    public function getEffectiveSourceDisplay(bool $chat_shows_sources): ?string
    {
        return $chat_shows_sources ? $this->source_display : null;
    }

    /**
     * Whether the file of the resource may be downloaded from the source list: the chat allows
     * downloads, the resource is shown with its passages, is a file, included and allows it
     */
    public function isDownloadable(bool $chat_allows_downloads): bool
    {
        return $chat_allows_downloads
            && $this->allow_download
            && $this->source_display === self::SOURCE_FULL
            && $this->canBeDownloaded()
            && $this->enabled;
    }

    /**
     * Resources of a chat by the RAG file and collection IDs of their files
     *
     * A collection belongs to one resource and stays when the resource is updated, so passages of
     * earlier snapshots in stored answers are still assigned via the collection.
     *
     * @return array{files: array<string, self>, collections: array<string, self>}
     */
    public static function getRagIndex(string $chat_id): array
    {
        $resources = [];
        foreach (self::getByChatId($chat_id) as $resource) {
            $resources[$resource->getId()] = $resource;
        }
        $index = ['files' => [], 'collections' => []];
        if ($resources === []) {
            return $index;
        }

        $db = self::db();
        $result = $db->query(
            "SELECT resource_ref, rag_remote_file_id, rag_collection_id FROM pcaic_attachments"
            . " WHERE chat_id = " . $db->quote($chat_id, 'text') . " AND resource_ref IS NOT NULL"
        );
        while ($row = $db->fetchAssoc($result)) {
            $resource = $resources[(int) $row['resource_ref']] ?? null;
            if ($resource === null) {
                continue;
            }
            if (!empty($row['rag_remote_file_id'])) {
                $index['files'][(string) $row['rag_remote_file_id']] = $resource;
            }
            if (!empty($row['rag_collection_id'])) {
                $index['collections'][(string) $row['rag_collection_id']] = $resource;
            }
        }
        return $index;
    }

    /**
     * Apply the source settings of the resources to the sources of an answer
     *
     * The list keeps its order and length, because source N belongs to citation [N]: a hidden
     * source becomes a placeholder ['hidden' => true], whose citations the browser removes. A
     * source shown with its name only loses its passage text. Each source gets the ID of its
     * resource ('resource_id', null for chat uploads and removed resources).
     *
     * @param array $metadata Sources as stored with the answer, see AIChatPageComponentRAG::chunksToSources()
     * @param array{files: array<string, self>, collections: array<string, self>} $index See getRagIndex()
     */
    public static function restrictSources(array $metadata, array $index): array
    {
        $restricted = [];
        foreach (array_values($metadata) as $source) {
            $source = is_array($source) ? $source : [];
            $resource = self::findForPassage(
                $index,
                isset($source['file_id']) ? (string) $source['file_id'] : null,
                isset($source['collection_id']) ? (string) $source['collection_id'] : null
            );
            $display = $resource !== null ? $resource->getSourceDisplay() : self::SOURCE_FULL;

            if ($display === self::SOURCE_HIDDEN) {
                $restricted[] = ['hidden' => true];
                continue;
            }
            if ($display === self::SOURCE_NAME) {
                $source['text'] = '';
            }
            $source['resource_id'] = $resource?->getId();
            $restricted[] = $source;
        }
        return $restricted;
    }

    /**
     * Resource a passage of the RAG belongs to, see getRagIndex()
     *
     * @param array{files: array<string, self>, collections: array<string, self>} $index
     * @return self|null Null for chat uploads and removed resources
     */
    public static function findForPassage(array $index, ?string $file_id, ?string $collection_id): ?self
    {
        if ($file_id !== null && $file_id !== '' && isset($index['files'][$file_id])) {
            return $index['files'][$file_id];
        }
        if ($collection_id !== null && $collection_id !== '' && isset($index['collections'][$collection_id])) {
            return $index['collections'][$collection_id];
        }
        return null;
    }
}
