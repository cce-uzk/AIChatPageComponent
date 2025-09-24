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
     * Execute command from ILIAS control structure
     */
    public function performCommand(string $cmd): void
    {
        switch ($cmd) {
            case 'configure':
            case 'showConfigurationForm':
                $this->showConfigurationForm('general');
                break;
            case 'showRamsesConfig':
                $this->showConfigurationForm('ramses');
                break;
            case 'showOpenAiConfig':
                $this->showConfigurationForm('openai');
                break;
            case 'saveConfiguration':
                $this->saveConfiguration();
                break;
            case 'saveRamsesConfiguration':
                $this->saveRamsesConfiguration();
                break;
            case 'saveOpenAiConfiguration':
                $this->saveOpenAiConfiguration();
                break;
            case 'refreshRamsesModels':
                $this->refreshRamsesModels();
                break;
            default:
                $this->showConfigurationForm('general');
        }
    }

    /**
     * Display the configuration form using modern ILIAS 9 UI components
     */
    private function showConfigurationForm(string $active_tab = 'general'): void
    {
        try {
            // Setup tabs
            $tabs = $this->dic->tabs();
            $tabs->addTab('general', $this->plugin->txt('tab_general_config'), 
                         $this->ctrl->getLinkTarget($this, 'showConfigurationForm'));
            $tabs->addTab('ramses', $this->plugin->txt('tab_ramses_config'), 
                         $this->ctrl->getLinkTargetByClass(get_class($this), 'showRamsesConfig'));
            $tabs->addTab('openai', $this->plugin->txt('tab_openai_config'), 
                         $this->ctrl->getLinkTargetByClass(get_class($this), 'showOpenAiConfig'));
            
            // Set active tab
            $tabs->setTabActive($active_tab);
            
            // Show appropriate form based on active tab
            switch ($active_tab) {
                case 'ramses':
                    $form_html = $this->buildRamsesConfigurationForm();
                    break;
                case 'openai':
                    $form_html = $this->buildOpenAiConfigurationForm();
                    break;
                case 'general':
                default:
                    $form_html = $this->buildGeneralConfigurationForm();
                    break;
            }
            
            $this->dic->ui()->mainTemplate()->setContent($form_html);
        } catch (\Exception $e) {
            $this->dic->logger()->comp('pcaic')->error('Failed to show configuration form', [
                'error' => $e->getMessage()
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
     * Build RAMSES configuration form (RAMSES tab)
     * 
     * @return string Rendered HTML form
     * @throws \Exception If form building fails
     */
    private function buildRamsesConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        $inputs = [];

        // RAMSES API Configuration Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildRamsesConfigInputs(),
            $this->plugin->txt('config_ramses_title'),
            $this->plugin->txt('config_ramses_info')
        );

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveRamsesConfiguration'),
            $inputs
        );

        // Add refresh models button with info about last update
        $models_cache_time = \platform\AIChatPageComponentConfig::get('models_cache_time');
        $cached_models = \platform\AIChatPageComponentConfig::get('cached_models');
        
        $button_text = $this->plugin->txt('config_refresh_models_button');
        $info_text = '';
        
        if ($models_cache_time) {
            // Convert string timestamp to integer if needed
            $timestamp = is_string($models_cache_time) ? (int)$models_cache_time : $models_cache_time;
            $last_update = date('d.m.Y H:i', $timestamp);
            $model_count = is_array($cached_models) ? count($cached_models) : 0;
            $info_text = '<p><small>Zuletzt aktualisiert: ' . $last_update . ' (' . $model_count . ' Modelle verfügbar)</small></p>';
        } else {
            $info_text = '<p><small>Modelle noch nicht geladen. Klicken Sie auf den Button, um verfügbare Modelle zu laden.</small></p>';
        }
        
        $refresh_button = $ui_factory->button()->standard(
            $button_text,
            $this->ctrl->getLinkTarget($this, 'refreshRamsesModels')
        );

        $form_html = $ui_renderer->render($form);
        $button_html = $ui_renderer->render($refresh_button);
        
        return $form_html . '<div style="margin-top: 20px;">' . $info_text . $button_html . '</div>';
    }

    /**
     * Build OpenAI configuration form (OpenAI tab)
     * 
     * @return string Rendered HTML form
     * @throws \Exception If form building fails
     */
    private function buildOpenAiConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        $inputs = [];

        // OpenAI API Configuration Section
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildOpenAiConfigInputs(),
            $this->plugin->txt('config_openai_title'),
            $this->plugin->txt('config_openai_info')
        );

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveOpenAiConfiguration'),
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
    private function buildModernConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        // Build main configuration sections (non-tabbed)
        $main_inputs = [];

        // Default Values Section - Platform-wide defaults for new chat instances
        $main_inputs[] = $ui_factory->input()->field()->section(
            $this->buildDefaultValuesInputs(),
            $this->plugin->txt('config_defaults_title'),
            $this->plugin->txt('config_defaults_info')
        );

        // Verarbeitungs-Limits Section - AI processing constraints
        $main_inputs[] = $ui_factory->input()->field()->section(
            $this->buildProcessingLimitsInputs(),
            $this->plugin->txt('config_processing_title'),
            $this->plugin->txt('config_processing_info')
        );

        // AI Service Selection Section - Choose which service to use
        $main_inputs[] = $ui_factory->input()->field()->section(
            $this->buildAiServiceSelectionInputs(), 
            $this->plugin->txt('config_services_title'),
            $this->plugin->txt('config_services_info')
        );

        // Datei-Upload Beschränkungen Section - File upload limits per message
        $main_inputs[] = $ui_factory->input()->field()->section(
            $this->buildUploadConstraintsInputs(),
            $this->plugin->txt('config_upload_title'),
            $this->plugin->txt('config_upload_info')
        );
        
        // File Upload Restrictions Section - Admin controls for file upload permissions
        $main_inputs[] = $ui_factory->input()->field()->section(
            $this->buildFileUploadRestrictionsInputs(),
            $this->plugin->txt('config_file_restrictions_title'),
            $this->plugin->txt('config_file_restrictions_info')
        );

        // Create tabs for AI service configurations
        $ramses_form_inputs = [];
        $ramses_form_inputs[] = $ui_factory->input()->field()->section(
            $this->buildRamsesConfigInputs(),
            $this->plugin->txt('config_ramses_title'),
            $this->plugin->txt('config_ramses_info')
        );

        $openai_form_inputs = [];
        $openai_form_inputs[] = $ui_factory->input()->field()->section(
            $this->buildOpenAiConfigInputs(),
            $this->plugin->txt('config_openai_title'),
            $this->plugin->txt('config_openai_info')
        );

        // Create separate forms for each tab
        $ramses_form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            array_merge($main_inputs, $ramses_form_inputs)
        );

        $openai_form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            array_merge($main_inputs, $openai_form_inputs)
        );

        // Create tabs
        $tabs = [];
        $tabs['ramses'] = $ui_factory->modal()->roundtrip(
            $this->plugin->txt('tab_ramses_config'),
            [$ui_factory->legacy($ui_renderer->render($ramses_form))]
        );

        $tabs['openai'] = $ui_factory->modal()->roundtrip(
            $this->plugin->txt('tab_openai_config'), 
            [$ui_factory->legacy($ui_renderer->render($openai_form))]
        );

        // For now, return the combined form (tabs require more complex handling)
        // We'll use a simpler approach with sections for better UX
        $all_inputs = array_merge(
            $main_inputs,
            $ramses_form_inputs,
            $openai_form_inputs
        );

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            $all_inputs
        );

        return $ui_renderer->render($form);
    }

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
    private function buildRamsesConfigInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // API Base URL for chat completions
        $chat_api_url = \platform\AIChatPageComponentConfig::get('ramses_chat_api_url');
        $inputs['ramses_chat_api_url'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_ramses_chat_api_url'),
            $this->plugin->txt('config_ramses_chat_api_url_info')
        )->withValue($chat_api_url ?: 'https://ramses-oski.itcc.uni-koeln.de/v1/chat/completions');

        // API URL for models endpoint
        $models_api_url = \platform\AIChatPageComponentConfig::get('ramses_models_api_url');
        $inputs['ramses_models_api_url'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_ramses_models_api_url'),
            $this->plugin->txt('config_ramses_models_api_url_info')
        )->withValue($models_api_url ?: 'https://ramses-oski.itcc.uni-koeln.de/v1/models');

        // API Token for authentication
        $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');
        $inputs['ramses_api_token'] = $ui_factory->input()->field()->password(
            $this->plugin->txt('config_ramses_api_token'),
            $this->plugin->txt('config_ramses_api_token_info')
        )->withValue($api_token ?: '');

        // Selected model dropdown (populated from API or cached models)
        $selected_model = \platform\AIChatPageComponentConfig::get('ramses_selected_model');
        $available_models = $this->getAvailableModels();
        
        $inputs['ramses_selected_model'] = $ui_factory->input()->field()->select(
            $this->plugin->txt('config_ramses_selected_model'),
            $available_models,
            $this->plugin->txt('config_ramses_selected_model_info')
        )->withValue($selected_model ?: '');

        // Remove the unused "Available Models" info field

        return $inputs;
    }

    /**
     * Get available models from cache only (no API calls)
     * Models are only fetched when user clicks the refresh button
     * 
     * @return array Available models for dropdown
     */
    private function getAvailableModels(): array
    {
        try {
            // Only use cached models - no automatic API calls
            $cached_models = \platform\AIChatPageComponentConfig::get('cached_models');
            if (is_array($cached_models) && !empty($cached_models)) {
                return $cached_models;
            }
        } catch (\Exception $e) {
            // Log error but don't break form
            try {
                $this->dic->logger()->comp('pcaic')->error('Failed to get cached models', [
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $logError) {
                // Ignore logging errors
            }
        }
        
        // No default models - user must fetch them manually
        return [];
    }

    /**
     * Build AI service selection input fields
     * 
     * @return array UI input components for service selection
     */
    private function buildAiServiceSelectionInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];
        
        // Main service selection dropdown
        $selected_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $service_options = [
            'ramses' => $this->plugin->txt('service_ramses_option'),
            'openai' => $this->plugin->txt('service_openai_option')
        ];
        
        $inputs['selected_ai_service'] = $ui_factory->input()->field()->select(
            $this->plugin->txt('config_selected_ai_service'),
            $service_options,
            $this->plugin->txt('config_selected_ai_service_info')
        )->withValue($selected_service);

        // Enable/disable services
        $available_services = \platform\AIChatPageComponentConfig::get('available_services') ?? [];
        
        $inputs['service_ramses_enabled'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_service_ramses_enabled'),
            $this->plugin->txt('config_service_ramses_enabled_info')
        )->withValue(($available_services['ramses'] ?? '1') === '1');

        $inputs['service_openai_enabled'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_service_openai_enabled'),
            $this->plugin->txt('config_service_openai_enabled_info')
        )->withValue(($available_services['openai'] ?? '0') === '1');

        return $inputs;
    }

    /**
     * Build OpenAI API configuration input fields
     * 
     * @return array UI input components for OpenAI API configuration
     */
    private function buildOpenAiConfigInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        // API Base URL for chat completions
        $openai_api_url = \platform\AIChatPageComponentConfig::get('openai_api_url');
        $inputs['openai_api_url'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_openai_api_url'),
            $this->plugin->txt('config_openai_api_url_info')
        )->withValue($openai_api_url ?: 'https://api.openai.com/v1/chat/completions');

        // API Token for authentication
        $openai_api_token = \platform\AIChatPageComponentConfig::get('openai_api_token');
        $inputs['openai_api_token'] = $ui_factory->input()->field()->password(
            $this->plugin->txt('config_openai_api_token'),
            $this->plugin->txt('config_openai_api_token_info')
        )->withValue($openai_api_token ?: '');

        // Selected model dropdown (OpenAI models)
        $openai_selected_model = \platform\AIChatPageComponentConfig::get('openai_selected_model');
        $openai_models = $this->getOpenAiModels();
        
        $inputs['openai_selected_model'] = $ui_factory->input()->field()->select(
            $this->plugin->txt('config_openai_selected_model'),
            $openai_models,
            $this->plugin->txt('config_openai_selected_model_info')
        )->withValue($openai_selected_model ?: 'gpt-3.5-turbo');

        // Enable streaming for OpenAI
        $openai_streaming = \platform\AIChatPageComponentConfig::get('openai_streaming_enabled');
        $inputs['openai_streaming_enabled'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_openai_streaming'),
            $this->plugin->txt('config_openai_streaming_info')
        )->withValue(($openai_streaming ?? '0') === '1');

        return $inputs;
    }

    /**
     * Get available OpenAI models
     * 
     * @return array Available OpenAI models for dropdown
     */
    private function getOpenAiModels(): array
    {
        // Static OpenAI model list (could be made dynamic via API in the future)
        return [
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo'
        ];
    }

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
     * Build file upload restrictions input fields following ILIAS Test KioskMode pattern
     * 
     * Creates optional group with checkboxes for file upload permissions:
     * - Enable file uploads globally
     * - Allowed file types whitelist 
     * - Separate controls for background files vs chat uploads
     * 
     * @return array UI input components for file upload restrictions
     */
    private function buildFileUploadRestrictionsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $refinery = $this->dic->refinery();
        
        // Get current file restrictions configuration
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];
        
        // Create transformation function similar to KioskMode pattern
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
        
        // Build sub-inputs for the optional group with default values
        $sub_inputs = [];
        
        // File types whitelist input with centralized defaults
        $default_file_types = \platform\AIChatPageComponentConfig::get('default_allowed_file_types');
        $default_types_string = is_array($default_file_types) 
            ? implode(',', $default_file_types) 
            : 'txt,md,pdf,csv,png,jpg,jpeg,webp,gif';
            
        $sub_inputs['allowed_file_types'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_allowed_file_types'),
            $this->plugin->txt('config_allowed_file_types_info')
        )->withValue($default_types_string);
        
        // Background files permission enabled by default
        $sub_inputs['allow_background_files'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_background_files'),
            $this->plugin->txt('config_allow_background_files_info')
        )->withValue(true);
        
        // Chat uploads permission enabled by default
        $sub_inputs['allow_chat_uploads'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_chat_uploads'),
            $this->plugin->txt('config_allow_chat_uploads_info')
        )->withValue(true);
        
        // Create optional group with transformation
        $file_restrictions_group = $ui_factory->input()->field()->optionalGroup(
            $sub_inputs,
            $this->plugin->txt('config_enable_file_handling'),
            $this->plugin->txt('config_enable_file_handling_info')
        );
        
        // Set value properly for optional group with smart defaults
        if ($file_restrictions['enabled'] ?? false) {
            $current_values = [
                'allowed_file_types' => isset($file_restrictions['allowed_file_types']) && !empty($file_restrictions['allowed_file_types'])
                    ? implode(',', $file_restrictions['allowed_file_types']) 
                    : $default_types_string,
                'allow_background_files' => $file_restrictions['allow_background_files'] ?? true,
                'allow_chat_uploads' => $file_restrictions['allow_chat_uploads'] ?? true
            ];
            $file_restrictions_group = $file_restrictions_group->withValue($current_values);
        } else {
            // When disabled, don't set values - let defaults from sub-inputs show when enabled
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

                // Save service selection and availability
                if (isset($services_data['selected_ai_service'])) {
                    \platform\AIChatPageComponentConfig::set('selected_ai_service', $services_data['selected_ai_service']);
                }
                
                $available_services = [];
                if (isset($services_data['service_ramses_enabled'])) {
                    $available_services['ramses'] = $services_data['service_ramses_enabled'] ? '1' : '0';
                }
                if (isset($services_data['service_openai_enabled'])) {
                    $available_services['openai'] = $services_data['service_openai_enabled'] ? '1' : '0';
                }
                \platform\AIChatPageComponentConfig::set('available_services', $available_services);

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
                
                // Save file upload restrictions
                if (isset($restrictions_data['file_restrictions'])) {
                    \platform\AIChatPageComponentConfig::set('file_upload_restrictions', $restrictions_data['file_restrictions']);
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

    /**
     * Save RAMSES configuration settings
     */
    public function saveRamsesConfiguration(): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            $inputs = [];
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildRamsesConfigInputs(),
                $this->plugin->txt('config_ramses_title'),
                $this->plugin->txt('config_ramses_info')
            );

            $form = $ui_factory->input()->container()->form()->standard(
                $this->ctrl->getFormAction($this, 'saveRamsesConfiguration'),
                $inputs
            );

            $form = $form->withRequest($request);
            $data = $form->getData();

            if ($data !== null) {
                $ramses_data = $data[0] ?? [];

                // Save RAMSES configuration
                if (isset($ramses_data['ramses_chat_api_url'])) {
                    \platform\AIChatPageComponentConfig::set('ramses_chat_api_url', $ramses_data['ramses_chat_api_url']);
                }
                if (isset($ramses_data['ramses_models_api_url'])) {
                    \platform\AIChatPageComponentConfig::set('ramses_models_api_url', $ramses_data['ramses_models_api_url']);
                }
                if (isset($ramses_data['ramses_api_token'])) {
                    // Handle ILIAS\Data\Password object conversion
                    $token = $ramses_data['ramses_api_token'];
                    if (is_object($token) && method_exists($token, 'toString')) {
                        $token = $token->toString();
                    }
                    \platform\AIChatPageComponentConfig::set('ramses_api_token', $token);
                }
                if (isset($ramses_data['ramses_selected_model'])) {
                    \platform\AIChatPageComponentConfig::set('ramses_selected_model', $ramses_data['ramses_selected_model']);
                }

                $this->dic->ui()->mainTemplate()->setOnScreenMessage('success', $this->plugin->txt('config_saved_success'));
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_form_invalid'));
            }
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_save_error') . ': ' . $e->getMessage());
        }

        $this->showConfigurationForm('ramses');
    }

    /**
     * Save OpenAI configuration settings
     */
    public function saveOpenAiConfiguration(): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            $inputs = [];
            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildOpenAiConfigInputs(),
                $this->plugin->txt('config_openai_title'),
                $this->plugin->txt('config_openai_info')
            );

            $form = $ui_factory->input()->container()->form()->standard(
                $this->ctrl->getFormAction($this, 'saveOpenAiConfiguration'),
                $inputs
            );

            $form = $form->withRequest($request);
            $data = $form->getData();

            if ($data !== null) {
                $openai_data = $data[0] ?? [];

                // Save OpenAI configuration
                if (isset($openai_data['openai_api_url'])) {
                    \platform\AIChatPageComponentConfig::set('openai_api_url', $openai_data['openai_api_url']);
                }
                if (isset($openai_data['openai_api_token'])) {
                    // Handle ILIAS\Data\Password object conversion
                    $token = $openai_data['openai_api_token'];
                    if (is_object($token) && method_exists($token, 'toString')) {
                        $token = $token->toString();
                    }
                    \platform\AIChatPageComponentConfig::set('openai_api_token', $token);
                }
                if (isset($openai_data['openai_selected_model'])) {
                    \platform\AIChatPageComponentConfig::set('openai_selected_model', $openai_data['openai_selected_model']);
                }
                if (isset($openai_data['openai_streaming_enabled'])) {
                    \platform\AIChatPageComponentConfig::set('openai_streaming_enabled', $openai_data['openai_streaming_enabled'] ? '1' : '0');
                }

                $this->dic->ui()->mainTemplate()->setOnScreenMessage('success', $this->plugin->txt('config_saved_success'));
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_form_invalid'));
            }
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_save_error') . ': ' . $e->getMessage());
        }

        $this->showConfigurationForm('openai');
    }

    /**
     * Refresh RAMSES models from API
     */
    public function refreshRamsesModels(): void
    {
        try {
            $models_api_url = \platform\AIChatPageComponentConfig::get('ramses_models_api_url') ?: 'https://ramses-oski.itcc.uni-koeln.de/v1/models';
            $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');
            
            // Handle potential Password object conversion
            if (is_object($api_token) && method_exists($api_token, 'toString')) {
                $api_token = $api_token->toString();
            }
            
            if (empty($api_token)) {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', 
                    $this->plugin->txt('refresh_models_no_token'));
                $this->showConfigurationForm('ramses');
                return;
            }

            // Try to fetch models from API
            $ch = curl_init();
            
            // Get certificate path from this plugin
            try {
                $plugin = \ilAIChatPageComponentPlugin::getInstance();
                $plugin_path = $plugin->getDirectory();
                $absolute_plugin_path = realpath($plugin_path);
                $ca_cert_path = $absolute_plugin_path . '/certs/RAMSES.pem';

                if (file_exists($ca_cert_path)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca_cert_path);
                } else {
                    // Fallback: disable SSL verification for development/testing
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                }
            } catch (\Exception $e) {
                // If AIChat plugin not available, disable SSL verification
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            
            curl_setopt($ch, CURLOPT_URL, $models_api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $api_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $models_response = json_decode($response, true);

                // Handle both old format (direct array) and new format (object with data array)
                $models_data = [];
                if (is_array($models_response)) {
                    if (isset($models_response['object']) && $models_response['object'] === 'list' && isset($models_response['data'])) {
                        // New format: {object: "list", data: [...]}
                        $models_data = $models_response['data'];
                    } else {
                        // Old format: direct array
                        $models_data = $models_response;
                    }
                }

                if (is_array($models_data) && !empty($models_data)) {
                    $models = [];
                    foreach ($models_data as $model) {
                        // Support both 'name' and 'id' as model identifier
                        $model_id = $model['id'] ?? $model['name'] ?? null;
                        $model_name = $model['display_name'] ?? $model['name'] ?? $model['id'] ?? null;

                        if ($model_id && $model_name) {
                            $models[$model_id] = $model_name;
                        }
                    }
                    
                    if (!empty($models)) {
                        // Cache models and timestamp
                        \platform\AIChatPageComponentConfig::set('cached_models', $models);
                        \platform\AIChatPageComponentConfig::set('models_cache_time', time());
                        
                        $this->dic->ui()->mainTemplate()->setOnScreenMessage('success', 
                            $this->plugin->txt('refresh_models_success') . ' (' . count($models) . ' ' . 
                            $this->plugin->txt('refresh_models_count') . ')');
                    } else {
                        $this->dic->ui()->mainTemplate()->setOnScreenMessage('info', 
                            $this->plugin->txt('refresh_models_no_models'));
                    }
                } else {
                    $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', 
                        $this->plugin->txt('refresh_models_invalid_response'));
                }
            } else {
                $error_msg = $this->plugin->txt('refresh_models_api_error') . ' (HTTP ' . $httpCode . ')';
                if ($error) {
                    $error_msg .= ': ' . $error;
                }
                
                // Add debug information for HTTP 401
                if ($httpCode === 401) {
                    $error_msg .= ' - Check API token validity';
                    // Log token info for debugging (without exposing the actual token)
                    try {
                        $this->dic->logger()->comp('pcaic')->error("RAMSES Models API 401 Error", [
                            'api_url' => $models_api_url,
                            'token_length' => strlen($api_token),
                            'token_starts_with' => substr($api_token, 0, 8) . '...'
                        ]);
                    } catch (\Exception $logError) {
                        // Ignore logging errors
                    }
                }
                
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $error_msg);
            }
            
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', 
                $this->plugin->txt('refresh_models_exception') . ': ' . $e->getMessage());
        }

        $this->showConfigurationForm('ramses');
    }
}