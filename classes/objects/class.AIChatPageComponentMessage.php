<?php declare(strict_types=1);

namespace objects;

use DateTime;
use platform\AIChatPageComponentException;

/**
 * Class AIChatPageComponentMessage
 * Based on Message from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentMessage
{
    private int $id = 0;
    private string $chat_id;
    private DateTime $date;
    private string $role;
    private string $message;
    private array $attachments = [];

    public function __construct(?int $id = null, string $chat_id = '', bool $from_session = false)
    {
        $this->date = new DateTime();
        $this->chat_id = $chat_id;

        if ($id !== null && $id > 0) {
            $this->id = $id;
            if ($from_session) {
                $this->loadFromSession();
            } else {
                $this->loadFromDB();
            }
        }
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getChatId(): string
    {
        return $this->chat_id;
    }

    public function setChatId(string $chat_id): void
    {
        $this->chat_id = $chat_id;
    }

    public function getDate(): DateTime
    {
        return $this->date;
    }

    public function setDate(DateTime $date): void
    {
        $this->date = $date;
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

    /**
     * Load message from database
     * @throws AIChatPageComponentException
     */
    public function loadFromDB(): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();

        try {
            $result = $db->query("SELECT * FROM pcaic_messages WHERE id = " . $db->quote($this->getId(), 'integer'));
            
            if ($row = $db->fetchAssoc($result)) {
                $this->setChatId($row["chat_id"]);
                $this->setDate(new DateTime($row["timestamp"]));
                $this->setRole($row["role"]);
                $this->setMessage($row["message"]);
                $this->loadAttachments();
            }
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to load message from database: " . $e->getMessage());
        }
    }

    /**
     * Load message from session
     */
    public function loadFromSession(): void
    {
        $messages = $_SESSION['pcaic_messages'] ?? [];

        foreach ($messages as $message) {
            if ($message["id"] == $this->getId()) {
                $this->setChatId($message["chat_id"]);
                $this->setDate(new DateTime($message["timestamp"]));
                $this->setRole($message["role"]);
                $this->setMessage($message["message"]);
                break;
            }
        }
    }

    /**
     * Save message to database
     * @throws AIChatPageComponentException
     */
    public function save(): void
    {
        global $DIC;
        
        if (!isset($DIC) || !$DIC->database()) {
            throw new AIChatPageComponentException("ILIAS DIC not available or database not initialized");
        }
        
        $db = $DIC->database();
        $user_id = $DIC->user() ? $DIC->user()->getId() : 0;


        try {
            if ($this->getId() > 0) {
                $db->manipulate(
                    "UPDATE pcaic_messages SET " .
                    "chat_id = " . $db->quote($this->getChatId(), 'text') . ", " .
                    "user_id = " . $db->quote($user_id, 'integer') . ", " .
                    "role = " . $db->quote($this->getRole(), 'text') . ", " .
                    "message = " . $db->quote($this->getMessage(), 'text') . ", " .
                    "timestamp = " . $db->quote($this->getDate()->format('Y-m-d H:i:s'), 'timestamp') . " " .
                    "WHERE id = " . $db->quote($this->getId(), 'integer')
                );
            } else {
                $id = $db->nextId('pcaic_messages');
                $this->setId($id);
                
                $db->manipulate(
                    "INSERT INTO pcaic_messages (id, chat_id, user_id, role, message, timestamp) VALUES (" .
                    $db->quote($id, 'integer') . ", " .
                    $db->quote($this->getChatId(), 'text') . ", " .
                    $db->quote($user_id, 'integer') . ", " .
                    $db->quote($this->getRole(), 'text') . ", " .
                    $db->quote($this->getMessage(), 'text') . ", " .
                    $db->quote($this->getDate()->format('Y-m-d H:i:s'), 'timestamp') .
                    ")"
                );
            }
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to save message: " . $e->getMessage());
        }
    }

    /**
     * Save message to session
     */
    public function saveToSession(): void
    {
        $messages = $_SESSION['pcaic_messages'] ?? [];

        if ($this->getId() == 0) {
            $next_id = ($_SESSION['pcaic_messages_next_id'] ?? 0) + 1;
            $this->setId($next_id);
            $_SESSION['pcaic_messages_next_id'] = $next_id;
        }

        $message = [
            "id" => $this->getId(),
            "chat_id" => $this->getChatId(),
            "timestamp" => $this->getDate()->format("Y-m-d H:i:s"),
            "role" => $this->getRole(),
            "message" => $this->getMessage()
        ];

        $found = false;
        foreach ($messages as $key => $msg) {
            if ($msg["id"] == $this->getId()) {
                $messages[$key] = $message;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $messages[] = $message;
        }

        $_SESSION['pcaic_messages'] = $messages;
    }

    /**
     * Load attachments for this message
     */
    private function loadAttachments(): void
    {
        // The attachment class should already be loaded by the API
        if (class_exists('AIChatPageComponentAttachment')) {
            $this->attachments = \AIChatPageComponentAttachment::getByMessageId($this->getId());
        } else {
            $this->attachments = [];
        }
    }

    /**
     * Get attachments for this message
     */
    public function getAttachments(): array
    {
        if (empty($this->attachments) && $this->getId() > 0) {
            $this->loadAttachments();
        }
        return $this->attachments;
    }

    /**
     * Add attachment to this message
     */
    public function addAttachment(\AIChatPageComponentAttachment $attachment): void
    {
        $attachment->setMessageId($this->getId());
        $attachment->setChatId($this->getChatId());
        $attachment->save();
        $this->attachments[] = $attachment;
    }

    /**
     * Delete message from database
     * @throws AIChatPageComponentException
     */
    public function delete(): void
    {
        global $DIC;
        $db = $DIC->database();

        try {
            // Delete attachments first
            foreach ($this->getAttachments() as $attachment) {
                $attachment->delete();
            }
            
            // Delete message
            $db->manipulate("DELETE FROM pcaic_messages WHERE id = " . $db->quote($this->getId(), 'integer'));
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to delete message: " . $e->getMessage());
        }
    }

    /**
     * Convert message to array
     */
    public function toArray(): array
    {
        $attachments_data = [];
        foreach ($this->getAttachments() as $attachment) {
            $attachments_data[] = [
                'id' => $attachment->getId(),
                'title' => $attachment->getTitle(),
                'size' => $attachment->getSize(),
                'mime_type' => $attachment->getMimeType(),
                'is_image' => $attachment->isImage(),
                'download_url' => $attachment->getDownloadUrl(),
                'preview_url' => $attachment->getPreviewUrl()
            ];
        }
        
        return [
            "id" => $this->getId(),
            "chat_id" => $this->getChatId(),
            "role" => $this->getRole(),
            "content" => $this->getMessage(),
            "timestamp" => $this->getDate()->format("Y-m-d H:i:s"),
            "attachments" => $attachments_data
        ];
    }
}