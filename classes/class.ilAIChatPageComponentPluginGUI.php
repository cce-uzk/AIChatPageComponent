<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

/**
 * AI Chat Page Component GUI Controller
 * 
 * Handles all user interface interactions for the AI Chat PageComponent.
 * Manages form creation, validation, configuration storage, and rendering
 * of embedded AI chat instances within ILIAS pages.
 * 
 * Core responsibilities:
 * - Form-based configuration interface for chat settings
 * - Background file upload and management
 * - Integration with ILIAS page editor
 * - Chat rendering with proper context and permissions
 * - Session management and user interaction handling
 * 
 * ilAIChatPageComponentPlugin
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @see ilPageComponentPluginGUI Base class for PageComponent GUIs
 * @see ilAIChatPageComponentPlugin Main plugin class
 *
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilPCPluggedGUI
 */
class ilAIChatPageComponentPluginGUI extends ilPageComponentPluginGUI
{
    /** @var ilLanguage Language service for localization */
    protected ilLanguage $lng;
    
    /** @var ilCtrl Control service for URL generation and routing */
    protected ilCtrl $ctrl;
    
    /** @var ilGlobalTemplateInterface Global template for page rendering */
    protected ilGlobalTemplateInterface $tpl;
    
    /** @var \Psr\Http\Message\ServerRequestInterface HTTP request object */
    protected $request;
    
    /** @var \ilLogger Component logger for debugging and monitoring */
    protected $logger;

    /**
     * Constructor - initializes GUI dependencies and services
     * 
     * Sets up all required ILIAS services through dependency injection.
     * Establishes component-specific logging for debugging and monitoring.
     */
    public function __construct()
    {
        global $DIC;

        parent::__construct();

        // Initialize ILIAS core services
        $this->lng = $DIC->language();
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC['tpl'];
        $this->request = $DIC->http()->request();
        
        // Initialize component-specific logging
        $this->logger = $DIC->logger()->comp('pcaic');
    }

    /**
     * Sets the page content GUI reference
     * 
     * Called by ILIAS during page component initialization to establish
     * the connection between this GUI and the parent page context.
     * 
     * @param ilPageContentGUI $a_val Page content GUI instance
     */
    public function setPCGUI(ilPageContentGUI $a_val): void
    {
        $this->logger->debug("Page content GUI reference established");
        parent::setPCGUI($a_val);
    }

