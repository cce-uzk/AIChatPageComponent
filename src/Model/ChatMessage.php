<?php

namespace ILIAS\Plugin\pcaic\Model;

/**
 * Chat message model
 *
 * Represents individual messages within a chat session.
 * Messages belong to sessions and include role, content, and timestamp.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatMessage
{
    private ?int $messageId = null;
    private string $sessionId;
    private string $role;
    private string $message;
    private ?\DateTime $timestamp = null;
    private ?array $metadata = null;  // RAG source citations
    private ?array $usage = null;     // Token usage data

    /**
     * Constructor
     *
     * @param int|null $messageId Optional message ID to load existing message
     */
    public function __construct(int $messageId = null)
    {
        if ($messageId) {
            $this->messageId = $messageId;
            $this->load();
        } else {
            $this->timestamp = new \DateTime();
        }
    }

    /**
     * Get all messages for session
     *
     * @param string $sessionId Session ID
     * @return ChatMessage[] Array of messages in chronological order
     */
    public static function getForSession(string $sessionId): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages 
                  WHERE session_id = " . $db->quote($sessionId, 'text') . "
                  ORDER BY timestamp ASC";
        
        $result = $db->query($query);
        $messages = [];
        
        while ($row = $db->fetchAssoc($result)) {
            $messages[] = new self((int)$row['message_id']);
        }
        
        return $messages;
    }

    /**
     * Get recent messages for session with limit
     *
     * @param string $sessionId Session ID
     * @param int $limit Maximum number of messages to retrieve
     * @return ChatMessage[] Array of recent messages in chronological order
     */
    public static function getRecentForSession(string $sessionId, int $limit): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages 
                  WHERE session_id = " . $db->quote($sessionId, 'text') . "
                  ORDER BY timestamp DESC 
                  LIMIT " . $limit;
        
        $result = $db->query($query);
        $messages = [];
        
        while ($row = $db->fetchAssoc($result)) {
            $messages[] = new self((int)$row['message_id']);
        }

        return array_reverse($messages);
    }

    /**
     * Delete all messages for session
     *
     * @param string $sessionId Session ID
     * @return bool Always returns true
     */
    public static function deleteForSession(string $sessionId): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_messages WHERE session_id = " . $db->quote($sessionId, 'text');
        $db->manipulate($query);
        
        return true;
    }

    /**
     * Load message data from database
     *
     * @return bool True if message was found and loaded, false otherwise
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_messages WHERE message_id = " . $db->quote($this->messageId, 'integer');
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            $this->sessionId = $row['session_id'];
            $this->role = $row['role'];
            $this->message = $row['message'];
            $this->timestamp = $row['timestamp'] ? new \DateTime($row['timestamp']) : null;

            // Load metadata (RAG sources) if present
            if (!empty($row['metadata'])) {
                $this->metadata = json_decode($row['metadata'], true);
            }

            // Load usage (token data) if present
            if (!empty($row['usage'])) {
                $this->usage = json_decode($row['usage'], true);
            }

            return true;
        }
        
        return false;
    }

    /**
     * Save message to database
     *
     * Performs INSERT for new messages or UPDATE for existing ones.
     *
     * @return bool Always returns true
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        if (!$this->timestamp) {
            $this->timestamp = new \DateTime();
        }

        $values = [
            'session_id' => ['text', $this->sessionId],
            'role' => ['text', $this->role],
            'message' => ['clob', $this->message],
            'timestamp' => ['timestamp', $this->timestamp->format('Y-m-d H:i:s')],
            'metadata' => ['clob', $this->metadata ? json_encode($this->metadata) : null],
            'usage' => ['clob', $this->usage ? json_encode($this->usage) : null]
        ];

        if ($this->messageId) {
            $db->update('pcaic_messages', $values, ['message_id' => ['integer', $this->messageId]]);
        } else {
            $this->messageId = $db->nextId('pcaic_messages');
            $values['message_id'] = ['integer', $this->messageId];
            $db->insert('pcaic_messages', $values);
        }

        return true;
    }

    /**
     * Delete message from database
     *
     * @return bool True if message was deleted, false if message ID is not set
     */
    public function delete(): bool
    {
        if (!$this->messageId) {
            return false;
        }

        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_messages WHERE message_id = " . $db->quote($this->messageId, 'integer');
        $db->manipulate($query);

        return true;
    }

    /**
     * Check if message exists in database
     *
     * @return bool True if message exists, false otherwise
     */
    public function exists(): bool
    {
        if (!$this->messageId) {
            return false;
        }

        global $DIC;
        $db = $DIC->database();

        $query = "SELECT message_id FROM pcaic_messages WHERE message_id = " . $db->quote($this->messageId, 'integer');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== null;
    }

    /**
     * Get attachments for message
     *
     * @return Attachment[] Array of attachments
     */
    public function getAttachments(): array
    {
        if (!$this->messageId) {
            return [];
        }

        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_attachments WHERE message_id = " . $db->quote($this->messageId, 'integer');
        $result = $db->query($query);

        $attachments = [];
        while ($row = $db->fetchAssoc($result)) {
            $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment((int)$row['id']);
            $attachments[] = $attachment;
        }

        return $attachments;
    }

    public function getMessageId(): ?int { return $this->messageId; }
    public function getSessionId(): string { return $this->sessionId; }
    public function setSessionId(string $sessionId): void { $this->sessionId = $sessionId; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $role): void { $this->role = $role; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): void { $this->message = $message; }
    public function getTimestamp(): ?\DateTime { return $this->timestamp; }
    public function setTimestamp(\DateTime $timestamp): void { $this->timestamp = $timestamp; }

    /**
     * Get RAG metadata (source citations)
     * @return array|null Array of source objects with filename, page_numbers, text
     */
    public function getMetadata(): ?array { return $this->metadata; }

    /**
     * Set RAG metadata (source citations)
     * @param array|null $metadata Array of source objects from RAG response
     */
    public function setMetadata(?array $metadata): void { $this->metadata = $metadata; }

    /**
     * Get token usage data
     * @return array|null Array with prompt_tokens, completion_tokens, total_tokens
     */
    public function getUsage(): ?array { return $this->usage; }

    /**
     * Set token usage data
     * @param array|null $usage Token usage from AI response
     */
    public function setUsage(?array $usage): void { $this->usage = $usage; }

    /**
     * Check if message has RAG sources
     * @return bool True if metadata contains sources
     */
    public function hasSources(): bool
    {
        return !empty($this->metadata) && is_array($this->metadata);
    }

    /**
     * Get formatted sources for display
     * @return array Array of simplified source objects for frontend
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
     * Bind attachment to message
     *
     * Message must be saved (have ID) before attachments can be added.
     *
     * @param int $attachment_id Attachment ID
     * @return bool True if successful, false if message not saved or attachment not found
     */
    public function addAttachment(int $attachment_id): bool
    {
        if (!$this->messageId) {
            return false;
        }

        $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);
        if (!$attachment->getId()) {
            return false;
        }

        try {
            $attachment->setMessageId($this->messageId);
            $attachment->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convert message to array representation
     *
     * @return array Associative array containing message data and attachments
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'session_id' => $this->sessionId,
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