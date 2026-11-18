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

namespace ai;

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;
use ILIAS\Plugin\pcaic\Model\ChatMessage;
use ILIAS\Plugin\pcaic\Model\Attachment;
use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Service\StreamKeepAlive;
use ILIAS\Plugin\pcaic\Validation\FileTypePolicy;
use platform\AIChatPageComponentException;

require_once __DIR__ . '/class.AIChatPageComponentRAG.php';
require_once __DIR__ . '/class.AIChatPageComponentRAGStatus.php';

/**
 * Base class of the AI services
 *
 * Implements the message flow (session, context, files, RAG) independently of a
 * specific API. A service implements the API call in sendMessagesArray() and is
 * registered in AIChatPageComponentLLMRegistry.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
abstract class AIChatPageComponentLLM
{
    /** @var string Exception message if the AI service is overloaded or temporarily unavailable */
    public const SERVICE_BUSY = 'ai_service_busy';

    /** @var int[] HTTP status codes of the AI service that are reported as SERVICE_BUSY */
    public const SERVICE_BUSY_HTTP_CODES = [429, 502, 503, 504];

    /**
     * @var string Added to the system prompt in RAG mode. The RAG service requires a
     *             citation for every statement and always returns the most similar
     *             passages, also if none of them is relevant to the question.
     */
    public const RAG_CITATION_INSTRUCTION = 'Citation rules for the provided context: Cite a context item only where'
        . ' the statement is actually based on that item, using the citation format specified in the context'
        . ' instructions. Do not add citations to statements that are not derived from the context. If none of'
        . ' the context items is relevant to the question, answer without citations and without referring to'
        . ' the context.';

    /** @var int Seconds to establish the connection to the AI service */
    protected const CONNECT_TIMEOUT = 15;

    /** @var int Seconds until the first data of a streamed answer */
    protected const STREAM_FIRST_DATA_TIMEOUT = 120;

    /** @var int Seconds without data in a streamed answer after which it is aborted */
    protected const STREAM_IDLE_TIMEOUT = 60;

    /** @var int Seconds to wait for further data after the service reported the end of the answer */
    protected const STREAM_END_GRACE = 3;

    /** @var int Maximum duration of a request to the AI service, in seconds */
    protected const REQUEST_TIMEOUT = 600;

    protected ?int $max_memory_messages = null;
    protected ?string $prompt = null;
    protected bool $streaming = false;
    protected \ilLogger $logger;
    protected ?float $temperature_override = null;
    protected ?string $model_override = null;

    // Sources of the last answer (RAG)
    protected ?array $last_response_metadata = null;
    // Token usage of the last answer
    protected ?array $last_response_usage = null;
    // Remaining image data (bytes) of the current request, see max_image_data_mb
    protected int $image_data_budget = PHP_INT_MAX;
    /** @var array<string, bool> Titles of images omitted because of the limit */
    protected array $omitted_images = [];
    // Background files of the chat are not (yet) usable in the RAG
    protected bool $rag_incomplete = false;
    // Receives the sources of a streamed RAG answer before the answer is generated
    protected ?\Closure $sources_listener = null;

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->pcaic();
    }

    public function setTemperatureOverride(?float $temperature): void
    {
        $this->temperature_override = $temperature;
    }

    /**
     * Set the per-chat model
     *
     * A model the admin no longer offers to editors falls back to the default model.
     */
    public function setModelOverride(?string $model): void
    {
        $available = static::getAvailableModels();
        if ($model !== null && $model !== '' && !empty($available) && !isset($available[$model])) {
            $this->logger->debug("Chat model is not available for editors, using default model", ['model' => $model]);
            $model = null;
        }

        $this->model_override = ($model === '') ? null : $model;
    }

    /**
     * Config key of the model list (model ID => name) from the last refresh
     */
    protected static function getModelCacheKey(): string
    {
        return static::getServiceId() . '_cached_models';
    }

    /**
     * @return array<string, string> model ID => name
     */
    public static function getCachedModels(): array
    {
        $models = \platform\AIChatPageComponentConfig::get(static::getModelCacheKey());
        return is_array($models) ? $models : [];
    }

    /**
     * Models offered to editors (admin selection in the service configuration)
     *
     * Without a saved selection, all models except those that look like
     * non-chat models (embedding, reranking, speech, image, ...) are offered.
     *
     * @return array model_id => display name
     */
    public static function getAvailableModels(): array
    {
        $all = static::getCachedModels();
        $selected = \platform\AIChatPageComponentConfig::get(static::getServiceId() . '_available_models');

        if (!is_array($selected) || empty($selected)) {
            return array_filter($all, fn($id) => !self::looksLikeNonChatModel((string) $id), ARRAY_FILTER_USE_KEY);
        }

        return array_intersect_key($all, array_flip($selected));
    }

    /**
     * Guess from the model ID whether it is not a chat model
     *
     * Model list endpoints do not provide a model type, so only the name can be used.
     */
    public static function looksLikeNonChatModel(string $model_id): bool
    {
        return (bool) preg_match(
            '/embed|(^|[-_\/])e5[-_]|bge|rerank|whisper|tts|transcribe|realtime|audio|image|dall-e|moderation|davinci|babbage|sora|turbo-instruct|codex/i',
            $model_id
        );
    }

    /**
     * Checkbox list input for the models offered to editors
     */
    /**
     * Switch for the file handling of a service, with the file types that may be sent to the service directly
     */
    protected static function buildFileHandlingInput(string $service_id): \ILIAS\UI\Component\Input\Field\OptionalGroup
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        $enabled = (\platform\AIChatPageComponentConfig::get($service_id . '_file_handling_enabled') ?? '1') === '1';
        $types_key = $service_id . '_allowed_file_types';

        $group = $ui_factory->input()->field()->optionalGroup(
            [
                $types_key => $ui_factory->input()->field()->text(
                    $plugin->txt('config_allowed_file_types'),
                    sprintf($plugin->txt('config_service_file_types_info'), implode(', ', FileTypePolicy::DIRECT_SUPPORTED))
                )->withMaxLength(500)
            ],
            $plugin->txt('config_file_handling'),
            $plugin->txt('config_file_handling_info')
        );

        return $group->withValue($enabled
            ? [$types_key => implode(',', FileTypePolicy::getServiceTypes($service_id))]
            : null);
    }

    /**
     * Form data of buildFileHandlingInput() as settings: the switch as '1'/'0' and the list of file types
     */
    protected static function flattenFileHandlingInput(array $form_data, string $service_id): array
    {
        $key = $service_id . '_file_handling_enabled';
        if (!array_key_exists($key, $form_data) || is_bool($form_data[$key])) {
            return $form_data;
        }

        $group = $form_data[$key];
        $form_data[$key] = is_array($group) ? '1' : '0';
        if (is_array($group)) {
            $types_key = $service_id . '_allowed_file_types';
            $form_data[$types_key] = AIChatPageComponentRAG::keepSupportedFileTypes(
                FileTypePolicy::normalize((string) ($group[$types_key] ?? '')),
                FileTypePolicy::DIRECT_SUPPORTED
            );
        }
        return $form_data;
    }

    protected static function buildAvailableModelsInput(array $model_options): \ILIAS\UI\Component\Input\Field\MultiSelect
    {
        global $DIC;
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        return $DIC->ui()->factory()->input()->field()->multiSelect(
            $plugin->txt('config_available_models'),
            $model_options,
            $plugin->txt('config_available_models_info')
        )->withValue(array_values(array_intersect(
            array_map('strval', array_keys(static::getAvailableModels())),
            array_map('strval', array_keys($model_options))
        )))->withRequired(true);
    }

    /**
     * Normalize the submitted selection; the default model is always available
     *
     * @param mixed $selection Submitted multiselect value
     * @param mixed $default_model Submitted default model
     */
    protected static function normalizeAvailableModels($selection, $default_model): array
    {
        $selection = array_values(is_array($selection) ? $selection : []);
        if (is_string($default_model) && $default_model !== '' && !in_array($default_model, $selection, true)) {
            $selection[] = $default_model;
        }
        return $selection;
    }

    /**
     * Update the editor selection after refreshing the model list
     *
     * Keeps the existing selection, drops models that no longer exist and adds
     * new models unless they look like non-chat models.
     *
     * @param array $previous_ids Model IDs known before the refresh
     * @param array $models New model list (model_id => name)
     */
    protected static function updateAvailableModels(array $previous_ids, array $models): void
    {
        $key = static::getServiceId() . '_available_models';
        $selected = \platform\AIChatPageComponentConfig::get($key);
        if (!is_array($selected) || empty($selected)) {
            // No selection saved yet: the name-based default applies automatically
            return;
        }

        $updated = array_values(array_intersect($selected, array_map('strval', array_keys($models))));
        foreach (array_keys($models) as $id) {
            $id = (string) $id;
            if (!in_array($id, $previous_ids, true) && !self::looksLikeNonChatModel($id)) {
                $updated[] = $id;
            }
        }

        \platform\AIChatPageComponentConfig::set($key, array_values(array_unique($updated)));
    }

    /**
     * Cache a refreshed model list and update the editor selection
     *
     * @param array $models model_id => display name
     */
    protected static function storeRefreshedModels(array $models): void
    {
        $previous_ids = array_map('strval', array_keys(static::getCachedModels()));
        \platform\AIChatPageComponentConfig::set(static::getModelCacheKey(), $models);
        static::updateAvailableModels($previous_ids, $models);
    }

    /**
     * Unique service ID, also used as prefix of the configuration keys
     */
    abstract public static function getServiceId(): string;

    abstract public static function getServiceName(): string;

    abstract public static function getServiceDescription(): string;

    /**
     * Name shown for the service in tabs and selections
     *
     * Any OpenAI-compatible API can be configured in a service, so the administrator
     * can replace the built-in name by the name of the provider that is actually used.
     */
    public static function getDisplayName(): string
    {
        $label = \platform\AIChatPageComponentConfig::get(static::getServiceId() . '_service_label');
        return is_string($label) && trim($label) !== '' ? trim($label) : static::getServiceName();
    }

    /**
     * @return array<string, \ILIAS\UI\Component\Input\Field\FormInput> Inputs of the service tab
     */
    abstract public function getConfigurationFormInputs(): array;

    abstract public function saveConfiguration(array $form_data): void;

    /**
     * @return array<string, mixed> config key => default value
     */
    abstract public static function getDefaultConfiguration(): array;

    /**
     * Supported features
     *
     * Keys: streaming (bool), rag (bool), multimodal (bool), file_types (string[]),
     * max_tokens (int|null)
     */
    abstract public function getCapabilities(): array;

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
     * Use the proxy configured in ILIAS (Administration > General Settings > Server)
     * for a request to an external service
     */
    public static function applyProxySettings(\CurlHandle $curl): void
    {
        if (class_exists('ilProxySettings') && \ilProxySettings::_getInstance()->isActive()) {
            curl_setopt(
                $curl,
                CURLOPT_PROXY,
                \ilProxySettings::_getInstance()->getHost() . ':' . \ilProxySettings::_getInstance()->getPort()
            );
        }
    }

    /**
     * Listener for the sources of a streamed RAG answer
     *
     * The sources are known after the retrieval, before the answer is generated, so
     * that they can be shown while the answer is streamed.
     *
     * @param callable|null $listener Receives the sources (see getLastResponseMetadata())
     */
    public function setSourcesListener(?callable $listener): void
    {
        $this->sources_listener = $listener === null ? null : \Closure::fromCallable($listener);
    }

    /**
     * Set timeouts and, in streaming mode, forward the text fragments of a chat request
     *
     * AI services occasionally keep a stream open without sending the end marker or
     * closing the connection. The transfer therefore ends
     * - at the end marker [DONE],
     * - STREAM_END_GRACE seconds after the service reported the end of the answer
     *   (finish_reason), if nothing follows,
     * - with an error after STREAM_FIRST_DATA_TIMEOUT seconds without any data or
     *   STREAM_IDLE_TIMEOUT seconds without further data.
     * Events may be split across several received chunks, so only complete lines are
     * processed.
     *
     * @param string $response_content Receives the raw response in streaming mode
     * @return \stdClass State of the stream, evaluated by resolveChatRequestError()
     */
    protected function configureChatRequest(\CurlHandle $curl, string &$response_content): \stdClass
    {
        $state = new \stdClass();
        $state->done = false;           // end marker received
        $state->finished = false;       // finish_reason received
        $state->finished_at = null;
        $state->ended_after_finish = false;
        $state->idle = false;           // aborted for lack of data
        $state->received = false;
        $state->last_data_at = microtime(true);

        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, static::CONNECT_TIMEOUT);
        curl_setopt($curl, CURLOPT_TIMEOUT, static::REQUEST_TIMEOUT);

        if (!$this->isStreaming()) {
            // Answer without streaming in the stream endpoint (streaming disabled for the chat)
            StreamKeepAlive::attach($curl);
            return $state;
        }

        $buffer = '';
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($curl, string $chunk) use (&$response_content, &$buffer, $state): int {
            $response_content .= $chunk;
            $buffer .= $chunk;
            $state->received = true;
            $state->last_data_at = microtime(true);

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if (strpos($line, 'data:') !== 0) {
                    continue;
                }
                $json_data = trim(substr($line, strlen('data:')));
                if ($json_data === '[DONE]') {
                    $state->done = true;
                    return 0; // Ends the transfer; handled as success in resolveChatRequestError()
                }

                $json = json_decode($json_data, true);
                if (!is_array($json)) {
                    continue;
                }
                if (!empty($json['choices'][0]['finish_reason']) && $state->finished_at === null) {
                    $state->finished = true;
                    $state->finished_at = microtime(true);
                }
                $content = $json['choices'][0]['delta']['content'] ?? '';
                if (is_string($content) && $content !== '') {
                    echo "data: " . json_encode(['type' => 'chunk', 'content' => $content]) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    StreamKeepAlive::touch();
                }
            }

            return strlen($chunk);
        });

        // Called by cURL about once per second, also while no data arrives
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_setopt($curl, StreamKeepAlive::progressOption(), function () use ($state): int {
            // The browser gets a sign of life while the AI service has not started the answer
            StreamKeepAlive::beat();
            $now = microtime(true);
            if ($state->finished_at !== null && $now - $state->finished_at > static::STREAM_END_GRACE) {
                $state->ended_after_finish = true;
                return 1;
            }
            $limit = $state->received ? static::STREAM_IDLE_TIMEOUT : static::STREAM_FIRST_DATA_TIMEOUT;
            if ($now - $state->last_data_at > $limit) {
                $state->idle = true;
                return 1;
            }
            return 0;
        });

        return $state;
    }

    /**
     * Evaluate the cURL error of a chat request
     *
     * Ending the transfer at the end marker or after the reported end of the answer is
     * not an error. A timeout before the answer was complete is reported to the user as
     * SERVICE_BUSY.
     *
     * @return int The cURL error number, 0 if the request succeeded
     * @throws AIChatPageComponentException On a timeout before the answer was complete
     */
    protected function resolveChatRequestError(int $error_number, \stdClass $state, string $api_url): int
    {
        if ($error_number === CURLE_WRITE_ERROR && $state->done) {
            return 0;
        }

        if ($error_number === CURLE_ABORTED_BY_CALLBACK && $state->ended_after_finish) {
            $this->logger->info("AI service kept the stream open after the end of the answer", ['url' => $api_url]);
            return 0;
        }

        $timed_out = $error_number === CURLE_OPERATION_TIMEDOUT
            || ($error_number === CURLE_ABORTED_BY_CALLBACK && $state->idle);
        if ($timed_out) {
            if ($state->finished) {
                return 0;
            }
            $this->logger->warning("AI service request timed out", [
                'url' => $api_url,
                'streaming' => $this->isStreaming(),
                'data_received' => $state->received,
            ]);
            throw new AIChatPageComponentException(self::SERVICE_BUSY, 504);
        }

        return $error_number;
    }

    /**
     * @return array|null Sources of the last answer (RAG)
     */
    public function getLastResponseMetadata(): ?array
    {
        return $this->last_response_metadata;
    }

    /**
     * @return array|null Token usage of the last answer
     */
    public function getLastResponseUsage(): ?array
    {
        return $this->last_response_usage;
    }

    protected function clearLastResponseData(): void
    {
        $this->last_response_metadata = null;
        $this->last_response_usage = null;
    }

    /**
     * File handling must be enabled globally and for the service
     */
    public function isFileHandlingEnabledForService(string $ai_service): bool
    {
        $global_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
        if ($global_file_handling !== '1') {
            return false;
        }

        $service_file_handling_key = $ai_service . '_file_handling_enabled';
        $service_file_handling = \platform\AIChatPageComponentConfig::get($service_file_handling_key);

        $default_file_handling = '1';
        $service_file_handling = $service_file_handling ?? $default_file_handling;

        if ($service_file_handling !== '1') {
            return false;
        }

        return true;
    }

    /**
     * Process a message of a logged-in user: store it, build the context, send it to
     * the AI service and store the answer
     *
     * @param array $attachment_ids Uploaded attachments to bind to the message
     * @return string Answer text
     * @throws AIChatPageComponentException
     */
    public function handleSendMessage(string $chat_id, int $user_id, string $message, array $attachment_ids = []): string
    {
        try {
            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                throw new AIChatPageComponentException('Chat configuration not found');
            }

            $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);

            $user_message = $session->addMessage('user', $message);

            if (!empty($attachment_ids)) {
                foreach ($attachment_ids as $attachment_id) {
                    if (is_numeric($attachment_id)) {
                        $user_message->addAttachment($attachment_id);
                    }
                }
            }

            $this->setPrompt($chat_config->getSystemPrompt());
            $this->setMaxMemoryMessages($chat_config->getMaxMemory());
            $this->resetImageDataBudget();

            $ai_service_id = $chat_config->getAiService();
            $force_temperature = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_temperature') === '1';
            $this->setTemperatureOverride($force_temperature ? null : $chat_config->getTemperature());
            $force_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_model') === '1';
            $this->setModelOverride($force_model ? null : $chat_config->getModel());

            // Settings of this service: it differs from the stored one if the administrator enforces the default service
            $file_handling_enabled = $this->isFileHandlingEnabledForService(static::getServiceId());

            // RAG mode must be known before the background files are processed,
            // because PDFs are only converted to images without RAG
            $collection_ids = [];
            $use_rag = false;
            $recent_limit = min($chat_config->getMaxMemory(), 20);
            if ($file_handling_enabled) {
                $this->ensureBackgroundFilesInRAG($chat_config);
                if ($this->isRagEnabledForChat($chat_config)) {
                    // Before the mode is decided: a chat upload that is not in the RAG yet
                    // (its first upload failed) may be the only file of the chat
                    $sync_stats = $this->syncChatAttachmentsToRAG($session, $recent_limit);
                    if ($sync_stats['uploaded'] > 0) {
                        $this->logger->info("Synced chat attachments to RAG", $sync_stats);
                    }
                }
                $collection_ids = $this->getAllRAGCollectionIds($chat_config, $user_id);
                $use_rag = $this->isRagEnabledForChat($chat_config) && !empty($collection_ids);
            }

            // Decides where each file goes (see FileTypePolicy); independent of whether the RAG
            // has processed any file yet, so that RAG file types are never also sent directly
            $rag_active = $file_handling_enabled && $this->isRagEnabledForChat($chat_config);

            $context_resources = [];
            if ($file_handling_enabled) {
                $context_resources = $this->processBackgroundFiles($chat_config, $rag_active);
            } else {
                $this->logger->debug("File handling disabled - skipping background files processing");
            }

            $page_context = $this->getPageContextForRequest(
                $chat_config,
                $file_handling_enabled && $this->isRagEnabledForChat($chat_config)
            );
            if ($page_context !== null) {
                $context_resources[] = [
                    'kind' => 'page_context',
                    'title' => 'Page Context',
                    'content' => $page_context,
                    'mime_type' => 'text/plain'
                ];
            }

            $ai_messages = $this->processChatMessages($session, $recent_limit, $rag_active, $file_handling_enabled);
            $this->addOmittedImagesNote($ai_messages);

            $this->clearLastResponseData();

            if ($use_rag) {
                $this->logger->debug("Using RAG mode", ['collection_ids' => $collection_ids]);
                $ai_response = $this->sendRagChatWithRebind($chat_config, $session, $ai_messages, $collection_ids, $context_resources);
            } else {
                $this->logger->debug("Using standard mode");
                $ai_response = $this->sendMessagesArray($ai_messages, $context_resources);
            }

            // Empty answers are not stored, some APIs reject them in later requests
            if (trim($ai_response) === '') {
                throw new AIChatPageComponentException('Empty response from AI service');
            }

            $assistant_message = $session->addMessage('assistant', $ai_response);

            if ($this->last_response_metadata !== null) {
                $assistant_message->setMetadata($this->last_response_metadata);
                $this->logger->debug("Storing RAG metadata", [
                    'sources_count' => count($this->last_response_metadata)
                ]);
            }

            if ($this->last_response_usage !== null) {
                $assistant_message->setUsage($this->last_response_usage);
                $this->logger->debug("Storing token usage", $this->last_response_usage);
            }

            if ($this->last_response_metadata !== null || $this->last_response_usage !== null) {
                $assistant_message->save();
            }

            return $ai_response;

        } catch (\Exception $e) {
            if (in_array($e->getMessage(), [AIChatPageComponentRAG::UNAVAILABLE, self::SERVICE_BUSY], true)) {
                throw $e; // Shown to the user as a specific message
            }
            $this->logger->error("handleSendMessage failed", [
                'chat_id' => $chat_id,
                'error' => $e->getMessage()
            ]);
            throw new AIChatPageComponentException('Failed to send message: ' . $e->getMessage());
        }
    }

    /**
     * Process a message of an anonymous user without storing anything
     *
     * The history is provided by the frontend.
     *
     * @param array $conversation_history Items with role and message
     * @return string Answer text
     * @throws AIChatPageComponentException
     */
    public function handleStatelessMessage(string $chat_id, array $conversation_history, string $message): string
    {
        try {
            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                throw new AIChatPageComponentException('Chat configuration not found');
            }

            $this->setPrompt($chat_config->getSystemPrompt());
            $this->setMaxMemoryMessages($chat_config->getMaxMemory());
            $this->resetImageDataBudget();

            $ai_service_id = $chat_config->getAiService();
            $force_temperature = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_temperature') === '1';
            $this->setTemperatureOverride($force_temperature ? null : $chat_config->getTemperature());
            $force_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_model') === '1';
            $this->setModelOverride($force_model ? null : $chat_config->getModel());

            // Settings of this service: it differs from the stored one if the administrator enforces the default service
            $file_handling_enabled = $this->isFileHandlingEnabledForService(static::getServiceId());

            $collection_ids = [];
            $use_rag = false;
            if ($file_handling_enabled) {
                // Background files only: anonymous users cannot upload
                $this->ensureBackgroundFilesInRAG($chat_config);
                $collection_ids = $this->getAllRAGCollectionIds($chat_config);
                $use_rag = $this->isRagEnabledForChat($chat_config) && !empty($collection_ids);
            }

            $context_resources = [];
            if ($file_handling_enabled) {
                $context_resources = $this->processBackgroundFiles(
                    $chat_config,
                    $this->isRagEnabledForChat($chat_config)
                );
            }

            $page_context = $this->getPageContextForRequest(
                $chat_config,
                $file_handling_enabled && $this->isRagEnabledForChat($chat_config)
            );
            if ($page_context !== null) {
                $context_resources[] = [
                    'kind' => 'page_context',
                    'title' => 'Page Context',
                    'content' => $page_context,
                    'mime_type' => 'text/plain'
                ];
            }

            // History from the frontend, limited like the stored history
            $recent_limit = min($chat_config->getMaxMemory(), 20);
            $history_slice = array_slice($conversation_history, -$recent_limit);

            $ai_messages = [];
            foreach ($history_slice as $entry) {
                $role = $entry['role'] ?? '';
                $text = $entry['message'] ?? '';
                if (!empty($text) && in_array($role, ['user', 'assistant'], true)) {
                    $ai_messages[] = ['role' => $role, 'content' => $text];
                }
            }
            $ai_messages[] = ['role' => 'user', 'content' => $message];
            $this->addOmittedImagesNote($ai_messages);

            $this->clearLastResponseData();

            if ($use_rag) {
                return $this->sendRagChatWithRebind($chat_config, null, $ai_messages, $collection_ids, $context_resources);
            }
            return $this->sendMessagesArray($ai_messages, $context_resources);

        } catch (\Exception $e) {
            if (in_array($e->getMessage(), [AIChatPageComponentRAG::UNAVAILABLE, self::SERVICE_BUSY], true)) {
                throw $e; // Shown to the user as a specific message
            }
            $this->logger->error("handleStatelessMessage failed", [
                'chat_id' => $chat_id,
                'error' => $e->getMessage()
            ]);
            throw new AIChatPageComponentException('Failed to send message: ' . $e->getMessage());
        }
    }

    /**
     * Start a new request with the configured limit for image data (max_image_data_mb)
     */
    protected function resetImageDataBudget(): void
    {
        $limit_mb = max(1, (int) (\platform\AIChatPageComponentConfig::get('max_image_data_mb') ?: 15));
        $this->image_data_budget = $limit_mb * 1024 * 1024;
        $this->omitted_images = [];
    }

    /**
     * Reserve the size of an image for the current request
     *
     * Images are added in the order background files, then messages from old to new;
     * images that exceed the remaining budget are omitted.
     *
     * @return bool False if the image does not fit into the limit
     */
    protected function consumeImageDataBudget(string $data_url, string $title): bool
    {
        $size = strlen($data_url);
        if ($size > $this->image_data_budget) {
            $this->omitted_images[$title] = true;
            $this->logger->info("Image omitted, image data limit reached: " . $title);
            return false;
        }

        $this->image_data_budget -= $size;
        return true;
    }

    /**
     * Tell the AI service which images were omitted because of the image data limit
     */
    protected function addOmittedImagesNote(array &$ai_messages): void
    {
        if (empty($this->omitted_images) || empty($ai_messages)) {
            return;
        }

        $note = "[Note: the following images were not sent because of the size limit: "
            . implode(', ', array_keys($this->omitted_images)) . "]";

        $last = count($ai_messages) - 1;
        if (is_array($ai_messages[$last]['content'])) {
            $ai_messages[$last]['content'][] = ['type' => 'text', 'text' => $note];
        } else {
            $ai_messages[$last]['content'] .= "\n\n" . $note;
        }
    }

    /**
     * Background files as context resources
     *
     * Only files that go to the AI service directly (see FileTypePolicy) are added: text files
     * as text, images as image, PDFs as page images. Files of the RAG file types are searched in
     * the RAG instead, files of other types are not used.
     *
     * @param bool $rag_active RAG is enabled for the service and the chat
     */
    protected function processBackgroundFiles(ChatConfig $chat_config, bool $rag_active = false): array
    {
        $context_resources = [];
        $policy = $this->getFileTypePolicy();

        try {
            $background_files = $chat_config->getBackgroundFiles();

            if (empty($background_files)) {
                $this->logger->debug("No background files found");
                return [];
            }

            $this->logger->debug("Processing background files", [
                'count' => count($background_files),
                'rag_active' => $rag_active
            ]);

            global $DIC;
            $irss = $DIC->resourceStorage();

            // The content of the page is added by getPageContextForRequest(), also without file handling
            $page_context = ChatResource::findPageContext($chat_config->getChatId());
            $page_context_files = $page_context === null
                ? []
                : array_map(fn(Attachment $a): string => (string) $a->getResourceId(), $page_context->getAttachments());

            foreach ($background_files as $file_id) {
                if (in_array($file_id, $page_context_files, true)) {
                    continue;
                }
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

                    if ($policy->target($suffix, $rag_active) !== FileTypePolicy::TARGET_DIRECT) {
                        continue;
                    }

                    if (in_array($suffix, ['txt', 'csv', 'md'])) {
                        $stream = $irss->consume()->stream($identification);
                        $content = Attachment::toUtf8($stream->getStream()->getContents());

                        if (!empty($content)) {
                            $context_resources[] = [
                                'kind' => 'text_file',
                                'id' => 'bg-text-' . $file_id,
                                'title' => $revision->getTitle(),
                                'mime_type' => $mime_type,
                                'content' => $content
                            ];
                        }
                    } elseif (in_array($suffix, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $attachment = new Attachment();
                        $attachment->setResourceId($file_id);
                        $attachment->setChatId($chat_config->getChatId());

                        $data_url = $attachment->getDataUrl();
                        if ($data_url && $this->consumeImageDataBudget($data_url, $revision->getTitle())) {
                            $context_resources[] = [
                                'kind' => 'image_file',
                                'id' => 'bg-img-' . $file_id,
                                'title' => $revision->getTitle(),
                                'mime_type' => $mime_type,
                                'url' => $data_url
                            ];
                        }
                    } elseif ($suffix === 'pdf') {
                        $attachment = new Attachment();
                        $attachment->setResourceId($file_id);
                        $attachment->setChatId($chat_config->getChatId());

                        $pdf_data_urls = $attachment->getDataUrl();
                        if ($pdf_data_urls && is_array($pdf_data_urls)) {
                            foreach ($pdf_data_urls as $page_index => $page_data_url) {
                                if (!empty($page_data_url) && $this->consumeImageDataBudget($page_data_url, $revision->getTitle())) {
                                    $context_resources[] = [
                                        'kind' => 'pdf_page',
                                        'id' => 'bg-pdf-' . $file_id . '-p' . ($page_index + 1),
                                        'title' => $revision->getTitle() . ' (Page ' . ($page_index + 1) . ')',
                                        'mime_type' => 'image/png',
                                        'page_number' => $page_index + 1,
                                        'source_file' => $revision->getTitle(),
                                        'url' => $page_data_url
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

        return $context_resources;
    }

    /**
     * Recent session messages in the API message format, each with the attachments that go to the
     * AI service directly (see FileTypePolicy): text files as text, images and PDF pages as images
     *
     * @param bool $rag_active RAG is enabled for the service and the chat: files of the RAG file types
     *                         are searched in the RAG and not added
     */
    protected function processChatMessages(
        ChatSession $session,
        int $limit = 10,
        bool $rag_active = false,
        bool $file_handling_enabled = true
    ): array {
        $ai_messages = [];
        $policy = $this->getFileTypePolicy();

        try {
            $recent_messages = $session->getRecentMessages($limit);

            foreach ($recent_messages as $msg) {
                $content = $msg->getMessage();
                $attachments = $msg->getAttachments();

                if (!$file_handling_enabled) {
                    $ai_messages[] = [
                        'role' => $msg->getRole(),
                        'content' => $content
                    ];
                    continue;
                }

                // Files of the RAG file types are searched in the RAG, files of other types not allowed
                // for the service are not used; also for files uploaded before a setting changed
                $direct_attachments = array_values(array_filter(
                    $attachments,
                    fn(Attachment $attachment): bool =>
                        $policy->target($attachment->getSuffix(), $rag_active) === FileTypePolicy::TARGET_DIRECT
                ));

                // Also in RAG mode each image stays at its message: sendRagChat() sends the text to the
                // RAG and sets the images into the messages the RAG returns
                $base64_attachments = $direct_attachments;

                if (!empty($base64_attachments)) {
                    $multimodal_content = [];

                    if (!empty(trim($content))) {
                        $multimodal_content[] = ['type' => 'text', 'text' => $content];
                    }

                    foreach ($this->getTextAttachmentParts($base64_attachments) as $text_part) {
                        $multimodal_content[] = ['type' => 'text', 'text' => $text_part];
                    }

                    foreach ($base64_attachments as $attachment) {
                        try {
                            if ($attachment->isImage()) {
                                $image_data = $attachment->getDataUrl();
                                if ($image_data && $this->consumeImageDataBudget($image_data, (string) $attachment->getTitle())) {
                                    $multimodal_content[] = [
                                        'type' => 'image_url',
                                        'image_url' => ['url' => $image_data]
                                    ];
                                }
                            } elseif ($attachment->isPdf()) {
                                $pdf_data_urls = $attachment->getDataUrl();
                                if ($pdf_data_urls && is_array($pdf_data_urls)) {
                                    foreach ($pdf_data_urls as $page_data_url) {
                                        if ($page_data_url && $this->consumeImageDataBudget($page_data_url, (string) $attachment->getTitle())) {
                                            $multimodal_content[] = [
                                                'type' => 'image_url',
                                                'image_url' => ['url' => $page_data_url]
                                            ];
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning("Attachment processing failed", ['error' => $e->getMessage()]);
                        }
                    }

                    $ai_messages[] = [
                        'role' => $msg->getRole(),
                        'content' => $multimodal_content
                    ];
                } else {
                    $ai_messages[] = [
                        'role' => $msg->getRole(),
                        'content' => $content
                    ];
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("processChatMessages failed", ['error' => $e->getMessage()]);
        }

        // Drop empty messages (e.g. from failed earlier requests); some APIs reject them
        return array_values(array_filter(
            $ai_messages,
            fn($m) => is_array($m['content']) ? !empty($m['content']) : trim((string) $m['content']) !== ''
        ));
    }

    /**
     * Content of the text attachments, one part per file (callers pass the files that go to the AI service directly)
     *
     * @param Attachment[] $attachments
     * @return string[] One part per file, headed by the file name
     */
    protected function getTextAttachmentParts(array $attachments): array
    {
        $parts = [];
        foreach ($attachments as $attachment) {
            if (!$attachment->isTextFile()) {
                continue;
            }
            $text = $attachment->getTextContent();
            if ($text !== null && trim($text) !== '') {
                $parts[] = "[Attached file: " . $attachment->getTitle() . "]\n" . $text;
            }
        }
        return $parts;
    }

    /**
     * SQL condition for the attachments whose collections a request may search, see getAllRAGCollectionIds()
     *
     * @param int|null $user_id User who sent the request; null for anonymous users
     */
    public static function ragCollectionOwnerCondition(?int $user_id): string
    {
        return $user_id === null
            ? "background_file = 1"
            : "(background_file = 1 OR user_id = " . $user_id . ")";
    }

    /**
     * Whether a file in this state can contribute passages to the retrieval
     *
     * Files that failed, are empty or are not meant for the RAG are not searched. Files still
     * processing are searched: their local state is updated at most once per minute, the RAG
     * may have processed them already (e.g. a file uploaded just before the question).
     */
    public static function isSearchableRagStatus(?string $status): bool
    {
        return !in_array($status, [
            AIChatPageComponentRAGStatus::FAILED,
            AIChatPageComponentRAGStatus::EMPTY,
            AIChatPageComponentRAGStatus::SKIPPED,
        ], true);
    }

    /**
     * Collections the retrieval may search for a request
     *
     * Background files belong to the chat and are searched for everybody. Files uploaded
     * in the chat belong to the user who uploaded them: they are only searched for
     * requests of that user, never for other users of the chat or anonymous users.
     *
     * @param int|null $user_id User who sent the request; null for anonymous users (background files only)
     * @return string[] Collections of the background files of the chat and of the uploads of the user
     */
    protected function getAllRAGCollectionIds(ChatConfig $chat_config, ?int $user_id = null): array
    {
        global $DIC;
        $db = $DIC->database();

        $collection_ids = [];

        try {
            $query = "SELECT rag_collection_id, resource_id, rag_status FROM pcaic_attachments " .
                     "WHERE chat_id = " . $db->quote($chat_config->getChatId(), 'text') . " " .
                     "AND rag_collection_id IS NOT NULL " .
                     "AND " . self::ragCollectionOwnerCondition($user_id) . " " .
                     "AND " . ChatResource::usableAttachmentCondition();

            // A collection is searched only while it holds a file of the RAG file types: files of
            // other types are removed from the RAG by the cron job, until then they are not searched
            // if they are the only files of their collection
            $policy = $this->getFileTypePolicy();
            $result = $db->query($query);
            while ($row = $db->fetchAssoc($result)) {
                $collection_id = (string) ($row['rag_collection_id'] ?? '');
                if ($collection_id === '' || in_array($collection_id, $collection_ids, true)) {
                    continue;
                }
                if (!self::isSearchableRagStatus($row['rag_status'] ?? null)) {
                    continue;
                }
                if ($policy->isRagType(Attachment::suffixOfResource((string) $row['resource_id']))) {
                    $collection_ids[] = $collection_id;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to get RAG collection IDs", ['error' => $e->getMessage()]);
        }

        return array_unique($collection_ids);
    }

    /**
     * Content of the page to add to a request directly, see ChatResource::TYPE_PAGE_CONTEXT
     *
     * The snapshot of the page content is a resource: searched in the RAG if the RAG is used,
     * otherwise added as it was stored. Chats without the resource (from before resources
     * existed) still use the current content of the page if "include page context" is set.
     *
     * @param bool $searched_in_rag The resources of the chat are searched in the RAG for this request
     * @return string|null Null if nothing is to be added
     */
    protected function getPageContextForRequest(ChatConfig $chat_config, bool $searched_in_rag): ?string
    {
        $resource = ChatResource::findPageContext($chat_config->getChatId());
        if ($resource === null) {
            $live = $chat_config->isIncludePageContext() ? $this->getPageContext($chat_config) : '';
            return $live !== '' ? $live : null;
        }
        if (!$resource->isEnabled() || $searched_in_rag) {
            return null;
        }
        foreach ($resource->getAttachments() as $attachment) {
            $text = $attachment->getTextContent();
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
        }
        return null;
    }

    /**
     * Current text of the page containing the chat, for the snapshot of the page content resource
     */
    public function getPageContextText(ChatConfig $chat_config): string
    {
        return $this->getPageContext($chat_config);
    }

    /**
     * Visible text (paragraphs) of the page containing the chat
     */
    protected function getPageContext(ChatConfig $chat_config): string
    {
        global $DIC;

        $page_id = (int) $chat_config->getPageId();
        $parent_id = (int) $chat_config->getParentId();
        $parent_type = (string) $chat_config->getParentType();

        if (!$page_id && !$parent_id) {
            return '';
        }

        // Page object type used by the page manager for the parent type
        $copage_type_map = [
            'crs' => 'cont',
            'grp' => 'cont',
            'cont' => 'cont',
            'cat' => 'cont',
            'lm' => 'lm',
            'wpg' => 'wpg',
            'wiki' => 'wpg',
            'copa' => 'copa',
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

    /**
     * Send the conversation to the API
     *
     * @param array $messages Messages in the API format
     * @param array|null $context_resources Page context, background files
     * @return string Answer text
     * @throws AIChatPageComponentException
     */
    abstract public function sendMessagesArray(array $messages, ?array $context_resources = null): string;

    /**
     * @return string[] Allowed file extensions
     */
    public function getAllowedFileTypes(bool $rag_enabled): array
    {
        return $this->getFileTypePolicy()->uploadTypes($rag_enabled);
    }

    /**
     * Rule for the file types of this service, see FileTypePolicy
     */
    public function getFileTypePolicy(): FileTypePolicy
    {
        return FileTypePolicy::forService(static::getServiceId());
    }

    /**
     * @return string[] File types uploaded to the RAG
     */
    public function getRagFileTypes(): array
    {
        return $this->supportsRAG() ? $this->getFileTypePolicy()->ragTypes() : [];
    }

    public function isFileTypeAllowed(string $extension, bool $rag_enabled): bool
    {
        $extension = strtolower($extension);
        $allowed = $this->getAllowedFileTypes($rag_enabled);
        return in_array($extension, $allowed, true);
    }

    public function getAllowedFileTypesDescription(bool $rag_enabled): string
    {
        $types = $this->getAllowedFileTypes($rag_enabled);
        return implode(', ', array_map(fn($type) => strtoupper($type), $types));
    }

    /**
     * Additional request parameters such as temperature
     */
    protected function getModelParameters(): array
    {
        return [
            'temperature' => 0.7
        ];
    }

    /**
     * RAG is provided by the separate RAG service for every AI service
     */
    public function supportsRAG(): bool
    {
        return AIChatPageComponentRAG::isAvailable();
    }

    /**
     * RAG is used if the RAG service is available, allowed for the AI service
     * and enabled in the chat
     *
     * The setting of this service decides, not the one of the service stored in the
     * chat: they differ if the administrator enforces the default service.
     */
    public function isRagEnabledForChat(ChatConfig $chat_config): bool
    {
        if (!$this->supportsRAG()) {
            return false;
        }

        $rag_config_key = static::getServiceId() . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        if (!$rag_globally_enabled) {
            return false;
        }

        return $chat_config->isEnableRag();
    }

    public function supportsMultimodal(): bool
    {
        return false;
    }

    public function supportsBase64Images(): bool
    {
        return false;
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    /**
     * @return array{collection_id: string, remote_file_id: string}
     * @throws AIChatPageComponentException
     */
    public function uploadFileToRAG(string $filepath, string $entity_id, ?string $filename = null): array
    {
        if (!$this->supportsRAG()) {
            throw new AIChatPageComponentException("RAG service is not enabled or not configured");
        }

        return (new AIChatPageComponentRAG())->uploadFile($filepath, $entity_id, $filename);
    }

    /**
     * Delete a file from the RAG service
     *
     * Also works if RAG is disabled, so that no files remain in the RAG.
     */
    public function deleteFileFromRAG(string $remote_file_id, string $entity_id): bool
    {
        $deleted = (new AIChatPageComponentRAG())->deleteFile($remote_file_id, $entity_id);
        if (!$deleted) {
            // E.g. still being processed: deleted later, so that it does not remain in the RAG
            AIChatPageComponentRAGStatus::queueDeletion($remote_file_id, $entity_id);
        }
        return $deleted;
    }

    /**
     * Answer with RAG: the RAG service retrieves relevant passages and returns the
     * augmented conversation, which is sent to this AI service
     *
     * Without relevant passages the original conversation is sent, so that the AI
     * service can still answer.
     *
     * @throws AIChatPageComponentException
     */
    public function sendRagChat(array $messages, array $collection_ids, ?array $context_resources = null): string
    {
        if (!$this->supportsRAG()) {
            // RAG not available: send without retrieval
            $this->logger->warning("RAG service not available, falling back to standard chat", [
                'service' => get_class($this),
                'collections' => $collection_ids
            ]);
            return $this->sendMessagesArray($messages, $context_resources);
        }

        // Only text goes to the RAG: images leave ILIAS towards the AI service only
        $source_indexes = [];
        $retrieval = (new AIChatPageComponentRAG())->augment(
            $this->toTextMessages($messages, $source_indexes),
            $collection_ids
        );

        if (empty($retrieval['chunks'])) {
            $this->logger->debug("RAG found no relevant chunks, answering without retrieved context", [
                'collections' => $collection_ids
            ]);
            return $this->sendMessagesArray($this->addNoSourcesNote($messages), $context_resources);
        }

        $this->last_response_metadata = AIChatPageComponentRAG::chunksToSources($retrieval['chunks']);
        if ($this->isStreaming() && $this->sources_listener !== null) {
            ($this->sources_listener)($this->last_response_metadata);
        }

        $prompt = $this->prompt;
        $this->prompt = $this->getRagSystemPrompt();
        try {
            [$answer_messages, $answer_resources] = $this->restoreImageParts(
                $messages,
                $source_indexes,
                $retrieval['messages'],
                $context_resources ?? []
            );
            $response = $this->sendMessagesArray($answer_messages, $answer_resources);
        } finally {
            $this->prompt = $prompt;
        }

        return AIChatPageComponentRAG::convertCitationMarkers($response);
    }

    /**
     * System prompt of the chat with the citation rules for RAG answers
     */
    protected function getRagSystemPrompt(): string
    {
        $prompt = trim((string) $this->prompt);
        return ($prompt === '' ? '' : $prompt . "\n\n") . self::RAG_CITATION_INSTRUCTION;
    }

    /**
     * sendRagChat(), with a new upload of the files if the RAG no longer grants access
     * to the stored collections (e.g. after a change of the tenant)
     *
     * Only an explicit rejection of the collections triggers the new upload; an
     * unreachable RAG service is reported as error. The request is repeated once.
     *
     * @param ChatSession|null $session Session of the user; null for anonymous users
     * @throws AIChatPageComponentException
     */
    protected function sendRagChatWithRebind(
        ChatConfig $chat_config,
        ?ChatSession $session,
        array $messages,
        array $collection_ids,
        ?array $context_resources
    ): string {
        try {
            return $this->sendRagChat($messages, $collection_ids, $context_resources);
        } catch (AIChatPageComponentRAGBindingLostException $e) {
            $this->logger->warning(
                "RAG collections of chat " . $chat_config->getChatId() . " not accessible, files are uploaded again: "
                . implode(', ', $e->getCollectionIds())
            );

            $this->resetRagReferences($chat_config->getChatId(), $e->getCollectionIds());
            $this->ensureBackgroundFilesInRAG($chat_config);
            if ($session !== null) {
                $this->syncChatAttachmentsToRAG($session, min($chat_config->getMaxMemory(), 20));
            }

            $this->clearLastResponseData();
            $collection_ids = $this->getAllRAGCollectionIds($chat_config, $session !== null ? $session->getUserId() : null);
            if (empty($collection_ids)) {
                return $this->sendMessagesArray($messages, $context_resources);
            }
            return $this->sendRagChat($messages, $collection_ids, $context_resources);
        }
    }

    /**
     * Remove the stored RAG references of the files of a chat in the given collections,
     * so that they are uploaded again
     *
     * @param string[] $collection_ids
     */
    protected function resetRagReferences(string $chat_id, array $collection_ids): void
    {
        if (empty($collection_ids)) {
            return;
        }

        global $DIC;
        $db = $DIC->database();
        $in = $db->in('rag_collection_id', $collection_ids, false, 'text');

        $db->manipulate(
            "UPDATE pcaic_attachments SET rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL,"
            . " rag_status = NULL, rag_status_error = NULL, rag_failed_count = 0, rag_processing_failures = 0, rag_retry_at = NULL"
            . " WHERE chat_id = " . $db->quote($chat_id, 'text') . " AND " . $in
        );
        $db->manipulate(
            "UPDATE pcaic_chats SET rag_collection_id = NULL"
            . " WHERE chat_id = " . $db->quote($chat_id, 'text') . " AND " . $in
        );
    }

    /**
     * Tell the AI service that the documents of the chat contain nothing relevant,
     * so that it does not invent their content
     */
    protected function addNoSourcesNote(array $messages): array
    {
        // Limited to information from the documents: a general rule made models refuse to look at attached images
        $note = "[Note: The document search of this chat found no passages relevant to this question."
            . " Answer from the conversation and any attached images. Only for information that would have to"
            . " come from the documents, say that no matching information was found instead of guessing.]";

        $last = count($messages) - 1;
        if ($last < 0) {
            return $messages;
        }
        if (is_array($messages[$last]['content'])) {
            // In the text part, before the images: a note after an image is read as referring to it
            foreach ($messages[$last]['content'] as $i => $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $messages[$last]['content'][$i]['text'] = $part['text'] . "\n\n" . $note;
                    return $messages;
                }
            }
            array_unshift($messages[$last]['content'], ['type' => 'text', 'text' => $note]);
        } else {
            $messages[$last]['content'] .= "\n\n" . $note;
        }
        return $messages;
    }

    /**
     * Reduce messages to user/assistant messages with text content, for the RAG service
     *
     * The text parts of a message become one text: the RAG service accepts one text part per
     * message only. A user message without text, e.g. with an image only, gets a placeholder,
     * because the RAG service rejects a last message without text; other messages without text
     * are left out. The augmented messages may contain fields that chat APIs reject.
     *
     * @param int[]|null $source_indexes Set to the index in $messages of each returned message
     */
    protected function toTextMessages(array $messages, ?array &$source_indexes = null): array
    {
        $result = [];
        $source_indexes = [];
        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = self::textOfMessage($message);
            if (trim($content) === '') {
                if ($role !== 'user' || self::imagePartsOfMessage($message) === []) {
                    continue;
                }
                $content = self::imageOnlyPlaceholder();
            }

            $result[] = ['role' => $role, 'content' => $content];
            $source_indexes[] = $index;
        }

        return $result;
    }

    /**
     * Text parts of a message as one text
     */
    protected static function textOfMessage(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $texts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $texts[] = (string) $part['text'];
                }
            }
            return implode("\n", $texts);
        }
        return is_string($content) ? $content : '';
    }

    /**
     * @return array[] Image parts (images and PDF pages) of a message
     */
    protected static function imagePartsOfMessage(array $message): array
    {
        $content = $message['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }
        return array_values(array_filter(
            $content,
            fn($part): bool => is_array($part) && ($part['type'] ?? '') === 'image_url'
        ));
    }

    /**
     * Text of a user message that contains images only, in the language of the user
     */
    protected static function imageOnlyPlaceholder(): string
    {
        try {
            $text = \ilAIChatPageComponentPlugin::getInstance()->txt('rag_image_only_query');
            if ($text !== '' && $text[0] !== '-') {
                return $text;
            }
        } catch (\Throwable $e) {
        }
        return 'Please look at the attached image.';
    }

    /**
     * Set the images of the messages sent to the RAG into the messages the RAG returned
     *
     * The RAG service returns the messages it received in the same order and replaces the text
     * of the last one only, so each image goes back to its message by position. If the number or
     * the roles of the messages differ, the images are passed as context resources instead.
     *
     * @param array $messages Messages with images, as built for the AI service
     * @param int[] $source_indexes Index in $messages of each message sent to the RAG (see toTextMessages())
     * @param array $returned Messages returned by the RAG
     * @return array{0: array, 1: array} Messages and context resources for the AI service
     */
    protected function restoreImageParts(array $messages, array $source_indexes, array $returned, array $context_resources): array
    {
        $answer = [];
        foreach ($returned as $message) {
            if (is_array($message) && in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                $answer[] = ['role' => $message['role'], 'content' => self::textOfMessage($message)];
            }
        }

        $aligned = count($answer) === count($source_indexes);
        for ($i = 0; $aligned && $i < count($answer); $i++) {
            $aligned = ($messages[$source_indexes[$i]]['role'] ?? '') === $answer[$i]['role'];
        }

        if ($aligned) {
            foreach ($answer as $i => $message) {
                $images = self::imagePartsOfMessage($messages[$source_indexes[$i]]);
                if ($images !== []) {
                    $answer[$i]['content'] = array_merge([['type' => 'text', 'text' => $message['content']]], $images);
                }
            }
            return [$answer, $context_resources];
        }

        // Fallback: the images of all messages as context resources
        $this->logger->warning("RAG returned " . count($answer) . " messages for " . count($source_indexes)
            . " sent, images are passed as context");
        $number = 0;
        foreach ($messages as $message) {
            foreach (self::imagePartsOfMessage($message) as $part) {
                $number++;
                $url = (string) ($part['image_url']['url'] ?? '');
                $context_resources[] = [
                    'kind' => 'image_file',
                    'id' => 'chat-img-' . $number,
                    'title' => 'Chat image ' . $number,
                    'mime_type' => preg_match('#^data:([^;,]+)#', $url, $match) ? $match[1] : 'image/png',
                    'url' => $url
                ];
            }
        }
        return [$answer, $context_resources];
    }

    /**
     * Load the model list from the API
     *
     * @return array{success: bool, message: string, models: array|null}
     */
    public function refreshModels(): array
    {
        return [
            'success' => false,
            'message' => 'Model refresh not implemented for ' . static::getServiceName(),
            'models' => null
        ];
    }

    /**
     * Upload background files that are not in the RAG, e.g. after the RAG service
     * has been changed
     */
    protected function ensureBackgroundFilesInRAG(ChatConfig $chat_config): void
    {
        if (!$this->isRagEnabledForChat($chat_config)) {
            return;
        }

        $stats = $this->syncBackgroundFilesToRAG($chat_config);
        if ($stats['uploaded'] > 0 || $stats['errors'] > 0) {
            $this->logger->info("Background files synced to RAG before sending", $stats);
        }

        // At most one status query to the RAG per chat and minute
        AIChatPageComponentRAGStatus::refreshChat($chat_config->getChatId());
        $this->rag_incomplete = AIChatPageComponentRAGStatus::hasUnprocessedBackgroundFiles($chat_config->getChatId());
    }

    /**
     * Whether background files of the chat were not (yet) usable in the RAG for the last answer
     */
    public function isRagIncomplete(): bool
    {
        return $this->rag_incomplete;
    }

    /**
     * Upload background files of the RAG file types that are not in the RAG yet
     *
     * @param int|null $deadline No further upload is started after this time (Unix timestamp); null = no limit
     * @return array{uploaded: int, skipped: int, errors: int}
     */
    public function syncBackgroundFilesToRAG(ChatConfig $chat_config, ?int $deadline = null): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            global $DIC;
            $db = $DIC->database();

            $query = "SELECT id, resource_id, message_id, resource_ref
                      FROM pcaic_attachments
                      WHERE chat_id = " . $db->quote($chat_config->getChatId(), 'text') . "
                      AND background_file = 1
                      AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')
                      AND " . AIChatPageComponentRAGStatus::uploadDueCondition();

            $result = $db->query($query);
            $attachments_to_upload = [];

            while ($row = $db->fetchAssoc($result)) {
                $attachments_to_upload[] = $row;
            }

            if (empty($attachments_to_upload)) {
                $this->logger->debug("No background files need RAG sync", ['chat_id' => $chat_config->getChatId()]);
                return $stats;
            }

            $this->logger->info("Starting RAG sync for background files", [
                'chat_id' => $chat_config->getChatId(),
                'count' => count($attachments_to_upload)
            ]);

            $irss = $DIC->resourceStorage();

            foreach ($attachments_to_upload as $att_row) {
                if ($deadline !== null && time() >= $deadline) {
                    // The rest is uploaded with the next run
                    break;
                }
                // Only a failure of an upload this request reserved is recorded: before the
                // reservation, another request may be uploading the file
                $claimed = false;
                try {
                    $attachment = Attachment::loadById((int) $att_row['id']);
                    if (!$attachment) {
                        $stats['skipped']++;
                        continue;
                    }

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

                    $rag_compatible = in_array($suffix, $this->getRagFileTypes(), true);
                    if (!$rag_compatible) {
                        AIChatPageComponentRAGStatus::markSkipped((int) $att_row['id']);
                        $stats['skipped']++;
                        continue;
                    }

                    // Another request (chat or cron job) is uploading the file
                    if (!AIChatPageComponentRAGStatus::claimUpload((int) $att_row['id'])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $claimed = true;

                    // Collection of the resource the file belongs to
                    $entity_id = Attachment::backgroundEntityId(
                        $chat_config->getChatId(),
                        isset($att_row['resource_ref']) ? (int) $att_row['resource_ref'] : null
                    );
                    $stream = $irss->consume()->stream($identification);

                    // Temporary file with the original name, the RAG validates the extension
                    $original_filename = $revision->getTitle();
                    $temp_file = sys_get_temp_dir() . '/' . 'rag_sync_' . uniqid() . '_' . basename($original_filename);
                    file_put_contents($temp_file, $stream->getStream()->getContents());
                    try {
                        $upload_result = $this->uploadFileToRAG($temp_file, $entity_id, $original_filename);
                    } finally {
                        @unlink($temp_file);
                    }

                    // The file was removed during the upload: the copy in the RAG is deleted later
                    if (!AIChatPageComponentRAGStatus::attachmentExists((int) $att_row['id'])) {
                        AIChatPageComponentRAGStatus::queueDeletion((string) $upload_result['remote_file_id'], (string) $entity_id);
                        $stats['skipped']++;
                        continue;
                    }

                    $attachment->setRagCollectionId($upload_result['collection_id']);
                    $attachment->setRagRemoteFileId($upload_result['remote_file_id']);
                    $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));
                    $attachment->save();
                    AIChatPageComponentRAGStatus::markUploaded((int) $att_row['id']);

                    $this->logger->info("Synced background file to RAG", [
                        'attachment_id' => $att_row['id'],
                        'remote_file_id' => $upload_result['remote_file_id'],
                        'collection_id' => $upload_result['collection_id']
                    ]);

                    $stats['uploaded']++;

                } catch (\Exception $e) {
                    $this->logger->error("Failed to sync attachment to RAG: attachment " . $att_row['id'] . " | " . $e->getMessage());
                    if ($claimed) {
                        // An upload the RAG rejected is not repeated
                        AIChatPageComponentRAGStatus::markFailed(
                            (int) $att_row['id'],
                            $e->getMessage(),
                            AIChatPageComponentRAGStatus::isPermanentUploadError((int) $e->getCode()) ? 1 : 0
                        );
                    }
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
     * Upload attachments of the session that are not in the RAG yet
     *
     * @param int|null $max_memory Only attachments of this number of recent messages (the messages sent to the
     *                             AI service); null = all messages of the session. The retrieval searches the
     *                             files of the whole chat, so the cron job retries all of them.
     * @param int|null $deadline No further upload is started after this time (Unix timestamp); null = no limit
     * @return array{uploaded: int, skipped: int, errors: int}
     */
    public function syncChatAttachmentsToRAG(ChatSession $session, ?int $max_memory, ?int $deadline = null): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            global $DIC;
            $db = $DIC->database();

            if ($max_memory === null) {
                $message_condition = "message_id IN (SELECT message_id FROM pcaic_messages WHERE session_id = "
                    . $db->quote($session->getSessionId(), 'text') . ")";
                $message_count = null;
            } else {
                $recent_messages = $session->getRecentMessages($max_memory);
                if (empty($recent_messages)) {
                    return $stats;
                }
                $message_ids = array_map(fn($msg) => $msg->getMessageId(), $recent_messages);
                $message_condition = "message_id IN ("
                    . implode(',', array_map(fn($id) => $db->quote($id, 'integer'), $message_ids)) . ")";
                $message_count = count($recent_messages);
            }

            $query = "SELECT id, resource_id, message_id
                      FROM pcaic_attachments
                      WHERE " . $message_condition . "
                      AND background_file = 0
                      AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')
                      AND " . AIChatPageComponentRAGStatus::uploadDueCondition();

            $result = $db->query($query);
            $attachments_to_upload = [];

            while ($row = $db->fetchAssoc($result)) {
                $attachments_to_upload[] = $row;
            }

            if (empty($attachments_to_upload)) {
                $this->logger->debug("No chat attachments need RAG sync", [
                    'session_id' => $session->getSessionId(),
                    'message_count' => $message_count
                ]);
                return $stats;
            }

            $this->logger->info("Starting RAG sync for chat attachments", [
                'session_id' => $session->getSessionId(),
                'count' => count($attachments_to_upload)
            ]);

            $irss = $DIC->resourceStorage();
            $chat_id = $session->getChatId();

            foreach ($attachments_to_upload as $att_row) {
                if ($deadline !== null && time() >= $deadline) {
                    // The rest is uploaded with the next run
                    break;
                }
                // Only a failure of an upload this request reserved is recorded: before the
                // reservation, another request may be uploading the file
                $claimed = false;
                try {
                    $attachment = Attachment::loadById((int) $att_row['id']);
                    if (!$attachment) {
                        $stats['skipped']++;
                        continue;
                    }

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

                    $rag_compatible = in_array($suffix, $this->getRagFileTypes(), true);
                    if (!$rag_compatible) {
                        AIChatPageComponentRAGStatus::markSkipped((int) $att_row['id']);
                        $stats['skipped']++;
                        continue;
                    }

                    // Another request (chat or cron job) is uploading the file
                    if (!AIChatPageComponentRAGStatus::claimUpload((int) $att_row['id'])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $claimed = true;

                    // Same entity as the direct upload in api.php and Attachment::delete()
                    $entity_id = (string) $session->getSessionId();
                    $stream = $irss->consume()->stream($identification);

                    // Temporary file with the original name, the RAG validates the extension
                    $original_filename = $revision->getTitle();
                    $temp_file = sys_get_temp_dir() . '/' . 'rag_chat_sync_' . uniqid() . '_' . basename($original_filename);
                    file_put_contents($temp_file, $stream->getStream()->getContents());
                    try {
                        $upload_result = $this->uploadFileToRAG($temp_file, $entity_id, $original_filename);
                    } finally {
                        @unlink($temp_file);
                    }

                    // The file was removed during the upload: the copy in the RAG is deleted later
                    if (!AIChatPageComponentRAGStatus::attachmentExists((int) $att_row['id'])) {
                        AIChatPageComponentRAGStatus::queueDeletion((string) $upload_result['remote_file_id'], (string) $entity_id);
                        $stats['skipped']++;
                        continue;
                    }

                    $attachment->setRagCollectionId($upload_result['collection_id']);
                    $attachment->setRagRemoteFileId($upload_result['remote_file_id']);
                    $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));
                    $attachment->save();
                    AIChatPageComponentRAGStatus::markUploaded((int) $att_row['id']);

                    $this->logger->info("Synced chat attachment to RAG", [
                        'attachment_id' => $att_row['id'],
                        'message_id' => $att_row['message_id'],
                        'remote_file_id' => $upload_result['remote_file_id'],
                        'collection_id' => $upload_result['collection_id']
                    ]);

                    $stats['uploaded']++;

                } catch (\Exception $e) {
                    $this->logger->error("Failed to sync chat attachment to RAG: attachment " . $att_row['id'] . " | " . $e->getMessage());
                    if ($claimed) {
                        // An upload the RAG rejected is not repeated
                        AIChatPageComponentRAGStatus::markFailed(
                            (int) $att_row['id'],
                            $e->getMessage(),
                            AIChatPageComponentRAGStatus::isPermanentUploadError((int) $e->getCode()) ? 1 : 0
                        );
                    }
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
