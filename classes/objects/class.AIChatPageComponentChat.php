<?php declare(strict_types=1);

namespace objects;

use DateTime;
use platform\AIChatPageComponentException;

/**
 * Class AIChatPageComponentChat
 * Based on Chat from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentChat
{
    private string $id;
    private string $title;
    private DateTime $created_at;
    private int $user_id = 0;
    private DateTime $last_update;
    private array $messages = array();
    private ?int $max_messages = null;
    private bool $persistent = false;
    private ?string $session_name = null;
    private bool $is_saved_session = false;
    private array $properties = [];
    private \ilLogger $logger;

    public function __construct(?string $id = null, bool $persistent = false)
    {
        global $DIC;
        $this->logger = $DIC->logger()->comp('pcaic');
        
        $this->created_at = new DateTime();
        $this->last_update = new DateTime();
        $this->persistent = $persistent;
        
        if ($id !== null) {
            $this->id = $id;
            if ($persistent) {
                $this->loadFromDB();
            } else {
                $this->loadFromSession();
            }
        } else {
            $this->id = uniqid('chat_', true);
        }
        
        $this->setTitle();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title = null): void
    {
        if ($title === null) {
            $this->title = "AI Chat - " . $this->created_at->format("Y-m-d H:i:s");
        } else {
            $this->title = $title;
        }
    }

    public function getCreatedAt(): DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(DateTime $created_at): void
    {
        $this->created_at = $created_at;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function setUserId(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    public function getLastUpdate(): DateTime
    {
        return $this->last_update;
    }

    public function setLastUpdate(DateTime $last_update): void
    {
        $this->last_update = $last_update;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function addMessage(AIChatPageComponentMessage $message): void
    {
        $this->messages[] = $message;
        $this->last_update = new DateTime();
    }

    public function setMaxMessages(int $max_messages): void
    {
        $this->max_messages = $max_messages;
    }

    public function getMaxMessages(): ?int
    {
        return $this->max_messages;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Check if chat exists (has messages or is saved)
     */
    public function exists(): bool
    {
        return !empty($this->messages) || $this->is_saved_session;
    }

    public function setPersistent(bool $persistent): void
    {
        $this->persistent = $persistent;
    }

    /**
     * Load chat from database
     * @throws AIChatPageComponentException
     */
    public function loadFromDB(): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();
        $user_id = $DIC->user() ? $DIC->user()->getId() : 0;

        try {
            // Load messages for this chat and user
            $result = $db->query(
                "SELECT * FROM pcaic_messages WHERE chat_id = " . $db->quote($this->getId(), 'text') . 
                " AND user_id = " . $db->quote($user_id, 'integer') .
                " ORDER BY timestamp ASC"
            );
            
            while ($row = $db->fetchAssoc($result)) {
                $message = new AIChatPageComponentMessage();
                $message->setId((int)$row["id"]);
                $message->setChatId($row["chat_id"]);
                $message->setDate(new DateTime($row["timestamp"]));
                $message->setRole($row["role"]);
                $message->setMessage($row["message"]);
                $this->addMessage($message);
            }
            
            // Load properties for this chat and user
            $this->loadPropertiesFromDB($db, $user_id);
            
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to load chat from database: " . $e->getMessage());
        }
    }

    /**
     * Load chat from session
     */
    public function loadFromSession(): void
    {
        $messages = $_SESSION['pcaic_messages'] ?? [];

        // Sort messages by timestamp
        usort($messages, function ($a, $b) {
            return strtotime($a["timestamp"]) - strtotime($b["timestamp"]);
        });

        foreach ($messages as $messageData) {
            if ($messageData["chat_id"] == $this->getId()) {
                $message = new AIChatPageComponentMessage();
                $message->setId($messageData["id"]);
                $message->setChatId($messageData["chat_id"]);
                $message->setDate(new DateTime($messageData["timestamp"]));
                $message->setRole($messageData["role"]);
                $message->setMessage($messageData["message"]);
                $this->addMessage($message);
            }
        }
    }

    /**
     * Load properties from database
     */
    private function loadPropertiesFromDB($db, $user_id): void
    {
        try {
            $result = $db->query(
                "SELECT property_key, property_value FROM pcaic_chat_properties WHERE chat_id = " . $db->quote($this->getId(), 'text') . 
                " AND user_id = " . $db->quote($user_id, 'integer')
            );
            
            $this->properties = [];
            while ($row = $db->fetchAssoc($result)) {
                $this->properties[$row["property_key"]] = $row["property_value"];
            }
            
            $this->logger->debug("Loaded properties for chat", [
                'property_count' => count($this->properties),
                'chat_id' => $this->getId()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning("Error loading properties from database", ['error' => $e->getMessage()]);
            $this->properties = [];
        }
    }

    /**
     * Save chat (messages are saved individually)
     */
    public function save(): void
    {
        if ($this->persistent) {
            foreach ($this->messages as $message) {
                $message->save();
            }
            $this->savePropertiesToDB();
        } else {
            $this->saveToSession();
        }
    }

    /**
     * Save properties to database
     */
    private function savePropertiesToDB(): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            $this->logger->warning("Cannot save properties - ILIAS DIC not available");
            return;
        }
        
        $db = $DIC->database();
        $user_id = $DIC->user() ? $DIC->user()->getId() : 0;

        try {
            // First, delete existing properties for this chat and user
            $db->manipulate(
                "DELETE FROM pcaic_chat_properties WHERE chat_id = " . $db->quote($this->getId(), 'text') . 
                " AND user_id = " . $db->quote($user_id, 'integer')
            );
            
            // Insert current properties
            foreach ($this->properties as $key => $value) {
                $property_id = $db->nextId('pcaic_chat_properties');
                $now = date('Y-m-d H:i:s');
                
                $db->manipulate(
                    "INSERT INTO pcaic_chat_properties (id, chat_id, user_id, property_key, property_value, created_at, updated_at) VALUES (" .
                    $db->quote($property_id, 'integer') . ", " .
                    $db->quote($this->getId(), 'text') . ", " .
                    $db->quote($user_id, 'integer') . ", " .
                    $db->quote($key, 'text') . ", " .
                    $db->quote($value, 'text') . ", " .
                    $db->quote($now, 'timestamp') . ", " .
                    $db->quote($now, 'timestamp') . ")"
                );
            }
            
            $this->logger->debug("Saved properties for chat", [
                'property_count' => count($this->properties),
                'chat_id' => $this->getId()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning("Error saving properties to database", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Save chat to session
     */
    public function saveToSession(): void
    {
        foreach ($this->messages as $message) {
            $message->saveToSession();
        }
    }

    /**
     * Delete chat and all its messages
     * @throws AIChatPageComponentException
     */
    public function delete(): void
    {
        if ($this->persistent) {
            global $DIC;
            
            if (!isset($DIC) || !$DIC->database()) {
                throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
            }
            
            $db = $DIC->database();
            $user_id = $DIC->user() ? $DIC->user()->getId() : 0;
            
            try {
                // Delete messages via session_id since messages don't have direct chat_id
                $db->manipulate(
                    "DELETE FROM pcaic_messages WHERE session_id IN (
                        SELECT session_id FROM pcaic_sessions 
                        WHERE chat_id = " . $db->quote($this->getId(), 'text') . "
                        AND user_id = " . $db->quote($user_id, 'integer') . "
                    )"
                );
            } catch (\Exception $e) {
                throw new AIChatPageComponentException("Failed to delete chat: " . $e->getMessage());
            }
        } else {
            $this->deleteFromSession();
        }
    }

    /**
     * Delete chat from session
     */
    public function deleteFromSession(): void
    {
        $messages = $_SESSION['pcaic_messages'] ?? [];

        foreach ($messages as $key => $message) {
            if ($message["chat_id"] == $this->getId()) {
                unset($messages[$key]);
            }
        }

        $_SESSION['pcaic_messages'] = $messages;
    }

    /**
     * Get session name
     */
    public function getSessionName(): ?string
    {
        return $this->session_name;
    }

    /**
     * Set session name
     */
    public function setSessionName(?string $session_name): void
    {
        $this->session_name = $session_name;
    }

    /**
     * Get chat properties
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Set chat properties
     */
    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    /**
     * Set a specific property
     */
    public function setProperty(string $key, $value): void
    {
        $this->properties[$key] = $value;
        
        // Auto-save properties for persistent chats
        if ($this->persistent) {
            $this->savePropertiesToDB();
        }
    }

    /**
     * Get a specific property
     */
    public function getProperty(string $key, $default = null)
    {
        return $this->properties[$key] ?? $default;
    }

    /**
     * Check if this is a saved session
     */
    public function isSavedSession(): bool
    {
        return $this->is_saved_session;
    }

    /**
     * Mark as saved session
     */
    public function setSavedSession(bool $is_saved_session): void
    {
        $this->is_saved_session = $is_saved_session;
    }

    /**
     * Save chat session to database with a name
     * @throws AIChatPageComponentException
     */
    public function saveAsSession(string $session_name): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();
        $user_id = $DIC->user() ? $DIC->user()->getId() : 0;

        try {
            // Save to sessions table
            $session_id = $db->nextId('pcaic_sessions');
            
            $db->manipulate(
                "INSERT INTO pcaic_sessions (id, chat_id, user_id, session_name, created_at, last_activity, is_active) VALUES (" .
                $db->quote($session_id, 'integer') . ", " .
                $db->quote($this->getId(), 'text') . ", " .
                $db->quote($user_id, 'integer') . ", " .
                $db->quote($session_name, 'text') . ", " .
                $db->quote($this->getCreatedAt()->format('Y-m-d H:i:s'), 'timestamp') . ", " .
                $db->quote($this->getLastUpdate()->format('Y-m-d H:i:s'), 'timestamp') . ", " .
                $db->quote(1, 'integer') .
                ")"
            );
            
            // Save all messages persistently
            foreach ($this->messages as $message) {
                $message->save();
            }
            
            $this->session_name = $session_name;
            $this->is_saved_session = true;
            
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to save session: " . $e->getMessage());
        }
    }

    /**
     * Load a saved session by name and user
     * @throws AIChatPageComponentException
     */
    public static function loadSession(string $session_name, int $user_id): ?self
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();

        try {
            $result = $db->query(
                "SELECT * FROM pcaic_sessions WHERE session_name = " . $db->quote($session_name, 'text') . 
                " AND user_id = " . $db->quote($user_id, 'integer') .
                " AND is_active = 1 ORDER BY last_activity DESC LIMIT 1"
            );
            
            if ($row = $db->fetchAssoc($result)) {
                $chat = new self($row['chat_id'], true);
                $chat->setSessionName($row['session_name']);
                $chat->setSavedSession(true);
                $chat->setCreatedAt(new DateTime($row['created_at']));
                $chat->setLastUpdate(new DateTime($row['last_activity']));
                return $chat;
            }
            
            return null;
            
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to load session: " . $e->getMessage());
        }
    }

    /**
     * Get all saved sessions for a user
     * @throws AIChatPageComponentException
     */
    public static function getUserSessions(int $user_id): array
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();
        $sessions = [];

        try {
            $result = $db->query(
                "SELECT * FROM pcaic_sessions WHERE user_id = " . $db->quote($user_id, 'integer') . 
                " AND is_active = 1 ORDER BY last_activity DESC"
            );
            
            while ($row = $db->fetchAssoc($result)) {
                $sessions[] = [
                    'id' => $row['id'],
                    'chat_id' => $row['chat_id'],
                    'session_name' => $row['session_name'],
                    'created_at' => $row['created_at'],
                    'last_activity' => $row['last_activity']
                ];
            }
            
            return $sessions;
            
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to load user sessions: " . $e->getMessage());
        }
    }

    /**
     * Delete a saved session
     * @throws AIChatPageComponentException
     */
    public function deleteSession(): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();
        $user_id = $DIC->user() ? $DIC->user()->getId() : 0;

        try {
            // Mark session as inactive
            $db->manipulate(
                "UPDATE pcaic_sessions SET is_active = 0 WHERE chat_id = " . $db->quote($this->getId(), 'text') .
                " AND user_id = " . $db->quote($user_id, 'integer')
            );
            
            // Delete associated messages
            $this->delete();
            
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to delete session: " . $e->getMessage());
        }
    }

    /**
     * Convert chat to array
     */
    public function toArray(): array
    {
        $messages = array();

        foreach ($this->messages as $message) {
            $messages[] = $message->toArray();
        }

        // Apply max message limit
        if ($this->max_messages !== null && $this->max_messages > 0) {
            $messages = array_slice($messages, -$this->max_messages);
        }

        return [
            "id" => $this->getId(),
            "title" => $this->getTitle(),
            "created_at" => $this->getCreatedAt()->format("Y-m-d H:i:s"),
            "user_id" => $this->getUserId(),
            "messages" => $messages,
            "last_update" => $this->getLastUpdate()->format("Y-m-d H:i:s"),
            "persistent" => $this->isPersistent(),
            "session_name" => $this->getSessionName(),
            "is_saved_session" => $this->isSavedSession()
        ];
    }
}