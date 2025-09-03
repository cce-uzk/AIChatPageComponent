<?php

namespace ILIAS\Plugin\pcaic\Model;

/**
 * ChatSession Model - User-Chat Relationship
 * 
 * Represents a user's session with a specific chat
 * One user can have multiple sessions with different chats
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatSession
{
    private string $sessionId;
    private string $chatId;
    private int $userId;
    private string $sessionName = '';
    private ?\DateTime $createdAt = null;
    private ?\DateTime $lastActivity = null;
    private bool $isActive = true;

    public function __construct(string $sessionId = null)
    {
        if ($sessionId) {
            $this->sessionId = $sessionId;
            $this->load();
        } else {
            $this->sessionId = uniqid('session_', true);
            $this->createdAt = new \DateTime();
            $this->lastActivity = new \DateTime();
        }
    }

    /**
     * Create new session for user and chat
     */
    public static function createForUserAndChat(int $userId, string $chatId, string $sessionName = ''): self
    {
        $session = new self();
        $session->userId = $userId;
        $session->chatId = $chatId;
        $session->sessionName = $sessionName;
        return $session;
    }

    /**
     * Find existing session for user and chat
     */
    public static function findForUserAndChat(int $userId, string $chatId): ?self
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions 
                  WHERE user_id = " . $db->quote($userId, 'integer') . "
                  AND chat_id = " . $db->quote($chatId, 'text') . "
                  AND is_active = 1
                  ORDER BY last_activity DESC LIMIT 1";
        
        $result = $db->query($query);
        if ($row = $db->fetchAssoc($result)) {
            return new self($row['session_id']);
        }
        
        return null;
    }

    /**
     * Get or create session for user and chat
     */
    public static function getOrCreateForUserAndChat(int $userId, string $chatId, string $sessionName = ''): self
    {
        $session = self::findForUserAndChat($userId, $chatId);
        if (!$session) {
            $session = self::createForUserAndChat($userId, $chatId, $sessionName);
            $session->save();
        }
        return $session;
    }

    /**
     * Load session from database
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_sessions WHERE session_id = " . $db->quote($this->sessionId, 'text');
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            $this->chatId = $row['chat_id'];
            $this->userId = (int)$row['user_id'];
            $this->sessionName = $row['session_name'] ?? '';
            $this->createdAt = $row['created_at'] ? new \DateTime($row['created_at']) : null;
            $this->lastActivity = $row['last_activity'] ? new \DateTime($row['last_activity']) : null;
            $this->isActive = (bool)$row['is_active'];
            
            return true;
        }
        
        return false;
    }

    /**
     * Save session to database
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $this->lastActivity = new \DateTime();

        // Check if exists
        $query = "SELECT session_id FROM pcaic_sessions WHERE session_id = " . $db->quote($this->sessionId, 'text');
        $result = $db->query($query);
        $exists = $db->fetchAssoc($result);

        $values = [
            'chat_id' => ['text', $this->chatId],
            'user_id' => ['integer', $this->userId],
            'session_name' => ['text', $this->sessionName],
            'last_activity' => ['timestamp', $this->lastActivity->format('Y-m-d H:i:s')],
            'is_active' => ['integer', $this->isActive ? 1 : 0]
        ];

        if ($exists) {
            // Update
            $db->update('pcaic_sessions', $values, ['session_id' => ['text', $this->sessionId]]);
        } else {
            // Insert
            $values['session_id'] = ['text', $this->sessionId];
            $values['created_at'] = ['timestamp', $this->createdAt->format('Y-m-d H:i:s')];
            $db->insert('pcaic_sessions', $values);
        }

        return true;
    }

    /**
     * Delete session and all its messages
     */
    public function delete(): bool
    {
        global $DIC;
        $db = $DIC->database();

        // Delete will cascade to messages due to foreign keys
        $query = "DELETE FROM pcaic_sessions WHERE session_id = " . $db->quote($this->sessionId, 'text');
        $db->manipulate($query);

        return true;
    }

    /**
     * Mark session as inactive
     */
    public function deactivate(): bool
    {
        $this->isActive = false;
        return $this->save();
    }

    /**
     * Update last activity
     */
    public function touch(): bool
    {
        $this->lastActivity = new \DateTime();
        return $this->save();
    }

    /**
     * Check if session exists
     */
    public function exists(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions WHERE session_id = " . $db->quote($this->sessionId, 'text');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== false;
    }

    /**
     * Get all messages for this session
     */
    public function getMessages(): array
    {
        return ChatMessage::getForSession($this->sessionId);
    }
    
    /**
     * Get recent messages for this session with limit
     */
    public function getRecentMessages(int $limit = 10): array
    {
        return ChatMessage::getRecentForSession($this->sessionId, $limit);
    }

    /**
     * Add message to this session
     */
    public function addMessage(string $role, string $content): ChatMessage
    {
        $message = new ChatMessage();
        $message->setSessionId($this->sessionId);
        $message->setRole($role);
        $message->setMessage($content);
        $message->save();
        
        // Update session activity
        $this->touch();
        
        return $message;
    }

    /**
     * Get chat configuration for this session
     */
    public function getChatConfig(): ?ChatConfig
    {
        return new ChatConfig($this->chatId);
    }

    // Getters and Setters
    public function getSessionId(): string { return $this->sessionId; }
    public function getChatId(): string { return $this->chatId; }
    public function setChatId(string $chatId): void { $this->chatId = $chatId; }
    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $userId): void { $this->userId = $userId; }
    public function getSessionName(): string { return $this->sessionName; }
    public function setSessionName(string $sessionName): void { $this->sessionName = $sessionName; }
    public function getCreatedAt(): ?\DateTime { return $this->createdAt; }
    public function getLastActivity(): ?\DateTime { return $this->lastActivity; }
    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $isActive): void { $this->isActive = $isActive; }

    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'chat_id' => $this->chatId,
            'user_id' => $this->userId,
            'session_name' => $this->sessionName,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'last_activity' => $this->lastActivity?->format('Y-m-d H:i:s'),
            'is_active' => $this->isActive
        ];
    }
}