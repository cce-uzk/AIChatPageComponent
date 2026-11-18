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

    private ?int $id = null;
    private string $chat_id = '';
    private string $type = self::TYPE_FILE;
    private string $title = '';
    private ?int $source_ref_id = null;
    private bool $enabled = true;
    private ?string $snapshot_at = null;
    private ?int $created_by = null;
    private ?string $created_at = null;

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
}
