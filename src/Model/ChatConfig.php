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
 * Configuration of a single chat (one PageComponent instance), stored in pcaic_chats
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatConfig
{
    private string $chat_id;
    private int $page_id = 0;
    private int $parent_id = 0;
    private string $parent_type = '';
    private string $title = '';
    private string $system_prompt = '';
    private string $ai_service = 'ramses';
    private int $max_memory = 10;
    private int $char_limit = 2000;
    private bool $persistent = true;
    private bool $include_page_context = true;
    private bool $enable_chat_uploads = false;
    private bool $enable_streaming = true;
    private bool $enable_rag = false;
    private bool $show_sources = true;
    private bool $allow_source_downloads = true;
    private bool $is_online = true;
    private string $disclaimer = '';
    private ?float $temperature = null;
    private ?string $model = null;
    private ?string $rag_collection_id = null;
    private ?\DateTime $created_at = null;
    private ?\DateTime $updated_at = null;

    /**
     * @param string|null $chat_id Loads the configuration of this chat if given
     */
    public function __construct(string $chat_id = null)
    {
        if ($chat_id) {
            $this->chat_id = $chat_id;
            $this->load();
        } else {
            $this->chat_id = uniqid('chat_', true);
            $this->created_at = new \DateTime();
            $this->updated_at = new \DateTime();
            $this->loadGlobalDefaults();
        }
    }

    /**
     * @return bool True if the configuration was found
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chat_id, 'text');
        $result = $db->query($query);

        if ($row = $db->fetchAssoc($result)) {
            $this->page_id = (int) $row['page_id'];
            $this->parent_id = (int) $row['parent_id'];
            $this->parent_type = $row['parent_type'];
            $this->title = $row['title'] ?? '';
            $this->system_prompt = $row['system_prompt'] ?? '';
            $this->ai_service = $row['ai_service'] ?? 'ramses';
            $this->max_memory = (int) $row['max_memory'];
            $this->char_limit = (int) $row['char_limit'];
            $this->persistent = (bool) $row['persistent'];
            $this->include_page_context = (bool) $row['include_page_context'];
            $this->enable_chat_uploads = (bool) $row['enable_chat_uploads'];
            $this->enable_streaming = (bool) ($row['enable_streaming'] ?? true);
            $this->enable_rag = (bool) ($row['enable_rag'] ?? false);
            $this->show_sources = (bool) ($row['show_sources'] ?? true);
            $this->allow_source_downloads = (bool) ($row['allow_source_downloads'] ?? true);
            $this->is_online = (bool) ($row['is_online'] ?? true);
            $this->disclaimer = $row['disclaimer'] ?? '';
            $this->temperature = isset($row['temperature']) && $row['temperature'] !== null
                ? (float) $row['temperature']
                : null;
            $this->model = !empty($row['model']) ? (string) $row['model'] : null;
            $this->rag_collection_id = $row['rag_collection_id'] ?? null;

            $this->created_at = $row['created_at'] ? new \DateTime($row['created_at']) : null;
            $this->updated_at = $row['updated_at'] ? new \DateTime($row['updated_at']) : null;

            return true;
        }

        return false;
    }

    /**
     * Initialise new configurations with the defaults of the plugin configuration
     */
    private function loadGlobalDefaults(): void
    {
        try {
            require_once(__DIR__ . '/../../classes/platform/class.AIChatPageComponentConfig.php');

            $default_prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
            if (!empty($default_prompt)) {
                $this->system_prompt = $default_prompt;
            }

            $default_disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
            if (!empty($default_disclaimer)) {
                $this->disclaimer = $default_disclaimer;
            }

            $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
            if (!empty($char_limit)) {
                $this->char_limit = (int) $char_limit;
            }

            $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
            if (!empty($max_memory)) {
                $this->max_memory = (int) $max_memory;
            }

            global $DIC;
            $DIC->logger()->pcaic()->debug("Loaded global defaults for new ChatConfig", [
                'system_prompt_length' => strlen($this->system_prompt),
                'disclaimer_length' => strlen($this->disclaimer),
                'char_limit' => $this->char_limit,
                'max_memory' => $this->max_memory
            ]);

        } catch (\Exception $e) {
            global $DIC;
            $DIC->logger()->pcaic()->warning("Failed to load global defaults for ChatConfig", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Insert or update the configuration
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $this->updated_at = new \DateTime();

        $query = "SELECT chat_id FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chat_id, 'text');
        $result = $db->query($query);
        $exists = $db->fetchAssoc($result);

        $values = [
            'page_id' => ['integer', $this->page_id],
            'parent_id' => ['integer', $this->parent_id],
            'parent_type' => ['text', $this->parent_type],
            'title' => ['text', $this->title],
            'system_prompt' => ['clob', $this->system_prompt],
            'ai_service' => ['text', $this->ai_service],
            'max_memory' => ['integer', $this->max_memory],
            'char_limit' => ['integer', $this->char_limit],
            'persistent' => ['integer', $this->persistent ? 1 : 0],
            'include_page_context' => ['integer', $this->include_page_context ? 1 : 0],
            'enable_chat_uploads' => ['integer', $this->enable_chat_uploads ? 1 : 0],
            'enable_streaming' => ['integer', $this->enable_streaming ? 1 : 0],
            'enable_rag' => ['integer', $this->enable_rag ? 1 : 0],
            'show_sources' => ['integer', $this->show_sources ? 1 : 0],
            'allow_source_downloads' => ['integer', $this->allow_source_downloads ? 1 : 0],
            'is_online' => ['integer', $this->is_online ? 1 : 0],
            'disclaimer' => ['clob', $this->disclaimer],
            'temperature' => ['float', $this->temperature],
            'model' => ['text', $this->model],
            'rag_collection_id' => ['text', $this->rag_collection_id],
            'updated_at' => ['timestamp', $this->updated_at->format('Y-m-d H:i:s')]
        ];

        if ($exists) {
            $db->update('pcaic_chats', $values, ['chat_id' => ['text', $this->chat_id]]);
        } else {
            if (!$this->created_at) {
                $this->created_at = new \DateTime();
            }
            $values['chat_id'] = ['text', $this->chat_id];
            $values['created_at'] = ['timestamp', $this->created_at->format('Y-m-d H:i:s')];
            $db->insert('pcaic_chats', $values);
        }

        return true;
    }

    /**
     * Delete the configuration row only
     *
     * Sessions, messages and files are removed by ilAIChatPageComponentPlugin::deleteCompleteChat().
     */
    public function delete(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chat_id, 'text');
        $db->manipulate($query);

        return true;
    }

    public function exists(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT chat_id FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chat_id, 'text');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== null;
    }

    /**
     * @return ChatSession[] Active sessions of this chat
     */
    public function getSessions(): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($this->chat_id, 'text') . " AND is_active = 1";
        $result = $db->query($query);

        $sessions = [];
        while ($row = $db->fetchAssoc($result)) {
            $sessions[] = new ChatSession($row['session_id']);
        }

        return $sessions;
    }

    public function getChatId(): string
    {
        return $this->chat_id;
    }
    public function setChatId(string $chat_id): void
    {
        $this->chat_id = $chat_id;
    }
    public function getPageId(): int
    {
        return $this->page_id;
    }
    public function setPageId(int $page_id): void
    {
        $this->page_id = $page_id;
    }
    public function getParentId(): int
    {
        return $this->parent_id;
    }
    public function setParentId(int $parent_id): void
    {
        $this->parent_id = $parent_id;
    }
    public function getParentType(): string
    {
        return $this->parent_type;
    }
    public function setParentType(string $parent_type): void
    {
        $this->parent_type = $parent_type;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
    public function getSystemPrompt(): string
    {
        return $this->system_prompt;
    }
    public function setSystemPrompt(string $system_prompt): void
    {
        $this->system_prompt = $system_prompt;
    }
    public function getAiService(): string
    {
        return $this->ai_service;
    }
    public function setAiService(string $ai_service): void
    {
        $this->ai_service = $ai_service;
    }
    public function getMaxMemory(): int
    {
        return $this->max_memory;
    }
    public function setMaxMemory(int $max_memory): void
    {
        $this->max_memory = $max_memory;
    }
    public function getCharLimit(): int
    {
        return $this->char_limit;
    }
    public function setCharLimit(int $char_limit): void
    {
        $this->char_limit = $char_limit;
    }
    public function isPersistent(): bool
    {
        return $this->persistent;
    }
    public function setPersistent(bool $persistent): void
    {
        $this->persistent = $persistent;
    }
    public function isIncludePageContext(): bool
    {
        return $this->include_page_context;
    }
    public function setIncludePageContext(bool $include_page_context): void
    {
        $this->include_page_context = $include_page_context;
    }
    public function isEnableChatUploads(): bool
    {
        return $this->enable_chat_uploads;
    }
    public function setEnableChatUploads(bool $enable_chat_uploads): void
    {
        $this->enable_chat_uploads = $enable_chat_uploads;
    }
    public function isEnableStreaming(): bool
    {
        return $this->enable_streaming;
    }
    public function setEnableStreaming(bool $enable_streaming): void
    {
        $this->enable_streaming = $enable_streaming;
    }

    public function isEnableRag(): bool
    {
        return $this->enable_rag;
    }
    public function setEnableRag(bool $enable_rag): void
    {
        $this->enable_rag = $enable_rag;
    }
    public function isShowSources(): bool
    {
        return $this->show_sources;
    }
    public function setShowSources(bool $show_sources): void
    {
        $this->show_sources = $show_sources;
    }
    public function isAllowSourceDownloads(): bool
    {
        return $this->allow_source_downloads;
    }
    public function setAllowSourceDownloads(bool $allow_source_downloads): void
    {
        $this->allow_source_downloads = $allow_source_downloads;
    }
    public function isOnline(): bool
    {
        return $this->is_online;
    }
    public function setIsOnline(bool $is_online): void
    {
        $this->is_online = $is_online;
    }
    public function getDisclaimer(): string
    {
        return $this->disclaimer;
    }
    public function setDisclaimer(string $disclaimer): void
    {
        $this->disclaimer = $disclaimer;
    }
    public function getTemperature(): ?float
    {
        return $this->temperature;
    }
    public function setTemperature(?float $temperature): void
    {
        $this->temperature = $temperature;
    }
    public function getModel(): ?string
    {
        return $this->model;
    }
    public function setModel(?string $model): void
    {
        $this->model = empty($model) ? null : $model;
    }
    public function getRAGCollectionId(): ?string
    {
        return $this->rag_collection_id;
    }
    public function setRAGCollectionId(?string $rag_collection_id): void
    {
        $this->rag_collection_id = $rag_collection_id;
    }
    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }

    public function toArray(): array
    {
        return [
            'chat_id' => $this->chat_id,
            'page_id' => $this->page_id,
            'parent_id' => $this->parent_id,
            'parent_type' => $this->parent_type,
            'title' => $this->title,
            'system_prompt' => $this->system_prompt,
            'ai_service' => $this->ai_service,
            'max_memory' => $this->max_memory,
            'char_limit' => $this->char_limit,
            'persistent' => $this->persistent,
            'include_page_context' => $this->include_page_context,
            'enable_chat_uploads' => $this->enable_chat_uploads,
            'show_sources' => $this->show_sources,
            'allow_source_downloads' => $this->allow_source_downloads,
            'is_online' => $this->is_online,
            'disclaimer' => $this->disclaimer,
            'rag_collection_id' => $this->rag_collection_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * @return string[] Resource IDs of the background files
     */
    public function getBackgroundFiles(): array
    {
        global $DIC;
        $db = $DIC->database();

        $file_ids = [];
        $query = "SELECT resource_id FROM pcaic_attachments " .
                 "WHERE chat_id = " . $db->quote($this->chat_id, 'text') . " " .
                 "AND background_file = 1 " .
                 "ORDER BY timestamp ASC";

        $result = $db->query($query);
        while ($row = $db->fetchAssoc($result)) {
            $file_ids[] = $row['resource_id'];
        }

        return $file_ids;
    }
}
