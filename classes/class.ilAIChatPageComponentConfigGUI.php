<?php declare(strict_types=1);

/**
 * AIChatPageComponent Configuration GUI
 * 
 * Provides plugin-wide administrative configuration interface.
 * Allows administrators to set default values, enable/disable AI services,
 * and configure file upload constraints that apply across all chat instances.
 * 
 * This configuration acts as fallback defaults when the central AIChat plugin
 * is not available, and as admin-controlled platform-wide settings.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0
 * 
 * @ilCtrl_IsCalledBy  ilAIChatPageComponentConfigGUI: ilObjComponentSettingsGUI
 */
class ilAIChatPageComponentConfigGUI extends ilPluginConfigGUI
{
    private ilAIChatPageComponentPlugin $plugin;
    private ilCtrlInterface $ctrl;
    private \ILIAS\DI\Container $dic;

    public function __construct()
    {
        global $DIC;
        
        $this->dic = $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->plugin = ilAIChatPageComponentPlugin::getInstance();
    }

    /**
     * Execute command from ILIAS control structure (DYNAMIC)
     *
     * Commands are dynamically routed based on registered LLM services.
     * Pattern: show{ServiceId}Config, save{ServiceId}Configuration, refresh{ServiceId}Models
     */
    public function performCommand(string $cmd): void
    {
        // General configuration
        if ($cmd === 'configure' || $cmd === 'showConfigurationForm') {
            $this->showConfigurationForm('general');
            return;
        }

        if ($cmd === 'saveConfiguration') {
            $this->saveConfiguration();
            return;
        }

        // Dynamic service-specific commands
        $services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();

        foreach ($services as $serviceId => $serviceClass) {
            $serviceIdCap = ucfirst($serviceId); // ramses -> Ramses

            // Show service config tab (e.g., showRamsesConfig)
            if ($cmd === "show{$serviceIdCap}Config") {
                $this->showConfigurationForm($serviceId);
                return;
            }

            // Save service configuration (e.g., saveRamsesConfiguration)
            if ($cmd === "save{$serviceIdCap}Configuration") {
                $this->saveServiceConfiguration($serviceId);
                return;
            }

            // Refresh service models (e.g., refreshRamsesModels) - if applicable
            if ($cmd === "refresh{$serviceIdCap}Models") {
                $this->refreshServiceModels($serviceId);
                return;
            }
        }

        // Fallback
        $this->showConfigurationForm('general');
    }

    /**
     * Display the configuration form using modern ILIAS 9 UI components (DYNAMIC)
     *
     * Tabs are automatically generated for all registered LLM services.
     */
    private function showConfigurationForm(string $active_tab = 'general'): void
    {
        try {
            // Setup tabs - General tab first
            $tabs = $this->dic->tabs();
            $tabs->addTab('general', $this->plugin->txt('tab_general_config'),
                         $this->ctrl->getLinkTarget($this, 'showConfigurationForm'));

            // Dynamically add service tabs from registry
            $services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            foreach ($services as $serviceId => $serviceClass) {
                $serviceIdCap = ucfirst($serviceId);
                $serviceTabLabel = $serviceClass::getServiceName(); // e.g., 'RAMSES', 'OpenAI GPT'

                $tabs->addTab(
                    $serviceId,
                    $serviceTabLabel,
                    $this->ctrl->getLinkTargetByClass(get_class($this), "show{$serviceIdCap}Config")
                );
            }

            // Set active tab
            $tabs->setTabActive($active_tab);

            // Show appropriate form based on active tab
            if ($active_tab === 'general') {
                $form_html = $this->buildGeneralConfigurationForm();
            } else {
                // Service-specific configuration
                $form_html = $this->buildServiceConfigurationForm($active_tab);
            }

            $this->dic->ui()->mainTemplate()->setContent($form_html);
        } catch (\Exception $e) {
            $this->dic->logger()->comp('pcaic')->error('Failed to show configuration form', [
                'error' => $e->getMessage(),
                'active_tab' => $active_tab
            ]);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('config_load_error') . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Build general configuration form (main tab)
     * 
     * @return string Rendered HTML form
     * @throws \Exception If form building fails
     */
    private function buildGeneralConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        $inputs = [];

        // Default Values Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildDefaultValuesInputs(),
            $this->plugin->txt('config_defaults_title'),
            $this->plugin->txt('config_defaults_info')
        );

        // Processing Limits Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildProcessingLimitsInputs(),
            $this->plugin->txt('config_processing_title'),
            $this->plugin->txt('config_processing_info')
        );

