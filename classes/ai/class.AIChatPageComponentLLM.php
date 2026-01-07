<?php declare(strict_types=1);

namespace ai;

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;
use ILIAS\Plugin\pcaic\Model\ChatMessage;
use ILIAS\Plugin\pcaic\Model\Attachment;
use platform\AIChatPageComponentException;

/**
 * Abstract LLM base class
 *
 * Provides core functionality for AI language model integrations.
 * Handles message processing, file attachments, RAG mode, and multimodal interactions.
 *
 * To add a new LLM service:
 * 1. Create a new class extending this abstract class
 * 2. Implement all abstract methods (metadata, configuration, capabilities)
 * 3. Add the class to AIChatPageComponentLLMRegistry::getAvailableServices()
 * 4. The system will automatically add configuration tab and service selector
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
abstract class AIChatPageComponentLLM
{
    protected ?int $max_memory_messages = null;
    protected ?string $prompt = null;
    protected bool $streaming = false;
    protected \ilLogger $logger;

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->comp('pcaic');
    }

    // ============================================
    // Service Metadata (MUST be implemented by each service)
    // ============================================

    /**
     * Get unique service identifier
     *
     * Used for configuration keys, routing, and service selection.
     * Must be unique across all services.
     *
     * @return string Service ID (e.g., 'ramses', 'openai', 'gemini')
     */
    abstract public static function getServiceId(): string;

    /**
     * Get human-readable service name
     *
     * Displayed in UI (tabs, dropdowns, etc.)
     *
     * @return string Service name (e.g., 'RAMSES', 'OpenAI GPT', 'Google Gemini')
     */
    abstract public static function getServiceName(): string;

    /**
     * Get service description
     *
     * Short description shown in configuration
     *
     * @return string Service description
     */
    abstract public static function getServiceDescription(): string;

    // ============================================
    // Configuration Management (MUST be implemented by each service)
    // ============================================

    /**
     * Get configuration form inputs for this service
     *
     * Returns array of ILIAS UI Factory form inputs that will be rendered
     * in the service's configuration tab.
     *
     * @return array Array of form field name => Input component
     */
    abstract public function getConfigurationFormInputs(): array;

    /**
     * Save configuration data for this service
     *
     * Called when configuration form is submitted.
     * Should save all service-specific settings.
     *
     * @param array $formData Form data from submission
     * @return void
     */
    abstract public function saveConfiguration(array $formData): void;

    /**
     * Get default configuration values for this service
     *
     * Used during plugin installation and for fallback values.
     *
     * @return array Array of config_key => default_value
     */
    abstract public static function getDefaultConfiguration(): array;

    // ============================================
    // Service Capabilities (MUST be implemented by each service)
    // ============================================

    /**
     * Get service capabilities
     *
     * Describes what features this service supports.
     * Used for conditional UI rendering and feature availability checks.
     *
     * Expected keys:
     * - 'streaming': bool - Supports streaming responses
     * - 'rag': bool - Supports RAG mode
     * - 'multimodal': bool - Supports images/files
     * - 'file_types': array - Supported file extensions
     * - 'max_tokens': int|null - Maximum context length
     *
     * @return array Service capabilities
     */
    abstract public function getCapabilities(): array;

    // ============================================
    // Configuration Getters/Setters
    // ============================================

    public function getMaxMemoryMessages(): ?int
    {
        return $this->max_memory_messages;
    }

    public function setMaxMemoryMessages(?int $max_memory_messages): void
    {
        $this->max_memory_messages = $max_memory_messages;
    }

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(?string $prompt): void
    {
        $this->prompt = $prompt;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    public function setStreaming(bool $streaming): void
    {
        $this->streaming = $streaming;
    }

    /**
     * Check if file handling is enabled for this AI service (hierarchical check)
     *
     * @param string $aiService AI service identifier (ramses|openai)
     * @return bool True if file handling should be used, false otherwise
     */
    protected function isFileHandlingEnabledForService(string $aiService): bool
    {
        // 1. Check central/global file handling setting
        $global_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
        if ($global_file_handling !== '1') {
            return false; // Centrally disabled
        }

        // 2. Check service-specific file handling setting
        $service_file_handling_key = $aiService . '_file_handling_enabled';
        $service_file_handling = \platform\AIChatPageComponentConfig::get($service_file_handling_key);

        // Default values: Both RAMSES and OpenAI enabled by default
        $default_file_handling = '1';
        $service_file_handling = $service_file_handling ?? $default_file_handling;

        if ($service_file_handling !== '1') {
            return false; // Service-specific disabled
        }

        return true; // Both conditions met
    }

    // ============================================
    // Core Message Handling (NEW ARCHITECTURE)
    // ============================================

    /**
     * Main entry point for sending a message - handles complete flow
     *
     * @param string $chat_id Chat identifier
     * @param int $user_id User identifier
     * @param string $message User message text
     * @param array $attachment_ids Optional attachment IDs to bind to message
     * @return string AI response
     */
    public function handleSendMessage(string $chat_id, int $user_id, string $message, array $attachment_ids = []): string
    {
        try {
            // Load chat configuration
            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                throw new AIChatPageComponentException('Chat configuration not found');
            }

            // Get or create session
            $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);

            // Add user message to session
            $userMessage = $session->addMessage('user', $message);

            // Bind attachments to message if provided
            if (!empty($attachment_ids)) {
                foreach ($attachment_ids as $attachment_id) {
                    if (is_numeric($attachment_id)) {
                        $userMessage->addAttachment($attachment_id);
                    }
                }
            }

            // Set configuration from chat
            $this->setPrompt($chatConfig->getSystemPrompt());
            $this->setMaxMemoryMessages($chatConfig->getMaxMemory());

            // Check hierarchical file handling (global → service)
            $ai_service = $chatConfig->getAiService();
            $fileHandlingEnabled = $this->isFileHandlingEnabledForService($ai_service);

            // Check if we should use RAG (service + global + chat settings)
            // This must be determined BEFORE processing background files!
            // RAG requires file handling to be enabled
            $collectionIds = [];
            $useRAG = false;
            if ($fileHandlingEnabled) {
                $collectionIds = $this->getAllRAGCollectionIds($chatConfig);
                $useRAG = $this->isRagEnabledForChat($chatConfig) && !empty($collectionIds);
            }

            // Process background files as context (only if file handling is enabled)
            // In RAG mode: Skip PDFs (they're already in the collection)
            // In Multimodal mode: Convert PDFs to images
            $contextResources = [];
            if ($fileHandlingEnabled) {
                $contextResources = $this->processBackgroundFiles($chatConfig, $useRAG);
            } else {
                $this->logger->debug("File handling disabled - skipping background files processing");
            }

            // Add page context if enabled
            if ($chatConfig->isIncludePageContext()) {
                $pageContext = $this->getPageContext($chatConfig);
                if (!empty($pageContext)) {
                    $contextResources[] = [
                        'kind' => 'page_context',
                        'title' => 'Page Context',
                        'content' => $pageContext,
                        'mime_type' => 'text/plain'
                    ];
                }
            }

            // Convert recent messages to AI format
            $recent_limit = min($chatConfig->getMaxMemory(), 20);
            $aiMessages = $this->processChatMessages($session, $recent_limit, $useRAG, $fileHandlingEnabled);

            // Sync chat attachments to RAG if RAG is enabled AND file handling is enabled
            if ($useRAG && $fileHandlingEnabled) {
                $this->logger->debug("RAG mode active, checking for chat attachments to sync");
                $sync_stats = $this->syncChatAttachmentsToRAG($session, $recent_limit);
                if ($sync_stats['uploaded'] > 0) {
                    $this->logger->info("Synced chat attachments to RAG", $sync_stats);
                    // Refresh collection IDs after sync
                    $collectionIds = $this->getAllRAGCollectionIds($chatConfig);
                }
            }

            // Send to AI
            if ($useRAG) {
                $this->logger->debug("Using RAG mode", ['collection_ids' => $collectionIds]);
                $aiResponse = $this->sendRagChat($aiMessages, $collectionIds, $contextResources);
            } else {
                $this->logger->debug("Using standard mode");
                $aiResponse = $this->sendMessagesArray($aiMessages, $contextResources);
            }

            // Add AI response to session
            $session->addMessage('assistant', $aiResponse);

            return $aiResponse;

        } catch (\Exception $e) {
            $this->logger->error("handleSendMessage failed", [
                'chat_id' => $chat_id,
                'error' => $e->getMessage()
            ]);
            throw new AIChatPageComponentException('Failed to send message: ' . $e->getMessage());
        }
    }

    /**
     * Process background files using Attachment class (with Flavour caching!)
     *
     * @param ChatConfig $chatConfig Chat configuration
     * @param bool $ragMode Whether RAG mode is active
     * @return array Context resources array
     */
    protected function processBackgroundFiles(ChatConfig $chatConfig, bool $ragMode = false): array
    {
        $contextResources = [];

        try {
            // Get background files from attachments table (message_id = NULL)
            $background_files = $chatConfig->getBackgroundFiles();

            if (empty($background_files)) {
                $this->logger->debug("No background files found");
                return [];
            }

            $this->logger->debug("Processing background files", [
                'count' => count($background_files),
                'rag_mode' => $ragMode
            ]);

            global $DIC;
            $irss = $DIC->resourceStorage();

            foreach ($background_files as $file_id) {
                try {
                    $identification = $irss->manage()->find($file_id);
                    if ($identification === null) {
                        continue;
                    }

                    $revision = $irss->manage()->getCurrentRevision($identification);
                    if ($revision === null) {
                        continue;
                    }

                    $suffix = strtolower($revision->getInformation()->getSuffix());
                    $mime_type = $revision->getInformation()->getMimeType();

                    // Process text files
                    if (in_array($suffix, ['txt', 'md', 'csv'])) {
                        $stream = $irss->consume()->stream($identification);
                        $content = $stream->getStream()->getContents();

                        if (!empty($content)) {
                            $contextResources[] = [
                                'kind' => 'text_file',
                                'id' => 'bg-text-' . $file_id,
                                'title' => $revision->getTitle(),
                                'mime_type' => $mime_type,
                                'content' => $content
                            ];
                        }
                    }
                    // Process images using Attachment class (with caching)
                    elseif (in_array($suffix, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        // Create temporary Attachment instance for processing
                        $attachment = new Attachment();
                        $attachment->setResourceId($file_id);
                        $attachment->setChatId($chatConfig->getChatId());

                        $dataUrl = $attachment->getDataUrl();
                        if ($dataUrl) {
                            $contextResources[] = [
                                'kind' => 'image_file',
                                'id' => 'bg-img-' . $file_id,
                                'title' => $revision->getTitle(),
                                'mime_type' => $mime_type,
                                'url' => $dataUrl
                            ];
                        }
                    }
                    // Process PDFs using Attachment class (with Flavour caching!)
                    elseif ($suffix === 'pdf') {
                        // In RAG mode: Skip PDF processing (files are already in RAMSES collection)
                        if ($ragMode) {
                            $this->logger->debug("Skipping PDF flavour generation (RAG mode active)", [
                                'file_id' => $file_id,
                                'title' => $revision->getTitle()
                            ]);
                            continue;
                        }

                        // In Multimodal mode: Convert PDF pages to images
                        // Create temporary Attachment instance for processing
                        $attachment = new Attachment();
                        $attachment->setResourceId($file_id);
                        $attachment->setChatId($chatConfig->getChatId());

                        $pdfDataUrls = $attachment->getDataUrl();
                        if ($pdfDataUrls && is_array($pdfDataUrls)) {
                            foreach ($pdfDataUrls as $pageIndex => $pageDataUrl) {
                                if (!empty($pageDataUrl)) {
                                    $contextResources[] = [
                                        'kind' => 'pdf_page',
                                        'id' => 'bg-pdf-' . $file_id . '-p' . ($pageIndex + 1),
                                        'title' => $revision->getTitle() . ' (Page ' . ($pageIndex + 1) . ')',
                                        'mime_type' => 'image/png',
                                        'page_number' => $pageIndex + 1,
                                        'source_file' => $revision->getTitle(),
                                        'url' => $pageDataUrl
                                    ];
                                }
                            }
                        }
                    }

                } catch (\Exception $e) {
                    $this->logger->warning("Background file processing failed", [
                        'file_id' => $file_id,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("processBackgroundFiles failed", ['error' => $e->getMessage()]);
        }

        return $contextResources;
    }

    /**
     * Convert session messages to AI format with multimodal support
     *
     * @param ChatSession $session User session
     * @param int $limit Maximum number of recent messages
     * @param bool $ragMode Whether RAG mode is active
     * @param bool $fileHandlingEnabled Whether file handling is enabled (hierarchical check)
     * @return array AI-formatted messages array
     */
    protected function processChatMessages(ChatSession $session, int $limit = 10, bool $ragMode = false, bool $fileHandlingEnabled = true): array
    {
        $aiMessages = [];

        try {
            $recentMessages = $session->getRecentMessages($limit);

            foreach ($recentMessages as $msg) {
                $content = $msg->getMessage();
                $attachments = $msg->getAttachments();

                // Skip attachment processing if file handling is disabled
                if (!$fileHandlingEnabled) {
                    // File handling disabled: Text-only messages
                    $aiMessages[] = [
                        'role' => $msg->getRole(),
                        'content' => $content
                    ];
                    continue; // Skip to next message
                }

                // In RAG mode: Skip attachments (they're either in collection or incompatible)
                // In Multimodal mode: Process attachments as Base64
                if ($ragMode) {
                    // RAG mode: Text-only messages (attachments are in RAG collection)
                    $aiMessages[] = [
                        'role' => $msg->getRole(),
                        'content' => $content
                    ];
                } else {
                    // Multimodal mode: Process attachments as Base64
                    // Separate attachments into RAG vs Base64 (multimodal)
                    $separated = $this->separateAttachmentsByMode($attachments);
                    $base64Attachments = $separated['base64'];

                    // Build multimodal content if we have image/PDF attachments
                    if (!empty($base64Attachments)) {
                        $multimodalContent = [];

                        // Add text content if present
                        if (!empty(trim($content))) {
                            $multimodalContent[] = ['type' => 'text', 'text' => $content];
                        }

                        // Add image attachments
                        foreach ($base64Attachments as $attachment) {
                            try {
                                if ($attachment->isImage()) {
                                    $imageData = $attachment->getDataUrl();
                                    if ($imageData) {
                                        $multimodalContent[] = [
                                            'type' => 'image_url',
                                            'image_url' => ['url' => $imageData]
                                        ];
                                    }
                                } elseif ($attachment->isPdf()) {
                                    $pdfDataUrls = $attachment->getDataUrl();
                                    if ($pdfDataUrls && is_array($pdfDataUrls)) {
                                        foreach ($pdfDataUrls as $pageDataUrl) {
                                            if ($pageDataUrl) {
                                                $multimodalContent[] = [
                                                    'type' => 'image_url',
                                                    'image_url' => ['url' => $pageDataUrl]
                                                ];
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $this->logger->warning("Attachment processing failed", ['error' => $e->getMessage()]);
                            }
                        }

                        $aiMessages[] = [
                            'role' => $msg->getRole(),
                            'content' => $multimodalContent
                        ];
                    } else {
                        // Text-only message
                        $aiMessages[] = [
                            'role' => $msg->getRole(),
                            'content' => $content
                        ];
                    }
                } // End of if ($ragMode) else block
            }

        } catch (\Exception $e) {
            $this->logger->error("processChatMessages failed", ['error' => $e->getMessage()]);
        }

        return $aiMessages;
    }

    /**
     * Separate attachments into RAG vs Base64 (multimodal) modes
     */
    protected function separateAttachmentsByMode(array $attachments): array
    {
        $rag_attachments = [];
        $base64_attachments = [];

        foreach ($attachments as $attachment) {
            if ($attachment->isInRAG()) {
                $rag_attachments[] = $attachment;
            } else {
                $base64_attachments[] = $attachment;
            }
        }

        return [
            'rag' => $rag_attachments,
            'base64' => $base64_attachments
        ];
    }

    /**
     * Get all RAG collection IDs for a chat
     */
    protected function getAllRAGCollectionIds(ChatConfig $chatConfig): array
    {
        global $DIC;
        $db = $DIC->database();

        $collection_ids = [];

        try {
            $query = "SELECT DISTINCT rag_collection_id FROM pcaic_attachments " .
                     "WHERE chat_id = " . $db->quote($chatConfig->getChatId(), 'text') . " " .
                     "AND rag_collection_id IS NOT NULL";

            $result = $db->query($query);
            while ($row = $db->fetchAssoc($result)) {
                if (!empty($row['rag_collection_id'])) {
                    $collection_ids[] = $row['rag_collection_id'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to get RAG collection IDs", ['error' => $e->getMessage()]);
        }

        return array_unique($collection_ids);
    }

    /**
     * Get page context for chat
     */
    protected function getPageContext(ChatConfig $chatConfig): string
    {
        global $DIC;

        $page_id = (int) $chatConfig->getPageId();
        $parent_id = (int) $chatConfig->getParentId();
        $parent_type = (string) $chatConfig->getParentType();

        if (!$page_id && !$parent_id) {
            return '';
        }

        // Map to COPage type
        $copage_type_map = [
            'crs' => 'cont',
            'grp' => 'cont',
            'cont'=> 'cont',
            'cat' => 'cont',
            'lm'  => 'lm',
            'wpg' => 'wpg',
            'wiki'=> 'wpg',
            'copa'=> 'copa',
            'glo' => 'glo',
            'blp' => 'blp',
            'frm' => 'frm',
            'tst' => 'tst',
            'qpl' => 'qpl'
        ];

        $copage_type = $copage_type_map[$parent_type] ?? null;
        if (!$copage_type) {
            return '';
        }

        try {
            $page_manager = $DIC->copage()->internal()->domain()->page();
            $page = null;

            if ($copage_type === 'wpg' && $page_id > 0) {
                $page = $page_manager->get('wpg', $page_id);
            } else {
                $page = $page_manager->get($copage_type, $parent_id);
            }

            if (!$page) {
                return '';
            }

            $page_xml = $page->getXMLContent();
            if (empty($page_xml)) {
                return '';
            }

            // Extract text content from XML
            $dom = new \DOMDocument();
            @$dom->loadXML($page_xml);

            $xpath = new \DOMXPath($dom);
            $paragraphs = $xpath->query('//Paragraph');

            $content_parts = [];
            foreach ($paragraphs as $para) {
                $text = trim($para->textContent);
                if (!empty($text)) {
                    $content_parts[] = $text;
                }
            }

            return implode("\n\n", $content_parts);

        } catch (\Exception $e) {
            $this->logger->warning("Failed to get page context", ['error' => $e->getMessage()]);
            return '';
        }
    }

    // ============================================
    // Abstract Methods (Service-Specific)
    // ============================================

    /**
     * Send messages array directly (implemented by service-specific classes)
     *
     * @param array $messages Array of messages with 'role' and 'content'
     * @param array|null $contextResources Optional context resources
     * @return string AI response
     */
    abstract public function sendMessagesArray(array $messages, ?array $contextResources = null): string;

    // ============================================
    // File Type Support (RAG-specific)
    // ============================================

    /**
     * Get allowed file types based on RAG mode
     *
     * @param bool $ragEnabled Whether RAG mode is enabled
     * @return array Array of allowed file extensions (without dots)
     */
    abstract public function getAllowedFileTypes(bool $ragEnabled): array;

    /**
     * Check if a file type is allowed in current mode
     *
     * @param string $extension File extension (without dot)
     * @param bool $ragEnabled Whether RAG mode is enabled
     * @return bool True if file type is allowed
     */
    public function isFileTypeAllowed(string $extension, bool $ragEnabled): bool
    {
        $extension = strtolower($extension);
        $allowed = $this->getAllowedFileTypes($ragEnabled);
        return in_array($extension, $allowed, true);
    }

    /**
     * Get human-readable description of allowed types
     *
     * @param bool $ragEnabled Whether RAG mode is enabled
     * @return string Formatted list of allowed extensions
     */
    public function getAllowedFileTypesDescription(bool $ragEnabled): string
    {
        $types = $this->getAllowedFileTypes($ragEnabled);
        return implode(', ', array_map(fn($type) => strtoupper($type), $types));
    }

    // ============================================
    // Model Parameters
    // ============================================

    /**
     * Get model-specific API parameters
     *
     * Override in subclasses to customize parameters for specific models/services.
     * Some models don't support certain parameters (e.g., OpenAI o1 doesn't support temperature).
     *
     * @return array Associative array of API parameters (temperature, top_p, etc.)
     */
    protected function getModelParameters(): array
    {
        // Default parameters - override in subclasses if needed
        return [
            'temperature' => 0.7
        ];
    }

    // ============================================
    // RAG (Retrieval-Augmented Generation) Support
    // ============================================

    public function supportsRAG(): bool
    {
        return false; // Default: No RAG support
    }

    /**
     * Check if RAG is actually enabled for a specific chat
     *
     * RAG is only enabled if ALL three conditions are met:
     * 1. The AI service supports RAG functionality
     * 2. RAG is globally enabled for this service in plugin config
     * 3. RAG is enabled for this specific chat
     *
     * @param ChatConfig $chatConfig Chat configuration
     * @return bool True if RAG should be used, false otherwise (multimodal mode)
     */
    public function isRagEnabledForChat(ChatConfig $chatConfig): bool
    {
        // First check: Does the service support RAG at all?
        if (!$this->supportsRAG()) {
            return false;
        }

        // Second check: Is RAG globally enabled for this service?
        $ai_service = $chatConfig->getAiService();
        $rag_config_key = $ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        if (!$rag_globally_enabled) {
            return false;
        }

        // Third check: Has the chat enabled RAG?
        return $chatConfig->isEnableRag();
    }

    public function supportsMultimodal(): bool
    {
        return false; // Default: No multimodal support
    }

    public function supportsBase64Images(): bool
    {
        return false; // Default: No base64 image support
    }

    public function supportsStreaming(): bool
    {
        return false; // Default: No streaming support
    }

    public function uploadFileToRAG(string $filepath, string $entityId): array
    {
        if (!$this->supportsRAG()) {
            throw new AIChatPageComponentException(get_class($this) . " does not support RAG file uploads");
        }

        throw new AIChatPageComponentException("uploadFileToRAG() not implemented in " . get_class($this));
    }

    public function deleteFileFromRAG(string $remoteFileId, string $entityId): bool
    {
        if (!$this->supportsRAG()) {
            throw new AIChatPageComponentException(get_class($this) . " does not support RAG file deletion");
        }

        throw new AIChatPageComponentException("deleteFileFromRAG() not implemented in " . get_class($this));
    }

    public function sendRagChat(array $messages, array $collectionIds, ?array $contextResources = null): string
    {
        if (!$this->supportsRAG()) {
            // Fallback: Use standard chat endpoint (ignore collection_ids)
            $this->logger->warning("Service does not support RAG, falling back to standard chat", [
                'service' => get_class($this),
                'collections' => $collectionIds
            ]);
            return $this->sendMessagesArray($messages, $contextResources);
        }

        throw new AIChatPageComponentException("sendRagChat() not implemented in " . get_class($this));
    }

    /**
     * Refresh available models from API
     *
     * Fetches the list of available models from the service's API endpoint,
     * caches them, and returns success/error information.
     *
     * @return array ['success' => bool, 'message' => string, 'models' => array|null]
     */
    public function refreshModels(): array
    {
        return [
            'success' => false,
            'message' => 'Model refresh not implemented for ' . static::getServiceName(),
            'models' => null
        ];
    }

    public function getRecommendedFileHandlingMode(): string
    {
        if ($this->supportsRAG()) {
            return 'auto'; // Use RAG when beneficial
        } elseif ($this->supportsBase64Images()) {
            return 'base64_embedding'; // Fallback to embedding
        }

        return 'none'; // No file support
    }

    public function shouldUseRAGForFile(int $fileSize, bool $isReused, string $mimeType): bool
    {
        if (!$this->supportsRAG()) {
            return false; // No RAG available
        }

        // Default strategy: Use RAG for large or reused files
        $threshold = \platform\AIChatPageComponentConfig::get('rag_upload_threshold_kb') ?: 1024;
        $thresholdBytes = $threshold * 1024;

        if ($fileSize > $thresholdBytes) {
            return true; // Large file
        }

        if ($isReused) {
            return true; // Reused file
        }

        // Text files benefit from RAG semantic search
        if (strpos($mimeType, 'text/') === 0) {
            return true;
        }

        return false; // Default: Use base64
    }

    /**
     * Sync existing BACKGROUND FILES to RAG when RAG mode is activated
     *
     * This function handles the case where a chat is switched from Multimodal to RAG mode.
     * It uploads only BACKGROUND FILES (background_file = 1) that are RAG-compatible
     * and haven't been uploaded to RAMSES yet.
     *
     * @param ChatConfig $chatConfig Chat configuration
     * @return array Statistics ['uploaded' => int, 'skipped' => int, 'errors' => int]
     */
    public function syncBackgroundFilesToRAG(ChatConfig $chatConfig): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            global $DIC;
            $db = $DIC->database();

            // Find all BACKGROUND FILES for this chat that haven't been uploaded to RAG yet
            $query = "SELECT id, resource_id, message_id
                      FROM pcaic_attachments
                      WHERE chat_id = " . $db->quote($chatConfig->getChatId(), 'text') . "
                      AND background_file = 1
                      AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')";

            $result = $db->query($query);
            $attachments_to_upload = [];

            while ($row = $db->fetchAssoc($result)) {
                $attachments_to_upload[] = $row;
            }

            if (empty($attachments_to_upload)) {
                $this->logger->debug("No background files need RAG sync", ['chat_id' => $chatConfig->getChatId()]);
                return $stats;
            }

            $this->logger->info("Starting RAG sync for background files", [
                'chat_id' => $chatConfig->getChatId(),
                'count' => count($attachments_to_upload)
            ]);

            $irss = $DIC->resourceStorage();

            foreach ($attachments_to_upload as $att_row) {
                try {
                    $attachment = Attachment::loadById((int)$att_row['id']);
                    if (!$attachment) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Check if file type is RAG-compatible
                    $identification = $irss->manage()->find($att_row['resource_id']);
                    if ($identification === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    $revision = $irss->manage()->getCurrentRevision($identification);
                    if ($revision === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    $suffix = strtolower($revision->getInformation()->getSuffix());

                    // Only upload RAG-compatible file types
                    $ragCompatible = in_array($suffix, ['txt', 'md', 'csv', 'pdf'], true);
                    if (!$ragCompatible) {
                        $this->logger->debug("Skipping non-RAG file type", [
                            'attachment_id' => $att_row['id'],
                            'suffix' => $suffix
                        ]);
                        $stats['skipped']++;
                        continue;
                    }

                    // Upload to RAG
                    $entityId = $chatConfig->getChatId();
                    $stream = $irss->consume()->stream($identification);

                    // Create temp file with original filename (RAMSES validates file extension)
                    $originalFilename = $revision->getTitle();
                    $tempFile = sys_get_temp_dir() . '/' . 'rag_sync_' . uniqid() . '_' . $originalFilename;
                    file_put_contents($tempFile, $stream->getStream()->getContents());

                    $uploadResult = $this->uploadFileToRAG($tempFile, $entityId);
                    unlink($tempFile);

                    // Update attachment with RAG info
                    $attachment->setRagCollectionId($uploadResult['collection_id']);
                    $attachment->setRagRemoteFileId($uploadResult['remote_file_id']);
                    $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));
                    $attachment->save();

                    $this->logger->info("Synced background file to RAG", [
                        'attachment_id' => $att_row['id'],
                        'remote_file_id' => $uploadResult['remote_file_id'],
                        'collection_id' => $uploadResult['collection_id']
                    ]);

                    $stats['uploaded']++;

                } catch (\Exception $e) {
                    $this->logger->error("Failed to sync attachment to RAG", [
                        'attachment_id' => $att_row['id'],
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }

            $this->logger->info("RAG sync completed", $stats);

        } catch (\Exception $e) {
            $this->logger->error("RAG sync failed", ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * Sync chat attachments from recent messages to RAG
     *
     * This function is called when sending a message in RAG mode.
     * It uploads chat attachments (background_file = 0) from the last X messages
     * that are RAG-compatible and haven't been uploaded yet.
     *
     * @param ChatSession $session User chat session
     * @param int $maxMemory Maximum number of recent messages to check
     * @return array Statistics ['uploaded' => int, 'skipped' => int, 'errors' => int]
     */
    public function syncChatAttachmentsToRAG(ChatSession $session, int $maxMemory): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            global $DIC;
            $db = $DIC->database();

            // Get recent messages
            $recentMessages = $session->getRecentMessages($maxMemory);
            if (empty($recentMessages)) {
                return $stats;
            }

            // Collect message IDs
            $messageIds = array_map(fn($msg) => $msg->getMessageId(), $recentMessages);

            // Find chat attachments from recent messages that haven't been uploaded to RAG
            $messageIdList = implode(',', array_map(fn($id) => $db->quote($id, 'integer'), $messageIds));

            $query = "SELECT id, resource_id, message_id
                      FROM pcaic_attachments
                      WHERE message_id IN (" . $messageIdList . ")
                      AND background_file = 0
                      AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')";

            $result = $db->query($query);
            $attachments_to_upload = [];

            while ($row = $db->fetchAssoc($result)) {
                $attachments_to_upload[] = $row;
            }

            if (empty($attachments_to_upload)) {
                $this->logger->debug("No chat attachments need RAG sync", [
                    'session_id' => $session->getSessionId(),
                    'message_count' => count($recentMessages)
                ]);
                return $stats;
            }

            $this->logger->info("Starting RAG sync for chat attachments", [
                'session_id' => $session->getSessionId(),
                'count' => count($attachments_to_upload)
            ]);

            $irss = $DIC->resourceStorage();
            $chatId = $session->getChatId();

            foreach ($attachments_to_upload as $att_row) {
                try {
                    $attachment = Attachment::loadById((int)$att_row['id']);
                    if (!$attachment) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Check if file type is RAG-compatible
                    $identification = $irss->manage()->find($att_row['resource_id']);
                    if ($identification === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    $revision = $irss->manage()->getCurrentRevision($identification);
                    if ($revision === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    $suffix = strtolower($revision->getInformation()->getSuffix());

                    // Only upload RAG-compatible file types
                    $ragCompatible = in_array($suffix, ['txt', 'md', 'csv', 'pdf'], true);
                    if (!$ragCompatible) {
                        $this->logger->debug("Skipping non-RAG file type", [
                            'attachment_id' => $att_row['id'],
                            'suffix' => $suffix
                        ]);
                        $stats['skipped']++;
                        continue;
                    }

                    // Upload to RAG
                    $entityId = $chatId;
                    $stream = $irss->consume()->stream($identification);

                    // Create temp file with original filename (RAMSES validates file extension)
                    $originalFilename = $revision->getTitle();
                    $tempFile = sys_get_temp_dir() . '/' . 'rag_chat_sync_' . uniqid() . '_' . $originalFilename;
                    file_put_contents($tempFile, $stream->getStream()->getContents());

                    $uploadResult = $this->uploadFileToRAG($tempFile, $entityId);
                    unlink($tempFile);

                    // Update attachment with RAG info
                    $attachment->setRagCollectionId($uploadResult['collection_id']);
                    $attachment->setRagRemoteFileId($uploadResult['remote_file_id']);
                    $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));
                    $attachment->save();

                    $this->logger->info("Synced chat attachment to RAG", [
                        'attachment_id' => $att_row['id'],
                        'message_id' => $att_row['message_id'],
                        'remote_file_id' => $uploadResult['remote_file_id'],
                        'collection_id' => $uploadResult['collection_id']
                    ]);

                    $stats['uploaded']++;

                } catch (\Exception $e) {
                    $this->logger->error("Failed to sync chat attachment to RAG", [
                        'attachment_id' => $att_row['id'],
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }

            $this->logger->info("Chat attachments RAG sync completed", $stats);

        } catch (\Exception $e) {
            $this->logger->error("Chat attachments RAG sync failed", ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        return $stats;
    }
}
