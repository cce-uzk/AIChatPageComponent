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
                $this->showConfigurationForm();
                break;
            case 'saveConfiguration':
                $this->saveConfiguration();
                break;
            default:
                $this->showConfigurationForm();
        }
    }

    /**
     * Display the configuration form using modern ILIAS 9 UI components
     */
    private function showConfigurationForm(): void
    {
        try {
            $form_html = $this->buildModernConfigurationForm();
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
     * Build configuration form using modern ILIAS 9 UI components
     * 
     * Creates a sectioned form with default values, service settings,
     * and file constraint configurations.
     * 
     * @return string Rendered HTML form
     * @throws \Exception If form building fails
     */
    private function buildModernConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        // Build form sections
        $inputs = [];

        // Default Values Section - Platform-wide defaults for new chat instances
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildDefaultValuesInputs(),
            $this->plugin->txt('config_defaults_title'),
            $this->plugin->txt('config_defaults_info')
        );

        // Verarbeitungs-Limits Section - AI processing constraints
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildProcessingLimitsInputs(),
            $this->plugin->txt('config_processing_title'),
            $this->plugin->txt('config_processing_info')
        );

        // AI Services Section - Enable/disable available AI services
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildAiServicesInputs(), 
            $this->plugin->txt('config_services_title'),
            $this->plugin->txt('config_services_info')
        );

        // Datei-Upload Beschränkungen Section - File upload limits per message
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildUploadConstraintsInputs(),
            $this->plugin->txt('config_upload_title'),
            $this->plugin->txt('config_upload_info')
        );
        
        // File Upload Restrictions Section - Admin controls for file upload permissions
        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildFileUploadRestrictionsInputs(),
            $this->plugin->txt('config_file_restrictions_title'),
            $this->plugin->txt('config_file_restrictions_info')
        );

        // Create standard form with submit action
        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            $inputs
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
     * Build AI services configuration input fields
     * 
     * @return array UI input components for service availability
     */
    private function buildAiServicesInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];
        
        $available_services = \platform\AIChatPageComponentConfig::get('available_services') ?? [];

        // RAMSES (University of Cologne Mistral) service availability
        $inputs['service_ramses'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_service_ramses'),
            $this->plugin->txt('config_service_ramses_info')
        )->withValue(($available_services['ramses'] ?? '0') === '1');

        // OpenAI GPT service availability
        $inputs['service_openai'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_service_openai'),
            $this->plugin->txt('config_service_openai_info')
        )->withValue(($available_services['openai'] ?? '0') === '1');

        return $inputs;
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
            // Build form structure identical to display form
            $inputs = [];
            
            // Match the order from buildModernConfigurationForm()
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
                $this->buildAiServicesInputs(), 
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
                
                // Extract data from sections (match buildModernConfigurationForm order)
                $defaults_data = $data[0] ?? [];        // buildDefaultValuesInputs
                $processing_data = $data[1] ?? [];      // buildProcessingLimitsInputs  
                $services_data = $data[2] ?? [];        // buildAiServicesInputs
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

                // Save service settings
                $available_services = [];
                if (isset($services_data['service_ramses'])) {
                    $available_services['ramses'] = $services_data['service_ramses'] ? '1' : '0';
                }
                if (isset($services_data['service_openai'])) {
                    $available_services['openai'] = $services_data['service_openai'] ? '1' : '0';
                }
                \platform\AIChatPageComponentConfig::set('available_services', $available_services);
                
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
        
        $this->ctrl->redirect($this, 'configure');
    }
}