    /**
     * Main command dispatcher for all GUI actions
     * 
     * Routes incoming requests to appropriate handlers based on the command
     * and next class parameters. Supports both direct commands (create, edit, etc.)
     * and forwarded commands (file upload handlers).
     * 
     * Supported commands:
     * - create: Create new chat configuration
     * - save: Save chat configuration 
     * - edit: Edit existing chat
     * - update: Update existing chat
     * - cancel: Cancel current operation
     * 
     * @throws ilException On invalid commands
     */
    public function executeCommand() : void
    {
        // Load background file upload handler for multimodal support
        require_once($this->plugin->getPluginBaseDir() . '/classes/class.ilAIChatBackgroundFileUploadHandlerGUI.php');
        
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case 'ilaichatbackgroundfileuploadhandlergui':
                $upload_handler = new ilAIChatBackgroundFileUploadHandlerGUI();
                $this->ctrl->forwardCommand($upload_handler);
                break;
            default:
                // Execute standard PageComponent commands
                $cmd = $this->ctrl->getCmd();
                if (in_array($cmd, array("create", "save", "edit", "update", "cancel"))) {
                    $this->$cmd();
                } else {
                    $this->tpl->setOnScreenMessage("failure", $this->lng->txt("msg_invalid_cmd"), true);
                    $this->returnToParent();
                }
                break;
        }
    }

    /**
     * Displays the form for inserting a new AI chat component
     * 
     * Called when user selects "Insert > Plugin > AI Chat" in page editor.
     * Renders the configuration form with all available options.
     */
    public function insert() : void
    {
        global $DIC;
        $form = $this->initForm(true);
        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    /**
     * Processes form submission for creating a new AI chat component
     * 
     * Validates form data, saves configuration to database, and returns
     * to page editor. Handles both chat settings and background file uploads.
     * 
     * On success: Redirects to parent page
     * On failure: Redisplays form with error messages
     */
    public function create() : void
    {
        global $DIC;
        $form = $this->initForm(true);
        $request = $DIC->http()->request();
        
        if ($request->getMethod() == "POST") {
            $form = $form->withRequest($request);
            $data = $form->getData();
            
            if ($form->getError() != null) {
                $this->logger->debug("Form validation errors in create");
                $renderer = $DIC->ui()->renderer();
                $this->tpl->setContent($renderer->render($form));
                return;
            }
            
            if ($data !== null) {
                $this->logger->debug("Form data received in create: " . print_r($data, true));
                if ($this->saveForm($data, true)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
                    $this->returnToParent();
                    return;
                } else {
                    $this->logger->debug("saveForm failed in create");
                }
            } else {
                $this->logger->debug("No form data received in create - form validation failed");
            }
        }
        
        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    public function edit() : void
    {
        global $DIC;
        $form = $this->initForm(false);
        $renderer = $DIC->ui()->renderer();

        $this->tpl->setContent($renderer->render($form));
    }

    public function update() : void
    {
        global $DIC;
        $form = $this->initForm(false);
        $request = $DIC->http()->request();
        
        if ($request->getMethod() == "POST") {
            $form = $form->withRequest($request);
            $data = $form->getData();
            
            if ($form->getError() != null) {
                $this->logger->debug("Form validation errors in update");
                $renderer = $DIC->ui()->renderer();
                $this->tpl->setContent($renderer->render($form));
                return;
            }
            
            if ($data !== null) {
                $this->logger->debug("Form data received in update: " . print_r($data, true));
                if ($this->saveForm($data, false)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("msg_obj_modified"), true);
                    $this->returnToParent();
                    return;
                } else {
                    $this->logger->debug("saveForm failed in update");
                }
            } else {
                $this->logger->debug('No form data received in update - form validation failed');
            }
        }
        
        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    /**
     * Init editing form
     */
    protected function initForm(bool $a_create = false)
    {
        // Modern ILIAS 9 UI FileUpload component for background files
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        
        // Load bootstrap for new models
        require_once(__DIR__ . '/../src/bootstrap.php');
        
        // Create upload handler
        require_once($this->plugin->getPluginBaseDir() . '/classes/class.ilAIChatBackgroundFileUploadHandlerGUI.php');
        $upload_handler = new ilAIChatBackgroundFileUploadHandlerGUI();
        
        // Create the modern UI FileUpload component with multi-file support
        $file_upload = $ui_factory->input()->field()->file(
            $upload_handler,
            $this->plugin->txt('background_files_upload_label'),
            $this->plugin->txt('background_files_upload_info')
        )
        ->withDedicatedName('background_files')
        ->withAcceptedMimeTypes([
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'text/csv', 'text/json',
            'text/markdown'
        ])
        ->withMaxFiles(10);

        // Get AIChat defaults
        $defaults = $this->getAIChatDefaults();
        
        // Load existing configuration from new ChatConfig model
        $prop = [];
        $chatConfig = null;
        
        if (!$a_create) {
            // Try to load from new ChatConfig first
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';
            
            if (!empty($chat_id)) {
                try {
                    $chatConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chatConfig->exists()) {
                        // Load from new architecture
                        $prop = [
                            'chat_title' => $chatConfig->getTitle(),
                            'system_prompt' => $chatConfig->getSystemPrompt(),
                            'ai_service' => $chatConfig->getAiService(),
                            'max_memory' => $chatConfig->getMaxMemory(),
                            'char_limit' => $chatConfig->getCharLimit(),
                            'persistent' => $chatConfig->isPersistent(),
                            'include_page_context' => $chatConfig->isIncludePageContext(),
                            'enable_chat_uploads' => $chatConfig->isEnableChatUploads(),
                            'disclaimer' => $chatConfig->getDisclaimer(),
                            'background_files' => json_encode($chatConfig->getBackgroundFiles())
                        ];
                        $this->logger->debug("Loaded configuration from new ChatConfig model");
                    } else {
                        // Fallback to old properties
                        $prop = $old_properties;
                        $this->logger->debug("ChatConfig not found, using old properties");
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig", ['error' => $e->getMessage()]);
                    $prop = $old_properties;
                }
            } else {
                $prop = $old_properties;
            }
            
            $this->logger->debug("All properties in edit mode", ['properties' => $prop]);
        }
        
        // Set existing files for edit mode
        if (!$a_create && isset($prop['background_files'])) {
            $existing_file_ids = is_string($prop['background_files']) ? 
                json_decode($prop['background_files'], true) : 
                $prop['background_files'];
            
            $this->logger->debug("Loading existing files for edit mode", ['file_ids' => $existing_file_ids]);
            if (is_array($existing_file_ids) && !empty($existing_file_ids)) {
                // File upload field expects array of string IDs - ensure we have that format
                $file_upload = $file_upload->withValue($existing_file_ids);
                $this->logger->debug("Set file upload value", ['file_count' => count($existing_file_ids)]);
            }
        }

        // Chat title
        $chat_title = $ui_factory->input()->field()->text(
            $this->plugin->txt('chat_title_label'), 
            $this->plugin->txt('chat_title_info')
        )->withDedicatedName('chat_title')->withRequired(true)->withValue($prop['chat_title'] ?? $defaults['title']);

        // System prompt
        $system_prompt = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('system_prompt_label'),
            $this->plugin->txt('system_prompt_info')
        )->withDedicatedName('system_prompt')->withValue($prop['system_prompt'] ?? $defaults['prompt']);

        // AI Service Selection - DISABLED: Currently only RAMSES is available
        // Hardcoded to use RAMSES service for all chats
        /*
        $service_options = $this->getAvailableAIServices();
        $ai_service = $ui_factory->input()->field()->select(
            $this->plugin->txt('ai_service_label'),
            $service_options,
            $this->plugin->txt('ai_service_info')
        )->withDedicatedName('ai_service')->withValue($prop['ai_service'] ?? 'ramses');
        */

        // Max messages in memory
        $max_memory = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('max_memory_label'),
            $this->plugin->txt('max_memory_info')
        )->withDedicatedName('max_memory')->withValue((int)($prop['max_memory'] ?? $defaults['max_memory_messages']));

        // Character limit per message
        $char_limit = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('char_limit_label'),
            $this->plugin->txt('char_limit_info')
        )->withDedicatedName('char_limit')->withValue((int)($prop['char_limit'] ?? $defaults['characters_limit']));

        // Chat persistence
        $persistent = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('persistent_chat_label'),
            $this->plugin->txt('persistent_chat_info')
        )->withDedicatedName('persistent')->withValue($this->toBool($prop['persistent'] ?? false));

        // Page context inclusion
        $include_context = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('include_page_context_label'),
            $this->plugin->txt('include_page_context_info')
        )->withDedicatedName('include_page_context')->withValue($this->toBool($prop['include_page_context'] ?? true));

        // Enable chat file uploads
        $enable_chat_uploads = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('enable_chat_uploads_label'),
            $this->plugin->txt('enable_chat_uploads_info')
        )->withDedicatedName('enable_chat_uploads')->withValue($this->toBool($prop['enable_chat_uploads'] ?? false));

        // Disclaimer
        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        // Create the complete UI form with all fields
        $form_action = $a_create ? $this->ctrl->getFormAction($this, 'create') : $this->ctrl->getFormAction($this, 'update');
        $form = $ui_factory->input()->container()->form()->standard($form_action, [
            'chat_title' => $chat_title,
            'system_prompt' => $system_prompt,
            // 'ai_service' => $ai_service, // DISABLED: Hardcoded to RAMSES
            'max_memory' => $max_memory,
            'char_limit' => $char_limit,
            'persistent' => $persistent,
            'include_page_context' => $include_context,
            'enable_chat_uploads' => $enable_chat_uploads,
            'disclaimer' => $disclaimer,
            'background_files' => $file_upload
        ]);
        
        
        return $form;
    }

    protected function saveForm(array $form_data, bool $a_create) : bool
    {
        // Load bootstrap for new models
        require_once(__DIR__ . '/../src/bootstrap.php');
        
        // Generate or get chat ID
        $chat_id = '';
        if ($a_create) {
            $chat_id = uniqid('chat_', true);
        } else {
            $properties = $this->getProperties();
            $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        }
        
        // Handle file uploads - always process the form field, even if empty
        $background_files = $form_data['background_files'] ?? [];
        if (!is_array($background_files)) {
            $background_files = [];
        }
        
        $file_ids = [];
        foreach ($background_files as $file_id) {
            // AbstractCtrlAwareIRSSUploadHandler returns string IDs directly, not ResourceIdentification objects
            if (is_string($file_id) && !empty($file_id)) {
                $file_ids[] = $file_id;
            } elseif ($file_id instanceof \ILIAS\ResourceStorage\Identification\ResourceIdentification) {
                // Fallback: if it's still a ResourceIdentification object
                $file_ids[] = $file_id->serialize();
            }
        }
        
        $this->logger->debug("Background files form data", ['data' => $background_files]);
        $this->logger->debug("Background file IDs processed", ['file_ids' => $file_ids]);
        
        // Get page information for context
        $page_info = $this->getPageInfo();
        
        try {
            // Create or update ChatConfig in new architecture
            $chatConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            
            // Set all configuration data
            $chatConfig->setChatId($chat_id);
            $chatConfig->setPageId((int)($page_info['page_id'] ?? 0));
            $chatConfig->setParentId((int)($page_info['parent_id'] ?? 0));
            $chatConfig->setParentType($page_info['parent_type'] ?? '');
            $chatConfig->setTitle($form_data['chat_title'] ?? '');
            $chatConfig->setSystemPrompt($form_data['system_prompt'] ?? '');
            // AI Service is hardcoded to RAMSES - no longer configurable via form
            $chatConfig->setAiService('ramses');
            $chatConfig->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chatConfig->setCharLimit((int) ($form_data['char_limit'] ?? 2000));
            $chatConfig->setBackgroundFiles($file_ids);
            $chatConfig->setPersistent((bool) ($form_data['persistent'] ?? true));
            $chatConfig->setIncludePageContext((bool) ($form_data['include_page_context'] ?? true));
            $chatConfig->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            $chatConfig->setDisclaimer($form_data['disclaimer'] ?? '');
            
            // Save to database
            $result = $chatConfig->save();
            
            if ($result) {
                // Also save minimal properties to PageComponent for backward compatibility
                $properties = $this->getProperties();
                $properties['chat_id'] = $chat_id;
                $properties['chat_title'] = $form_data['chat_title'] ?? '';
                
                if ($a_create) {
                    $success = $this->createElement($properties);
                } else {
                    $success = $this->updateElement($properties);
                }
                
                $this->logger->debug("Saved ChatConfig successfully", ['chat_id' => $chat_id]);
                return $success;
            } else {
                $this->logger->warning("Failed to save ChatConfig");
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Exception saving ChatConfig", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Cancel
     */
    public function cancel()
    {
        $this->returnToParent();
    }

    /**
     * Get HTML for element
     * @param string    page mode (edit, presentation, print, preview, offline)
     * @return string   html code
     */
    public function getElementHTML(string $a_mode, array $a_properties, string $a_plugin_version) : string
    {
        // In edit mode, show placeholder
        if ($a_mode === 'edit') {
            return $this->renderEditPlaceholder($a_properties);
        }

        // Presentation mode: show full interface
        return $this->renderChatInterface($a_properties);
    }

    /**
     * Render edit mode placeholder
     */
    private function renderEditPlaceholder(array $properties) : string
    {
        $tpl = new ilTemplate(
            "tpl.ai_chat_placeholder.html", 
            true, 
            true, 
            $this->plugin->getDirectory()
        );
        
        $tpl->setVariable("CHAT_TITLE", htmlspecialchars($properties['chat_title'] ?? $this->plugin->txt('default_chat_title')));
        $tpl->setVariable("PLACEHOLDER_TEXT", 'AI Chat Component (Click to edit configuration)');
        
        // Add CSS for edit mode
        $this->addChatAssets();
        
        return $tpl->get();
    }

    /**
     * Render chat interface for presentation mode
     */
    private function renderChatInterface(array $properties) : string
    {
        // Load bootstrap for new models
        require_once(__DIR__ . '/../src/bootstrap.php');
        
        $tpl = new ilTemplate(
            "tpl.ai_chat.html", 
            true, 
            true, 
            $this->plugin->getDirectory()
        );
        
        // Get chat configuration - try loading from new ChatConfig model first
        $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        $config_properties = $properties; // fallback
        
        try {
            $chatConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if ($chatConfig->exists()) {
                // Update page context from current PageComponent context when rendering
                $this->updateChatConfigPageContext($chatConfig);
                
                // Use configuration from new model
                $config_properties = [
                    'chat_id' => $chat_id,
                    'chat_title' => $chatConfig->getTitle(),
                    'system_prompt' => $chatConfig->getSystemPrompt(),
                    'ai_service' => $chatConfig->getAiService(),
                    'max_memory' => $chatConfig->getMaxMemory(),
                    'char_limit' => $chatConfig->getCharLimit(),
                    'persistent' => $chatConfig->isPersistent(),
                    'include_page_context' => $chatConfig->isIncludePageContext(),
                    'enable_chat_uploads' => $chatConfig->isEnableChatUploads(),
                    'disclaimer' => $chatConfig->getDisclaimer(),
                    'background_files' => json_encode($chatConfig->getBackgroundFiles())
                ];
                $this->logger->debug("Using configuration from new ChatConfig model for rendering");
            } else {
                $this->logger->debug("ChatConfig not found, using legacy properties for rendering");
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error loading ChatConfig for rendering", ['error' => $e->getMessage()]);
        }
        
        $container_id = 'ai-chat-' . md5($chat_id);
        $messages_id = $container_id . '-messages';
        
        // Set basic template variables
        $tpl->setVariable("CONTAINER_ID", $container_id);
        $tpl->setVariable("MESSAGES_ID", $messages_id);
        $tpl->setVariable("CHAT_ID", htmlspecialchars($chat_id));
        
        $tpl->setVariable("CHAT_TITLE", htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title')));
        
        $tpl->setVariable("WELCOME_MESSAGE", $this->plugin->txt('welcome_message'));
        $tpl->setVariable("INPUT_PLACEHOLDER", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_BUTTON_TEXT", $this->plugin->txt('send_button_text'));
        $tpl->setVariable("LOADING_TEXT", $this->plugin->txt('loading_text'));
        $tpl->setVariable("CHAR_LIMIT", (int)($config_properties['char_limit'] ?? 2000));
        
        // File upload related strings
        $tpl->setVariable("ATTACHMENTS_LABEL", $this->plugin->txt('attachments_label'));
        $tpl->setVariable("CLEAR_ATTACHMENTS_TITLE", $this->plugin->txt('clear_attachments_title'));
        $tpl->setVariable("ATTACH_FILE_TITLE", $this->plugin->txt('attach_file_title'));
        $tpl->setVariable("ATTACH_FILE_LABEL", $this->plugin->txt('attach_file_label'));
        $tpl->setVariable("INPUT_ARIA_LABEL", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_ARIA_LABEL", $this->plugin->txt('send_aria_label'));
        $tpl->setVariable("FILE_INPUT_ARIA_LABEL", $this->plugin->txt('file_input_aria_label'));
        
        // Clear chat related strings
        $tpl->setVariable("CLEAR_CHAT_TEXT", $this->plugin->txt('clear_chat_text'));
        $tpl->setVariable("CLEAR_CHAT_TITLE", $this->plugin->txt('clear_chat_title'));
        $tpl->setVariable("CLEAR_CHAT_LABEL", $this->plugin->txt('clear_chat_label'));
        // Clear chat confirmation text
        $tpl->setVariable("CLEAR_CHAT_CONFIRM", htmlspecialchars($this->plugin->txt('clear_chat_confirm')));
        
        // Message action titles
        $tpl->setVariable("COPY_MESSAGE_TITLE", htmlspecialchars($this->plugin->txt('copy_message_title')));
        $tpl->setVariable("LIKE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('like_response_title')));
        $tpl->setVariable("DISLIKE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('dislike_response_title')));
        $tpl->setVariable("REGENERATE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('regenerate_response_title')));
        
        // Feedback messages
        $tpl->setVariable("MESSAGE_COPIED", htmlspecialchars($this->plugin->txt('message_copied')));
        $tpl->setVariable("MESSAGE_COPY_FAILED", htmlspecialchars($this->plugin->txt('message_copy_failed')));
        
        // Attachment actions
        $tpl->setVariable("REMOVE_ATTACHMENT", htmlspecialchars($this->plugin->txt('remove_attachment')));
        $tpl->setVariable("THINKING_HEADER", htmlspecialchars($this->plugin->txt('thinking_header')));
        
        // Configuration limits
        $max_size_config = \platform\AIChatPageComponentConfig::get('max_file_size_mb');
        $max_size_mb = $max_size_config ? (int)$max_size_config : 5;
        $tpl->setVariable("MAX_FILE_SIZE_MB", $max_size_mb);
        
        // Log config source for debugging
        global $DIC;
        if ($max_size_config !== null) {
            $DIC->logger()->comp('pcaic')->debug("Template: Using central config for file size", [
                'source' => 'central_config',
                'value' => $max_size_config,
                'effective_mb' => $max_size_mb
            ]);
        } else {
            $DIC->logger()->comp('pcaic')->debug("Template: Using fallback file size limit", [
                'source' => 'fallback',
                'effective_mb' => $max_size_mb
            ]);
        }
        
        // Error messages
        $tpl->setVariable("GENERATION_STOPPED", htmlspecialchars($this->plugin->txt('generation_stopped')));
        $tpl->setVariable("REGENERATE_FAILED", htmlspecialchars($this->plugin->txt('regenerate_failed')));
        $tpl->setVariable("WELCOME_MESSAGE", htmlspecialchars($this->plugin->txt('welcome_message')));
        $tpl->setVariable("STOP_GENERATION", htmlspecialchars($this->plugin->txt('stop_generation')));
        
        // Set data attributes for JavaScript configuration
        $tpl->setVariable("API_URL", htmlspecialchars($this->getAIChatApiUrl()));
        $tpl->setVariable("SYSTEM_PROMPT", htmlspecialchars($config_properties['system_prompt'] ?? 'You are a helpful AI assistant.'));
        $tpl->setVariable("MAX_MEMORY", (int)($config_properties['max_memory'] ?? 10));
        // Fix persistent data attribute for JavaScript
        $persistent_value = ($config_properties['persistent'] ?? false);
        $is_persistent = ($persistent_value === true || $persistent_value === '1' || $persistent_value === 1);
        $tpl->setVariable("PERSISTENT", $is_persistent ? 'true' : 'false');
        $tpl->setVariable("AI_SERVICE", htmlspecialchars($config_properties['ai_service'] ?? 'default'));
        
        // Chat file uploads setting
        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);
        $tpl->setVariable("ENABLE_CHAT_UPLOADS", $is_chat_uploads_enabled ? 'true' : 'false');
        
        // Add page info for context extraction in backend
        $page_info = $this->getPageInfo();
        $tpl->setVariable("PAGE_ID", (int)($page_info['page_id'] ?? 0));
        $tpl->setVariable("PARENT_ID", (int)($page_info['parent_id'] ?? 0));
        $tpl->setVariable("PARENT_TYPE", htmlspecialchars($page_info['parent_type'] ?? ''));
        $tpl->setVariable("INCLUDE_PAGE_CONTEXT", ($config_properties['include_page_context'] ?? true) ? 'true' : 'false');
        
        // Add background files data
        $background_files = $config_properties['background_files'] ?? '[]';
        if (is_array($background_files)) {
            $background_files = json_encode($background_files);
        }
        $tpl->setVariable("BACKGROUND_FILES", htmlspecialchars($background_files));
        
        // Handle optional disclaimer
        if (!empty($config_properties['disclaimer'])) {
            $tpl->setCurrentBlock("disclaimer");
            $tpl->setVariable("DISCLAIMER", htmlspecialchars($config_properties['disclaimer']));
            $tpl->parseCurrentBlock();
        }
        
        // Clear session management (old clear chat button removed - now in header)
        $tpl->setVariable("SESSION_MANAGEMENT_HTML", "");
        
        // Add CSS and JavaScript assets
        $this->addChatAssets();
        
        return $tpl->get();
    }

    /**
     * Update ChatConfig page context from current PageComponent context
     * This is called when rendering the chat to ensure correct page context
     * even for moved/copied PageComponents
     */
    private function updateChatConfigPageContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig): void
    {
        $this->logger->debug("Updating ChatConfig page context", ['chat_id' => $chatConfig->getChatId()]);
        
        try {
            // Get current page info from this PageComponent instance
            $currentPageInfo = $this->getPageInfo();
            
            $current_page_id = $chatConfig->getPageId();
            $current_parent_id = $chatConfig->getParentId();
            $current_parent_type = $chatConfig->getParentType();
            
            $new_page_id = $currentPageInfo['page_id'];
            $new_parent_id = $currentPageInfo['parent_id'];
            $new_parent_type = $currentPageInfo['parent_type'];
            
            // Check if page context needs updating
            if ($current_page_id !== $new_page_id || 
                $current_parent_id !== $new_parent_id || 
                $current_parent_type !== $new_parent_type) {
                
                $this->logger->debug("Updating page context during render", [
                    'old_page_id' => $current_page_id,
                    'old_parent_id' => $current_parent_id,
                    'old_parent_type' => $current_parent_type,
                    'new_page_id' => $new_page_id,
                    'new_parent_id' => $new_parent_id,
                    'new_parent_type' => $new_parent_type
                ]);
                
                $chatConfig->setPageId($new_page_id);
                $chatConfig->setParentId($new_parent_id);
                $chatConfig->setParentType($new_parent_type);
                $chatConfig->save();
                
                $this->logger->debug("Page context updated successfully during chat render");
            } else {
                $this->logger->debug("Page context already correct during render", [
                    'page_id' => $new_page_id,
                    'parent_id' => $new_parent_id,
                    'parent_type' => $new_parent_type
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Error updating page context during render", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Add CSS and JavaScript assets for AI chat
     */
    private function addChatAssets() : void
    {
        global $DIC;
        $tpl = $DIC['tpl'];
        
        // Add CSS
        $tpl->addCss($this->plugin->getDirectory() . "/css/ai_chat.css");
        
        // Add JavaScript - marked.js for markdown parsing (local version)
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/vendor/marked.min.js");
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/ai_chat.js");
    }


    /**
     * Get the AIChatPageComponent API URL for AJAX requests
     */
    private function getAIChatApiUrl() : string
    {
        try {
            $plugin_base_url = $this->plugin->getPluginBaseUrl();
            $api_url = $plugin_base_url . '/api.php';
            
            $this->logger->debug('API url: {api_url}', [
                'api_url' => $api_url
            ]);

            return $api_url;
        } catch (Exception $e) {
            $this->logger->warning("Failed to generate API URL", ['error' => $e->getMessage()]);
            return "";
        }
    }

    /**
     * Get available AI services from AIChat configuration
     */
    private function getAvailableAIServices() : array
    {
        // Currently only RAMSES is available - simplified service list
        $services = [
            'ramses' => $this->plugin->txt('ramses_service')
        ];
        
        /* Commented out other services for now - only RAMSES is active
        try {
            // Include the config class
            require_once($this->plugin->getPluginBaseDir() . '/classes/platform/class.AIChatPageComponentConfig.php');
            
            // Get available services from AIChat config
            $available_services = \platform\AIChatPageComponentConfig::get('available_services');
            
            if (is_array($available_services)) {
                if (isset($available_services['openai']) && $available_services['openai'] == "1") {
                    $services['openai'] = $this->plugin->txt('openai_service');
                }
                if (isset($available_services['ramses']) && $available_services['ramses'] == "1") {
                    $services['ramses'] = $this->plugin->txt('ramses_service');
                }
                if (isset($available_services['ollama']) && $available_services['ollama'] == "1") {
                    $services['ollama'] = $this->plugin->txt('ollama_service');
                }
                if (isset($available_services['gwdg']) && $available_services['gwdg'] == "1") {
                    $services['gwdg'] = $this->plugin->txt('gwdg_service');
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to load available services from AIChat config", ['error' => $e->getMessage()]);
            // Fallback to basic options
            $services['openai'] = $this->plugin->txt('openai_service');
            $services['ramses'] = $this->plugin->txt('ramses_service');
        }
        */
        
        return $services;
    }

    /**
     * Get default values from AIChat configuration
     */
    private function getAIChatDefaults() : array
    {
        $defaults = [
            'title' => 'AI Chat',
            'prompt' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.',
            'characters_limit' => 2000,
            'max_memory_messages' => 10,
            'disclaimer' => ''
        ];
        
        try {
            require_once($this->plugin->getPluginBaseDir() . '/classes/platform/class.AIChatPageComponentConfig.php');
            
            $prompt = \platform\AIChatPageComponentConfig::get('prompt');
            if (!empty($prompt)) {
                $defaults['prompt'] = $prompt;
            }
            
            $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
            if (!empty($char_limit)) {
                $defaults['characters_limit'] = (int)$char_limit;
            }
            
            $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
            if (!empty($max_memory)) {
                $defaults['max_memory_messages'] = (int)$max_memory;
            }
            
            $disclaimer = \platform\AIChatPageComponentConfig::get('disclaimer');
            if (!empty($disclaimer)) {
                $defaults['disclaimer'] = $disclaimer;
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to load defaults from AIChat config", ['error' => $e->getMessage()]);
        }
        
        return $defaults;
    }

    /**
     * Get information about the page that embeds the component
     * @return    array    key => value
     */
    private function getPageInfo() : array
    {
        $page_id     = (int) ($this->getPlugin()->getPageId()     ?? 0);
        $parent_id   = (int) ($this->getPlugin()->getParentId()   ?? 0);
        $parent_type =        ($this->getPlugin()->getParentType() ?? '');

        // Logging
        $this->logger->debug("Getting page info", [
            'page_id' => $page_id,
            'parent_id' => $parent_id,
            'parent_type' => $parent_type
        ]);

        return [
            'page_id'     => $page_id,
            'parent_id'   => $parent_id,
            'parent_type' => $parent_type,
        ];
    }

    /**
     * Extract page content for AI context
     * @return string The page content as plain text
     */
    public function getPageContext() : string
    {
        global $DIC;
        
        try {
            $page_id = $this->plugin->getPageId();
            $parent_id = $this->plugin->getParentId();
            $parent_type = $this->plugin->getParentType();

            if (!$page_id) {
                return '';
            }
            
            // Load the COPage object
            require_once("Services/COPage/classes/class.ilPageObject.php");
            require_once("Services/COPage/classes/class.ilPageObjectGUI.php");
            
            // Determine the correct page class based on parent type
            $page_class = $this->getPageClassForParentType($parent_type);
            
            if (!$page_class || !class_exists($page_class)) {
                $this->logger->warning("Page class not found", [
                    'page_class' => $page_class,
                    'parent_type' => $parent_type
                ]);
                return '';
            }
            
            // Create page object
            $page_obj = new $page_class($parent_id);
            
            if (!$page_obj->exists()) {
                $this->logger->warning("Page does not exist", [
                    'parent_id' => $parent_id,
                    'parent_type' => $parent_type
                ]);
                return '';
            }
            
            // Get the page XML content
            $xml_content = $page_obj->getXMLContent();
            
            if (empty($xml_content)) {
                $this->logger->warning("No XML content found");
                return '';
            }
            
            // Extract text content from XML
            $context = $this->extractTextFromPageXML($xml_content);
            
            // Add page title and metadata
            $page_title = $this->getPageTitle($parent_type, $parent_id);
            if (!empty($page_title)) {
                $context = "Page Title: $page_title\n\n" . $context;
            }
            
            $this->logger->debug("Extracted context", ['length' => strlen($context)]);
            
            return $context;
            
        } catch (Exception $e) {
            $this->logger->warning("Error extracting page context", ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Get appropriate page class for parent type
     */
    private function getPageClassForParentType(string $parent_type) : string
    {
        switch ($parent_type) {
            case 'lm':
                require_once("Modules/LearningModule/classes/class.ilLMPage.php");
                return 'ilLMPage';
            case 'wpg':
                require_once("Modules/Wiki/classes/class.ilWikiPage.php");
                return 'ilWikiPage';
            case 'cont':
                require_once("Services/Container/classes/class.ilContainerPage.php");
                return 'ilContainerPage';
            case 'copa':
                require_once("Services/COPage/classes/class.ilPageObject.php");
                return 'ilPageObject';
            default:
                // Try generic page object
                require_once("Services/COPage/classes/class.ilPageObject.php");
                return 'ilPageObject';
        }
    }
    
    /**
     * Get page title based on parent type
     */
    private function getPageTitle(string $parent_type, int $parent_id) : string
    {
        global $DIC;
        
        try {
            switch ($parent_type) {
                case 'lm':
                    require_once("Modules/LearningModule/classes/class.ilObjLearningModule.php");
                    require_once("Modules/LearningModule/classes/class.ilLMPageObject.php");
                    $lm_page = new ilLMPageObject($parent_id);
                    return $lm_page->getTitle();
                    
                case 'wpg':
                    require_once("Modules/Wiki/classes/class.ilWikiPage.php");
                    $wiki_page = new ilWikiPage($parent_id);
                    return $wiki_page->getTitle();
                    
                case 'cont':
                    require_once("Services/Object/classes/class.ilObject.php");
                    $obj = ilObject::_lookupTitle($parent_id);
                    return $obj;
                    
                default:
                    // Try to get title via object lookup
                    $title = ilObject::_lookupTitle($parent_id);
                    return $title ?: '';
            }
        } catch (Exception $e) {
            $this->logger->warning("Error getting page title", ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Extract plain text from ILIAS page XML
     */
    private function extractTextFromPageXML(string $xml_content) : string
    {
        if (empty($xml_content)) {
            return '';
        }
        
        try {
            // Load XML
            $dom = new DOMDocument();
            $dom->loadXML($xml_content);
            
            // Remove PageComponent elements that contain AI chats to avoid recursion
            $xpath = new DOMXPath($dom);
            $page_components = $xpath->query('//PageComponent[@ComponentType="AIChatPageComponent"]');
            foreach ($page_components as $component) {
                $component->parentNode->removeChild($component);
            }
            
            // Extract text content from various ILIAS page elements
            $text_parts = [];
            
            // Paragraphs
            $paragraphs = $xpath->query('//Paragraph');
            foreach ($paragraphs as $paragraph) {
                $text = trim($paragraph->textContent);
                if (!empty($text)) {
                    $text_parts[] = $text;
                }
            }
            
            // Lists
            $lists = $xpath->query('//List');
            foreach ($lists as $list) {
                $text = trim($list->textContent);
                if (!empty($text)) {
                    $text_parts[] = $text;
                }
            }
            
            // Tables
            $tables = $xpath->query('//Table');
            foreach ($tables as $table) {
                $text = trim($table->textContent);
                if (!empty($text)) {
                    $text_parts[] = "Table content: " . $text;
                }
            }
            
            // Media objects (get alt text/captions)
            $media_objects = $xpath->query('//MediaObject');
            foreach ($media_objects as $media) {
                $caption_nodes = $xpath->query('.//Caption', $media);
                foreach ($caption_nodes as $caption) {
                    $text = trim($caption->textContent);
                    if (!empty($text)) {
                        $text_parts[] = "Image/Media caption: " . $text;
                    }
                }
            }
            
            // Join all text parts
            $content = implode("\n\n", $text_parts);
            
            // Clean up whitespace
            $content = preg_replace('/\s+/', ' ', $content);
            $content = preg_replace('/\n\s*\n/', "\n\n", $content);
            
            return trim($content);
            
        } catch (Exception $e) {
            $this->logger->warning("Error parsing page XML", ['error' => $e->getMessage()]);
            // Fallback: try to extract basic text content
            return strip_tags($xml_content);
        }
    }
    

    
    /**
     * Get debug log file path
     */
    private function getDebugLogPath(): string
    {
        return $this->plugin->getPluginBaseDir() . '/debug.log';
    }
    
    /**
     * Convert property value to boolean
     * Handles various string representations of booleans
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }
        
        return (bool)$value;
    }
}