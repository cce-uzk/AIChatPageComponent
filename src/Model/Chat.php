<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Model;

use DateTime;
use ILIAS\Plugin\pcaic\Exception\PluginException;

/**
 * Chat Model for AIChatPageComponent
 * Based on Chat from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class Chat
{
    private string $id;
    private string $title;
    private DateTime $created_at;
    private int $user_id = 0;
    private DateTime $last_update;
    private array $messages = [];
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title = null): void
    {
        if ($title === null) {
            $this->title = 'Chat ' . substr($this->id, -8);
        } else {
            $this->title = $title;
        }
    }

    public function getCreatedAt(): DateTime
    {
        return $this->created_at;
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

    public function updateLastUpdate(): void
    {
        $this->last_update = new DateTime();
    }

    /**
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function addMessage($message): void
    {
        // Handle both new Message objects and legacy objects
        if ($message instanceof Message || 
            (is_object($message) && method_exists($message, 'getChatId'))) {
            $this->messages[] = $message;
            $this->updateLastUpdate();
            
            // Apply max messages limit if set
            if ($this->max_messages !== null && count($this->messages) > $this->max_messages) {
                $this->messages = array_slice($this->messages, -$this->max_messages);
            }
        } else {
            throw new \InvalidArgumentException('Invalid message object provided to addMessage()');
        }
    }

    public function getMaxMessages(): ?int
    {
        return $this->max_messages;
    }

    public function setMaxMessages(?int $max_messages): void
    {
        $this->max_messages = $max_messages;
        
        // Apply limit immediately if set
        if ($max_messages !== null && count($this->messages) > $max_messages) {
            $this->messages = array_slice($this->messages, -$max_messages);
        }
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function exists(): bool
    {
        if ($this->persistent) {
            return $this->existsInDB();
        } else {
            return $this->existsInSession();
        }
    }

    private function existsInDB(): bool
    {
        global $DIC;
        $db = $DIC->database();
        
        $query = "SELECT COUNT(*) as count FROM pcaic_chats WHERE id = " . $db->quote($this->id, 'text');
        $result = $db->query($query);
        $row = $db->fetchAssoc($result);
        
        return (int)$row['count'] > 0;
    }

    private function existsInSession(): bool
    {
        return isset($_SESSION[$this->getSessionName()]);
    }

    public function save(): void
    {
        if ($this->persistent) {
            $this->saveToDB();
        } else {
            $this->saveToSession();
        }
    }

    public function saveToSession(): void
    {
        $_SESSION[$this->getSessionName()] = serialize($this);
        $this->is_saved_session = true;
    }

    private function saveToDB(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        if ($this->existsInDB()) {
            // Update existing chat
            $query = "UPDATE pcaic_chats SET " .
                "title = " . $db->quote($this->title, 'text') . ", " .
                "user_id = " . $db->quote($this->user_id, 'integer') . ", " .
                "last_update = " . $db->quote($this->last_update->format('Y-m-d H:i:s'), 'timestamp') . ", " .
                "max_messages = " . $db->quote($this->max_messages, 'integer') . ", " .
                "properties = " . $db->quote(json_encode($this->properties), 'text') . " " .
                "WHERE id = " . $db->quote($this->id, 'text');
        } else {
            // Insert new chat
            $query = "INSERT INTO pcaic_chats (id, title, created_at, user_id, last_update, max_messages, properties) " .
                "VALUES (" .
                $db->quote($this->id, 'text') . ", " .
                $db->quote($this->title, 'text') . ", " .
                $db->quote($this->created_at->format('Y-m-d H:i:s'), 'timestamp') . ", " .
                $db->quote($this->user_id, 'integer') . ", " .
                $db->quote($this->last_update->format('Y-m-d H:i:s'), 'timestamp') . ", " .
                $db->quote($this->max_messages, 'integer') . ", " .
                $db->quote(json_encode($this->properties), 'text') . ")";
        }
        
        $db->manipulate($query);
        
        // Save messages
        foreach ($this->messages as $message) {
            $message->setChatId($this->id);
            $message->save();
        }
    }

    private function loadFromDB(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        $query = "SELECT * FROM pcaic_chats WHERE id = " . $db->quote($this->id, 'text');
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            $this->title = $row['title'];
            $this->created_at = new DateTime($row['created_at']);
            $this->user_id = (int)$row['user_id'];
            $this->last_update = new DateTime($row['last_update']);
            $this->max_messages = $row['max_messages'] ? (int)$row['max_messages'] : null;
            $this->properties = $row['properties'] ? json_decode($row['properties'], true) : [];
            
            // Load messages
            $this->loadMessagesFromDB();
        }
    }

    private function loadFromSession(): void
    {
        if (!isset($_SESSION[$this->getSessionName()])) {
            return;
        }
        
        $saved_chat = unserialize($_SESSION[$this->getSessionName()]);
        if ($saved_chat instanceof self) {
            $this->title = $saved_chat->title;
            $this->created_at = $saved_chat->created_at;
            $this->user_id = $saved_chat->user_id;
            $this->last_update = $saved_chat->last_update;
            $this->messages = $saved_chat->messages;
            $this->max_messages = $saved_chat->max_messages;
            $this->properties = $saved_chat->properties;
            $this->is_saved_session = true;
        }
    }

    private function loadMessagesFromDB(): void
    {
        $this->logger->debug("Loading messages from DB", ['chat_id' => $this->id]);
        $this->messages = Message::getByChatId($this->id);
        $this->logger->debug("Loaded messages from DB", ['message_count' => count($this->messages)]);
        foreach ($this->messages as $i => $message) {
            $this->logger->debug("Message details", [
                'index' => $i,
                'message_id' => $message->getId(),
                'role' => $message->getRole(),
                'content_preview' => substr($message->getMessage(), 0, 50)
            ]);
        }
    }


    // Properties management
    public function getProperty(string $key): mixed
    {
        return $this->properties[$key] ?? null;
    }

    public function setProperty(string $key, mixed $value): void
    {
        $this->properties[$key] = $value;
    }

    public function hasProperty(string $key): bool
    {
        return array_key_exists($key, $this->properties);
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function delete(): void
    {
        if ($this->persistent) {
            $this->deleteFromDB();
        } else {
            $this->deleteFromSession();
        }
    }

    private function deleteFromDB(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        // Delete messages first
        foreach ($this->messages as $message) {
            $message->delete();
        }
        
        // Delete chat
        $query = "DELETE FROM pcaic_chats WHERE id = " . $db->quote($this->id, 'text');
        $db->manipulate($query);
    }

    private function deleteFromSession(): void
    {
        // Delete messages and their attachments first (same as DB deletion)
        foreach ($this->messages as $message) {
            // For session messages, we still need to clean up attachments
            if (method_exists($message, 'getAttachments')) {
                $attachments = $message->getAttachments();
                foreach ($attachments as $attachment) {
                    if (method_exists($attachment, 'delete')) {
                        $attachment->delete();
                    }
                }
            }
        }
        
        // Remove from session
        unset($_SESSION[$this->getSessionName()]);
        $this->is_saved_session = false;
    }

    public function getSessionName(): string
    {
        if ($this->session_name === null) {
            $this->session_name = 'pcaic_chat_' . $this->id;
        }
        return $this->session_name;
    }

    public function isSavedSession(): bool
    {
        return $this->is_saved_session;
    }

    /**
     * Convert chat to array for API responses
     * Compatible with legacy AIChatPageComponentChat format
     */
    public function toArray(): array
    {
        $messages = [];

        foreach ($this->messages as $message) {
            // Handle both new Message objects and legacy message objects
            if (method_exists($message, 'toArray')) {
                $messages[] = $message->toArray();
            } else {
                // Fallback for objects that don't have toArray method
                $messageArray = [
                    'id' => method_exists($message, 'getId') ? $message->getId() : 0,
                    'role' => method_exists($message, 'getRole') ? $message->getRole() : 'user',
                    'message' => method_exists($message, 'getMessage') ? $message->getMessage() : '',
                    'date' => method_exists($message, 'getDate') ? $message->getDate()->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
                    'attachments' => method_exists($message, 'getAttachments') ? $message->getAttachments() : []
                ];
                $messages[] = $messageArray;
            }
        }

        // Apply max message limit
        if ($this->max_messages !== null && $this->max_messages > 0) {
            $messages = array_slice($messages, -$this->max_messages);
        }

        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
            'user_id' => $this->getUserId(),
            'messages' => $messages,
            'last_update' => $this->getLastUpdate()->format('Y-m-d H:i:s'),
            'persistent' => $this->isPersistent(),
            'session_name' => $this->getSessionName(),
            'is_saved_session' => $this->isSavedSession()
        ];
    }
}