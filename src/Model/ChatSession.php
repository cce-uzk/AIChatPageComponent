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
 * Chat session of a user in a chat, holding the message history
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatSession
{
    private string $session_id;
    private string $chat_id;
    private int $user_id;
    private string $session_name = '';
    private ?\DateTime $created_at = null;
    private ?\DateTime $last_activity = null;
    private bool $is_active = true;

    /**
     * @param string|null $session_id Loads this session if given
     */
    public function __construct(string $session_id = null)
    {
        if ($session_id) {
            $this->session_id = $session_id;
            $this->load();
        } else {
            $this->session_id = uniqid('session_', true);
            $this->created_at = new \DateTime();
            $this->last_activity = new \DateTime();
        }
    }

    public static function createForUserAndChat(int $user_id, string $chat_id, string $session_name = ''): self
    {
        $session = new self();
        $session->user_id = $user_id;
        $session->chat_id = $chat_id;
        $session->session_name = $session_name;
        return $session;
    }

    /**
     * Most recently active session of the user in the chat
     */
    public static function findForUserAndChat(int $user_id, string $chat_id): ?self
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions 
                  WHERE user_id = " . $db->quote($user_id, 'integer') . "
                  AND chat_id = " . $db->quote($chat_id, 'text') . "
                  AND is_active = 1
                  ORDER BY last_activity DESC LIMIT 1";

        $result = $db->query($query);
        if ($row = $db->fetchAssoc($result)) {
            return new self($row['session_id']);
        }

        return null;
    }

    /**
     * Active session of the user in the chat; a new one is created if none exists
     */
    public static function getOrCreateForUserAndChat(int $user_id, string $chat_id, string $session_name = ''): self
    {
        $session = self::findForUserAndChat($user_id, $chat_id);
        if (!$session) {
            $session = self::createForUserAndChat($user_id, $chat_id, $session_name);
            $session->save();
        }
        return $session;
    }

    /**
     * @return bool True if the session was found
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_sessions WHERE session_id = " . $db->quote($this->session_id, 'text');
        $result = $db->query($query);

        if ($row = $db->fetchAssoc($result)) {
            $this->chat_id = $row['chat_id'];
            $this->user_id = (int) $row['user_id'];
            $this->session_name = $row['session_name'] ?? '';
            $this->created_at = $row['created_at'] ? new \DateTime($row['created_at']) : null;
            $this->last_activity = $row['last_activity'] ? new \DateTime($row['last_activity']) : null;
            $this->is_active = (bool) $row['is_active'];

            return true;
        }

        return false;
    }

    /**
     * Insert or update the session and set last_activity to now
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $this->last_activity = new \DateTime();

        $query = "SELECT session_id FROM pcaic_sessions WHERE session_id = " . $db->quote($this->session_id, 'text');
        $result = $db->query($query);
        $exists = $db->fetchAssoc($result);

        $values = [
            'chat_id' => ['text', $this->chat_id],
            'user_id' => ['integer', $this->user_id],
            'session_name' => ['text', $this->session_name],
            'last_activity' => ['timestamp', $this->last_activity->format('Y-m-d H:i:s')],
            'is_active' => ['integer', $this->is_active ? 1 : 0]
        ];

        if ($exists) {
            $db->update('pcaic_sessions', $values, ['session_id' => ['text', $this->session_id]]);
        } else {
            $values['session_id'] = ['text', $this->session_id];
            $values['created_at'] = ['timestamp', $this->created_at->format('Y-m-d H:i:s')];
            $db->insert('pcaic_sessions', $values);
        }

        return true;
    }

    /**
     * Delete the session row only; messages are not deleted
     */
    public function delete(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_sessions WHERE session_id = " . $db->quote($this->session_id, 'text');
        $db->manipulate($query);

        return true;
    }

    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    public function touch(): bool
    {
        $this->last_activity = new \DateTime();
        return $this->save();
    }

    public function exists(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions WHERE session_id = " . $db->quote($this->session_id, 'text');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== null;
    }

    /**
     * @return ChatMessage[] All messages in chronological order
     */
    public function getMessages(): array
    {
        return ChatMessage::getForSession($this->session_id);
    }

    /**
     * @return ChatMessage[] The last $limit messages in chronological order
     */
    public function getRecentMessages(int $limit = 10): array
    {
        return ChatMessage::getRecentForSession($this->session_id, $limit);
    }

    /**
     * Add a message and update the activity timestamp of the session
     *
     * @param string $role user|assistant|system
     */
    public function addMessage(string $role, string $content): ChatMessage
    {
        $message = new ChatMessage();
        $message->setSessionId($this->session_id);
        $message->setRole($role);
        $message->setMessage($content);
        $message->save();

        $this->touch();

        return $message;
    }

    public function getChatConfig(): ?ChatConfig
    {
        return new ChatConfig($this->chat_id);
    }

    public function getSessionId(): string
    {
        return $this->session_id;
    }
    public function getChatId(): string
    {
        return $this->chat_id;
    }
    public function setChatId(string $chat_id): void
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
    public function getSessionName(): string
    {
        return $this->session_name;
    }
    public function setSessionName(string $session_name): void
    {
        $this->session_name = $session_name;
    }
    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }
    public function getLastActivity(): ?\DateTime
    {
        return $this->last_activity;
    }
    public function isActive(): bool
    {
        return $this->is_active;
    }
    public function setActive(bool $is_active): void
    {
        $this->is_active = $is_active;
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->session_id,
            'chat_id' => $this->chat_id,
            'user_id' => $this->user_id,
            'session_name' => $this->session_name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'last_activity' => $this->last_activity?->format('Y-m-d H:i:s'),
            'is_active' => $this->is_active
        ];
    }
}
