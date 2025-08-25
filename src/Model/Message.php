<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Model;

use DateTime;

/**
 * Message Model for AIChatPageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class Message
{
    protected ?int $id = null;
    protected ?string $chat_id = null;
    protected int $user_id = 0;
    protected string $role = 'user'; // 'user' or 'assistant'
    protected string $message = '';
    protected ?DateTime $timestamp = null;
    protected bool $persistent = false;

    public function __construct(?int $id = null, bool $persistent = false)
    {
        $this->persistent = $persistent;
        $this->timestamp = new DateTime();
        
        if ($id !== null) {
            $this->id = $id;
            if ($persistent) {
                $this->loadFromDB();
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatId(): ?string
    {
        return $this->chat_id;
    }

    public function setChatId(?string $chat_id): void
    {
        $this->chat_id = $chat_id;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function setUserId(int $user_id): void
    {
        $this->user_id = $user_id;
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

    public function getTimestamp(): ?DateTime
    {
        return $this->timestamp;
    }

    public function setTimestamp(?DateTime $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function save(): void
    {
        if ($this->persistent) {
            $this->saveToDB();
        }
        // Session-based messages are saved with the chat
    }

    public function saveToSession(): void
    {
        // Session messages are handled by the Chat object
        // This method exists for compatibility
    }

    private function saveToDB(): void
    {
        global $DIC;
        $db = $DIC->database();

        if ($this->id) {
            // Update existing message
            $query = "UPDATE pcaic_messages SET " .
                "chat_id = " . $db->quote($this->chat_id, 'text') . ", " .
                "user_id = " . $db->quote($this->user_id, 'integer') . ", " .
                "role = " . $db->quote($this->role, 'text') . ", " .
                "message = " . $db->quote($this->message, 'clob') . ", " .
                "timestamp = " . $db->quote($this->timestamp->format('Y-m-d H:i:s'), 'timestamp') . " " .
                "WHERE id = " . $db->quote($this->id, 'integer');
        } else {
            // Insert new message
            $this->id = $db->nextId('pcaic_messages');
            $this->logger->debug("Modern Message - Generated ID", ['id' => $this->id]);
            $query = "INSERT INTO pcaic_messages (id, chat_id, user_id, role, message, timestamp) " .
                "VALUES (" .
                $db->quote($this->id, 'integer') . ", " .
                $db->quote($this->chat_id, 'text') . ", " .
                $db->quote($this->user_id, 'integer') . ", " .
                $db->quote($this->role, 'text') . ", " .
                $db->quote($this->message, 'clob') . ", " .
                $db->quote($this->timestamp->format('Y-m-d H:i:s'), 'timestamp') . ")";
        }

        $this->logger->debug("Modern Message - Executing query", ['query' => $query]);
        $db->manipulate($query);
        $this->logger->debug("Modern Message - Saved with ID", ['id' => $this->id]);
    }

    private function loadFromDB(): void
    {
        if (!$this->id) {
            return;
        }

        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_messages WHERE id = " . $db->quote($this->id, 'integer');
        $result = $db->query($query);

        if ($row = $db->fetchAssoc($result)) {
            $this->chat_id = $row['chat_id'];
            $this->user_id = (int)$row['user_id'];
            $this->role = $row['role'];
            $this->message = $row['message'];
            $this->timestamp = new DateTime($row['timestamp']);
        }
    }

    /**
     * @return Message[]
     */
    public static function getByChatId(string $chat_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $messages = [];
        $query = "SELECT id FROM pcaic_messages WHERE chat_id = " . $db->quote($chat_id, 'text') . " ORDER BY timestamp ASC";
        $result = $db->query($query);

        while ($row = $db->fetchAssoc($result)) {
            $messages[] = new self((int)$row['id'], true);
        }

        return $messages;
    }

    /**
     * Get attachments for this message
     * @return Attachment[]
     */
    public function getAttachments(): array
    {
        if (!$this->id) {
            return [];
        }
        
        return Attachment::getByMessageId($this->id);
    }

    /**
     * Add attachment to this message
     * @param Attachment $attachment
     */
    public function addAttachment(Attachment $attachment): void
    {
        if ($this->id) {
            $attachment->setMessageId($this->id);
            $attachment->save();
        }
    }

    public function delete(): void
    {
        if (!$this->id) {
            return;
        }

        // Delete attachments first
        $attachments = $this->getAttachments();
        foreach ($attachments as $attachment) {
            $attachment->delete();
        }

        if ($this->persistent) {
            $this->deleteFromDB();
        }
        // Session messages are deleted with the chat
    }

    private function deleteFromDB(): void
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_messages WHERE id = " . $db->quote($this->id, 'integer');
        $db->manipulate($query);
    }

    /**
     * Convert message to array for API responses
     * Compatible with legacy format
     */
    public function toArray(): array
    {
        $this->logger->debug("Message::toArray() called", ['message_id' => $this->id ?? 'null']);
        $attachments = [];
        $loaded_attachments = $this->getAttachments();
        $this->logger->debug("Found attachments for message", [
            'attachment_count' => count($loaded_attachments),
            'message_id' => $this->id ?? 'null'
        ]);
        
        foreach ($loaded_attachments as $attachment) {
            $this->logger->debug("Processing attachment", [
                'attachment_id' => method_exists($attachment, 'getId') ? $attachment->getId() : 'unknown'
            ]);
            if (method_exists($attachment, 'toArray')) {
                $attachment_array = $attachment->toArray();
                $this->logger->debug("Attachment toArray", ['data' => $attachment_array]);
                $attachments[] = $attachment_array;
            } else {
                // Fallback for attachments without toArray method
                $fallback_attachment = [
                    'id' => method_exists($attachment, 'getId') ? $attachment->getId() : 0,
                    'filename' => method_exists($attachment, 'getFilename') ? $attachment->getFilename() : 'unknown',
                    'src' => method_exists($attachment, 'getSrc') ? $attachment->getSrc() : '',
                    'mime_type' => method_exists($attachment, 'getMimeType') ? $attachment->getMimeType() : 'application/octet-stream'
                ];
                $this->logger->debug("Using fallback attachment data", ['data' => $fallback_attachment]);
                $attachments[] = $fallback_attachment;
            }
        }

        return [
            'id' => $this->getId() ?? 0,
            'role' => $this->getRole(),
            'message' => $this->getMessage(),
            'date' => $this->timestamp ? $this->timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
            'attachments' => $attachments
        ];
    }

    /**
     * Compatibility method for legacy code
     */
    public function getDate(): ?DateTime
    {
        return $this->timestamp;
    }
}