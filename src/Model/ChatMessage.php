<?php

namespace ILIAS\Plugin\pcaic\Model;

/**
 * ChatMessage Model - Messages bound to sessions
 * 
 * Represents individual messages in a chat session
 * Messages are bound to sessions, not directly to chats
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
     * Get all messages for a session
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
     * Get recent messages for a session (for memory limit)
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
        
        // Reverse to get chronological order
        return array_reverse($messages);
    }

    /**
     * Delete all messages for a session
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
     * Load message from database
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
            
            return true;
        }
        
        return false;
    }

    /**
     * Save message to database
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
            'timestamp' => ['timestamp', $this->timestamp->format('Y-m-d H:i:s')]
        ];

        if ($this->messageId) {
            // Update (rare case)
            $db->update('pcaic_messages', $values, ['message_id' => ['integer', $this->messageId]]);
        } else {
            // Insert - ILIAS style with nextId()
            $this->messageId = $db->nextId('pcaic_messages');
            $values['message_id'] = ['integer', $this->messageId];
            $db->insert('pcaic_messages', $values);
        }

        return true;
    }

    /**
     * Delete message
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
     * Check if message exists
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
        return $db->fetchAssoc($result) !== false;
    }

    /**
     * Get attachments for this message
     */
    public function getAttachments(): array
    {
        if (!$this->messageId) {
            return [];
        }

        // Use existing Attachment class which references message_id
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

    // Getters and Setters
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
     * Add attachment to this message
     */
    public function addAttachment(int $attachment_id): bool
    {
        if (!$this->messageId) {
            // Message must be saved first to have an ID
            return false;
        }

        // Load the attachment and set the message_id
        $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);
        if (!$attachment->getId()) {
            // Attachment doesn't exist
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
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'session_id' => $this->sessionId,
            'role' => $this->role,
            'content' => $this->message, // Use 'content' for API consistency
            'message' => $this->message, // Keep 'message' for backward compatibility
            'timestamp' => $this->timestamp?->format('Y-m-d H:i:s'),
            'attachments' => array_map(fn($att) => $att->toArray(), $this->getAttachments())
        ];
    }
}