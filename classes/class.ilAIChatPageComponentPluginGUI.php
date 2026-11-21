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

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ILIAS\Plugin\pcaic\Validation\FileUploadValidator;

/**
 * Page editor integration of the chat: create and edit forms, editor preview and
 * rendering on the page
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilPCPluggedGUI
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilRepositoryGUI
 */
class ilAIChatPageComponentPluginGUI extends ilPageComponentPluginGUI
{
    protected ilLanguage $lng;

    protected ilCtrl $ctrl;

    protected ilGlobalTemplateInterface $tpl;

    protected $request;

    protected $logger;

    public function __construct()
    {
        global $DIC;

        parent::__construct();

        $this->lng = $DIC->language();
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC['tpl'];
        $this->request = $DIC->http()->request();

        $this->logger = $DIC->logger()->pcaic();
    }

    public function setPCGUI(ilPageContentGUI $a_val): void
    {
        parent::setPCGUI($a_val);
    }

    /**
     * Upload requests are forwarded to the upload handler, all other commands must
     * be in the list of allowed commands
     */
    public function executeCommand(): void
    {
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case strtolower(ilAIChatPageComponentFileUploadHandlerGUI::class):
                require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
                $gui = new ilAIChatPageComponentFileUploadHandlerGUI();
                $this->ctrl->forwardCommand($gui);
                break;
            default:
                $cmd = $this->ctrl->getCmd();
                $allowed = ["create", "save", "edit", "update", "cancel",
                            "showBasicTab", "saveBasic",
                            "showAdvancedTab", "saveAdvanced",
                            "showStatisticsTab", "clearHistory",
                            "showResourcesTab", "resourceAction", "confirmRemoveResource", "removeResource",
                            "showAddResourceFiles", "saveResourceFiles", "addPageContextResource",
                            "showAddEnvironmentResources", "saveEnvironmentResources"];
                if (in_array($cmd, $allowed)) {
                    $this->$cmd();
                } else {
                    $this->tpl->setOnScreenMessage("failure", $this->lng->txt("msg_invalid_cmd"), true);
                    $this->returnToParent();
                }
                break;
        }
    }

    /**
     * Form for inserting a new chat
     */
    public function insert(): void
    {
        global $DIC;
        $form = $this->initForm(true);
        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    /**
     * Save a new chat
     */
    public function create(): void
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
                $flat_data = array_merge(
                    $this->getCreationDefaults(),
                    ...array_map(fn($s) => is_array($s) ? $s : [], $data)
                );
                if ($this->saveForm($flat_data, true)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
                    $this->redirectToEditOfNewElement();
                    return;
                }
            }
        }

        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    /**
     * Settings of a new chat that the creation form does not ask for: the same values the
     * full form proposes (defaults of the administration), RAG and chat uploads off
     */
    private function getCreationDefaults(): array
    {
        $defaults = $this->getAIChatDefaults();
        return [
            'max_memory' => $defaults['max_memory_messages'],
            'char_limit' => $defaults['characters_limit'],
            'disclaimer' => $defaults['disclaimer'],
            'persistent' => false,
            'enable_chat_uploads' => false,
            'enable_streaming' => true,
        ];
    }

    /**
     * Open the edit form of the element just created, where the other settings are made
     *
     * The page editor finds the element by its PC ID, which the page assigns when it is saved.
     * Hierarchical IDs are only assigned when a page is loaded, so they are added to the page
     * here. If either ID is not known, the page is shown as before.
     */
    private function redirectToEditOfNewElement(): void
    {
        $hier_id = '';
        $pc_id = '';
        try {
            $content = $this->getPCGUI()->getContentObject();
            $pc_id = is_object($content) ? $content->readPCId() : '';
            if ($pc_id !== '') {
                // In the DOM the element was inserted into; the XML of the page object is
                // still the one before the insertion
                $page = $this->getPCGUI()->getPage();
                $page->addHierIDs();
                $hier_id = (string) ($page->getHierIdsForPCIds([$pc_id])[$pc_id] ?? '');
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Edit form of the new chat element not opened: " . $e->getMessage());
        }

        if ($hier_id === '' || $pc_id === '') {
            $this->logger->info("New chat element without hierarchical or PC ID ('$hier_id', '$pc_id'), returning to the page");
            $this->returnToParent();
            return;
        }

        $this->ctrl->setParameterByClass(ilPageEditorGUI::class, 'hier_id', $hier_id);
        $this->ctrl->setParameterByClass(ilPageEditorGUI::class, 'pc_id', $pc_id);
        $this->ctrl->redirectByClass(ilPCPluggedGUI::class, 'edit');
    }

    public function edit(): void
    {
        $this->showBasicTab();
    }

    public function update(): void
    {
        $this->saveBasic();
    }

    /**
     * Form for creating and editing a chat
     */
    protected function initForm(bool $a_create = false, string $tab = 'all')
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();

        $defaults = $this->getAIChatDefaults();

        $prop = [];
        $chat_config = null;

        if (!$a_create) {
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';

            if (!empty($chat_id)) {
                try {
                    $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chat_config->exists()) {
                        $prop = [
                            'chat_title' => $chat_config->getTitle(),
                            'system_prompt' => $chat_config->getSystemPrompt(),
                            'ai_service' => $chat_config->getAiService(),
                            'max_memory' => $chat_config->getMaxMemory(),
                            'char_limit' => $chat_config->getCharLimit(),
                            'persistent' => $chat_config->isPersistent(),
                            'include_page_context' => $chat_config->isIncludePageContext(),
                            'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                            'enable_streaming' => $chat_config->isEnableStreaming(),
                            'enable_rag' => $chat_config->isEnableRag(),
                            'show_sources' => $chat_config->isShowSources(),
                            'allow_source_downloads' => $chat_config->isAllowSourceDownloads(),
                            'is_online' => $chat_config->isOnline(),
                            'disclaimer' => $chat_config->getDisclaimer(),
                            'background_files' => json_encode($chat_config->getBackgroundFiles())
                        ];
                    } else {
                        // Chat without stored configuration: use the element properties
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

        $chat_title = $ui_factory->input()->field()->text(
            $this->plugin->txt('chat_title_label'),
            $this->plugin->txt('chat_title_info')
        )->withDedicatedName('chat_title')->withRequired(true)->withMaxLength(255)->withValue($prop['chat_title'] ?? $defaults['title']);

        $is_online = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('chat_online_label'),
            $this->plugin->txt('chat_online_info')
        )->withDedicatedName('is_online')->withValue($this->toBool($prop['is_online'] ?? !$a_create));

        $system_prompt = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('system_prompt_label'),
            $this->plugin->txt('system_prompt_info')
        )->withDedicatedName('system_prompt')->withMaxLimit(12000)->withValue($prop['system_prompt'] ?? $defaults['prompt']);

        // A new chat gets title, visibility and system prompt only; after saving, the edit
        // form with its tabs opens for all other settings (see create())
        if ($a_create) {
            return $ui_factory->input()->container()->form()->standard(
                $this->ctrl->getFormAction($this, 'create'),
                [$ui_factory->input()->field()->section(
                    ['chat_title' => $chat_title, 'is_online' => $is_online, 'system_prompt' => $system_prompt],
                    $this->plugin->txt('section_general_label'),
                    $this->plugin->txt('section_general_create_info')
                )]
            );
        }

        $default_ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        $stored_service = $prop['ai_service'] ?? null;
        if ($force_default_service === '1') {
            $ai_service_value = $default_ai_service;
        } elseif ($stored_service && isset($service_options[$stored_service])) {
            $ai_service_value = $stored_service;
        } elseif (!empty($service_options)) {
            // The stored service has been disabled: use the first available service
            $ai_service_value = array_key_first($service_options);

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
            $ai_service_value = null;
        }

        $ai_service = $ui_factory->input()->field()->select(
            $this->plugin->txt('ai_service_label'),
            $service_options,
            $this->plugin->txt('ai_service_info')
        )->withDedicatedName('ai_service');

        if ($ai_service_value && isset($service_options[$ai_service_value])) {
            $ai_service = $ai_service->withValue($ai_service_value);
        }

        if ($force_default_service === '1') {
            $ai_service = $ai_service->withDisabled(true);
        }

        $max_memory = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('max_memory_label'),
            $this->plugin->txt('max_memory_info')
        )->withDedicatedName('max_memory')->withValue((int) ($prop['max_memory'] ?? $defaults['max_memory_messages']));

        $char_limit = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('char_limit_label'),
            $this->plugin->txt('char_limit_info')
        )->withDedicatedName('char_limit')->withValue((int) ($prop['char_limit'] ?? $defaults['characters_limit']));

        $persistent = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('persistent_chat_label'),
            $this->plugin->txt('persistent_chat_info')
        )->withDedicatedName('persistent')->withValue($this->toBool($prop['persistent'] ?? false));

        // File handling must be known before the file-related fields are built
        $effective_ai_service = $ai_service_value ?? 'ramses';
        $global_file_handling = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $service_file_handling_key = $effective_ai_service . '_file_handling_enabled';
        $service_file_handling = (\platform\AIChatPageComponentConfig::get($service_file_handling_key) ?? '1') === '1';
        $file_handling_enabled_for_service = $global_file_handling && $service_file_handling;

        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
        if (!$file_handling_enabled_for_service) {
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        } elseif ($chat_uploads_globally_enabled) {
            $enable_chat_uploads = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('enable_chat_uploads_info')
            )->withDedicatedName('enable_chat_uploads')->withValue($this->toBool($prop['enable_chat_uploads'] ?? false));
        } else {
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('chat_uploads_disabled'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        }

        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        if ($streaming_globally_enabled) {
            $enable_streaming = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_streaming_label'),
                $this->plugin->txt('enable_streaming_info')
            )->withDedicatedName('enable_streaming')->withValue($this->toBool($prop['enable_streaming'] ?? true));
        }

        // RAG requires file handling, the RAG service and RAG allowed for the AI service
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($effective_ai_service);
        if ($llm === null) {
            $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            $first_service = !empty($available_services) ? array_key_first($available_services) : null;
            $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
        }

        $service_supports_rag = $llm->supportsRAG();

        $rag_config_key = $effective_ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        if (!$file_handling_enabled_for_service) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$service_supports_rag) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('rag_not_supported_info')
            )->withValue($this->plugin->txt('rag_not_supported'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$rag_globally_enabled) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } else {
            // Source settings are nested in the RAG option
            $rag_enabled_now = $this->toBool($prop['enable_rag'] ?? false);
            $rag_sub_inputs = [
                'show_sources' => $ui_factory->input()->field()->checkbox(
                    $this->plugin->txt('show_sources_label'),
                    $this->plugin->txt('show_sources_info')
                )->withValue($this->toBool($prop['show_sources'] ?? false)),
                'allow_source_downloads' => $ui_factory->input()->field()->checkbox(
                    $this->plugin->txt('allow_source_downloads_label'),
                    $this->plugin->txt('allow_source_downloads_info')
                )->withValue($this->toBool($prop['allow_source_downloads'] ?? false)),
            ];
            $enable_rag = $ui_factory->input()->field()->optionalGroup(
                $rag_sub_inputs,
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('enable_rag_info')
            );
            $enable_rag = $enable_rag->withValue($rag_enabled_now ? [
                'show_sources' => $this->toBool($prop['show_sources'] ?? false),
                'allow_source_downloads' => $this->toBool($prop['allow_source_downloads'] ?? false),
            ] : null);
        }

        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')->withMaxLimit(4000)->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        if ($a_create) {
            $form_action = $this->ctrl->getFormAction($this, 'create');
        } elseif ($tab === 'basic') {
            $form_action = $this->ctrl->getFormAction($this, 'saveBasic');
        } else {
            $form_action = $this->ctrl->getFormAction($this, 'update');
        }

        // General settings
        $sections = [];
        $sections[] = $ui_factory->input()->field()->section(
            ['chat_title' => $chat_title, 'is_online' => $is_online, 'system_prompt' => $system_prompt],
            $this->plugin->txt('section_general_label'),
            $this->plugin->txt('section_general_info')
        );

        // AI service (create mode only)
        if ($tab !== 'basic') {
            $ai_config_fields = [];
            if ($force_default_service !== '1') {
                $ai_config_fields['ai_service'] = $ai_service;
            }
            $ai_config_fields['max_memory'] = $max_memory;
            $ai_config_fields['char_limit'] = $char_limit;
            $sections[] = $ui_factory->input()->field()->section(
                $ai_config_fields,
                $this->plugin->txt('section_ai_config_label'),
                $this->plugin->txt('section_ai_config_info')
            );
        }

        // Chat behaviour
        // The page content is a resource (resources tab)
        $behavior_fields = [
            'persistent' => $persistent,
        ];
        if ($file_handling_enabled_for_service) {
            $behavior_fields['enable_chat_uploads'] = $enable_chat_uploads;
        }
        if ($streaming_globally_enabled) {
            $behavior_fields['enable_streaming'] = $enable_streaming;
        }
        if ($file_handling_enabled_for_service && $service_supports_rag && $rag_globally_enabled) {
            $behavior_fields['enable_rag'] = $enable_rag;
        }
        $sections[] = $ui_factory->input()->field()->section(
            $behavior_fields,
            $this->plugin->txt('section_behavior_label'),
            $this->plugin->txt('section_behavior_info')
        );

        // Background files are resources (resources tab)

        // Legal (create mode only)
        if ($tab !== 'basic') {
            $sections[] = $ui_factory->input()->field()->section(
                ['disclaimer' => $disclaimer],
                $this->plugin->txt('section_legal_label'),
                $this->plugin->txt('section_legal_info')
            );
        }

        $form = $ui_factory->input()->container()->form()->standard($form_action, $sections);

        return $form;
    }

    protected function saveForm(array $form_data, bool $a_create): bool
    {
        $chat_id = '';
        if ($a_create) {
            $chat_id = uniqid('chat_', true);
        } else {
            $properties = $this->getProperties();
            $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        }

        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $page_info = $this->getPageInfo();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);

            $chat_config->setChatId($chat_id);
            $chat_config->setPageId((int) ($page_info['page_id'] ?? 0));
            $chat_config->setParentId((int) ($page_info['parent_id'] ?? 0));
            $chat_config->setParentType($page_info['parent_type'] ?? '');
            $chat_config->setTitle($form_data['chat_title'] ?? '');
            $chat_config->setSystemPrompt($form_data['system_prompt'] ?? '');

            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                $ai_service = $form_data['ai_service'] ?? \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            }
            $chat_config->setAiService($ai_service);

            $chat_config->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chat_config->setCharLimit((int) ($form_data['char_limit'] ?? 2000));

            $chat_config->setIsOnline((bool) ($form_data['is_online'] ?? true));
            $chat_config->setPersistent((bool) ($form_data['persistent'] ?? true));
            // The page content is added as resource; chats from before keep their setting
            if ($a_create) {
                $chat_config->setIncludePageContext(false);
            }
            // Chat uploads and streaming can only be enabled if they are enabled globally
            $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
            if ($file_handling_enabled && $chat_uploads_globally_enabled) {
                $chat_config->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            } else {
                $chat_config->setEnableChatUploads(false);
            }
            $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';
            if ($streaming_globally_enabled) {
                $chat_config->setEnableStreaming((bool) ($form_data['enable_streaming'] ?? true));
            } else {
                $chat_config->setEnableStreaming(false);
            }

            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

            $ai_service = $chat_config->getAiService();
            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
            if ($llm === null) {
                $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
                $first_service = !empty($available_services) ? array_key_first($available_services) : null;
                $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
            }

            $service_supports_rag = $llm->supportsRAG();

            $rag_config_key = $ai_service . '_enable_rag';
            $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
            $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

            $rag_was_enabled = $chat_config->isEnableRag();
            $rag_now_enabled = false;

            if ($service_supports_rag && $rag_globally_enabled) {
                $rag_data = $form_data['enable_rag'] ?? null;
                $rag_now_enabled = is_array($rag_data);
                $chat_config->setEnableRag($rag_now_enabled);
                if ($rag_now_enabled) {
                    $chat_config->setShowSources((bool) ($rag_data['show_sources'] ?? false));
                    $chat_config->setAllowSourceDownloads((bool) ($rag_data['allow_source_downloads'] ?? false));
                }
            }
            // Otherwise keep the stored value: RAG may be temporarily unavailable (e.g. RAG
            // service not configured yet) and is checked again for every message

            $chat_config->setDisclaimer($form_data['disclaimer'] ?? '');

            $result = $chat_config->save();

            // When RAG is switched on, existing background files are uploaded to the RAG
            if ($result && !$rag_was_enabled && $rag_now_enabled) {
                $this->logger->info("RAG was activated, syncing background files", ['chat_id' => $chat_id]);
                try {
                    $sync_stats = $llm->syncBackgroundFilesToRAG($chat_config);
                    $this->logger->info("Background files RAG sync completed", $sync_stats);

                    if ($sync_stats['uploaded'] > 0) {
                        $this->tpl->setOnScreenMessage('info', sprintf(
                            $this->plugin->txt('rag_sync_success'),
                            $sync_stats['uploaded']
                        ), true);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Background files RAG sync failed", ['error' => $e->getMessage()]);
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('rag_sync_error'), true);
                }
            }

            if ($result) {
                // The page element references the chat via these properties
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

    public function cancel()
    {
        $this->returnToParent();
    }

    private function getChatIdForEdit(): string
    {
        $properties = $this->getProperties();
        return $properties['chat_id'] ?? '';
    }

    private function addEditTabs(string $active): void
    {
        global $DIC;
        $tabs = $DIC->tabs();
        $tabs->addTab(
            'basic',
            $this->plugin->txt('tab_settings'),
            $this->ctrl->getLinkTarget($this, 'showBasicTab')
        );
        $tabs->addTab(
            'resources',
            $this->plugin->txt('tab_resources'),
            $this->ctrl->getLinkTarget($this, 'showResourcesTab')
        );
        $tabs->addTab(
            'advanced',
            $this->plugin->txt('tab_advanced_settings'),
            $this->ctrl->getLinkTarget($this, 'showAdvancedTab')
        );
        $tabs->addTab(
            'statistics',
            $this->plugin->txt('tab_statistics'),
            $this->ctrl->getLinkTarget($this, 'showStatisticsTab')
        );
        $tabs->setTabActive($active);
    }

    public function showBasicTab(): void
    {
        global $DIC;
        $this->addEditTabs('basic');
        $form = $this->initForm(false, 'basic');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    public function saveBasic(): void
    {
        global $DIC;
        $form = $this->initForm(false, 'basic');
        if ($DIC->http()->request()->getMethod() === 'POST') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($form->getError() !== null) {
                $this->addEditTabs('basic');
                $this->tpl->setContent($DIC->ui()->renderer()->render($form));
                return;
            }
            if ($data !== null && $this->saveBasicFormData(array_merge(...array_map(fn($s) => is_array($s) ? $s : [], $data)))) {
                $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
                $this->ctrl->redirect($this, 'showBasicTab');
                return;
            }
        }
        $this->addEditTabs('basic');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    private function saveBasicFormData(array $form_data): bool
    {
        $chat_id = $this->getChatIdForEdit();
        if (empty($chat_id)) {
            return false;
        }

        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';

        $page_info = $this->getPageInfo();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            $chat_config->setChatId($chat_id);
            $chat_config->setPageId((int) ($page_info['page_id'] ?? 0));
            $chat_config->setParentId((int) ($page_info['parent_id'] ?? 0));
            $chat_config->setParentType($page_info['parent_type'] ?? '');
            $chat_config->setTitle($form_data['chat_title'] ?? '');
            $chat_config->setSystemPrompt($form_data['system_prompt'] ?? '');

            $chat_config->setIsOnline((bool) ($form_data['is_online'] ?? true));
            $chat_config->setPersistent((bool) ($form_data['persistent'] ?? true));

            $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
            if ($file_handling_enabled && $chat_uploads_globally_enabled) {
                $chat_config->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            } else {
                $chat_config->setEnableChatUploads(false);
            }

            $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';
            if ($streaming_globally_enabled) {
                $chat_config->setEnableStreaming((bool) ($form_data['enable_streaming'] ?? true));
            } else {
                $chat_config->setEnableStreaming(false);
            }

            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

            $ai_service = $chat_config->getAiService() ?: (\platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses');

            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
            if ($llm === null) {
                $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
                $first_service = !empty($available_services) ? array_key_first($available_services) : null;
                $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
            }

            $service_supports_rag = $llm ? $llm->supportsRAG() : false;
            $rag_config_key = $ai_service . '_enable_rag';
            $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
            $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

            $rag_was_enabled = $chat_config->isEnableRag();
            $rag_now_enabled = false;

            if ($service_supports_rag && $rag_globally_enabled) {
                $rag_data = $form_data['enable_rag'] ?? null;
                $rag_now_enabled = is_array($rag_data);
                $chat_config->setEnableRag($rag_now_enabled);
                if ($rag_now_enabled) {
                    $chat_config->setShowSources((bool) ($rag_data['show_sources'] ?? false));
                    $chat_config->setAllowSourceDownloads((bool) ($rag_data['allow_source_downloads'] ?? false));
                }
            } else {
                $chat_config->setEnableRag(false);
            }

            $result = $chat_config->save();

            if ($result && !$rag_was_enabled && $rag_now_enabled && $llm) {
                $this->logger->info("RAG was activated, syncing background files", ['chat_id' => $chat_id]);
                try {
                    $sync_stats = $llm->syncBackgroundFilesToRAG($chat_config);
                    if ($sync_stats['uploaded'] > 0) {
                        $this->tpl->setOnScreenMessage('info', sprintf($this->plugin->txt('rag_sync_success'), $sync_stats['uploaded']), true);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Background files RAG sync failed", ['error' => $e->getMessage()]);
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('rag_sync_error'), true);
                }
            }

            if ($result) {
                $properties = $this->getProperties();
                $properties['chat_id'] = $chat_id;
                $properties['chat_title'] = $form_data['chat_title'] ?? '';
                return (bool) $this->updateElement($properties);
            }

            return false;

        } catch (\Throwable $e) {
            $this->logger->warning("Exception in saveBasicFormData", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function showAdvancedTab(): void
    {
        global $DIC;
        $this->addEditTabs('advanced');
        $form = $this->buildAdvancedForm();
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    public function saveAdvanced(): void
    {
        global $DIC;
        $form = $this->buildAdvancedForm();
        if ($DIC->http()->request()->getMethod() === 'POST') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($form->getError() !== null) {
                $this->addEditTabs('advanced');
                $this->tpl->setContent($DIC->ui()->renderer()->render($form));
                return;
            }
            if ($data !== null && $this->saveAdvancedFormData($data)) {
                $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
                $this->ctrl->redirect($this, 'showAdvancedTab');
                return;
            }
        }
        $this->addEditTabs('advanced');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    private function buildAdvancedForm()
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $defaults = $this->getAIChatDefaults();

        $prop = [];
        $chat_id = $this->getChatIdForEdit();
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if ($chat_config->exists()) {
                    $prop = [
                        'max_memory' => $chat_config->getMaxMemory(),
                        'char_limit' => $chat_config->getCharLimit(),
                        'disclaimer' => $chat_config->getDisclaimer(),
                        'temperature' => $chat_config->getTemperature(),
                        'ai_service' => $chat_config->getAiService(),
                        'model' => $chat_config->getModel(),
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Error loading ChatConfig for advanced form", ['error' => $e->getMessage()]);
            }
        }

        // AI service
        $default_ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $force_default_svc = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        $current_ai_service = $prop['ai_service'] ?? $default_ai_service;
        if (!isset($service_options[$current_ai_service])) {
            $current_ai_service = array_key_first($service_options) ?: $default_ai_service;
        }

        if ($force_default_svc !== '1') {
            $ai_service_field = $ui_factory->input()->field()->select(
                $this->plugin->txt('ai_service_label'),
                $service_options,
                $this->plugin->txt('ai_service_info')
            )->withDedicatedName('ai_service')->withValue($current_ai_service);
        } else {
            $ai_service_field = null;
        }

        // Model
        $force_model_key = $current_ai_service . '_force_model';
        $force_model = \platform\AIChatPageComponentConfig::get($force_model_key) === '1';
        $current_model = $prop['model'] ?? null;

        if ($force_model) {
            $model_field = null;
        } else {
            $global_default_model = \platform\AIChatPageComponentConfig::get($current_ai_service . '_selected_model') ?: '';
            // Only models the administrator offers to editors
            $service_class = \ai\AIChatPageComponentLLMRegistry::getServiceClass($current_ai_service);
            $cached_models = $service_class ? $service_class::getAvailableModels() : [];
            $model_value = $current_model ?? $global_default_model;
            $model_info = $this->plugin->txt('model_override_info')
                . ($global_default_model ? ' (' . $this->plugin->txt('default_label') . ': ' . ($cached_models[$global_default_model] ?? $global_default_model) . ')' : '');
            if (is_array($cached_models) && !empty($cached_models)) {
                $model_field = $ui_factory->input()->field()->select(
                    $this->plugin->txt('model_override_label'),
                    $cached_models,
                    $model_info
                )->withDedicatedName('model_override');
                if ($model_value && isset($cached_models[$model_value])) {
                    $model_field = $model_field->withValue($model_value);
                }
            } else {
                $model_field = $ui_factory->input()->field()->text(
                    $this->plugin->txt('model_override_label'),
                    $model_info
                )->withDedicatedName('model_override')->withValue((string) $model_value);
            }
        }

        // Memory and character limit
        $max_memory = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('max_memory_label'),
            $this->plugin->txt('max_memory_info')
        )->withDedicatedName('max_memory')
         ->withValue((int) ($prop['max_memory'] ?? $defaults['max_memory_messages']));

        $char_limit = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('char_limit_label'),
            $this->plugin->txt('char_limit_info')
        )->withDedicatedName('char_limit')
         ->withValue((int) ($prop['char_limit'] ?? $defaults['characters_limit']));

        // Temperature
        $force_temp_key = $current_ai_service . '_force_temperature';
        $force_temperature = \platform\AIChatPageComponentConfig::get($force_temp_key) === '1';
        $temp_override_value = $prop['temperature'] ?? null;
        $temp_display = $temp_override_value !== null
            ? rtrim(rtrim(number_format((float) $temp_override_value, 2, '.', ''), '0'), '.')
            : '';

        if ($force_temperature) {
            $temperature_override = null;
        } else {
            $global_default_temp = \platform\AIChatPageComponentConfig::get($current_ai_service . '_temperature') ?: '0.7';
            $temp_value = $temp_override_value !== null ? $temp_display : (string) $global_default_temp;
            $temp_info = $this->plugin->txt('temperature_override_info')
                . ' (' . $this->plugin->txt('default_label') . ': ' . $global_default_temp . ')';
            $temperature_override = $ui_factory->input()->field()->text(
                $this->plugin->txt('temperature_override_label'),
                $temp_info
            )->withDedicatedName('temperature_override')->withMaxLength(10)->withValue($temp_value);
        }

        // Disclaimer
        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')
         ->withMaxLimit(4000)
         ->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        $form_fields_adv = [];
        if ($ai_service_field !== null) {
            $form_fields_adv['ai_service'] = $ai_service_field;
        }
        if ($model_field !== null) {
            $form_fields_adv['model_override'] = $model_field;
        }
        $form_fields_adv['max_memory'] = $max_memory;
        $form_fields_adv['char_limit'] = $char_limit;
        if ($temperature_override !== null) {
            $form_fields_adv['temperature_override'] = $temperature_override;
        }
        $form_fields_adv['disclaimer'] = $disclaimer;

        return $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveAdvanced'),
            $form_fields_adv
        );
    }

    private function saveAdvancedFormData(array $form_data): bool
    {
        $chat_id = $this->getChatIdForEdit();
        if (empty($chat_id)) {
            return false;
        }

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                return false;
            }
            $force_default_svc = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_svc === '1') {
                $chat_config->setAiService(\platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses');
            } elseif (!empty($form_data['ai_service'])) {
                $chat_config->setAiService($form_data['ai_service']);
            }

            $ai_service_id = $chat_config->getAiService();
            $force_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_model') === '1';
            if ($force_model) {
                $chat_config->setModel(null);
            } else {
                $model_value = $form_data['model_override'] ?? null;
                $model_value = is_string($model_value) && $model_value !== '' ? $model_value : null;
                // NULL keeps the chat linked to the global default
                $global_default_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_selected_model') ?: '';
                if ($model_value === $global_default_model) {
                    $model_value = null;
                }
                $chat_config->setModel($model_value);
            }

            $chat_config->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chat_config->setCharLimit((int) ($form_data['char_limit'] ?? 2000));

            $force_temperature = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_temperature') === '1';
            if ($force_temperature) {
                $chat_config->setTemperature(null);
            } else {
                $raw = str_replace(',', '.', (string) ($form_data['temperature_override'] ?? ''));
                $temp_value = is_numeric($raw) ? max(0.0, min(2.0, (float) $raw)) : null;
                // NULL keeps the chat linked to the global default
                $global_default_temp = (float) (\platform\AIChatPageComponentConfig::get($ai_service_id . '_temperature') ?: 0.7);
                if ($temp_value !== null && abs($temp_value - $global_default_temp) < 0.001) {
                    $temp_value = null;
                }
                $chat_config->setTemperature($temp_value);
            }

            $chat_config->setDisclaimer($form_data['disclaimer'] ?? '');
            return (bool) $chat_config->save();
        } catch (\Exception $e) {
            $this->logger->warning("Exception in saveAdvancedFormData", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function showStatisticsTab(): void
    {
        global $DIC;
        $this->addEditTabs('statistics');

        $chat_id = $this->getChatIdForEdit();
        $ui_factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        if (empty($chat_id)) {
            $this->tpl->setContent($renderer->render(
                $ui_factory->messageBox()->info($this->plugin->txt('stat_no_sessions'))
            ));
            return;
        }

        $db = $DIC->database();

        $res = $db->queryF(
            "SELECT COUNT(*) AS session_count, MAX(last_activity) AS last_activity
               FROM pcaic_sessions WHERE chat_id = %s",
            ['text'],
            [$chat_id]
        );
        $row = $db->fetchAssoc($res) ?? [];
        $session_count = (int) ($row['session_count'] ?? 0);
        $last_activity = $row['last_activity'] ?? null;

        $res = $db->queryF(
            "SELECT COUNT(*) AS message_count
               FROM pcaic_messages m
               JOIN pcaic_sessions s ON m.session_id = s.session_id
              WHERE s.chat_id = %s",
            ['text'],
            [$chat_id]
        );
        $msg_row = $db->fetchAssoc($res) ?? [];
        $message_count = (int) ($msg_row['message_count'] ?? 0);

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            $bg_count = $chat_config->exists() ? count($chat_config->getBackgroundFiles()) : 0;
        } catch (\Exception $e) {
            $bg_count = 0;
        }

        $last_activity_str = $last_activity
            ? $this->formatStoredDateTime($last_activity)
            : '–';

        $listing = $ui_factory->listing()->descriptive([
            $this->plugin->txt('stat_sessions') => (string) $session_count,
            $this->plugin->txt('stat_messages') => (string) $message_count,
            $this->plugin->txt('stat_last_activity') => $last_activity_str,
            $this->plugin->txt('stat_background_files') => (string) $bg_count,
        ]);
        $file_status_panel = $this->getBackgroundFileStatusPanel($chat_id);

        $panel = $ui_factory->panel()->standard(
            $this->plugin->txt('tab_statistics'),
            $listing
        );

        $html = '';
        if ($session_count > 0) {
            $clear_btn = $ui_factory->button()->standard(
                $this->plugin->txt('stat_clear_history'),
                $this->ctrl->getLinkTarget($this, 'clearHistory')
            );
            $html .= '<div style="margin-bottom:1rem">' . $renderer->render($clear_btn) . '</div>';
        }
        $html .= $renderer->render($panel);
        if ($file_status_panel !== null) {
            $html .= $renderer->render($file_status_panel);
        }

        $this->tpl->setContent($html);
    }

    /**
     * Panel with the background files of the chat and their processing state in the RAG
     *
     * The state is queried from the RAG service before (at most once per minute and
     * chat, see AIChatPageComponentRAGStatus::refreshChat()).
     *
     * @param bool $include_chat_uploads Also list the numbers of files users uploaded in the chat
     * @param bool $pending Set to true if a background file is still waiting for or in processing
     */
    private function getBackgroundFileStatusPanel(
        string $chat_id,
        bool $include_chat_uploads = true,
        bool &$pending = false
    ): ?\ILIAS\UI\Component\Panel\Standard {
        global $DIC;
        $db = $DIC->database();
        $ui_factory = $DIC->ui()->factory();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                return null;
            }
            // The service that processes the files, as in the chat and the cron job
            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance(
                \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService())
            );
            // As in the cron job: without file handling no file is uploaded to the RAG
            $rag_enabled = $llm !== null && $llm->isRagEnabledForChat($chat_config)
                && $llm->isFileHandlingEnabledForService($llm::getServiceId());
            if ($rag_enabled) {
                \ai\AIChatPageComponentRAGStatus::refreshChat($chat_id);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Processing state of background files not available", ['error' => $e->getMessage()]);
            return null;
        }

        $result = $db->query(
            "SELECT id, rag_status, rag_status_error, rag_retry_at FROM pcaic_attachments"
            . " WHERE chat_id = " . $db->quote($chat_id, 'text') . " AND background_file = 1 ORDER BY timestamp ASC"
        );

        // Files by processing state; problems first
        $groups = array_fill_keys([
            'rag_file_status_failed',
            'rag_file_status_empty',
            'rag_file_status_scanned',
            'rag_file_status_processing',
            'rag_file_status_pending',
            'rag_file_status_completed',
            'rag_file_status_direct',
        ], []);
        while ($row = $db->fetchAssoc($result)) {
            $title = (new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']))->getTitle();
            $status = $this->getRagFileStatusKey($rag_enabled, $row['rag_status'] ?? null);
            if ($status === 'rag_file_status_failed') {
                $title .= $this->getRagFailureDetails($row);
            }
            $groups[$status][] = $title;
        }

        $pending = $groups['rag_file_status_processing'] !== [] || $groups['rag_file_status_pending'] !== [];

        $items = [];
        foreach ($groups as $status => $titles) {
            if ($titles !== []) {
                $label = $this->plugin->txt($status) . ' (' . count($titles) . ')';
                $items[$label] = $ui_factory->listing()->unordered($titles);
            }
        }

        // Files uploaded by users in the chat: numbers only, the files belong to the users
        if ($rag_enabled && $include_chat_uploads) {
            $upload_states = [];
            foreach (\ai\AIChatPageComponentRAGStatus::countChatUploadStates($chat_id) as $state => $count) {
                $upload_states[] = $this->plugin->txt($this->getRagFileStatusKey(true, $state)) . ' (' . $count . ')';
            }
            if ($upload_states !== []) {
                $items[$this->plugin->txt('stat_chat_uploads')] = $ui_factory->listing()->unordered($upload_states);
            }
        }

        if ($items === []) {
            return null;
        }

        if ($rag_enabled) {
            $checked = $db->fetchAssoc($db->query(
                "SELECT rag_status_checked_at FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text')
            ));
            if (!empty($checked['rag_status_checked_at'])) {
                $items[$this->plugin->txt('stat_rag_checked_at')] =
                    $this->formatStoredDateTime($checked['rag_status_checked_at']);
            }
        }

        return $ui_factory->panel()->standard(
            $this->plugin->txt('stat_background_files'),
            $ui_factory->listing()->descriptive($items)
        );
    }

    /**
     * Date and time stored by the plugin (UTC) in the time zone and format of the user
     */
    private function formatStoredDateTime(string $value): string
    {
        return \ilDatePresentation::formatDate(new \ilDateTime($value, IL_CAL_DATETIME, 'UTC'));
    }

    /**
     * Language key of the processing state of a background file
     */
    private function getRagFileStatusKey(bool $rag_enabled, ?string $rag_status): string
    {
        if (!$rag_enabled) {
            return 'rag_file_status_direct';
        }

        switch ($rag_status) {
            case \ai\AIChatPageComponentRAGStatus::COMPLETED:
                return 'rag_file_status_completed';
            case \ai\AIChatPageComponentRAGStatus::PROCESSING:
                return 'rag_file_status_processing';
            case \ai\AIChatPageComponentRAGStatus::FAILED:
                return 'rag_file_status_failed';
            case \ai\AIChatPageComponentRAGStatus::EMPTY:
                return 'rag_file_status_empty';
            case \ai\AIChatPageComponentRAGStatus::SCANNED:
                return 'rag_file_status_scanned';
            case \ai\AIChatPageComponentRAGStatus::SKIPPED:
                return 'rag_file_status_direct';
            default:
                return 'rag_file_status_pending';
        }
    }

    /**
     * Time of the next attempt and error message of a failed background file
     *
     * @param array $row rag_status_error and rag_retry_at of the attachment
     */
    private function getRagFailureDetails(array $row): string
    {
        $details = [];
        if (!empty($row['rag_retry_at'])) {
            // The new upload is triggered by the next chat request or the cron job
            $details[] = $row['rag_retry_at'] <= gmdate('Y-m-d H:i:s')
                ? $this->plugin->txt('rag_file_retry_due')
                : sprintf($this->plugin->txt('rag_file_retry_at'), $this->formatStoredDateTime($row['rag_retry_at']));
        } else {
            // Rejected by the RAG or failed too often
            $details[] = $this->plugin->txt('rag_file_no_retry');
        }
        if (!empty($row['rag_status_error'])) {
            $details[] = mb_substr((string) $row['rag_status_error'], 0, 160);
        }
        return $details === [] ? '' : ' – ' . implode('; ', $details);
    }

    public function clearHistory(): void
    {
        $chat_id = $this->getChatIdForEdit();
        if (!empty($chat_id)) {
            try {
                $this->plugin->clearChatHistory($chat_id);
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('stat_history_cleared'), true);
            } catch (\Exception $e) {
                $this->tpl->setOnScreenMessage('failure', $e->getMessage(), true);
            }
        }
        $this->ctrl->redirect($this, 'showStatisticsTab');
    }

    /**
     * @param string $a_mode edit, presentation, print, preview or offline
     */
    public function getElementHTML(string $a_mode, array $a_properties, string $a_plugin_version): string
    {
        // Editors see a preview in the page editor
        if ($a_mode === 'edit') {
            return $this->renderEditPlaceholder($a_properties);
        }

        if (!$this->currentUserCanReadParent()) {
            return '';
        }

        // Chat deleted in the statistics tab: render nothing
        $chat_id = $a_properties['chat_id'] ?? '';
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if (!$chat_config->exists()) {
                    return '';
                }
            } catch (\Exception $e) {
                return '';
            }
        }

        $is_online = $this->isChatOnline($chat_id);

        if (!$is_online) {
            // Offline chats are shown to editors with a banner and hidden from learners
            if ($this->currentUserCanWriteParent()) {
                $a_properties['show_offline_banner'] = true;
                return $this->renderChatInterface($a_properties);
            }
            return '';
        }

        return $this->renderChatInterface($a_properties);
    }

    /**
     * Chats without stored configuration count as online
     */
    private function isChatOnline(string $chat_id): bool
    {
        if (empty($chat_id)) {
            return true;
        }
        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            return !$chat_config->exists() || $chat_config->isOnline();
        } catch (\Exception $e) {
            return true;
        }
    }

    private function currentUserCanWriteParent(): bool
    {
        global $DIC;

        $parent_id = (int) ($this->plugin->getParentId() ?? 0);
        if ($parent_id <= 0) {
            return false;
        }

        $refs = ilObject::_getAllReferences($parent_id);
        if (empty($refs)) {
            $refs = [$parent_id];
        }

        foreach ($refs as $ref_id) {
            if ($DIC->access()->checkAccess('write', '', (int) $ref_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read permission on the object containing the page; anonymous users need
     * anonymous access to be enabled in the plugin configuration
     */
    private function currentUserCanReadParent(): bool
    {
        global $DIC;

        if ($DIC->user()->isAnonymous()) {
            $allow_anonymous = (\platform\AIChatPageComponentConfig::get('allow_anonymous_access') === '1');
            if (!$allow_anonymous) {
                return false;
            }
        }

        $parent_id = (int) ($this->plugin->getParentId() ?? 0);
        if ($parent_id <= 0) {
            return true; // Element not yet placed on a page of an object
        }

        $refs = ilObject::_getAllReferences($parent_id);
        if (empty($refs)) {
            $refs = [$parent_id]; // Some page types store the ref_id
        }

        foreach ($refs as $ref_id) {
            if ($DIC->access()->checkAccess('read', '', (int) $ref_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preview of the chat in the page editor with demo messages
     */
    private function renderEditPlaceholder(array $properties): string
    {
        $tpl = new ilTemplate(
            "tpl.ai_chat_placeholder.html",
            true,
            true,
            $this->plugin->getDirectory()
        );

        $chat_id = $properties['chat_id'] ?? '';
        $config_properties = $properties;

        $is_orphaned = false;
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if ($chat_config->exists()) {
                    $config_properties = [
                        'chat_title' => $chat_config->getTitle(),
                        'system_prompt' => $chat_config->getSystemPrompt(),
                        'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                        'disclaimer' => $chat_config->getDisclaimer()
                    ];
                } else {
                    $is_orphaned = true;
                }
            } catch (\Exception $e) {
                $is_orphaned = true;
            }
        }

        $chat_title = htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title'));
        $tpl->setVariable("CHAT_TITLE", $chat_title);
        $tpl->setVariable("CHAT_ARIA_LABEL", sprintf($this->plugin->txt('chat_aria_label'), $chat_title));

        $tpl->setVariable("EDIT_MODE_LABEL", $this->plugin->txt('edit_mode_label'));
        $tpl->setVariable("CLICK_TO_EDIT_HINT", $this->plugin->txt('click_to_edit_hint'));

        // The chat was deleted in the statistics tab; editors should remove the element
        if ($is_orphaned) {
            $tpl->setCurrentBlock("placeholder_orphaned_badge");
            $tpl->setVariable("ORPHANED_BADGE_LABEL", $this->plugin->txt('chat_orphaned_title'));
            $tpl->parseCurrentBlock();
        }

        $chat_id_for_status = $properties['chat_id'] ?? '';
        if (!$is_orphaned && !$this->isChatOnline($chat_id_for_status)) {
            $tpl->setCurrentBlock("placeholder_offline_badge");
            $tpl->setVariable("OFFLINE_BADGE_LABEL", $this->plugin->txt('chat_offline_badge'));
            $tpl->parseCurrentBlock();
        }

        $system_prompt = $config_properties['system_prompt'] ?? '';
        $demo_user_message = $this->plugin->txt('demo_user_message');
        $demo_assistant_message = $this->getDemoAssistantMessage($system_prompt);

        $tpl->setVariable("DEMO_USER_MESSAGE", htmlspecialchars($demo_user_message));
        $tpl->setVariable("DEMO_ASSISTANT_MESSAGE", htmlspecialchars($demo_assistant_message));

        $tpl->setVariable("INPUT_PLACEHOLDER", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("CLEAR_CHAT_LABEL", $this->plugin->txt('clear_chat_label'));
        $tpl->setVariable("THEME_TOGGLE_TITLE", htmlspecialchars($this->plugin->txt('theme_toggle_title')));
        $tpl->setVariable("THEME_TOGGLE_LABEL", htmlspecialchars($this->plugin->txt('theme_toggle_label')));
        $tpl->setVariable("ATTACH_FILE_TITLE", $this->plugin->txt('attach_file_title'));

        $tpl->setVariable("COPY_MESSAGE_TITLE", htmlspecialchars($this->plugin->txt('copy_message_title')));
        $tpl->setVariable("REGENERATE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('regenerate_response_title')));

        if (!empty($config_properties['disclaimer'])) {
            $tpl->setCurrentBlock("disclaimer");
            $tpl->setVariable("DISCLAIMER", htmlspecialchars($config_properties['disclaimer']));
            $tpl->parseCurrentBlock();
        }

        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);

        if ($is_chat_uploads_enabled) {
            $tpl->setCurrentBlock("chat_attach_button_preview");
            $tpl->parseCurrentBlock();
        }

        $this->addChatAssets();

        return $tpl->get();
    }

    /**
     * Labels used when rendering answers (code blocks, tables, highlight boxes)
     */
    private function setRenderingLabels(ilTemplate $tpl): void
    {
        $tpl->setVariable("TABLE_COPY", htmlspecialchars($this->plugin->txt('table_copy')));
        $tpl->setVariable("TABLE_EXPORT_CSV", htmlspecialchars($this->plugin->txt('table_export_csv')));
        $tpl->setVariable("CODE_COPY", htmlspecialchars($this->plugin->txt('code_copy')));
        $tpl->setVariable("ALERT_NOTE", htmlspecialchars($this->plugin->txt('alert_note')));
        $tpl->setVariable("ALERT_TIP", htmlspecialchars($this->plugin->txt('alert_tip')));
        $tpl->setVariable("ALERT_IMPORTANT", htmlspecialchars($this->plugin->txt('alert_important')));
        $tpl->setVariable("ALERT_WARNING", htmlspecialchars($this->plugin->txt('alert_warning')));
        $tpl->setVariable("ALERT_CAUTION", htmlspecialchars($this->plugin->txt('alert_caution')));
    }

    /**
     * Demo answer for the preview, chosen by keywords of the system prompt
     */
    private function getDemoAssistantMessage(string $system_prompt): string
    {
        $default = $this->plugin->txt('demo_assistant_message');

        if (empty($system_prompt)) {
            return $default;
        }

        $system_lower = strtolower($system_prompt);

        if (strpos($system_lower, 'tutor') !== false || strpos($system_lower, 'lehrer') !== false || strpos($system_lower, 'teacher') !== false) {
            return $this->plugin->txt('demo_assistant_tutor') ?: $default;
        }

        if (strpos($system_lower, 'code') !== false || strpos($system_lower, 'programming') !== false || strpos($system_lower, 'programmier') !== false) {
            return $this->plugin->txt('demo_assistant_coding') ?: $default;
        }

        if (strpos($system_lower, 'creative') !== false || strpos($system_lower, 'kreativ') !== false || strpos($system_lower, 'story') !== false) {
            return $this->plugin->txt('demo_assistant_creative') ?: $default;
        }

        return $default;
    }

    private function renderChatInterface(array $properties): string
    {
        $tpl = new ilTemplate(
            "tpl.ai_chat.html",
            true,
            true,
            $this->plugin->getDirectory()
        );

        $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        $config_properties = $properties;

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if ($chat_config->exists()) {
                // The page may have been moved since the chat was created
                $this->updateChatConfigPageContext($chat_config);

                $config_properties = [
                    'chat_id' => $chat_id,
                    'chat_title' => $chat_config->getTitle(),
                    'system_prompt' => $chat_config->getSystemPrompt(),
                    'ai_service' => $chat_config->getAiService(),
                    'max_memory' => $chat_config->getMaxMemory(),
                    'char_limit' => $chat_config->getCharLimit(),
                    'persistent' => $chat_config->isPersistent(),
                    'include_page_context' => $chat_config->isIncludePageContext(),
                    'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                    'enable_streaming' => $chat_config->isEnableStreaming(),
                    'disclaimer' => $chat_config->getDisclaimer(),
                    'background_files' => json_encode($chat_config->getBackgroundFiles()),
                    // Flags set by the caller (e.g. offline) are kept
                    'show_offline_banner' => $properties['show_offline_banner'] ?? false,
                ];
            } else {
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error loading ChatConfig for rendering", ['error' => $e->getMessage()]);
        }

        $service_unavailable = empty(\ai\AIChatPageComponentLLMRegistry::getEnabledServices());

        $container_id = 'ai-chat-' . md5($chat_id);
        $messages_id = $container_id . '-messages';

        $tpl->setVariable("CONTAINER_ID", $container_id);
        $tpl->setVariable("MESSAGES_ID", $messages_id);
        $tpl->setVariable("CHAT_ID", htmlspecialchars($chat_id));

        $tpl->setVariable("CHAT_TITLE", htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title')));

        $tpl->setVariable("WELCOME_MESSAGE", $this->plugin->txt('welcome_message'));
        $tpl->setVariable("INPUT_PLACEHOLDER", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_BUTTON_TEXT", $this->plugin->txt('send_button_text'));
        $tpl->setVariable("LOADING_TEXT", $this->plugin->txt('loading_text'));
        $tpl->setVariable("CHAR_LIMIT", (int) ($config_properties['char_limit'] ?? 2000));

        $tpl->setVariable("ATTACHMENTS_LABEL", $this->plugin->txt('attachments_label'));
        $tpl->setVariable("CLEAR_ATTACHMENTS_TITLE", $this->plugin->txt('clear_attachments_title'));
        $tpl->setVariable("ATTACH_FILE_TITLE", $this->plugin->txt('attach_file_title'));
        $tpl->setVariable("ATTACH_FILE_LABEL", $this->plugin->txt('attach_file_label'));
        $tpl->setVariable("INPUT_ARIA_LABEL", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_ARIA_LABEL", $this->plugin->txt('send_aria_label'));
        $tpl->setVariable("SEND_DISABLED_PROCESSING", htmlspecialchars($this->plugin->txt('send_disabled_processing')));
        $tpl->setVariable("FILE_INPUT_ARIA_LABEL", $this->plugin->txt('file_input_aria_label'));

        $chat_title = htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title'));
        $tpl->setVariable("SKIP_TO_INPUT", $this->plugin->txt('skip_to_input'));
        $tpl->setVariable("CHAT_ARIA_LABEL", sprintf($this->plugin->txt('chat_aria_label'), $chat_title));
        $tpl->setVariable("MESSAGES_ARIA_LABEL", $this->plugin->txt('messages_aria_label'));
        $tpl->setVariable("ATTACHMENTS_ARIA_LABEL", $this->plugin->txt('attachments_aria_label'));
        $tpl->setVariable("ACTIONS_ARIA_LABEL", $this->plugin->txt('actions_aria_label'));
        $tpl->setVariable("NEW_MESSAGE_ARIA", $this->plugin->txt('new_message_aria'));

        $tpl->setVariable("CLEAR_CHAT_TEXT", $this->plugin->txt('clear_chat_text'));
        $tpl->setVariable("CLEAR_CHAT_TITLE", $this->plugin->txt('clear_chat_title'));
        $tpl->setVariable("CLEAR_CHAT_LABEL", $this->plugin->txt('clear_chat_label'));
        $tpl->setVariable("THEME_TOGGLE_TITLE", htmlspecialchars($this->plugin->txt('theme_toggle_title')));
        $tpl->setVariable("THEME_TOGGLE_LABEL", htmlspecialchars($this->plugin->txt('theme_toggle_label')));
        $tpl->setVariable("CLEAR_CHAT_CONFIRM", htmlspecialchars($this->plugin->txt('clear_chat_confirm')));

        $tpl->setVariable("COPY_MESSAGE_TITLE", htmlspecialchars($this->plugin->txt('copy_message_title')));
        $tpl->setVariable("REGENERATE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('regenerate_response_title')));

        $tpl->setVariable("MESSAGE_COPIED", htmlspecialchars($this->plugin->txt('message_copied')));
        $tpl->setVariable("MESSAGE_COPY_FAILED", htmlspecialchars($this->plugin->txt('message_copy_failed')));

        $tpl->setVariable("REMOVE_ATTACHMENT", htmlspecialchars($this->plugin->txt('remove_attachment')));
        $tpl->setVariable("THINKING_HEADER", htmlspecialchars($this->plugin->txt('thinking_header')));

        $tpl->setVariable("SOURCES_LABEL", htmlspecialchars($this->plugin->txt('sources_label')));
        $tpl->setVariable("RAG_INCOMPLETE_NOTICE", htmlspecialchars($this->plugin->txt('rag_incomplete_notice')));
        $tpl->setVariable("RAG_UPLOAD_PROCESSING", htmlspecialchars($this->plugin->txt('rag_upload_processing')));
        $tpl->setVariable("RAG_UPLOAD_COMPLETED", htmlspecialchars($this->plugin->txt('rag_upload_completed')));
        $tpl->setVariable("RAG_UPLOAD_FAILED", htmlspecialchars($this->plugin->txt('rag_upload_failed')));
        $tpl->setVariable("RAG_UPLOAD_REJECTED", htmlspecialchars($this->plugin->txt('rag_upload_rejected')));
        $tpl->setVariable("RAG_UPLOAD_EMPTY", htmlspecialchars($this->plugin->txt('rag_upload_empty')));
        $tpl->setVariable("RAG_UPLOAD_SCANNED", htmlspecialchars($this->plugin->txt('rag_upload_scanned')));
        // Short state shown in the file bubble; the long text above is its tooltip
        foreach (['processing', 'completed', 'failed', 'rejected', 'empty', 'scanned'] as $rag_state) {
            $tpl->setVariable(
                'RAG_UPLOAD_' . strtoupper($rag_state) . '_SHORT',
                htmlspecialchars($this->plugin->txt('rag_upload_' . $rag_state . '_short'))
            );
        }
        $tpl->setVariable("PAGE_LABEL", htmlspecialchars($this->plugin->txt('page_label')));
        $tpl->setVariable("PAGES_LABEL", htmlspecialchars($this->plugin->txt('pages_label')));
        $this->setRenderingLabels($tpl);
        $tpl->setVariable("CITATION_MORE_SOURCE", htmlspecialchars($this->plugin->txt('citation_more_source')));
        $tpl->setVariable("CITATION_MORE_SOURCES", htmlspecialchars($this->plugin->txt('citation_more_sources')));
        $tpl->setVariable("SOURCE_PREVIOUS", htmlspecialchars($this->plugin->txt('source_previous')));
        $tpl->setVariable("SOURCE_NEXT", htmlspecialchars($this->plugin->txt('source_next')));
        $tpl->setVariable("SOURCES_UNUSED", htmlspecialchars($this->plugin->txt('sources_unused')));
        $tpl->setVariable("SOURCE_PASSAGE", htmlspecialchars($this->plugin->txt('source_passage')));
        $tpl->setVariable("SOURCE_PASSAGE_CITED", htmlspecialchars($this->plugin->txt('source_passage_cited')));
        $tpl->setVariable("SOURCE_DOWNLOAD", htmlspecialchars($this->plugin->txt('source_download')));
        $tpl->setVariable("SOURCE_OPEN_IN_ILIAS", htmlspecialchars($this->plugin->txt('source_open_in_ilias')));
        $tpl->setVariable("SOURCE_OPEN_PAGE", htmlspecialchars($this->plugin->txt('source_open_page')));
        $tpl->setVariable("SOURCE_PASSAGES_TOGGLE", htmlspecialchars($this->plugin->txt('source_passages_toggle')));
        $tpl->setVariable("SOURCE_PASSAGES_ONE", htmlspecialchars($this->plugin->txt('source_passages_one')));
        $tpl->setVariable("SOURCE_PASSAGES_MANY", htmlspecialchars($this->plugin->txt('source_passages_many')));
        $tpl->setVariable("SCROLL_TO_BOTTOM", htmlspecialchars($this->plugin->txt('scroll_to_bottom')));

        $tpl->setVariable("MAX_FILE_SIZE_MB", FileUploadValidator::getMaxFileSizeMb());

        $max_attachments_config = \platform\AIChatPageComponentConfig::get('max_attachments_per_message');
        $max_attachments = $max_attachments_config ? (int) $max_attachments_config : 5;
        $tpl->setVariable("MAX_ATTACHMENTS_PER_MESSAGE", $max_attachments);

        // Upload error messages with the configured limits
        $error_max_attachments_template = $this->plugin->txt('error_max_attachments');
        $error_file_too_large_template = $this->plugin->txt('error_file_too_large');
        $error_file_type_not_allowed_template = $this->plugin->txt('error_file_type_not_allowed');
        $error_file_upload_failed_template = $this->plugin->txt('error_file_upload_failed');

        // txt() returns the key if the entry is missing
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

        $error_max_attachments = sprintf($error_max_attachments_template, $max_attachments);
        $error_file_too_large = sprintf($error_file_too_large_template, FileUploadValidator::getMaxFileSizeMb());

        // These messages are completed by JavaScript with runtime values
        $error_file_type_not_allowed = $error_file_type_not_allowed_template;
        $error_file_upload_failed = $error_file_upload_failed_template;

        $tpl->setVariable("ERROR_MAX_ATTACHMENTS", $error_max_attachments);
        $tpl->setVariable("ERROR_FILE_TOO_LARGE", $error_file_too_large);

        $max_total_upload_mb = (int) (\platform\AIChatPageComponentConfig::get('max_total_upload_size_mb') ?: 25);
        $tpl->setVariable("MAX_TOTAL_UPLOAD_SIZE_MB", $max_total_upload_mb);
        $tpl->setVariable(
            "ERROR_TOTAL_UPLOAD_TOO_LARGE",
            htmlspecialchars(sprintf($this->plugin->txt('error_total_upload_too_large'), $max_total_upload_mb))
        );
        $tpl->setVariable("ERROR_FILE_TYPE_NOT_ALLOWED", $error_file_type_not_allowed);
        $tpl->setVariable("ERROR_FILE_UPLOAD_FAILED", $error_file_upload_failed);

        global $DIC;
        $DIC->logger()->pcaic()->debug("Template: file size limit", [
            'effective_mb' => FileUploadValidator::getMaxFileSizeMb()
        ]);

        $tpl->setVariable("GENERATION_STOPPED", htmlspecialchars($this->plugin->txt('generation_stopped')));
        $tpl->setVariable("REGENERATE_FAILED", htmlspecialchars($this->plugin->txt('regenerate_failed')));
        $tpl->setVariable("WELCOME_MESSAGE", htmlspecialchars($this->plugin->txt('welcome_message')));
        $tpl->setVariable("STOP_GENERATION", htmlspecialchars($this->plugin->txt('stop_generation')));

        $tpl->setVariable("API_URL", htmlspecialchars($this->getAIChatApiUrl()));
        $tpl->setVariable("SYSTEM_PROMPT", htmlspecialchars($config_properties['system_prompt'] ?? 'You are a helpful AI assistant.'));
        $tpl->setVariable("MAX_MEMORY", (int) ($config_properties['max_memory'] ?? 10));
        // JavaScript expects '1' or '0'
        $persistent_value = ($config_properties['persistent'] ?? false);
        $is_persistent = ($persistent_value === true || $persistent_value === '1' || $persistent_value === 1);
        $tpl->setVariable("PERSISTENT", $is_persistent ? 'true' : 'false');
        $tpl->setVariable("AI_SERVICE", htmlspecialchars($config_properties['ai_service'] ?? 'default'));

        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);
        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');

        // Anonymous users can never upload
        $is_anonymous = $DIC->user()->isAnonymous();

        // Raw data panel for administrators (system role ID 2)
        $is_ilias_admin = $DIC->rbac()->review()->isAssigned($DIC->user()->getId(), SYSTEM_ROLE_ID);
        $tpl->setVariable("IS_ADMIN", $is_ilias_admin ? 'true' : 'false');

        $effective_chat_uploads_enabled = $is_chat_uploads_enabled && $chat_uploads_globally_enabled && !$is_anonymous;
        $tpl->setVariable("ENABLE_CHAT_UPLOADS", $effective_chat_uploads_enabled ? 'true' : 'false');
        $tpl->setVariable("IS_ANONYMOUS", $is_anonymous ? 'true' : 'false');
        $tpl->setVariable("SERVICE_UNAVAILABLE", $service_unavailable ? 'true' : 'false');
        $tpl->setVariable("NO_SERVICE_AVAILABLE", htmlspecialchars($this->plugin->txt('no_service_available')));

        $enable_streaming = ($config_properties['enable_streaming'] ?? true);
        $is_streaming_enabled = ($enable_streaming === true || $enable_streaming === '1' || $enable_streaming === 1);
        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        // Streaming must be enabled in the chat and globally
        $effective_streaming_enabled = $is_streaming_enabled && $streaming_globally_enabled;
        $tpl->setVariable("ENABLE_STREAMING", $effective_streaming_enabled ? 'true' : 'false');

        $page_info = $this->getPageInfo();
        $tpl->setVariable("PAGE_ID", (int) ($page_info['page_id'] ?? 0));
        $tpl->setVariable("PARENT_ID", (int) ($page_info['parent_id'] ?? 0));
        $tpl->setVariable("PARENT_TYPE", htmlspecialchars($page_info['parent_type'] ?? ''));
        $tpl->setVariable("INCLUDE_PAGE_CONTEXT", ($config_properties['include_page_context'] ?? true) ? 'true' : 'false');

        $background_files = $config_properties['background_files'] ?? '[]';
        if (is_array($background_files)) {
            $background_files = json_encode($background_files);
        }
        $tpl->setVariable("BACKGROUND_FILES", htmlspecialchars($background_files));

        if (!empty($config_properties['show_offline_banner'])) {
            $tpl->setCurrentBlock("chat_offline_badge");
            $tpl->setVariable("OFFLINE_BADGE_TEXT", $this->plugin->txt('chat_offline_badge'));
            $tpl->parseCurrentBlock();
        }

        if (!empty($config_properties['disclaimer'])) {
            $tpl->setCurrentBlock("disclaimer");
            $tpl->setVariable("DISCLAIMER", htmlspecialchars($config_properties['disclaimer']));
            $tpl->parseCurrentBlock();
        }

        if ($effective_chat_uploads_enabled) {
            $tpl->setCurrentBlock("chat_attachments_area");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_attach_button");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_file_input");
            $tpl->parseCurrentBlock();
        }

        $tpl->setVariable("SESSION_MANAGEMENT_HTML", "");

        $this->addChatAssets();

        return $tpl->get();
    }

    /**
     * Resources of the chat: uploaded files and the content of the page, each a snapshot that is
     * used until it is updated, see ChatResource
     */
    public function showResourcesTab(): void
    {
        global $DIC;
        $this->addEditTabs('resources');

        $chat_config = $this->getChatConfigForEdit();
        if ($chat_config === null) {
            $this->tpl->setContent('');
            return;
        }
        $this->migrateLivePageContext($chat_config);

        // Processing state in the RAG, as in the statistics tab; limited per chat by refreshChat()
        if ($this->isRagActiveForChat($chat_config)) {
            try {
                \ai\AIChatPageComponentRAGStatus::refreshChat($chat_config->getChatId());
            } catch (\Exception $e) {
                $this->logger->warning("Processing state of resources not available: " . $e->getMessage());
            }
        }

        // Changed, missing or moved sources are shown, never updated on their own
        (new \ILIAS\Plugin\pcaic\Resource\SourceStateService(\ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry::default()))->refresh(
            $this->resourceContext($chat_config),
            \ILIAS\Plugin\pcaic\Model\ChatResource::getByChatId($chat_config->getChatId())
        );

        $f = $DIC->ui()->factory();
        $components = [$f->messageBox()->info(
            $this->plugin->txt('resources_info') . ' ' . $this->plugin->txt('resources_source_info')
        )];

        $toolbar = [];
        if ($this->isBackgroundFileUploadAvailable($chat_config)) {
            $toolbar[] = $f->button()->standard(
                $this->plugin->txt('resources_add_files'),
                $this->ctrl->getLinkTarget($this, 'showAddResourceFiles')
            );
        }
        if (\ILIAS\Plugin\pcaic\Model\ChatResource::findPageContext($chat_config->getChatId()) === null) {
            $toolbar[] = $f->button()->standard(
                $this->plugin->txt('resources_add_page_context'),
                $this->ctrl->getLinkTarget($this, 'addPageContextResource')
            );
        }
        // Objects of the environment only with RAG
        if ($this->isRagActiveForChat($chat_config) && $this->getEnvRefId() !== null) {
            $toolbar[] = $f->button()->standard(
                $this->plugin->txt('resources_add_environment'),
                $this->ctrl->getLinkTarget($this, 'showAddEnvironmentResources')
            );
        }
        foreach ($toolbar as $button) {
            $DIC->toolbar()->addComponent($button);
        }

        $resources = \ILIAS\Plugin\pcaic\Model\ChatResource::getByChatId($chat_config->getChatId());
        if (empty($resources)) {
            $components[] = $f->messageBox()->info($this->plugin->txt('resources_none'));
        } else {
            // Restrictions of single resources only take effect within the settings of the chat
            if (!$this->chatShowsSources($chat_config)) {
                $components[] = $f->messageBox()->info($this->plugin->txt('resources_sources_inactive'));
            } elseif (!$chat_config->isAllowSourceDownloads()) {
                $components[] = $f->messageBox()->info($this->plugin->txt('resources_downloads_inactive'));
            }
            $components[] = $this->buildResourceTable($chat_config, $resources);
        }

        $this->tpl->setContent($DIC->ui()->renderer()->render($components));
    }

    /**
     * Whether answers of the chat show sources: RAG is used and "show sources" is set
     */
    private function chatShowsSources(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): bool
    {
        $llm = $this->getLLMInstanceForChat($chat_config);
        return $chat_config->isShowSources() && $llm->isRagEnabledForChat($chat_config);
    }

    /**
     * Table of the resources with the actions include/exclude, update, source display, download and remove
     *
     * @param \ILIAS\Plugin\pcaic\Model\ChatResource[] $resources
     */
    private function buildResourceTable(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config, array $resources)
    {
        global $DIC;
        $f = $DIC->ui()->factory();

        $rag_active = $this->isRagActiveForChat($chat_config);

        $records = [];
        $disabled = [];
        foreach ($resources as $resource) {
            $id = (string) $resource->getId();
            $records[$id] = [
                'title' => $resource->getTitle(),
                'type' => $this->plugin->txt('resource_type_' . $resource->getType()),
                'snapshot' => $this->getSnapshotLabel($resource),
                'state' => $this->getResourceStateLabel($resource, $rag_active),
                'included' => $this->plugin->txt($resource->isEnabled() ? 'resource_included' : 'resource_excluded'),
                'source' => $this->plugin->txt('resource_source_' . $resource->getSourceDisplay()),
                'download' => $this->plugin->txt(
                    !$resource->canBeDownloaded()
                    || $resource->getSourceDisplay() !== \ILIAS\Plugin\pcaic\Model\ChatResource::SOURCE_FULL
                        ? 'resource_download_not_possible'
                        : ($resource->isAllowDownload() ? 'resource_download_allowed' : 'resource_download_denied')
                ),
            ];
            // Uploaded files have no source to take a new snapshot from, a gone or moved source neither
            if ($resource->getType() === \ILIAS\Plugin\pcaic\Model\ChatResource::TYPE_FILE
                || in_array($resource->getSourceState(), [\ILIAS\Plugin\pcaic\Model\ChatResource::STATE_MISSING, \ILIAS\Plugin\pcaic\Model\ChatResource::STATE_OUTSIDE], true)) {
                $disabled[$id][] = 'update';
            }
            $disabled[$id][] = $resource->isEnabled() ? 'include' : 'exclude';
            $disabled[$id][] = 'source_' . $resource->getSourceDisplay();
            // Downloads only of files shown with their passages
            if (!$resource->canBeDownloaded()
                || $resource->getSourceDisplay() !== \ILIAS\Plugin\pcaic\Model\ChatResource::SOURCE_FULL) {
                $disabled[$id][] = 'download_allow';
                $disabled[$id][] = 'download_deny';
            } else {
                $disabled[$id][] = $resource->isAllowDownload() ? 'download_allow' : 'download_deny';
            }
        }

        $columns = [
            'title' => $f->table()->column()->text($this->plugin->txt('resource_title')),
            'type' => $f->table()->column()->text($this->plugin->txt('resource_type')),
            'snapshot' => $f->table()->column()->text($this->plugin->txt('resource_snapshot')),
            'state' => $f->table()->column()->text($this->plugin->txt('resource_state')),
            'included' => $f->table()->column()->text($this->plugin->txt('resource_used')),
            'source' => $f->table()->column()->text($this->plugin->txt('resource_source')),
            'download' => $f->table()->column()->text($this->plugin->txt('resource_download')),
        ];

        $uri = new \ILIAS\Data\URI(ILIAS_HTTP_PATH . '/' . $this->ctrl->getLinkTarget($this, 'resourceAction'));
        [$url_builder, $action_token, $ids_token] = (new \ILIAS\UI\URLBuilder($uri))
            ->acquireParameters(self::RESOURCE_TABLE_NAMESPACE, 'action', 'ids');
        $actions = [];
        $actions_order = [
            'include', 'exclude', 'update',
            'source_full', 'source_name', 'source_hidden', 'download_allow', 'download_deny',
            'remove',
        ];
        foreach ($actions_order as $action) {
            $actions[$action] = $f->table()->action()->single(
                $this->plugin->txt('resource_action_' . $action),
                $url_builder->withParameter($action_token, $action),
                $ids_token
            );
        }

        $retrieval = new \ILIAS\Plugin\pcaic\Table\ResourceTableRetrieval($records, $disabled);
        $title = $this->plugin->txt('tab_resources');
        // The order of the parameters changed with ILIAS 10
        $first = (new \ReflectionMethod($f->table(), 'data'))->getParameters()[0]->getName();
        $table = $first === 'data_retrieval'
            ? $f->table()->data($retrieval, $title, $columns)
            : $f->table()->data($title, $columns, $retrieval);

        return $table->withActions($actions)->withRequest($DIC->http()->request());
    }

    private const RESOURCE_TABLE_NAMESPACE = ['pcaic', 'resource'];

    /**
     * Date of the snapshot, with the state of its source if that needs attention
     */
    private function getSnapshotLabel(\ILIAS\Plugin\pcaic\Model\ChatResource $resource): string
    {
        $label = $resource->getSnapshotAt() ? $this->formatStoredDateTime((string) $resource->getSnapshotAt()) : '';
        $state = $resource->getSourceState();
        if (in_array($state, [\ILIAS\Plugin\pcaic\Model\ChatResource::STATE_CHANGED, \ILIAS\Plugin\pcaic\Model\ChatResource::STATE_MISSING, \ILIAS\Plugin\pcaic\Model\ChatResource::STATE_OUTSIDE], true)) {
            $label .= ($label === '' ? '' : ' · ') . $this->plugin->txt('resource_source_state_' . $state);
        }
        return $label;
    }

    /**
     * The chat uses the RAG and file handling is allowed for its service
     */
    private function isRagActiveForChat(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): bool
    {
        $llm = $this->getLLMInstanceForChat($chat_config);
        return $llm->isRagEnabledForChat($chat_config)
            && $llm->isFileHandlingEnabledForService(\ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService()));
    }

    private function resourceContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): \ILIAS\Plugin\pcaic\Resource\ResourceContext
    {
        global $DIC;
        return new \ILIAS\Plugin\pcaic\Resource\ResourceContext($chat_config, $this->getEnvRefId(), $DIC->user()->getId());
    }

    /**
     * State of a resource for editors
     */
    private function getResourceStateLabel(\ILIAS\Plugin\pcaic\Model\ChatResource $resource, bool $rag_active): string
    {
        if (!$rag_active) {
            return $this->plugin->txt('resource_state_direct');
        }
        $state = $resource->getDisplayState();
        if ($state === null) {
            return $this->plugin->txt('resource_state_waiting');
        }
        if ($state === \ai\AIChatPageComponentRAGStatus::SKIPPED) {
            return $this->plugin->txt('resource_state_direct');
        }
        $key = 'rag_upload_' . ($state === \ai\AIChatPageComponentRAGStatus::PENDING ? 'processing' : $state) . '_short';
        return $this->plugin->txt($key);
    }

    /**
     * Action of the resource table for the selected resource
     */
    public function resourceAction(): void
    {
        $params = $this->request->getQueryParams();
        $prefix = implode('_', self::RESOURCE_TABLE_NAMESPACE) . '_';
        $action = (string) ($params[$prefix . 'action'] ?? '');
        $ids = $params[$prefix . 'ids'] ?? [];
        $id = (int) (is_array($ids) ? ($ids[0] ?? 0) : $ids);

        $resource = $this->getResourceOfChat($id);
        if ($resource === null) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('msg_no_perm_write'), true);
            $this->ctrl->redirect($this, 'showResourcesTab');
            return;
        }

        switch ($action) {
            case 'include':
            case 'exclude':
                $resource->setEnabled($action === 'include');
                $resource->save();
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_msg_' . $action), true);
                break;
            case 'source_full':
            case 'source_name':
            case 'source_hidden':
                $resource->setSourceDisplay(substr($action, strlen('source_')));
                $resource->save();
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_msg_source'), true);
                break;
            case 'download_allow':
            case 'download_deny':
                $resource->setAllowDownload($action === 'download_allow');
                $resource->save();
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_msg_download'), true);
                break;
            case 'update':
                if ($resource->getType() === \ILIAS\Plugin\pcaic\Model\ChatResource::TYPE_PAGE_CONTEXT) {
                    $this->snapshotPageContext($this->getChatConfigForEdit(), $resource);
                } else {
                    $this->updateResourceSnapshot($resource);
                }
                break;
            case 'remove':
                $this->ctrl->setParameter($this, 'resource_id', $id);
                $this->ctrl->redirect($this, 'confirmRemoveResource');
                return;
        }
        $this->ctrl->redirect($this, 'showResourcesTab');
    }

    public function confirmRemoveResource(): void
    {
        $this->addEditTabs('resources');
        $id = (int) ($this->request->getQueryParams()['resource_id'] ?? 0);
        $resource = $this->getResourceOfChat($id);
        if ($resource === null) {
            $this->ctrl->redirect($this, 'showResourcesTab');
            return;
        }

        $this->ctrl->setParameter($this, 'resource_id', $id);
        $confirmation = new ilConfirmationGUI();
        $confirmation->setFormAction($this->ctrl->getFormAction($this));
        $confirmation->setHeaderText($this->plugin->txt('resource_remove_confirm'));
        $confirmation->setConfirm($this->lng->txt('remove'), 'removeResource');
        $confirmation->setCancel($this->lng->txt('cancel'), 'showResourcesTab');
        $confirmation->addItem('resource_id', (string) $id, $resource->getTitle());
        $this->tpl->setContent($confirmation->getHTML());
    }

    public function removeResource(): void
    {
        $id = (int) ($this->request->getQueryParams()['resource_id'] ?? 0);
        $resource = $this->getResourceOfChat($id);
        if ($resource !== null) {
            $resource->delete();
            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_removed'), true);
        }
        $this->ctrl->setParameter($this, 'resource_id', null);
        $this->ctrl->redirect($this, 'showResourcesTab');
    }

    /**
     * New snapshot of a resource taken from an object of the environment
     *
     * Only while the editor may still read the source; the new snapshot replaces the old one,
     * also in the RAG.
     */
    private function updateResourceSnapshot(\ILIAS\Plugin\pcaic\Model\ChatResource $resource): void
    {
        global $DIC;
        $chat_config = $this->getChatConfigForEdit();
        $provider = \ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry::default()->get($resource->getType());
        if ($chat_config === null || $provider === null) {
            return;
        }
        if (!$provider->isAccessible($resource, $DIC->user()->getId())) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('resource_update_no_access'), true);
            return;
        }
        $context = $this->resourceContext($chat_config);
        $stored = (new \ILIAS\Plugin\pcaic\Resource\SnapshotService())->store(
            $resource,
            $provider->createSnapshot($context, $resource),
            $DIC->user()->getId(),
            $provider->getSourceVersion($context, $resource)
        );
        if (!$stored) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('resource_update_failed'), true);
            return;
        }
        $this->syncResourcesToRag($chat_config);
        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_updated'), true);
    }

    public function showAddEnvironmentResources(): void
    {
        global $DIC;
        $chat_config = $this->getChatConfigForEdit();
        $form = $chat_config !== null ? $this->buildEnvironmentForm($chat_config) : null;
        if ($form === null) {
            $this->tpl->setOnScreenMessage('info', $this->plugin->txt('resources_env_none'), true);
            $this->ctrl->redirect($this, 'showResourcesTab');
            return;
        }
        $this->addEditTabs('resources');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    public function saveEnvironmentResources(): void
    {
        global $DIC;
        $chat_config = $this->getChatConfigForEdit();
        $form = $chat_config !== null ? $this->buildEnvironmentForm($chat_config) : null;
        if ($form === null) {
            $this->ctrl->redirect($this, 'showResourcesTab');
            return;
        }
        $form = $form->withRequest($DIC->http()->request());
        $data = $form->getData();
        if ($data === null || $form->getError() !== null) {
            $this->addEditTabs('resources');
            $this->tpl->setContent($DIC->ui()->renderer()->render($form));
            return;
        }

        // Only what is offered now: the candidates are checked again with the current permissions
        $context = $this->resourceContext($chat_config);
        $offered = [];
        foreach ($this->getEnvironmentCandidates($context) as $candidate) {
            $offered[$candidate->getProviderType() . ':' . $candidate->getRefId()] = $candidate;
        }

        $registry = \ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry::default();
        $snapshots = new \ILIAS\Plugin\pcaic\Resource\SnapshotService();
        $added = 0;
        $failed = [];
        foreach ((array) ($data['env']['items'] ?? []) as $key) {
            $candidate = $offered[(string) $key] ?? null;
            $provider = $candidate !== null ? $registry->get($candidate->getProviderType()) : null;
            if ($candidate === null || $provider === null || $candidate->isAdded()) {
                continue;
            }
            $resource = new \ILIAS\Plugin\pcaic\Model\ChatResource();
            $resource->setChatId($chat_config->getChatId());
            $resource->setType($candidate->getProviderType());
            $resource->setTitle((string) \ilObject::_lookupTitle($candidate->getObjId()));
            $resource->setSourceRefId($candidate->getRefId());
            $resource->setSourceObjId($candidate->getObjId());
            $resource->setEnvRefId($context->getEnvRefId());
            $resource->setCreatedBy($DIC->user()->getId());
            $stored = $snapshots->store(
                $resource,
                $provider->createSnapshot($context, $resource),
                $DIC->user()->getId(),
                $provider->getSourceVersion($context, $resource)
            );
            if ($stored) {
                $added++;
            } else {
                $failed[] = $candidate->getTitle();
            }
        }

        if ($failed !== []) {
            $this->tpl->setOnScreenMessage('failure', sprintf($this->plugin->txt('resources_env_failed'), implode(', ', $failed)), true);
        }
        if ($added > 0) {
            $this->syncResourcesToRag($chat_config);
            $this->tpl->setOnScreenMessage('success', sprintf($this->plugin->txt('resources_env_added'), $added), true);
        }
        $this->ctrl->redirect($this, 'showResourcesTab');
    }

    /**
     * Candidates of all kinds of the environment that need the RAG
     *
     * @return \ILIAS\Plugin\pcaic\Resource\Candidate[]
     */
    private function getEnvironmentCandidates(\ILIAS\Plugin\pcaic\Resource\ResourceContext $context): array
    {
        if ($context->getEnvRefId() === null || !$this->isRagActiveForChat($context->getChat())) {
            return [];
        }
        $candidates = [];
        foreach (\ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry::default()->available(true) as $provider) {
            if ($provider->requiresRag()) {
                array_push($candidates, ...$provider->getCandidates($context));
            }
        }
        return $candidates;
    }

    /**
     * Choice of objects of the environment; null if there is nothing to add
     */
    private function buildEnvironmentForm(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config)
    {
        global $DIC;
        $f = $DIC->ui()->factory();

        $options = [];
        $already = 0;
        foreach ($this->getEnvironmentCandidates($this->resourceContext($chat_config)) as $candidate) {
            if ($candidate->isAdded()) {
                $already++;
                continue;
            }
            $options[$candidate->getProviderType() . ':' . $candidate->getRefId()] =
                $this->plugin->txt('resource_type_' . $candidate->getProviderType()) . ': ' . $candidate->getTitle();
        }
        if ($options === []) {
            return null;
        }

        $byline = $this->plugin->txt('resources_env_byline')
            . ($already > 0 ? ' ' . sprintf($this->plugin->txt('resources_env_already'), $already) : '');
        $input = $f->input()->field()->multiSelect($this->plugin->txt('resources_env_select'), $options, $byline)
            ->withRequired(true);

        return $f->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveEnvironmentResources'),
            ['env' => $f->input()->field()->section(['items' => $input], $this->plugin->txt('resources_add_environment'))]
        );
    }

    public function showAddResourceFiles(): void
    {
        global $DIC;
        $this->addEditTabs('resources');
        $this->tpl->setContent($DIC->ui()->renderer()->render($this->buildAddResourceFilesForm()));
    }

    public function saveResourceFiles(): void
    {
        global $DIC;
        $chat_config = $this->getChatConfigForEdit();
        $form = $this->buildAddResourceFilesForm()->withRequest($DIC->http()->request());
        $data = $form->getData();
        if ($chat_config === null || $data === null || $form->getError() !== null) {
            $this->addEditTabs('resources');
            $this->tpl->setContent($DIC->ui()->renderer()->render($form));
            return;
        }

        $file_ids = [];
        foreach ((array) ($data['files']['files'] ?? []) as $file) {
            if (is_string($file) && $file !== '') {
                $file_ids[] = $file;
            } elseif (is_array($file) && isset($file['resource_id'])) {
                $file_ids[] = (string) $file['resource_id'];
            }
        }

        $added = $this->addFileResources($chat_config, $file_ids);
        if ($added > 0) {
            $this->tpl->setOnScreenMessage('success', sprintf($this->plugin->txt('resources_files_added'), $added), true);
        }
        $this->ctrl->redirect($this, 'showResourcesTab');
    }

    private function buildAddResourceFilesForm()
    {
        global $DIC;
        $f = $DIC->ui()->factory();

        require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
        $extensions = $this->getAllowedBackgroundFileExtensions(false);
        $input = $f->input()->field()->file(
            new ilAIChatPageComponentFileUploadHandlerGUI(),
            $this->plugin->txt('background_files_upload_label')
        )->withByline(sprintf($this->plugin->txt('resources_files_byline'), implode(', ', array_map('strtoupper', $extensions))))
         ->withMaxFiles(10)
         ->withRequired(true)
         ->withAcceptedMimeTypes(FileUploadValidator::extensionsToAcceptValues($extensions));

        return $f->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveResourceFiles'),
            ['files' => $f->input()->field()->section(['files' => $input], $this->plugin->txt('resources_add_files'))]
        );
    }

    /**
     * Add uploaded files as resources, one resource per file
     *
     * File types are checked here: the file input of the browser can be bypassed. Files of
     * other types are removed from the Resource Storage. The RAG upload follows right away.
     *
     * @param string[] $file_ids Identifications in the Resource Storage
     * @return int Number of resources added
     */
    private function addFileResources(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config, array $file_ids): int
    {
        global $DIC;
        $irss = $DIC->resourceStorage();
        $llm = $this->getLLMInstanceForChat($chat_config);
        $ai_service = \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService());
        $rag_active = $llm->isRagEnabledForChat($chat_config) && $llm->isFileHandlingEnabledForService($ai_service);
        $policy = $llm->getFileTypePolicy();

        $added = 0;
        $rejected = [];
        foreach (array_unique($file_ids) as $file_id) {
            $identification = $irss->manage()->find($file_id);
            if ($identification === null) {
                continue;
            }
            $revision = $irss->manage()->getCurrentRevision($identification);
            $suffix = (string) $revision->getInformation()->getSuffix();
            if ($policy->target($suffix, $rag_active) === \ILIAS\Plugin\pcaic\Validation\FileTypePolicy::TARGET_NONE) {
                $rejected[] = $revision->getTitle();
                $irss->manage()->remove($identification, new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder());
                continue;
            }

            $resource = new \ILIAS\Plugin\pcaic\Model\ChatResource();
            $resource->setChatId($chat_config->getChatId());
            $resource->setType(\ILIAS\Plugin\pcaic\Model\ChatResource::TYPE_FILE);
            $resource->setTitle($revision->getTitle());
            $resource->setCreatedBy($DIC->user()->getId());
            $resource->setEnvRefId($this->getEnvRefId());
            (new \ILIAS\Plugin\pcaic\Resource\SnapshotService())->store(
                $resource,
                [\ILIAS\Plugin\pcaic\Resource\SnapshotDocument::ofStoredFile($revision->getTitle(), $file_id)],
                $DIC->user()->getId()
            );
            $added++;
        }

        if (!empty($rejected)) {
            $this->tpl->setOnScreenMessage('failure', sprintf(
                $this->plugin->txt('background_files_type_rejected'),
                implode(', ', $rejected),
                implode(', ', $policy->uploadTypes($rag_active))
            ), true);
        }

        $this->syncResourcesToRag($chat_config);
        return $added;
    }

    /**
     * Upload new snapshots to the RAG, as the next chat request would do
     */
    private function syncResourcesToRag(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {
        try {
            $llm = $this->getLLMInstanceForChat($chat_config);
            $ai_service = \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService());
            if ($llm->isRagEnabledForChat($chat_config) && $llm->isFileHandlingEnabledForService($ai_service)) {
                $llm->syncBackgroundFilesToRAG($chat_config, time() + 20);
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Upload of resources to the RAG failed, the cron job retries: " . $e->getMessage());
        }
    }

    public function addPageContextResource(): void
    {
        $chat_config = $this->getChatConfigForEdit();
        if ($chat_config !== null && \ILIAS\Plugin\pcaic\Model\ChatResource::findPageContext($chat_config->getChatId()) === null) {
            $this->snapshotPageContext($chat_config, null);
        }
        $this->ctrl->redirect($this, 'showResourcesTab');
    }

    /**
     * Store the current text of the page as snapshot of the page content resource
     *
     * @param \ILIAS\Plugin\pcaic\Model\ChatResource|null $resource Existing resource to update, or null to add one
     */
    private function snapshotPageContext(?\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config, ?\ILIAS\Plugin\pcaic\Model\ChatResource $resource): bool
    {
        global $DIC;
        if ($chat_config === null) {
            return false;
        }

        if ($resource === null) {
            $resource = new \ILIAS\Plugin\pcaic\Model\ChatResource();
            $resource->setChatId($chat_config->getChatId());
            $resource->setType(\ILIAS\Plugin\pcaic\Model\ChatResource::TYPE_PAGE_CONTEXT);
            $resource->setTitle($this->plugin->txt('resource_page_context_title'));
            $resource->setCreatedBy($DIC->user()->getId());
            $resource->setEnvRefId($this->getEnvRefId());
        }

        $context = new \ILIAS\Plugin\pcaic\Resource\ResourceContext($chat_config, $this->getEnvRefId(), $DIC->user()->getId());
        $provider = \ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry::default()->get($resource->getType());
        $documents = $provider !== null ? $provider->createSnapshot($context, $resource) : [];

        // The new snapshot replaces the old one, also in the RAG
        $stored = (new \ILIAS\Plugin\pcaic\Resource\SnapshotService())->store(
            $resource,
            $documents,
            $DIC->user()->getId(),
            $provider?->getSourceVersion($context, $resource)
        );
        if (!$stored) {
            $this->tpl->setOnScreenMessage('info', $this->plugin->txt('resource_page_context_empty'), true);
            return false;
        }

        $this->syncResourcesToRag($chat_config);
        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('resource_page_context_saved'), true);
        return true;
    }

    /**
     * Reference of the object containing the page being edited, the environment of the chat
     */
    private function getEnvRefId(): ?int
    {
        $ref_id = (int) ($this->request->getQueryParams()['ref_id'] ?? 0);
        return $ref_id > 0 ? $ref_id : null;
    }

    /**
     * Chats from before resources existed used the current page content if "include page context" was set:
     * they keep using it, now as snapshot taken when an editor opens the resources
     */
    private function migrateLivePageContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {
        if (!$chat_config->isIncludePageContext()
            || \ILIAS\Plugin\pcaic\Model\ChatResource::findPageContext($chat_config->getChatId()) !== null) {
            return;
        }
        if ($this->snapshotPageContext($chat_config, null)) {
            $chat_config->setIncludePageContext(false);
            $chat_config->save();
        }
    }

    private function getChatConfigForEdit(): ?\ILIAS\Plugin\pcaic\Model\ChatConfig
    {
        $chat_id = $this->getChatIdForEdit();
        if ($chat_id === '') {
            return null;
        }
        $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
        return $chat_config->exists() ? $chat_config : null;
    }

    /**
     * Files can be added if file handling is allowed for the service and background files are allowed
     */
    private function isBackgroundFileUploadAvailable(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): bool
    {
        $llm = $this->getLLMInstanceForChat($chat_config);
        $ai_service = \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService());
        return $llm->isFileHandlingEnabledForService($ai_service) && FileUploadValidator::isUploadEnabled('background');
    }

    /**
     * Resource with this ID if it belongs to the chat being edited
     */
    private function getResourceOfChat(int $id): ?\ILIAS\Plugin\pcaic\Model\ChatResource
    {
        $resource = $id > 0 ? \ILIAS\Plugin\pcaic\Model\ChatResource::find($id) : null;
        return $resource !== null && $resource->getChatId() === $this->getChatIdForEdit() ? $resource : null;
    }

    /**
     * Service that answers in the chat (the enforced default service, if any); falls back to the first available service
     */
    private function getLLMInstanceForChat(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config)
    {
        $ai_service = \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($chat_config->getAiService());

        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);

        if ($instance === null) {
            $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            if (!empty($available_services)) {
                $first_service = array_key_first($available_services);
                $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service);
            }

            if ($instance === null) {
                throw new \Exception("No AI services available in registry");
            }
        }

        return $instance;
    }

    /**
     * Update page_id, parent_id and parent_type of the chat if the page has moved
     */
    private function updateChatConfigPageContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {

        try {
            $current_page_info = $this->getPageInfo();

            $current_page_id = $chat_config->getPageId();
            $current_parent_id = $chat_config->getParentId();
            $current_parent_type = $chat_config->getParentType();

            $new_page_id = $current_page_info['page_id'];
            $new_parent_id = $current_page_info['parent_id'];
            $new_parent_type = $current_page_info['parent_type'];

            if ($current_page_id !== $new_page_id ||
                $current_parent_id !== $new_parent_id ||
                $current_parent_type !== $new_parent_type) {

                $chat_config->setPageId($new_page_id);
                $chat_config->setParentId($new_parent_id);
                $chat_config->setParentType($new_parent_type);
                $chat_config->save();

            }

        } catch (\Exception $e) {
            $this->logger->warning("Error updating page context during render", ['error' => $e->getMessage()]);
        }
    }

    private function addChatAssets(): void
    {
        global $DIC;
        $tpl = $DIC['tpl'];

        $tpl->addCss($this->plugin->getDirectory() . "/css/ai_chat.css");

        // marked.js renders Markdown in the browser
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/vendor/marked.min.js");
        // DOMPurify cleans the rendered answers
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/vendor/purify.min.js");
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/ai_chat.js");
    }

    /**
     * URL of api.php
     */
    private function getAIChatApiUrl(): string
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
     * Defaults for new chats from the plugin configuration
     */
    private function getAIChatDefaults(): array
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
                $defaults['characters_limit'] = (int) $char_limit;
            }

            $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
            if (!empty($max_memory)) {
                $defaults['max_memory_messages'] = (int) $max_memory;
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
     * @return array{page_id: int, parent_id: int, parent_type: string}
     */
    private function getPageInfo(): array
    {
        $page_id = (int) ($this->getPlugin()->getPageId() ?? 0);
        $parent_id = (int) ($this->getPlugin()->getParentId() ?? 0);
        $parent_type = ($this->getPlugin()->getParentType() ?? '');

        return [
            'page_id' => $page_id,
            'parent_id' => $parent_id,
            'parent_type' => $parent_type,
        ];
    }

    /**
     * Allowed file types for background files
     *
     * In create mode the RAG file types are used if RAG is available, because RAG will
     * probably be enabled; in edit mode the RAG setting of the chat is used.
     */
    private function getAllowedBackgroundFileExtensions(bool $a_create): array
    {
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        $ai_service = null;
        $rag_enabled_for_chat = false;

        if (!$a_create) {
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';

            if (!empty($chat_id)) {
                try {
                    $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chat_config->exists()) {
                        $ai_service = $chat_config->getAiService();
                        $rag_enabled_for_chat = $chat_config->isEnableRag();
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig for file extensions", ['error' => $e->getMessage()]);
                }
            }
        }

        if (empty($ai_service)) {
            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);
                $ai_service = !empty($service_options) ? array_key_first($service_options) : 'ramses';
            }
        }

        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
        if ($llm === null) {
            return FileUploadValidator::getAllowedExtensions('background');
        }

        $rag_config_key = $ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        $effective_rag_enabled = false;
        if ($rag_globally_enabled && $llm->supportsRAG()) {
            if ($a_create) {
                $effective_rag_enabled = true;
            } else {
                $effective_rag_enabled = $rag_enabled_for_chat;
            }
        }

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
     * Convert stored property values ('1', 'true', 'on', ...) to bool
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

        return (bool) $value;
    }
}