        // AI Service Selection Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildAiServiceSelectionInputs(), 
            $this->plugin->txt('config_services_title'),
            $this->plugin->txt('config_services_info')
        );

        // File Upload Constraints Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildUploadConstraintsInputs(),
            $this->plugin->txt('config_upload_title'),
            $this->plugin->txt('config_upload_info')
        );
        
        // File Upload Restrictions Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildFileUploadRestrictionsInputs(),
            $this->plugin->txt('config_file_restrictions_title'),
            $this->plugin->txt('config_file_restrictions_info')
        );

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            $inputs
        );

        return $ui_renderer->render($form);
    }


    /**
     * Build configuration form using modern ILIAS 9 UI components (DEPRECATED)
     * 
     * @deprecated Use tab-specific methods instead
     * @return string Rendered HTML form
     * @throws \Exception If form building fails
     */

    /**
     * Build default values input fields for new chat instances
     * Contains system prompt and disclaimer only
     * 
     * @return array UI input components for default configuration
     */
    private function buildDefaultValuesInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // Default system prompt for new chats
        $default_prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
        $inputs['default_prompt'] = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('config_default_prompt'),
            $this->plugin->txt('config_default_prompt_info')
        )->withValue($default_prompt ?: 'You are a helpful AI assistant. Please provide accurate and helpful responses.');

        // Default disclaimer text
        $disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
        $inputs['default_disclaimer'] = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('config_default_disclaimer'),
            $this->plugin->txt('config_default_disclaimer_info')
        )->withValue($disclaimer ?: '');

        return $inputs;
    }

    /**
     * Build processing limits input fields for AI constraints
     * Contains char limit, memory, PDF pages, and compressed image data limits
     * 
     * @return array UI input components for processing configuration
     */
    private function buildProcessingLimitsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // Default character limit per message
        $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
        $inputs['default_char_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_default_char_limit'),
            $this->plugin->txt('config_default_char_limit_info')
        )->withValue($char_limit ? (int)$char_limit : 2000);

        // Default conversation memory limit
        $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
        $inputs['default_max_memory'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_default_max_memory'),
            $this->plugin->txt('config_default_max_memory_info')
        )->withValue($max_memory ? (int)$max_memory : 10);

        // PDF pages processed per document
        $pdf_pages_processed = \platform\AIChatPageComponentConfig::get('pdf_pages_processed');
        $inputs['pdf_pages_processed'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_pdf_pages_processed'),
            $this->plugin->txt('config_pdf_pages_processed_info')
        )->withValue($pdf_pages_processed ? (int)$pdf_pages_processed : 20);

        // Maximum total image data sent to AI service (compressed)
        $max_image_data = \platform\AIChatPageComponentConfig::get('max_image_data_mb');
        $inputs['max_image_data_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_image_data'),
            $this->plugin->txt('config_max_image_data_info')
        )->withValue($max_image_data ? (int)$max_image_data : 15);

        // Enable streaming responses globally
        $streaming_enabled = \platform\AIChatPageComponentConfig::get('enable_streaming');
        $inputs['enable_streaming'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_enable_streaming'),
            $this->plugin->txt('config_enable_streaming_info')
        )->withValue(($streaming_enabled ?? '1') === '1');

        return $inputs;
    }

    /**
     * Build RAMSES API configuration input fields
     * 
     * @return array UI input components for RAMSES API configuration
     */

    /**
     * Get available models from cache only (no API calls)
     * Models are only fetched when user clicks the refresh button
     * 
     * @return array Available models for dropdown
     */

    /**
     * Build AI service selection input fields
     * 
     * @return array UI input components for service selection
     */
    private function buildAiServiceSelectionInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // Build service options dynamically from LLMRegistry (only enabled services)
        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        // If no services enabled, show placeholder
        if (empty($service_options)) {
            $service_options['none'] = 'No AI services enabled - Please enable at least one service';
        }

        $selected_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';

        // If selected service doesn't exist in available options, use first available
        if (!isset($service_options[$selected_service])) {
            $selected_service = array_key_first($service_options);
        }

        $inputs['selected_ai_service'] = $ui_factory->input()->field()->select(
            $this->plugin->txt('config_selected_ai_service'),
            $service_options,
            $this->plugin->txt('config_selected_ai_service_info')
        )->withValue($selected_service);

        // Force default AI service for all chats
        $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
        $inputs['force_default_ai_service'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_force_default_ai_service'),
            $this->plugin->txt('config_force_default_ai_service_info')
        )->withValue($force_default_service === '1');

        return $inputs;
    }

    /**
     * Build OpenAI API configuration input fields
     * 
     * @return array UI input components for OpenAI API configuration
     */

    /**
     * Get available OpenAI models from cache only (no API calls)
     * Models are only fetched when user clicks the refresh button
     *
     * @return array Available OpenAI models for dropdown
     */

    /**
     * Build file upload constraint input fields
     * 
     * @return array UI input components for file upload limits
     */
    private function buildUploadConstraintsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // Maximum individual file size in MB
        $max_file_size = \platform\AIChatPageComponentConfig::get('max_file_size_mb');
        $inputs['max_file_size_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_file_size'),
            $this->plugin->txt('config_max_file_size_info')
        )->withValue($max_file_size ? (int)$max_file_size : 5);

        // Maximum number of attachments per chat message
        $max_attachments = \platform\AIChatPageComponentConfig::get('max_attachments_per_message');
        $inputs['max_attachments_per_message'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_attachments'),
            $this->plugin->txt('config_max_attachments_info')
        )->withValue($max_attachments ? (int)$max_attachments : 5);

        // Maximum total upload size per message in MB
        $max_upload_size = \platform\AIChatPageComponentConfig::get('max_total_upload_size_mb');
        $inputs['max_total_upload_size_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_upload_size'),
            $this->plugin->txt('config_max_upload_size_info')
        )->withValue($max_upload_size ? (int)$max_upload_size : 25);

        return $inputs;
    }

    /**
     * Build file upload restrictions input fields
     *
     * Creates hierarchical file handling controls with OptionalGroup:
     * - Global enable/disable for file handling (OptionalGroup checkbox)
     * - Allowed file types whitelist (shown only when enabled)
     * - Separate controls for background files vs chat uploads (shown only when enabled)
     *
     * Note: Per-service file handling is configured in RAMSES/OpenAI tabs
     *
     * @return array UI input components for file upload restrictions
     */
    private function buildFileUploadRestrictionsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $refinery = $this->dic->refinery();

        // Get current settings
        $enable_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        // File types whitelist input with centralized defaults
        $default_file_types = \platform\AIChatPageComponentConfig::get('default_allowed_file_types');
        $default_types_string = is_array($default_file_types)
            ? implode(',', $default_file_types)
            : 'txt,md,pdf,csv,png,jpg,jpeg,webp,gif';

        $current_types = $file_restrictions['allowed_file_types'] ?? $default_file_types;
        $current_types_string = is_array($current_types) ? implode(',', $current_types) : $default_types_string;

        // Build sub-inputs for the optional group
        $sub_inputs = [];

        $sub_inputs['allowed_file_types'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_allowed_file_types'),
            $this->plugin->txt('config_allowed_file_types_info')
        )->withValue($current_types_string);

        $sub_inputs['allow_background_files'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_background_files'),
            $this->plugin->txt('config_allow_background_files_info')
        )->withValue(true);

        $sub_inputs['allow_chat_uploads'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_chat_uploads'),
            $this->plugin->txt('config_allow_chat_uploads_info')
        )->withValue(true);

        // Create transformation function for the optional group
        $restrictions_trafo = $refinery->custom()->transformation(
            static function (?array $vs): array {
                if ($vs === null) {
                    return ['enabled' => false];
                }

                $restrictions = ['enabled' => true];

                // Process file type whitelist
                if (isset($vs['allowed_file_types']) && !empty($vs['allowed_file_types'])) {
                    $allowed_types = array_map('trim', explode(',', $vs['allowed_file_types']));
                    $restrictions['allowed_file_types'] = array_filter($allowed_types);
                }

                // Process background files permission
                if (isset($vs['allow_background_files'])) {
                    $restrictions['allow_background_files'] = $vs['allow_background_files'];
                }

                // Process chat uploads permission
                if (isset($vs['allow_chat_uploads'])) {
                    $restrictions['allow_chat_uploads'] = $vs['allow_chat_uploads'];
                }

                return $restrictions;
            }
        );

        // Create optional group
        $file_restrictions_group = $ui_factory->input()->field()->optionalGroup(
            $sub_inputs,
            $this->plugin->txt('config_enable_file_handling'),
            $this->plugin->txt('config_enable_file_handling_info')
        );

        // Set value properly for optional group
        if ($enable_file_handling === '1') {
            $current_values = [
                'allowed_file_types' => $current_types_string,
                'allow_background_files' => $file_restrictions['allow_background_files'] ?? true,
                'allow_chat_uploads' => $file_restrictions['allow_chat_uploads'] ?? true
            ];
            $file_restrictions_group = $file_restrictions_group->withValue($current_values);
        } else {
            $file_restrictions_group = $file_restrictions_group->withValue(null);
        }

        $file_restrictions_group = $file_restrictions_group->withAdditionalTransformation($restrictions_trafo);

        return ['file_restrictions' => $file_restrictions_group];
    }

    /**
     * Save configuration settings using modern form processing
     */
    public function saveConfiguration(): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            // Build form structure matching buildGeneralConfigurationForm()
            $inputs = [];
            
            // Only General Configuration sections (no RAMSES/OpenAI here)
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildDefaultValuesInputs(),
                $this->plugin->txt('config_defaults_title'),
                $this->plugin->txt('config_defaults_info')
            );
            
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildProcessingLimitsInputs(),
                $this->plugin->txt('config_processing_title'),
                $this->plugin->txt('config_processing_info')
            );
            
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildAiServiceSelectionInputs(),
                $this->plugin->txt('config_services_title'),
                $this->plugin->txt('config_services_info')
            );
            
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildUploadConstraintsInputs(),
                $this->plugin->txt('config_upload_title'),
                $this->plugin->txt('config_upload_info')
            );
            
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildFileUploadRestrictionsInputs(),
                $this->plugin->txt('config_file_restrictions_title'),
                $this->plugin->txt('config_file_restrictions_info')
            );

            $form = $ui_factory->input()->container()->form()->standard(
                $this->ctrl->getFormAction($this, 'saveConfiguration'),
                $inputs
            );

            // Process form data
            $form = $form->withRequest($request);
            $data = $form->getData();

            if ($data !== null) {
                // Debug: Log received form data
                try {
                    $this->dic->logger()->comp('pcaic')->debug("Form data received", [
                        'raw_data' => $data,
                        'data_count' => count($data),
                        'data_keys' => array_keys($data)
                    ]);
                } catch (\Exception $e) {
                    // Ignore logging errors
                }
                
                // Extract data from sections (match buildGeneralConfigurationForm order)
                $defaults_data = $data[0] ?? [];        // buildDefaultValuesInputs
                $processing_data = $data[1] ?? [];      // buildProcessingLimitsInputs  
                $services_data = $data[2] ?? [];        // buildAiServiceSelectionInputs
                $constraints_data = $data[3] ?? [];     // buildUploadConstraintsInputs
                $restrictions_data = $data[4] ?? [];    // buildFileUploadRestrictionsInputs

                // Save default values
                if (isset($defaults_data['default_prompt'])) {
                    \platform\AIChatPageComponentConfig::set('default_prompt', $defaults_data['default_prompt']);
                }
                if (isset($defaults_data['default_disclaimer'])) {
                    \platform\AIChatPageComponentConfig::set('default_disclaimer', $defaults_data['default_disclaimer']);
                }

                // Save processing limits
                if (isset($processing_data['default_char_limit'])) {
                    \platform\AIChatPageComponentConfig::set('characters_limit', (int)$processing_data['default_char_limit']);
                }
                if (isset($processing_data['default_max_memory'])) {
                    \platform\AIChatPageComponentConfig::set('max_memory_messages', (int)$processing_data['default_max_memory']);
                }
                if (isset($processing_data['pdf_pages_processed'])) {
                    \platform\AIChatPageComponentConfig::set('pdf_pages_processed', (int)$processing_data['pdf_pages_processed']);
                }
                if (isset($processing_data['max_image_data_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_image_data_mb', (int)$processing_data['max_image_data_mb']);
                }
                if (isset($processing_data['enable_streaming'])) {
                    \platform\AIChatPageComponentConfig::set('enable_streaming', $processing_data['enable_streaming'] ? '1' : '0');
                }

                // RAMSES configuration is handled in separate RAMSES tab

                // Save service selection
                if (isset($services_data['selected_ai_service'])) {
                    \platform\AIChatPageComponentConfig::set('selected_ai_service', $services_data['selected_ai_service']);
                }

                // Save force default service setting
                if (isset($services_data['force_default_ai_service'])) {
                    \platform\AIChatPageComponentConfig::set('force_default_ai_service', $services_data['force_default_ai_service'] ? '1' : '0');
                }

                // OpenAI configuration is handled in separate OpenAI tab
                
                // Save upload constraints
                if (isset($constraints_data['max_file_size_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_file_size_mb', (int)$constraints_data['max_file_size_mb']);
                }
                if (isset($constraints_data['max_attachments_per_message'])) {
                    \platform\AIChatPageComponentConfig::set('max_attachments_per_message', (int)$constraints_data['max_attachments_per_message']);
                }
                if (isset($constraints_data['max_total_upload_size_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_total_upload_size_mb', (int)$constraints_data['max_total_upload_size_mb']);
                }
                
                // Save file upload restrictions (OptionalGroup structure)
                // Note: Transformation returns array with 'enabled' key
                $file_restrictions_value = $restrictions_data['file_restrictions'] ?? ['enabled' => false];

                // Check if enabled based on the 'enabled' key in the array
                $is_enabled = is_array($file_restrictions_value) && ($file_restrictions_value['enabled'] ?? false);

                if ($is_enabled) {
                    // Checkbox checked - save enabled state and restrictions
                    \platform\AIChatPageComponentConfig::set('enable_file_handling', '1');
                    \platform\AIChatPageComponentConfig::set('file_upload_restrictions', $file_restrictions_value);
                } else {
                    // Checkbox unchecked - explicitly save disabled state
                    \platform\AIChatPageComponentConfig::set('enable_file_handling', '0');
                    \platform\AIChatPageComponentConfig::set('file_upload_restrictions', ['enabled' => false]);
                }

                $this->dic->ui()->mainTemplate()->setOnScreenMessage('success', $this->plugin->txt('config_saved_success'));
                
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_form_invalid'));
            }
                
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_save_error') . ': ' . $e->getMessage());
        }
        
        $this->showConfigurationForm('general');
    }

    // ============================================
    // DYNAMIC Service Configuration Methods
    // ============================================

    /**
     * Build configuration form for any registered LLM service (DYNAMIC)
     *
     * @param string $serviceId Service identifier (e.g., 'ramses', 'openai')
     * @return string Rendered HTML form
     */
    private function buildServiceConfigurationForm(string $serviceId): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $renderer = $this->dic->ui()->renderer();

        // Get service class from registry
        $serviceClass = \ai\AIChatPageComponentLLMRegistry::getServiceClass($serviceId);
        if ($serviceClass === null) {
            return $renderer->render(
                $ui_factory->messageBox()->failure("Service '$serviceId' not found in registry")
            );
        }

        // Create bare service instance (without requiring full configuration)
        $service = \ai\AIChatPageComponentLLMRegistry::createBareServiceInstance($serviceId);
        if ($service === null) {
            return $renderer->render(
                $ui_factory->messageBox()->failure("Failed to create service instance for '$serviceId'")
            );
        }

        // Get configuration inputs from service
        $serviceInputs = $service->getConfigurationFormInputs();

        // Wrap in section
        $section = $ui_factory->input()->field()->section(
            $serviceInputs,
            $serviceClass::getServiceName(),
            $serviceClass::getServiceDescription()
        );

        // Build form
        $serviceIdCap = ucfirst($serviceId);
        $form_action = $this->ctrl->getFormAction($this, "save{$serviceIdCap}Configuration");
        $form = $ui_factory->input()->container()->form()->standard($form_action, [$section]);

        $form_html = $renderer->render($form);

        // Add refresh models button if service supports it
        $button_html = $this->buildRefreshModelsButton($serviceId);

        return $form_html . $button_html;
    }

    /**
     * Build refresh models button for services that support it
     *
     * @param string $serviceId Service identifier
     * @return string HTML for button or empty string
     */
    private function buildRefreshModelsButton(string $serviceId): string
    {
        // Only RAMSES and OpenAI support model refresh currently
        if (!in_array($serviceId, ['ramses', 'openai'])) {
            return '';
        }

        $ui_factory = $this->dic->ui()->factory();
        $renderer = $this->dic->ui()->renderer();

        // Get cache info based on service
        $cache_time_key = ($serviceId === 'ramses') ? 'models_cache_time' : 'openai_models_cache_time';
        $cached_models_key = ($serviceId === 'ramses') ? 'cached_models' : 'openai_cached_models';

        $models_cache_time = \platform\AIChatPageComponentConfig::get($cache_time_key);
        $cached_models = \platform\AIChatPageComponentConfig::get($cached_models_key);

        $button_text = $this->plugin->txt('config_refresh_models_button');
        $info_text = '';

        if ($models_cache_time) {
            $timestamp = is_string($models_cache_time) ? (int)$models_cache_time : $models_cache_time;
            $last_update = date('d.m.Y H:i', $timestamp);
            $model_count = is_array($cached_models) ? count($cached_models) : 0;
            $info_text = '<p><small>Zuletzt aktualisiert: ' . $last_update . ' (' . $model_count . ' ' . $this->plugin->txt('refresh_models_count') . ')</small></p>';
        } else {
            $info_text = '<p><small>' . $this->plugin->txt('refresh_models_not_loaded') . '</small></p>';
        }

        $serviceIdCap = ucfirst($serviceId);
        $refresh_button = $ui_factory->button()->standard(
            $button_text,
            $this->ctrl->getLinkTarget($this, "refresh{$serviceIdCap}Models")
        );

        $button_html = $renderer->render($refresh_button);

        return '<div style="margin-top: 20px;">' . $info_text . $button_html . '</div>';
    }

    /**
     * Save configuration for any registered LLM service (DYNAMIC)
     *
     * @param string $serviceId Service identifier (e.g., 'ramses', 'openai')
     */
    private function saveServiceConfiguration(string $serviceId): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            // Get service class from registry
            $serviceClass = \ai\AIChatPageComponentLLMRegistry::getServiceClass($serviceId);
            if ($serviceClass === null) {
                throw new \Exception("Service '$serviceId' not found in registry");
            }

            // Create bare service instance (without requiring full configuration)
            $service = \ai\AIChatPageComponentLLMRegistry::createBareServiceInstance($serviceId);
            if ($service === null) {
                throw new \Exception("Failed to create service instance for '$serviceId'");
            }

            // Get configuration inputs from service
            $serviceInputs = $service->getConfigurationFormInputs();

            // Wrap in section
            $section = $ui_factory->input()->field()->section(
                $serviceInputs,
                $serviceClass::getServiceName(),
                $serviceClass::getServiceDescription()
            );

            // Build form for processing
            $serviceIdCap = ucfirst($serviceId);
            $form_action = $this->ctrl->getFormAction($this, "save{$serviceIdCap}Configuration");
            $form = $ui_factory->input()->container()->form()->standard($form_action, [$section])
                ->withRequest($request);

            $formData = $form->getData();

            if ($formData !== null && is_array($formData) && count($formData) > 0) {
                // Extract data from section wrapper
                $sectionData = reset($formData); // Get first (and only) section

                // Save configuration using service method
                $service->saveConfiguration($sectionData);

                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'success',
                    $this->plugin->txt('config_saved_success')
                );
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    $this->plugin->txt('config_form_invalid')
                );
            }

        } catch (\Exception $e) {
            $this->dic->logger()->comp('pcaic')->error("Failed to save {$serviceId} configuration", [
                'error' => $e->getMessage()
            ]);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('config_save_error') . ': ' . $e->getMessage()
            );
        }

        $this->showConfigurationForm($serviceId);
    }

    /**
     * Refresh models for any registered LLM service (DYNAMIC)
     *
     * @param string $serviceId Service identifier (e.g., 'ramses', 'openai')
     */
    private function refreshServiceModels(string $serviceId): void
    {
        // For now, delegate to service-specific methods if they exist
        // This allows for custom model refresh logic per service
        $serviceIdCap = ucfirst($serviceId);
        $methodName = "refresh{$serviceIdCap}Models";

        if (method_exists($this, $methodName)) {
            $this->$methodName();
        } else {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'info',
                "Model refresh not implemented for service: {$serviceId}"
            );
            $this->showConfigurationForm($serviceId);
        }
    }

    // ============================================
    // LEGACY Service-Specific Methods (Kept for backward compatibility)
    // ============================================

    /**
     * Save RAMSES configuration settings
     * @deprecated Use saveServiceConfiguration('ramses') instead
     */

    /**
     * Save OpenAI configuration settings
     */

    /**
     * Refresh RAMSES models from API
     */

    /**
     * Refresh OpenAI models from API
     */
}