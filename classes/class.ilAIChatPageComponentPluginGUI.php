<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ILIAS\Plugin\pcaic\Validation\FileUploadValidator;

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
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilRepositoryGUI
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
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case strtolower(ilAIChatPageComponentFileUploadHandlerGUI::class):
                require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
                $gui = new ilAIChatPageComponentFileUploadHandlerGUI();
                $this->ctrl->forwardCommand($gui);
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
                $renderer = $DIC->ui()->renderer();
                $this->tpl->setContent($renderer->render($form));
                return;
            }

            if ($data !== null) {
                if ($this->saveForm($data, true)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
                    $this->returnToParent();
                    return;
                } else {
                }
            } else {
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
                $renderer = $DIC->ui()->renderer();
                $this->tpl->setContent($renderer->render($form));
                return;
            }

            if ($data !== null) {
                if ($this->saveForm($data, false)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("msg_obj_modified"), true);
                    $this->returnToParent();
                    return;
                } else {
                }
            } else {
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

        // Check global file handling (hierarchical service-specific check happens in API)
        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $background_files_enabled = FileUploadValidator::isUploadEnabled('background');

        // Determine allowed extensions based on RAG mode
        // For background files, we need to check if RAG is likely to be enabled
        $allowed_extensions = $this->getAllowedBackgroundFileExtensions($a_create);

        if (!$file_handling_enabled) {
            // File handling completely disabled - show clear message
            $file_upload = $ui_factory->input()->field()->text(
                $this->plugin->txt('background_files_upload_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('background_files');
        } elseif (!$background_files_enabled) {
            // Create disabled placeholder if background files are globally disabled
            $file_upload = $ui_factory->input()->field()->text(
                $this->plugin->txt('background_files_upload_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('background_files_disabled'))->withDisabled(true)->withDedicatedName('background_files');
        } else {
            // Use the same approach for both CREATE and EDIT
            $extensions_display = implode(', ', array_map('strtoupper', $allowed_extensions));
            $info_text = 'Upload background files for AI context. Allowed types: ' . $extensions_display;

            // File uploads now work in both CREATE and EDIT mode with IRSS handler
            require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
            $upload_handler = new ilAIChatPageComponentFileUploadHandlerGUI();

            // Convert extensions to accept attribute values (MIME types + extensions)
            // This ensures browser compatibility for all file types including .md
            $allowed_accept_values = FileUploadValidator::extensionsToAcceptValues($allowed_extensions);

            $file_upload = $ui_factory->input()->field()->file(
                $upload_handler,
                $this->plugin->txt('background_files_upload_label')
            )->withByline($info_text)
             ->withDedicatedName('background_files')
             ->withMaxFiles(10)
             ->withAcceptedMimeTypes($allowed_accept_values);

            // Set existing values for EDIT mode
            if (!$a_create && isset($prop['background_files'])) {
                $existing_file_ids = is_string($prop['background_files']) ?
                    json_decode($prop['background_files'], true) :
                    $prop['background_files'];

                if (is_array($existing_file_ids) && !empty($existing_file_ids)) {
                    $file_upload = $file_upload->withValue($existing_file_ids);
                }
            }
        }

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
                            'enable_streaming' => $chatConfig->isEnableStreaming(),
                            'enable_rag' => $chatConfig->isEnableRag(),
                            'disclaimer' => $chatConfig->getDisclaimer(),
                            'background_files' => json_encode($chatConfig->getBackgroundFiles())
                        ];
                    } else {
                        // Fallback to old properties
                        $prop = $old_properties;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig", ['error' => $e->getMessage()]);
                    $prop = $old_properties;
                }
            } else {
                $prop = $old_properties;
            }

        }

        // Set existing files for edit mode
        if (!$a_create && isset($prop['background_files'])) {
            $existing_file_ids = is_string($prop['background_files']) ?
                json_decode($prop['background_files'], true) :
                $prop['background_files'];

            if (is_array($existing_file_ids) && !empty($existing_file_ids)) {
                // File upload field expects array of string IDs - ensure we have that format
                $file_upload = $file_upload->withValue($existing_file_ids);
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

        // AI Service Selection (DYNAMIC - using LLMRegistry)
        // Get default AI service from config
        $default_ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

        // Build available services dynamically from registry (only enabled ones)
        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true); // true = only enabled

        // Determine AI service value
        $stored_service = $prop['ai_service'] ?? null;
        if ($force_default_service === '1') {
            // Force is enabled: use default service
            $ai_service_value = $default_ai_service;
        } elseif ($stored_service && isset($service_options[$stored_service])) {
            // Stored service is still available: use it
            $ai_service_value = $stored_service;
        } elseif (!empty($service_options)) {
            // Stored service no longer available or not set: use first available service
            $ai_service_value = array_key_first($service_options);

            // Add warning if stored service was disabled
            if ($stored_service && !isset($service_options[$stored_service])) {
                global $DIC;
                $DIC->ui()->mainTemplate()->setOnScreenMessage(
                    'info',
                    sprintf(
                        'The previously selected AI service "%s" is no longer available. Please select a new service.',
                        $stored_service
                    )
                );
            }
        } else {
            // No services available at all
            $ai_service_value = null;
        }

        // Create AI service field (disabled if forced)
        $ai_service = $ui_factory->input()->field()->select(
            $this->plugin->txt('ai_service_label'),
            $service_options,
            $this->plugin->txt('ai_service_info')
        )->withDedicatedName('ai_service');

        // Only set value if we have one and it's valid
        if ($ai_service_value && isset($service_options[$ai_service_value])) {
            $ai_service = $ai_service->withValue($ai_service_value);
        }

        // Disable field if service is forced
        if ($force_default_service === '1') {
            $ai_service = $ai_service->withDisabled(true);
        }

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

        // Check hierarchical file handling (global → service) BEFORE defining file-related fields
        $effective_ai_service = $ai_service_value ?? 'ramses';
        $global_file_handling = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $service_file_handling_key = $effective_ai_service . '_file_handling_enabled';
        $service_file_handling = (\platform\AIChatPageComponentConfig::get($service_file_handling_key) ?? '1') === '1';
        $file_handling_enabled_for_service = $global_file_handling && $service_file_handling;

        // Enable chat file uploads - check hierarchical file handling and specific chat upload permissions
        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
        if (!$file_handling_enabled_for_service) {
            // File handling disabled (global or service-specific)
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        } elseif ($chat_uploads_globally_enabled) {
            // Chat uploads enabled - show checkbox
            $enable_chat_uploads = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('enable_chat_uploads_info')
            )->withDedicatedName('enable_chat_uploads')->withValue($this->toBool($prop['enable_chat_uploads'] ?? false));
        } else {
            // Chat uploads specifically disabled (but file handling is enabled)
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('chat_uploads_disabled'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        }

        // Enable streaming responses - check global streaming setting
        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        // Only create streaming field if globally enabled
        if ($streaming_globally_enabled) {
            $enable_streaming = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_streaming_label'),
                $this->plugin->txt('enable_streaming_info')
            )->withDedicatedName('enable_streaming')->withValue($this->toBool($prop['enable_streaming'] ?? true));
        }

        // Enable RAG mode - only available if file handling enabled AND AI service supports RAG AND RAG globally enabled
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        // Use LLMRegistry to dynamically create service instance
        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($effective_ai_service);
        if ($llm === null) {
            // Fallback to first available service
            $availableServices = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            $firstService = !empty($availableServices) ? array_key_first($availableServices) : null;
            $llm = $firstService ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($firstService) : null;
        }

        $service_supports_rag = $llm->supportsRAG();

        // Get global RAG setting (LLM-specific)
        $rag_config_key = $effective_ai_service . '_enable_rag'; // e.g., ramses_enable_rag
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        if (!$file_handling_enabled_for_service) {
            // File handling disabled - no RAG available
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$service_supports_rag) {
            // AI service doesn't support RAG
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('rag_not_supported_info')
            )->withValue($this->plugin->txt('rag_not_supported'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$rag_globally_enabled) {
            // RAG globally disabled
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } else {
            // RAG available - show checkbox
            $enable_rag = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('enable_rag_info')
            )->withDedicatedName('enable_rag')->withValue($this->toBool($prop['enable_rag'] ?? false));
        }

        // Disclaimer
        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        // Create the complete UI form with all fields
        $form_action = $a_create ? $this->ctrl->getFormAction($this, 'create') : $this->ctrl->getFormAction($this, 'update');

        // Build form fields array, conditionally including fields based on global settings
        $form_fields = [
            'chat_title' => $chat_title,
            'system_prompt' => $system_prompt
        ];

        // Only show AI service selector if not forced
        if ($force_default_service !== '1') {
            $form_fields['ai_service'] = $ai_service;
        }

        $form_fields['max_memory'] = $max_memory;
        $form_fields['char_limit'] = $char_limit;
        $form_fields['persistent'] = $persistent;
        $form_fields['include_page_context'] = $include_context;

        // Only show chat uploads field if file handling is enabled (hierarchical)
        if ($file_handling_enabled_for_service) {
            $form_fields['enable_chat_uploads'] = $enable_chat_uploads;
        }

        // Only show streaming field if globally enabled
        if ($streaming_globally_enabled) {
            $form_fields['enable_streaming'] = $enable_streaming;
        }

        // Only show RAG field if file handling enabled AND service supports it AND RAG globally enabled
        if ($file_handling_enabled_for_service && $service_supports_rag && $rag_globally_enabled) {
            $form_fields['enable_rag'] = $enable_rag;
        }

        $form_fields['disclaimer'] = $disclaimer;

        // Only show background files field if file handling is enabled (hierarchical) AND background files are allowed
        if ($file_handling_enabled_for_service && $background_files_enabled) {
            $form_fields['background_files'] = $file_upload;
        }

        $form = $ui_factory->input()->container()->form()->standard($form_action, $form_fields);


        return $form;
    }

    protected function saveForm(array $form_data, bool $a_create) : bool
    {
        // Generate or get chat ID
        $chat_id = '';
        if ($a_create) {
            $chat_id = uniqid('chat_', true);
        } else {
            $properties = $this->getProperties();
            $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        }

        // Handle file uploads - only process if file handling is enabled and background files are allowed
        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $background_files_enabled = FileUploadValidator::isUploadEnabled('background');

        $file_ids = [];
        if ($file_handling_enabled && $background_files_enabled) {
            $background_files = $form_data['background_files'] ?? [];

            // Handle upload handler results
            if (is_array($background_files)) {
                foreach ($background_files as $file_data) {
                    if (is_string($file_data)) {
                        // Direct resource ID from upload handler
                        $file_ids[] = $file_data;
                    } elseif (is_array($file_data) && isset($file_data['resource_id'])) {
                        // Resource ID wrapped in array
                        $file_ids[] = $file_data['resource_id'];
                    }
                }
            } elseif (is_string($background_files)) {
                // Fallback: JSON-encoded string
                $decoded = json_decode($background_files, true);
                if (is_array($decoded)) {
                    $file_ids = $decoded;
                }
            }
        }

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

            // AI Service: Use default if forced, otherwise use form value
            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                $ai_service = $form_data['ai_service'] ?? \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            }
            $chatConfig->setAiService($ai_service);

            $chatConfig->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chatConfig->setCharLimit((int) ($form_data['char_limit'] ?? 2000));

            // Save background files in pcaic_attachments table
            $this->saveBackgroundFilesToAttachments($chat_id, $file_ids, $chatConfig, $a_create);

            $chatConfig->setPersistent((bool) ($form_data['persistent'] ?? true));
            $chatConfig->setIncludePageContext((bool) ($form_data['include_page_context'] ?? true));
            // Only set chat uploads if file handling is enabled AND chat uploads are globally enabled
            $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
            if ($file_handling_enabled && $chat_uploads_globally_enabled) {
                $chatConfig->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            } else {
                $chatConfig->setEnableChatUploads(false); // Force disabled when globally disabled
            }
            // Only set streaming if globally enabled AND form field is present
            $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';
            if ($streaming_globally_enabled) {
                $chatConfig->setEnableStreaming((bool) ($form_data['enable_streaming'] ?? true));
            } else {
                $chatConfig->setEnableStreaming(false); // Force disabled when globally disabled
            }

            // Only set RAG if service supports it AND globally enabled
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

            $ai_service = $chatConfig->getAiService();
            // Use LLMRegistry to dynamically create service instance
            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
            if ($llm === null) {
                // Fallback to first available service
                $availableServices = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
                $firstService = !empty($availableServices) ? array_key_first($availableServices) : null;
                $llm = $firstService ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($firstService) : null;
            }

            $service_supports_rag = $llm->supportsRAG();

            // Get global RAG setting (LLM-specific)
            $rag_config_key = $ai_service . '_enable_rag'; // e.g., ramses_enable_rag
            $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
            $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

            // Track RAG state change
            $rag_was_enabled = $chatConfig->isEnableRag();
            $rag_now_enabled = false;

            if ($service_supports_rag && $rag_globally_enabled) {
                $rag_now_enabled = (bool) ($form_data['enable_rag'] ?? false);
                $chatConfig->setEnableRag($rag_now_enabled);
            } else {
                $chatConfig->setEnableRag(false); // Force disabled when not supported or globally disabled
            }

            $chatConfig->setDisclaimer($form_data['disclaimer'] ?? '');

            // Save to database
            $result = $chatConfig->save();

            // Sync existing BACKGROUND FILES to RAG if RAG was just enabled
            if ($result && !$rag_was_enabled && $rag_now_enabled) {
                $this->logger->info("RAG was activated, syncing background files", ['chat_id' => $chat_id]);
                try {
                    $sync_stats = $llm->syncBackgroundFilesToRAG($chatConfig);
                    $this->logger->info("Background files RAG sync completed", $sync_stats);

                    if ($sync_stats['uploaded'] > 0) {
                        ilUtil::sendInfo(sprintf(
                            $this->plugin->txt('rag_sync_success'),
                            $sync_stats['uploaded']
                        ), true);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Background files RAG sync failed", ['error' => $e->getMessage()]);
                    ilUtil::sendFailure($this->plugin->txt('rag_sync_error'), true);
                }
            }

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
                    'enable_streaming' => $chatConfig->isEnableStreaming(),
                    'disclaimer' => $chatConfig->getDisclaimer(),
                    'background_files' => json_encode($chatConfig->getBackgroundFiles())
                ];
            } else {
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

        // Accessibility (ARIA) labels and skip link
        $chat_title = htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title'));
        $tpl->setVariable("SKIP_TO_INPUT", $this->plugin->txt('skip_to_input'));
        $tpl->setVariable("CHAT_ARIA_LABEL", sprintf($this->plugin->txt('chat_aria_label'), $chat_title));
        $tpl->setVariable("MESSAGES_ARIA_LABEL", $this->plugin->txt('messages_aria_label'));
        $tpl->setVariable("ATTACHMENTS_ARIA_LABEL", $this->plugin->txt('attachments_aria_label'));
        $tpl->setVariable("ACTIONS_ARIA_LABEL", $this->plugin->txt('actions_aria_label'));
        $tpl->setVariable("NEW_MESSAGE_ARIA", $this->plugin->txt('new_message_aria'));

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

        $max_attachments_config = \platform\AIChatPageComponentConfig::get('max_attachments_per_message');
        $max_attachments = $max_attachments_config ? (int)$max_attachments_config : 5;
        $tpl->setVariable("MAX_ATTACHMENTS_PER_MESSAGE", $max_attachments);

        // Error messages for file upload validation - format with sprintf
        $error_max_attachments_template = $this->plugin->txt('error_max_attachments');
        $error_file_too_large_template = $this->plugin->txt('error_file_too_large');
        $error_file_type_not_allowed_template = $this->plugin->txt('error_file_type_not_allowed');
        $error_file_upload_failed_template = $this->plugin->txt('error_file_upload_failed');

        // Fallback if language key not found (when txt() returns the key itself) or if still using old format
        if ($error_max_attachments_template === 'error_max_attachments' || empty($error_max_attachments_template) || strpos($error_max_attachments_template, '{maxAttachments}') !== false) {
            $error_max_attachments_template = 'Maximum %d attachments per message allowed';
        }
        if ($error_file_too_large_template === 'error_file_too_large' || empty($error_file_too_large_template)) {
            $error_file_too_large_template = 'File too large. Maximum size is %dMB';
        }
        if ($error_file_type_not_allowed_template === 'error_file_type_not_allowed' || empty($error_file_type_not_allowed_template)) {
            $error_file_type_not_allowed_template = 'File type not allowed: %s';
        }
        if ($error_file_upload_failed_template === 'error_file_upload_failed' || empty($error_file_upload_failed_template)) {
            $error_file_upload_failed_template = 'File upload failed: %s';
        }

        // Format the error messages with actual values
        $error_max_attachments = sprintf($error_max_attachments_template, $max_attachments);
        $max_file_size_mb_config = \platform\AIChatPageComponentConfig::get('max_file_size_mb');
        $max_file_size_mb = $max_file_size_mb_config ? (int)$max_file_size_mb_config : 5;
        $error_file_too_large = sprintf($error_file_too_large_template, $max_file_size_mb);

        // These messages need JavaScript to fill in dynamic values, so pass templates directly
        $error_file_type_not_allowed = $error_file_type_not_allowed_template;
        $error_file_upload_failed = $error_file_upload_failed_template;


        $tpl->setVariable("ERROR_MAX_ATTACHMENTS", $error_max_attachments);
        $tpl->setVariable("ERROR_FILE_TOO_LARGE", $error_file_too_large);
        $tpl->setVariable("ERROR_FILE_TYPE_NOT_ALLOWED", $error_file_type_not_allowed);
        $tpl->setVariable("ERROR_FILE_UPLOAD_FAILED", $error_file_upload_failed);

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

        // Chat file uploads setting - respect global restrictions
        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);
        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');

        // Both page component setting AND global setting must be enabled
        $effective_chat_uploads_enabled = $is_chat_uploads_enabled && $chat_uploads_globally_enabled;
        $tpl->setVariable("ENABLE_CHAT_UPLOADS", $effective_chat_uploads_enabled ? 'true' : 'false');

        // Streaming setting - respect global restrictions
        $enable_streaming = ($config_properties['enable_streaming'] ?? true);
        $is_streaming_enabled = ($enable_streaming === true || $enable_streaming === '1' || $enable_streaming === 1);
        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        // Both page component setting AND global setting must be enabled
        $effective_streaming_enabled = $is_streaming_enabled && $streaming_globally_enabled;
        $tpl->setVariable("ENABLE_STREAMING", $effective_streaming_enabled ? 'true' : 'false');

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

        // Handle chat uploads - only render upload elements if enabled
        if ($effective_chat_uploads_enabled) {
            $tpl->setCurrentBlock("chat_attachments_area");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_attach_button");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_file_input");
            $tpl->parseCurrentBlock();
        }

        // Clear session management (old clear chat button removed - now in header)
        $tpl->setVariable("SESSION_MANAGEMENT_HTML", "");

        // Add CSS and JavaScript assets
        $this->addChatAssets();

        return $tpl->get();
    }

    /**
     * Save background files to pcaic_attachments table
     * Creates Attachment records with message_id = NULL (indicating background files)
     * Optionally uploads files to RAG based on background_files_mode configuration
     * Also handles deletion of removed background files
     */
    private function saveBackgroundFilesToAttachments(string $chat_id, array $new_file_ids, \ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig, bool $is_create): void
    {
        global $DIC;
        $db = $DIC->database();

        // Get current user ID
        $user_id = $DIC->user()->getId();

        // Check if RAG is enabled: LLM must support it AND admin must enable it for this LLM
        $llm = $this->getLLMInstanceForChat($chatConfig);
        $ai_service = $chatConfig->getAiService();

        // Get LLM-specific RAG configuration
        $llm_rag_enabled = '0';
        if ($ai_service === 'ramses') {
            $llm_rag_enabled = \platform\AIChatPageComponentConfig::get('ramses_enable_rag') ?: '1';
        }
        // Future: Add openai_enable_rag when OpenAI supports RAG

        $enable_rag = $llm->supportsRAG() && ($llm_rag_enabled == '1' || $llm_rag_enabled === 1);

        $this->logger->debug("RAG configuration check", [
            'llm_supports_rag' => $llm->supportsRAG(),
            'llm_rag_enabled' => $llm_rag_enabled,
            'enable_rag' => $enable_rag,
            'ai_service' => $ai_service
        ]);

        // Get existing background file attachments for this chat
        $existing_query = "SELECT id, resource_id FROM pcaic_attachments " .
                         "WHERE chat_id = " . $db->quote($chat_id, 'text') . " " .
                         "AND message_id IS NULL";
        $existing_result = $db->query($existing_query);
        $existing_files = [];
        while ($row = $db->fetchAssoc($existing_result)) {
            $existing_files[$row['resource_id']] = (int)$row['id'];
        }

        // On edit (not create): Delete background files that were removed
        if (!$is_create) {
            $files_to_delete = array_diff(array_keys($existing_files), $new_file_ids);
            foreach ($files_to_delete as $resource_id) {
                $attachment_id = $existing_files[$resource_id];
                try {
                    $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);

                    // Delete from RAG if needed
                    if ($enable_rag && $attachment->isInRAG()) {
                        $this->deleteFileFromRAG($attachment, $chat_id, $chatConfig);
                    }

                    // Delete attachment (also removes from IRSS)
                    $attachment->delete();

                    $this->logger->info("Deleted background file attachment", [
                        'chat_id' => $chat_id,
                        'resource_id' => $resource_id,
                        'attachment_id' => $attachment_id
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to delete background file attachment", [
                        'resource_id' => $resource_id,
                        'chat_id' => $chat_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Process new file_ids (add files that don't exist yet)
        foreach ($new_file_ids as $resource_id) {
            // Skip if already exists
            if (isset($existing_files[$resource_id])) {
                continue;
            }

            try {
                // Create new Attachment record
                $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment();
                $attachment->setMessageId(null);  // NULL for background files (not bound to message)
                $attachment->setBackgroundFile(true);  // Mark as background file
                $attachment->setChatId($chat_id);  // Set chat_id
                $attachment->setUserId($user_id);
                $attachment->setResourceId($resource_id);
                $attachment->setTimestamp(date('Y-m-d H:i:s'));

                $this->logger->debug("Processing new background file", [
                    'resource_id' => $resource_id,
                    'chat_id' => $chat_id,
                    'enable_rag' => $enable_rag
                ]);

                // Upload to RAG if enabled
                if ($enable_rag) {
                    $this->logger->debug("Calling uploadFileToRAG", ['resource_id' => $resource_id]);
                    $this->uploadFileToRAG($attachment, $chat_id, $chatConfig);
                    $this->logger->debug("uploadFileToRAG completed", [
                        'resource_id' => $resource_id,
                        'has_rag_collection_id' => $attachment->getRAGCollectionId() !== null,
                        'has_rag_remote_file_id' => $attachment->getRAGRemoteFileId() !== null
                    ]);
                }

                // Save attachment (includes RAG fields if set)
                $attachment->save();

                $this->logger->info("Saved background file attachment", [
                    'chat_id' => $chat_id,
                    'resource_id' => $resource_id,
                    'rag_collection_id' => $attachment->getRAGCollectionId(),
                    'rag_remote_file_id' => $attachment->getRAGRemoteFileId()
                ]);

            } catch (\Exception $e) {
                $this->logger->error("Failed to save background file attachment", [
                    'resource_id' => $resource_id,
                    'chat_id' => $chat_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Delete file from RAG collection (supports multiple AI services)
     * Removes file from RAG system using the configured AI service
     */
    private function deleteFileFromRAG(\ILIAS\Plugin\pcaic\Model\Attachment $attachment, string $chat_id, \ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig): void
    {
        try {
            $this->logger->debug("Starting RAG deletion", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'rag_remote_file_id' => $attachment->getRAGRemoteFileId()
            ]);

            if (!$attachment->getRAGRemoteFileId()) {
                $this->logger->warning("No RAG remote file ID to delete", ['resource_id' => $attachment->getResourceId()]);
                return;
            }

            // Get LLM instance based on ChatConfig AI service
            $llm = $this->getLLMInstanceForChat($chatConfig);

            // Delete from RAG using the configured LLM service
            $llm->deleteFileFromRAG($attachment->getRAGRemoteFileId(), $chat_id);

            $this->logger->info("File deleted from RAG successfully", [
                'resource_id' => $attachment->getResourceId(),
                'rag_remote_file_id' => $attachment->getRAGRemoteFileId(),
                'chat_id' => $chat_id,
                'ai_service' => $chatConfig->getAiService()
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to delete file from RAG", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Don't throw - allow attachment to be deleted even if RAG deletion fails
        }
    }

    /**
     * Upload file to RAG collection (supports multiple AI services)
     * Updates attachment with rag_collection_id and rag_remote_file_id
     */
    private function uploadFileToRAG(\ILIAS\Plugin\pcaic\Model\Attachment $attachment, string $chat_id, \ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig): void
    {
        global $DIC;

        try {
            $this->logger->debug("Starting RAG upload", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id
            ]);

            // Get file from IRSS
            $irss = $DIC->resourceStorage();
            $identification = $irss->manage()->find($attachment->getResourceId());
            if (!$identification) {
                $this->logger->warning("File not found in IRSS", ['resource_id' => $attachment->getResourceId()]);
                return;
            }

            $this->logger->debug("File found in IRSS");

            // Download file to temp location
            $stream = $irss->consume()->stream($identification);
            $content = $stream->getStream()->getContents();

            $revision = $irss->manage()->getCurrentRevision($identification);
            $original_filename = $revision->getTitle();
            $suffix = $revision->getInformation()->getSuffix();

            $this->logger->debug("File downloaded from IRSS", [
                'filename' => $original_filename,
                'suffix' => $suffix,
                'size' => strlen($content)
            ]);

            // Create temp file WITH correct extension AND original filename
            // RAG systems validate file signature against filename
            $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original_filename);
            $temp_file = sys_get_temp_dir() . '/' . $safe_filename;
            file_put_contents($temp_file, $content);

            $this->logger->debug("Temp file created", [
                'temp_path' => $temp_file,
                'exists' => file_exists($temp_file)
            ]);

            // Get LLM instance based on ChatConfig AI service
            $llm = $this->getLLMInstanceForChat($chatConfig);
            $this->logger->debug("LLM instance created, calling uploadFileToRAG", [
                'ai_service' => $chatConfig->getAiService()
            ]);

            $rag_result = $llm->uploadFileToRAG($temp_file, $chat_id);

            $this->logger->debug("RAG upload returned", ['result' => $rag_result]);

            // Update attachment with RAG info
            $attachment->setRagCollectionId($rag_result['collection_id']);
            $attachment->setRagRemoteFileId($rag_result['remote_file_id']);
            $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));

            // Update ChatConfig with collection_id if not set
            if (!$chatConfig->getRAGCollectionId()) {
                $chatConfig->setRAGCollectionId($rag_result['collection_id']);
            }

            // Clean up temp file
            @unlink($temp_file);

            $this->logger->info("File uploaded to RAG successfully", [
                'resource_id' => $attachment->getResourceId(),
                'collection_id' => $rag_result['collection_id'],
                'remote_file_id' => $rag_result['remote_file_id'],
                'ai_service' => $chatConfig->getAiService()
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to upload file to RAG", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Log more details about the configuration
            $this->logger->debug("RAG upload configuration", [
                'ai_service' => $chatConfig->getAiService(),
                'file_upload_url' => \platform\AIChatPageComponentConfig::get('ramses_file_upload_url'),
                'application_id' => \platform\AIChatPageComponentConfig::get('ramses_application_id'),
                'instance_id' => \platform\AIChatPageComponentConfig::get('ramses_instance_id'),
                'api_token_set' => !empty(\platform\AIChatPageComponentConfig::get('ramses_api_token'))
            ]);

            // Don't throw - allow attachment to be saved without RAG
        }
    }

    /**
     * Get LLM instance based on ChatConfig AI service
     * Factory method to create the correct LLM service instance
     */
    private function getLLMInstanceForChat(\ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig)
    {
        $ai_service = $chatConfig->getAiService();

        // Use LLMRegistry to dynamically create service instance
        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);

        if ($instance === null) {
            // Fallback to first available service if requested service not found
            $availableServices = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            if (!empty($availableServices)) {
                $firstService = array_key_first($availableServices);
                $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($firstService);
            }

            if ($instance === null) {
                throw new \Exception("No AI services available in registry");
            }
        }

        return $instance;
    }

    /**
     * Update ChatConfig page context from current PageComponent context
     * This is called when rendering the chat to ensure correct page context
     * even for moved/copied PageComponents
     */
    private function updateChatConfigPageContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chatConfig): void
    {

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

                $chatConfig->setPageId($new_page_id);
                $chatConfig->setParentId($new_parent_id);
                $chatConfig->setParentType($new_parent_type);
                $chatConfig->save();

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

            $prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
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

            $disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
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
     * Get allowed file extensions for background files based on RAG configuration
     *
     * This method determines which file types are allowed for background file uploads
     * based on the RAG mode settings. If RAG is enabled for the AI service, only
     * RAG-compatible file types are allowed.
     *
     * @param bool $a_create Whether this is create mode (no existing chat)
     * @return array Array of allowed file extensions
     */
    private function getAllowedBackgroundFileExtensions(bool $a_create): array
    {
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        // Determine AI service to use
        $ai_service = null;
        $rag_enabled_for_chat = false;

        if (!$a_create) {
            // EDIT mode: Try to get settings from existing chat
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';

            if (!empty($chat_id)) {
                try {
                    $chatConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chatConfig->exists()) {
                        $ai_service = $chatConfig->getAiService();
                        $rag_enabled_for_chat = $chatConfig->isEnableRag();
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig for file extensions", ['error' => $e->getMessage()]);
                }
            }
        }

        // Fallback: Use default/forced AI service
        if (empty($ai_service)) {
            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                // Use first available enabled service
                $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);
                $ai_service = !empty($service_options) ? array_key_first($service_options) : 'ramses';
            }
        }

        // Get LLM instance
        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
        if ($llm === null) {
            // Fallback to default extensions
            return FileUploadValidator::getAllowedExtensions('background');
        }

        // Check if RAG is globally enabled for this service
        $rag_config_key = $ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        // Determine effective RAG state:
        // - In EDIT mode: Use chat-specific RAG setting (if RAG is globally enabled)
        // - In CREATE mode: If RAG is globally enabled, show RAG-restricted types
        //   (user will likely enable RAG, and we want to prevent incompatible uploads)
        $effective_rag_enabled = false;
        if ($rag_globally_enabled && $llm->supportsRAG()) {
            if ($a_create) {
                // CREATE mode: Use RAG-restricted types if RAG is globally available
                // This prevents uploading incompatible files that would fail if RAG is enabled
                $effective_rag_enabled = true;
            } else {
                // EDIT mode: Use the chat's actual RAG setting
                $effective_rag_enabled = $rag_enabled_for_chat;
            }
        }

        // Get allowed file types from LLM service
        $allowed_extensions = $llm->getAllowedFileTypes($effective_rag_enabled);

        $this->logger->debug("Background file extensions determined", [
            'ai_service' => $ai_service,
            'rag_globally_enabled' => $rag_globally_enabled,
            'rag_enabled_for_chat' => $rag_enabled_for_chat,
            'effective_rag_enabled' => $effective_rag_enabled,
            'allowed_extensions' => $allowed_extensions,
            'mode' => $a_create ? 'create' : 'edit'
        ]);

        return $allowed_extensions;
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
