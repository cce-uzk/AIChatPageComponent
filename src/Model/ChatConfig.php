<?php

namespace ILIAS\Plugin\pcaic\Model;

/**
 * Chat configuration model
 *
 * Represents the configuration settings for a single PageComponent instance.
 * Each PageComponent has its own configuration stored in the pcaic_chats table.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ChatConfig
{
    private string $chatId;
    private int $pageId = 0;
    private int $parentId = 0;
    private string $parentType = '';
    private string $title = '';
    private string $systemPrompt = '';
    private string $aiService = 'ramses';
    private int $maxMemory = 10;
    private int $charLimit = 2000;
    private bool $persistent = true;
    private bool $includePageContext = true;
    private bool $enableChatUploads = false;
    private bool $enableStreaming = true;
    private bool $enableRag = false;
    private string $disclaimer = '';
    private ?string $ragCollectionId = null;
    private ?\DateTime $createdAt = null;
    private ?\DateTime $updatedAt = null;

    /**
     * Constructor
     *
     * @param string|null $chatId Optional chat ID to load existing configuration
     */
    public function __construct(string $chatId = null)
    {
        if ($chatId) {
            $this->chatId = $chatId;
            $this->load();
        } else {
            $this->chatId = uniqid('chat_', true);
            $this->createdAt = new \DateTime();
            $this->updatedAt = new \DateTime();
            $this->loadGlobalDefaults();
        }
    }

    /**
     * Load configuration from database
     *
     * @return bool True if configuration was found and loaded, false otherwise
     */
    private function load(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT * FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chatId, 'text');
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            $this->pageId = (int)$row['page_id'];
            $this->parentId = (int)$row['parent_id'];
            $this->parentType = $row['parent_type'];
            $this->title = $row['title'] ?? '';
            $this->systemPrompt = $row['system_prompt'] ?? '';
            $this->aiService = $row['ai_service'] ?? 'ramses';
            $this->maxMemory = (int)$row['max_memory'];
            $this->charLimit = (int)$row['char_limit'];
            $this->persistent = (bool)$row['persistent'];
            $this->includePageContext = (bool)$row['include_page_context'];
            $this->enableChatUploads = (bool)$row['enable_chat_uploads'];
            $this->enableStreaming = (bool)($row['enable_streaming'] ?? true);
            $this->enableRag = (bool)($row['enable_rag'] ?? false);
            $this->disclaimer = $row['disclaimer'] ?? '';
            $this->ragCollectionId = $row['rag_collection_id'] ?? null;

            $this->createdAt = $row['created_at'] ? new \DateTime($row['created_at']) : null;
            $this->updatedAt = $row['updated_at'] ? new \DateTime($row['updated_at']) : null;
            
            return true;
        }
        
        return false;
    }

    /**
     * Load default values from global plugin configuration
     */
    private function loadGlobalDefaults(): void
    {
        try {
            require_once(__DIR__ . '/../../classes/platform/class.AIChatPageComponentConfig.php');

            $default_prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
            if (!empty($default_prompt)) {
                $this->systemPrompt = $default_prompt;
            }
            
            $default_disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
            if (!empty($default_disclaimer)) {
                $this->disclaimer = $default_disclaimer;
            }
            
            $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
            if (!empty($char_limit)) {
                $this->charLimit = (int)$char_limit;
            }
            
            $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
            if (!empty($max_memory)) {
                $this->maxMemory = (int)$max_memory;
            }
            
            global $DIC;
            $DIC->logger()->pcaic()->debug("Loaded global defaults for new ChatConfig", [
                'system_prompt_length' => strlen($this->systemPrompt),
                'disclaimer_length' => strlen($this->disclaimer),
                'char_limit' => $this->charLimit,
                'max_memory' => $this->maxMemory
            ]);
            
        } catch (\Exception $e) {
            global $DIC;
            $DIC->logger()->pcaic()->warning("Failed to load global defaults for ChatConfig", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save configuration to database
     *
     * Performs INSERT for new configurations or UPDATE for existing ones.
     *
     * @return bool Always returns true
     */
    public function save(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $this->updatedAt = new \DateTime();

        $query = "SELECT chat_id FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chatId, 'text');
        $result = $db->query($query);
        $exists = $db->fetchAssoc($result);

        $values = [
            'page_id' => ['integer', $this->pageId],
            'parent_id' => ['integer', $this->parentId],
            'parent_type' => ['text', $this->parentType],
            'title' => ['text', $this->title],
            'system_prompt' => ['clob', $this->systemPrompt],
            'ai_service' => ['text', $this->aiService],
            'max_memory' => ['integer', $this->maxMemory],
            'char_limit' => ['integer', $this->charLimit],
            'persistent' => ['integer', $this->persistent ? 1 : 0],
            'include_page_context' => ['integer', $this->includePageContext ? 1 : 0],
            'enable_chat_uploads' => ['integer', $this->enableChatUploads ? 1 : 0],
            'enable_streaming' => ['integer', $this->enableStreaming ? 1 : 0],
            'enable_rag' => ['integer', $this->enableRag ? 1 : 0],
            'disclaimer' => ['clob', $this->disclaimer],
            'rag_collection_id' => ['text', $this->ragCollectionId],
            'updated_at' => ['timestamp', $this->updatedAt->format('Y-m-d H:i:s')]
        ];

        if ($exists) {
            $db->update('pcaic_chats', $values, ['chat_id' => ['text', $this->chatId]]);
        } else {
            if (!$this->createdAt) {
                $this->createdAt = new \DateTime();
            }
            $values['chat_id'] = ['text', $this->chatId];
            $values['created_at'] = ['timestamp', $this->createdAt->format('Y-m-d H:i:s')];
            $db->insert('pcaic_chats', $values);
        }

        return true;
    }

    /**
     * Delete configuration from database
     *
     * Cascades to associated sessions and messages via foreign key constraints.
     *
     * @return bool Always returns true
     */
    public function delete(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chatId, 'text');
        $db->manipulate($query);

        return true;
    }

    /**
     * Check if configuration exists in database
     *
     * @return bool True if configuration exists, false otherwise
     */
    public function exists(): bool
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT chat_id FROM pcaic_chats WHERE chat_id = " . $db->quote($this->chatId, 'text');
        $result = $db->query($query);
        return $db->fetchAssoc($result) !== false;
    }

    /**
     * Get all active sessions for this chat
     *
     * @return ChatSession[] Array of active ChatSession objects
     */
    public function getSessions(): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($this->chatId, 'text') . " AND is_active = 1";
        $result = $db->query($query);
        
        $sessions = [];
        while ($row = $db->fetchAssoc($result)) {
            $sessions[] = new ChatSession($row['session_id']);
        }
        
        return $sessions;
    }

    public function getChatId(): string { return $this->chatId; }
    public function setChatId(string $chatId): void { $this->chatId = $chatId; }
    public function getPageId(): int { return $this->pageId; }
    public function setPageId(int $pageId): void { $this->pageId = $pageId; }
    public function getParentId(): int { return $this->parentId; }
    public function setParentId(int $parentId): void { $this->parentId = $parentId; }
    public function getParentType(): string { return $this->parentType; }
    public function setParentType(string $parentType): void { $this->parentType = $parentType; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getSystemPrompt(): string { return $this->systemPrompt; }
    public function setSystemPrompt(string $systemPrompt): void { $this->systemPrompt = $systemPrompt; }
    public function getAiService(): string { return $this->aiService; }
    public function setAiService(string $aiService): void { $this->aiService = $aiService; }
    public function getMaxMemory(): int { return $this->maxMemory; }
    public function setMaxMemory(int $maxMemory): void { $this->maxMemory = $maxMemory; }
    public function getCharLimit(): int { return $this->charLimit; }
    public function setCharLimit(int $charLimit): void { $this->charLimit = $charLimit; }
    public function isPersistent(): bool { return $this->persistent; }
    public function setPersistent(bool $persistent): void { $this->persistent = $persistent; }
    public function isIncludePageContext(): bool { return $this->includePageContext; }
    public function setIncludePageContext(bool $includePageContext): void { $this->includePageContext = $includePageContext; }
    public function isEnableChatUploads(): bool { return $this->enableChatUploads; }
    public function setEnableChatUploads(bool $enableChatUploads): void { $this->enableChatUploads = $enableChatUploads; }
    public function isEnableStreaming(): bool { return $this->enableStreaming; }
    public function setEnableStreaming(bool $enableStreaming): void { $this->enableStreaming = $enableStreaming; }

    public function isEnableRag(): bool { return $this->enableRag; }
    public function setEnableRag(bool $enableRag): void { $this->enableRag = $enableRag; }
    public function getDisclaimer(): string { return $this->disclaimer; }
    public function setDisclaimer(string $disclaimer): void { $this->disclaimer = $disclaimer; }
    public function getRAGCollectionId(): ?string { return $this->ragCollectionId; }
    public function setRAGCollectionId(?string $ragCollectionId): void { $this->ragCollectionId = $ragCollectionId; }
    public function getCreatedAt(): ?\DateTime { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTime { return $this->updatedAt; }

    /**
     * Convert configuration to array representation
     *
     * @return array Associative array containing all configuration properties
     */
    public function toArray(): array
    {
        return [
            'chat_id' => $this->chatId,
            'page_id' => $this->pageId,
            'parent_id' => $this->parentId,
            'parent_type' => $this->parentType,
            'title' => $this->title,
            'system_prompt' => $this->systemPrompt,
            'ai_service' => $this->aiService,
            'max_memory' => $this->maxMemory,
            'char_limit' => $this->charLimit,
            'persistent' => $this->persistent,
            'include_page_context' => $this->includePageContext,
            'enable_chat_uploads' => $this->enableChatUploads,
            'disclaimer' => $this->disclaimer,
            'rag_collection_id' => $this->ragCollectionId,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Get background files for this chat
     *
     * Returns resource IDs of all background files (attachments with background_file=1).
     *
     * @return string[] Array of resource IDs
     */
    public function getBackgroundFiles(): array
    {
        global $DIC;
        $db = $DIC->database();

        $file_ids = [];
        $query = "SELECT resource_id FROM pcaic_attachments " .
                 "WHERE chat_id = " . $db->quote($this->chatId, 'text') . " " .
                 "AND background_file = 1 " .
                 "ORDER BY timestamp ASC";

        $result = $db->query($query);
        while ($row = $db->fetchAssoc($result)) {
            $file_ids[] = $row['resource_id'];
        }

        return $file_ids;
    }
}