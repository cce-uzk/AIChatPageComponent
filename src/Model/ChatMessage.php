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

namespace ILIAS\Plugin\pcaic\Model;

/**
 * Message of a chat session
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatMessage
{
    private ?int $message_id = null;
    private string $session_id;
    private string $role;
    private string $message;
    private ?\DateTime $timestamp = null;
    private ?array $metadata = null;  // RAG sources of an assistant message
    private ?array $usage = null;     // Token usage of an assistant message

    /**
     * @param int|null $message_id Loads this message if given
     */
    public function __construct(int $message_id = null)
    {
        if ($message_id) {
            $this->message_id = $message_id;
            $this->load();
        } else {
            $this->timestamp = new \DateTime();
        }
    }

    /**
     * @return ChatMessage[] All messages of the session in chronological order
     */
    public static function getForSession(string $session_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages 
                  WHERE session_id = " . $db->quote($session_id, 'text') . "
                  ORDER BY timestamp ASC, message_id ASC";

        $result = $db->query($query);
        $messages = [];

        while ($row = $db->fetchAssoc($result)) {
            $messages[] = new self((int) $row['message_id']);
        }

        return $messages;
    }

    /**
     * @return ChatMessage[] The last $limit messages of the session in chronological order
     */
    public static function getRecentForSession(string $session_id, int $limit): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages 
                  WHERE session_id = " . $db->quote($session_id, 'text') . "
                  ORDER BY timestamp DESC, message_id DESC
                  LIMIT " . $limit;

        $result = $db->query($query);
        $messages = [];

        while ($row = $db->fetchAssoc($result)) {
            $messages[] = new self((int) $row['message_id']);
        }

        return array_reverse($messages);
    }

    public static function deleteForSession(string $session_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_messages WHERE session_id = " . $db->quote($session_id, 'text');
        $db->manipulate($query);

        return true;
    }

    /**
     * @return bool True if the message was found
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_messages WHERE message_id = " . $db->quote($this->message_id, 'integer');
        $result = $db->query($query);

        if ($row = $db->fetchAssoc($result)) {
            $this->session_id = $row['session_id'];
            $this->role = $row['role'];
            $this->message = $row['message'];
            $this->timestamp = $row['timestamp'] ? new \DateTime($row['timestamp']) : null;

            if (!empty($row['metadata'])) {
                $this->metadata = json_decode($row['metadata'], true);
            }

            if (!empty($row['usage'])) {
                $this->usage = json_decode($row['usage'], true);
            }

            return true;
        }

        return false;
    }

    /**
     * Insert or update the message
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        if (!$this->timestamp) {
            $this->timestamp = new \DateTime();
        }

        $values = [
            'session_id' => ['text', $this->session_id],
            'role' => ['text', $this->role],
            'message' => ['clob', $this->message],
            'timestamp' => ['timestamp', $this->timestamp->format('Y-m-d H:i:s')],
            'metadata' => ['clob', $this->metadata ? json_encode($this->metadata) : null],
            'usage' => ['clob', $this->usage ? json_encode($this->usage) : null]
        ];

        if ($this->message_id) {
            $db->update('pcaic_messages', $values, ['message_id' => ['integer', $this->message_id]]);
        } else {
            $this->message_id = $db->nextId('pcaic_messages');
            $values['message_id'] = ['integer', $this->message_id];
            $db->insert('pcaic_messages', $values);
        }

        return true;
    }

    /**
     * @return bool False if the message has not been saved yet
     */
    public function delete(): bool
    {
        if (!$this->message_id) {
            return false;
        }

        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_messages WHERE message_id = " . $db->quote($this->message_id, 'integer');
        $db->manipulate($query);

        return true;
    }

    public function exists(): bool
    {
        if (!$this->message_id) {
            return false;
        }

        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages WHERE message_id = " . $db->quote($this->message_id, 'integer');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== null;
    }

    public function getAttachments(): array
    {
        if (!$this->message_id) {
            return [];
        }

        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_attachments WHERE message_id = " . $db->quote($this->message_id, 'integer');
        $result = $db->query($query);

        $attachments = [];
        while ($row = $db->fetchAssoc($result)) {
            $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']);
            $attachments[] = $attachment;
        }

        return $attachments;
    }

    public function getMessageId(): ?int
    {
        return $this->message_id;
    }
    public function getSessionId(): string
    {
        return $this->session_id;
    }
    public function setSessionId(string $session_id): void
    {
        $this->session_id = $session_id;
    }
    public function getRole(): string
    {
        return $this->role;
    }
    public function setRole(string $role): void
    {
        $this->role = $role;
    }
    public function getMessage(): string
    {
        return $this->message;
    }
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }
    public function getTimestamp(): ?\DateTime
    {
        return $this->timestamp;
    }
    public function setTimestamp(\DateTime $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * @return array|null RAG sources (filename, page_numbers, text, ...)
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @return array|null Token usage (prompt_tokens, completion_tokens, total_tokens)
     */
    public function getUsage(): ?array
    {
        return $this->usage;
    }

    public function setUsage(?array $usage): void
    {
        $this->usage = $usage;
    }

    public function hasSources(): bool
    {
        return !empty($this->metadata) && is_array($this->metadata);
    }

    /**
     * Sources in the format used by the frontend
     */
    public function getFormattedSources(): array
    {
        if (!$this->hasSources()) {
            return [];
        }

        $sources = [];
        foreach ($this->metadata as $source) {
            $sources[] = [
                'filename' => $source['filename'] ?? 'Unknown',
                'pages' => $source['page_numbers'] ?? [],
                'excerpt' => isset($source['text']) ? mb_substr($source['text'], 0, 200) . '...' : null
            ];
        }
        return $sources;
    }

    /**
     * Bind an uploaded attachment to this message
     *
     * @return bool False if the message has not been saved yet or the attachment does not exist
     */
    public function addAttachment(int $attachment_id): bool
    {
        if (!$this->message_id) {
            return false;
        }

        $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);
        if (!$attachment->getId()) {
            return false;
        }

        try {
            $attachment->setMessageId($this->message_id);
            $attachment->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->message_id,
            'session_id' => $this->session_id,
            'role' => $this->role,
            'content' => $this->message,
            'message' => $this->message,
            'timestamp' => $this->timestamp?->format('Y-m-d H:i:s'),
            'attachments' => array_map(fn($att) => $att->toArray(), $this->getAttachments()),
            'sources' => $this->getFormattedSources(),
            'usage' => $this->usage
        ];
    }
}
