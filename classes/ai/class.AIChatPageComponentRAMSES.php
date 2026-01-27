<?php declare(strict_types=1);

namespace ai;
use platform\AIChatPageComponentException;

/**
 * RAMSES AI Service Integration for PageComponent
 *
 * Integrates with the RAMSES (Mistral-based) AI service at University of Cologne.
 * Handles multimodal conversations including text, images, and PDF documents.
 *
 * Features:
 * - OpenAI-compatible API endpoint integration
 * - Multimodal message formatting (text + images)
 * - Context resource management for background files
 * - Conversation memory and session handling
 * - Error handling and logging
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @see AIChatPageComponentLLM Base class for AI service integrations
 *
 * @package ai
 */
class AIChatPageComponentRAMSES extends AIChatPageComponentLLM
{
    /** @var string Standard chat completions endpoint */
    private const ENDPOINT_CHAT = '/v1/chat/completions';

    /** @var string Models listing endpoint */
    private const ENDPOINT_MODELS = '/v1/models';

    /** @var string RAG chat completions endpoint */
    private const ENDPOINT_RAG_CHAT = '/v1/rag/completions';

    /** @var string RAG file upload endpoint */
    private const ENDPOINT_RAG_UPLOAD = '/v1/rag/upload';

    /** @var string RAG file deletion endpoint */
    private const ENDPOINT_RAG_DELETE = '/v1/rag/delete';

    /** @var string AI model identifier (e.g., 'mistral-small-3-2-24b-instruct-2506') */
    private string $model;

    /** @var string API key for RAMSES service authentication */
    private string $apiKey;

    // ============================================
    // Service Metadata Implementation
    // ============================================

    public static function getServiceId(): string
    {
        return 'ramses';
    }

    public static function getServiceName(): string
    {
        return 'RAMSES';
    }

    public static function getServiceDescription(): string
    {
        return 'RAMSES AI Service';
    }

    // ============================================
    // Configuration Management Implementation
    // ============================================

    public function getConfigurationFormInputs(): array
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        $inputs = [];

        // Service enabled checkbox
        $ramses_enabled = \platform\AIChatPageComponentConfig::get('ramses_service_enabled') ?? '0';
        $inputs['ramses_service_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_service_enabled'),
            $plugin->txt('config_service_enabled_info')
        )->withValue($ramses_enabled === '1');

        // API URL - ensure string
        $api_url = \platform\AIChatPageComponentConfig::get('ramses_api_url');
        $inputs['ramses_api_url'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_api_url'),
            $plugin->txt('config_api_url_info')
        )->withValue((string)($api_url ?: 'https://ramses-oski.itcc.uni-koeln.de'))->withRequired(true);

        // API Token - ensure string
        $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');
        $inputs['ramses_api_token'] = $ui_factory->input()->field()->password(
            $plugin->txt('config_api_token'),
            $plugin->txt('config_api_token_info')
        )->withValue((string)($api_token ?: ''))->withRequired(true);

        // Model Selection
        $selected_model = \platform\AIChatPageComponentConfig::get('ramses_selected_model');
        $cached_models = \platform\AIChatPageComponentConfig::get('cached_models');

        if (is_array($cached_models) && !empty($cached_models)) {
            // $cached_models is already in correct format: ['model-id' => 'Display Name']
            $model_options = $cached_models;

            $select_field = $ui_factory->input()->field()->select(
                $plugin->txt('config_selected_model'),
                $model_options,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            // Only set value if it exists in options
            if ($selected_model && isset($model_options[$selected_model])) {
                $select_field = $select_field->withValue($selected_model);
            }

            $inputs['ramses_selected_model'] = $select_field;
        } else {
            $inputs['ramses_selected_model'] = $ui_factory->input()->field()->text(
                $plugin->txt('config_selected_model'),
                $plugin->txt('refresh_models_not_loaded')
            )->withValue((string)($selected_model ?: ''))->withDisabled(true);
        }

        // Temperature - use text field with custom validation to support comma/dot
        $temperature = \platform\AIChatPageComponentConfig::get('ramses_temperature');
        $temp_value = '0.7'; // default as string
        if ($temperature !== null && $temperature !== '') {
            $temp_value = (string)$temperature;
        }

        // Create constraint that accepts comma or dot as decimal separator
        $refinery = $DIC->refinery();
        $temp_constraint = $refinery->custom()->constraint(
            function ($value) {
                if (is_string($value)) {
                    $normalized = str_replace(',', '.', $value);
                    return is_numeric($normalized);
                }
                return is_numeric($value);
            },
            'Must be a number (use comma or dot as decimal separator)'
        );

        // Create transformation to convert to float
        $temp_trafo = $refinery->custom()->transformation(
            function ($value) {
                if (is_string($value)) {
                    $value = str_replace(',', '.', $value);
                }
                return is_numeric($value) ? (float)$value : 0.7;
            }
        );

        $inputs['ramses_temperature'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_temperature'),
            $plugin->txt('config_temperature_info')
        )->withValue($temp_value)
         ->withAdditionalTransformation($temp_constraint)
         ->withAdditionalTransformation($temp_trafo);

        // Streaming enabled
        $streaming_enabled = \platform\AIChatPageComponentConfig::get('ramses_streaming_enabled') ?? '1';
        $inputs['ramses_streaming_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_streaming'),
            $plugin->txt('config_streaming_info')
        )->withValue($streaming_enabled === '1');

        // File handling enabled
        $file_handling_enabled = \platform\AIChatPageComponentConfig::get('ramses_file_handling_enabled') ?? '1';
        $inputs['ramses_file_handling_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_file_handling'),
            $plugin->txt('config_file_handling_info')
        )->withValue($file_handling_enabled === '1');

        // RAG Mode enabled with OptionalGroup for RAG-specific settings
        $rag_enabled = \platform\AIChatPageComponentConfig::get('ramses_enable_rag') ?? '1';

        // Build RAG sub-inputs
        $rag_sub_inputs = [];

        $app_id = \platform\AIChatPageComponentConfig::get('ramses_application_id');
        $rag_sub_inputs['ramses_application_id'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_rag_application_id'),
            $plugin->txt('config_rag_application_id_info')
        )->withValue((string)($app_id ?: 'ILIAS'));

        $instance_id = \platform\AIChatPageComponentConfig::get('ramses_instance_id');
        $rag_sub_inputs['ramses_instance_id'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_rag_instance_id'),
            $plugin->txt('config_rag_instance_id_info')
        )->withValue((string)($instance_id ?: 'ilias9'));

        // RAG allowed file types - ensure it's a string
        $rag_file_types = \platform\AIChatPageComponentConfig::get('ramses_rag_allowed_file_types');
        if (is_array($rag_file_types)) {
            $rag_file_types = implode(',', $rag_file_types);
        }
        $rag_sub_inputs['ramses_rag_allowed_file_types'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_rag_allowed_file_types'),
            $plugin->txt('config_rag_allowed_file_types_info')
        )->withValue($rag_file_types ?: 'txt,md,csv,pdf');

        // Create OptionalGroup
        $rag_optional_group = $ui_factory->input()->field()->optionalGroup(
            $rag_sub_inputs,
            $plugin->txt('config_enable_rag'),
            $plugin->txt('config_enable_rag_info')
        );

        // Set value based on current state
        if ($rag_enabled === '1') {
            $rag_optional_group = $rag_optional_group->withValue([
                'ramses_application_id' => (string)($app_id ?: 'ILIAS'),
                'ramses_instance_id' => (string)($instance_id ?: 'ilias9'),
                'ramses_rag_allowed_file_types' => $rag_file_types ?: 'txt,md,csv,pdf'
            ]);
        } else {
            $rag_optional_group = $rag_optional_group->withValue(null);
        }

        $inputs['ramses_rag_config'] = $rag_optional_group;

        return $inputs;
    }

    public function saveConfiguration(array $formData): void
    {
        foreach ($formData as $key => $value) {
            // Handle RAG OptionalGroup
            if ($key === 'ramses_rag_config') {
                if (is_array($value) && !empty($value)) {
                    // RAG enabled - save enabled state and sub-fields
                    \platform\AIChatPageComponentConfig::set('ramses_enable_rag', '1');
                    foreach ($value as $sub_key => $sub_value) {
                        \platform\AIChatPageComponentConfig::set($sub_key, $sub_value);
                    }
                } else {
                    // RAG disabled
                    \platform\AIChatPageComponentConfig::set('ramses_enable_rag', '0');
                }
                continue;
            }

            // Handle ILIAS Password object
            if ($value instanceof \ILIAS\Data\Password) {
                $value = $value->toString();
            }

            // Handle checkbox boolean conversion
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            // Handle numeric temperature with decimal separator normalization
            if ($key === 'ramses_temperature' && is_numeric($value)) {
                $value = (float)$value;
            }

            \platform\AIChatPageComponentConfig::set($key, $value);
        }
    }

    public static function getDefaultConfiguration(): array
    {
        return [
            'ramses_service_enabled' => '0',
            'ramses_api_url' => 'https://ramses-oski.itcc.uni-koeln.de',
            'ramses_api_token' => '',
            'ramses_selected_model' => '',
            'ramses_temperature' => 0.7,
            'ramses_streaming_enabled' => '1',
            'ramses_file_handling_enabled' => '1',
            'ramses_enable_rag' => '1',
            'ramses_application_id' => 'ILIAS',
            'ramses_instance_id' => 'ilias9',
            'ramses_rag_allowed_file_types' => 'txt,md,csv,pdf',
            'cached_models' => []
        ];
    }

    // ============================================
    // Service Capabilities Implementation
    // ============================================

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'rag' => true,
            'multimodal' => true,
            'file_types' => ['txt', 'md', 'csv', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            'rag_file_types' => ['txt', 'md', 'csv', 'pdf'],
            'max_tokens' => null, // No hard limit documented
        ];
    }

    // ============================================
    // Existing RAMSES Methods
    // ============================================

    /**
     * Get available RAMSES models from configuration
     *
     * @return array Available models from cached API response
     */
    public static function getModelTypes(): array
    {
        $cached_models = \platform\AIChatPageComponentConfig::get('cached_models');

        if (is_array($cached_models) && !empty($cached_models)) {
            return $cached_models;
        }

        return [];
    }

    /**
     * Get model-specific API parameters for RAMSES
     *
     * RAMSES (Mistral) supports temperature parameter.
     * Uses configured value from plugin settings.
     *
     * @return array Associative array of API parameters
     */
    protected function getModelParameters(): array
    {
        $temperature = \platform\AIChatPageComponentConfig::get('ramses_temperature') ?: 0.7;

        return [
            'temperature' => (float)$temperature
        ];
    }

    /**
     * Constructor
     *
     * Initializes RAMSES service with model and API configuration.
     *
     * @param string|null $model Optional model identifier, uses configured model if not provided
     */
    public function __construct(string $model = null)
    {
        parent::__construct();

        if ($model === null) {
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
        }

        $this->model = $model;
        $this->apiKey = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
    }

    /**
     * Construct full API endpoint URL from base URL and endpoint path
     *
     * @param string $endpoint Endpoint path constant (e.g., self::ENDPOINT_CHAT)
     * @return string Complete API URL
     */
    private function getEndpointUrl(string $endpoint): string
    {
        $baseUrl = \platform\AIChatPageComponentConfig::get('ramses_api_url') ?: 'https://ramses-oski.itcc.uni-koeln.de';

        // Remove trailing slash from base URL if present
        $baseUrl = rtrim($baseUrl, '/');

        // Ensure endpoint starts with slash
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        return $baseUrl . $endpoint;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setStreaming(bool $streaming): void
    {
        $this->streaming = $streaming;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * Check if RAG mode is supported
     *
     * @return bool Always true for RAMSES
     */
    public function supportsRAG(): bool
    {
        return true;
    }

    /**
     * RAMSES supports multimodal input (images, PDFs)
     */
    public function supportsMultimodal(): bool
    {
        return true;
    }

    /**
     * RAMSES supports base64 image embedding
     */
    public function supportsBase64Images(): bool
    {
        return true;
    }

    /**
     * RAMSES supports streaming responses
     */
    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Get allowed file types based on RAG mode
     *
     * RAMSES RAG limitations:
     * - RAG mode: Text-based files only (configurable, default: txt, md, csv, pdf)
     * - Multimodal mode: Images and PDFs converted to images via Ghostscript
     * - Cannot mix RAG collections with Base64 images in same request
     *
     * @param bool $ragEnabled Whether RAG mode is enabled
     * @return array Array of allowed file extensions
     */
    public function getAllowedFileTypes(bool $ragEnabled): array
    {
        if ($ragEnabled) {
            $configured_types = \platform\AIChatPageComponentConfig::get('ramses_rag_allowed_file_types');

            // Handle both array and comma-separated string formats from config
            if (is_string($configured_types) && !empty($configured_types)) {
                // Convert comma-separated string to array (e.g., "pdf" or "txt,pdf,md")
                $configured_types = array_map('trim', explode(',', $configured_types));
                // Remove empty values and convert to lowercase
                $configured_types = array_filter(array_map('strtolower', $configured_types));
            }

            return is_array($configured_types) && !empty($configured_types)
                ? array_values($configured_types)  // Re-index array
                : ['txt', 'md', 'csv', 'pdf'];
        } else {
            return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf', 'txt', 'md', 'csv'];
        }
    }

    /**
     * Upload file to RAMSES RAG system
     *
     * Converts text-based entity IDs to numeric hashes for RAMSES compatibility.
     *
     * @param string $filepath Local file path
     * @param string $entityId Entity identifier (chat_id for background files, chat_id_session_id for uploads)
     * @return array ['collection_id' => '...', 'remote_file_id' => '...']
     * @throws AIChatPageComponentException If upload fails
     */
    public function uploadFileToRAG(string $filepath, string $entityId): array
    {
        $fileUploadUrl = $this->getEndpointUrl(self::ENDPOINT_RAG_UPLOAD);

        $applicationIdText = \platform\AIChatPageComponentConfig::get('ramses_application_id') ?: 'ILIAS';
        $applicationId = abs(crc32($applicationIdText)) % 2147483647;

        $instanceIdText = \platform\AIChatPageComponentConfig::get('ramses_instance_id') ?: 'ilias9';
        $instanceId = abs(crc32($instanceIdText)) % 999999;

        $entityIdNumeric = abs(crc32($entityId)) % 2147483647;

        if (!file_exists($filepath)) {
            throw new AIChatPageComponentException("File not found: $filepath");
        }

        $filename = basename($filepath);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);

        $curlFile = curl_file_create($filepath, $mimeType, $filename);
        $postData = [
            'file' => $curlFile,
            'applicationid' => $applicationId,
            'instanceid' => $instanceId,
            'entityid' => $entityIdNumeric,
            'purpose' => 'assistants'
        ];

        // Log request details for debugging
        error_log("RAMSES RAG Upload Request:");
        error_log("  File: " . $filepath);
        error_log("  Filename: " . $filename);
        error_log("  MIME Type: " . $mimeType);
        error_log("  File size: " . filesize($filepath) . " bytes");
        error_log("  File exists: " . (file_exists($filepath) ? 'yes' : 'no'));
        error_log("  Application ID (numeric): " . $applicationId . " (from: " . $applicationIdText . ")");
        error_log("  Instance ID (numeric): " . $instanceId . " (from: " . $instanceIdText . ")");
        error_log("  Entity ID (numeric): " . $entityIdNumeric . " (from: " . $entityId . ")");
        error_log("  URL: " . $fileUploadUrl);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $fileUploadUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($curl, CURLOPT_TIMEOUT, 120);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);

        $plugin = \ilAIChatPageComponentPlugin::getInstance();
        $ca_cert_path = realpath($plugin->getDirectory()) . '/certs/RAMSES.pem';
        if (file_exists($ca_cert_path)) {
            curl_setopt($curl, CURLOPT_CAINFO, $ca_cert_path);
        } else {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        // Handle curl_exec returning false on failure
        if ($response === false) {
            $this->logger->error("RAMSES RAG cURL execution failed", [
                'curl_error' => $error,
                'url' => $fileUploadUrl,
                'entity_id' => $entityId,
                'filename' => $filename
            ]);
            throw new AIChatPageComponentException("RAG file upload failed: cURL error - $error");
        }

        $responsePreview = is_string($response) ? substr($response, 0, 500) : '(non-string response)';
        $this->logger->debug("RAMSES RAG Upload Response: HTTP $httpCode | File: $filename | Size: " . filesize($filepath) . " | Response: " . $responsePreview);

        if ($httpCode !== 200) {
            error_log("RAMSES RAG Upload Failed - HTTP $httpCode");
            error_log("cURL Error: " . $error);
            error_log("Response: " . (is_string($response) ? substr($response, 0, 1000) : '(non-string)'));
            error_log("URL: " . $fileUploadUrl);
            error_log("Entity ID: " . $entityId);

            $this->logger->error("RAMSES RAG file upload failed", [
                'http_code' => $httpCode,
                'curl_error' => $error,
                'response' => $responsePreview,
                'url' => $fileUploadUrl,
                'entity_id' => $entityId
            ]);
            throw new AIChatPageComponentException("RAG file upload failed: HTTP $httpCode - $error");
        }

        $data = json_decode($response, true);
        if (!isset($data['collection_id']) || !isset($data['id'])) {
            $this->logger->error("Invalid RAMSES RAG response structure: " . $response . " | Parsed: " . json_encode($data));
            throw new AIChatPageComponentException("Invalid RAMSES response: missing collection_id or id");
        }

        $this->logger->info("File uploaded to RAMSES RAG successfully: collection_id=" . $data['collection_id'] .
                           " | remote_file_id=" . $data['id'] .
                           " | filename=" . $filename .
                           " | size=" . filesize($filepath) .
                           " | Full response: " . json_encode($data));

        return [
            'collection_id' => $data['collection_id'],
            'remote_file_id' => $data['id']
        ];
    }

    /**
     * Delete file from RAMSES RAG system
     *
     * @param string $remoteFileId RAMSES file ID
     * @param string $entityId Entity identifier
     * @return bool True on success
     * @throws AIChatPageComponentException on deletion failure
     */
    public function deleteFileFromRAG(string $remoteFileId, string $entityId): bool
    {
        $fileDeleteUrl = $this->getEndpointUrl(self::ENDPOINT_RAG_DELETE);
        $applicationId = \platform\AIChatPageComponentConfig::get('ramses_application_id') ?: 'ILIAS';
        $instanceId = \platform\AIChatPageComponentConfig::get('ramses_instance_id') ?: 'ilias9';

        $deleteParams = [
            'application_id' => $applicationId,
            'instance_id' => $instanceId,
            'entity_id' => $entityId,
            'id' => $remoteFileId
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $fileDeleteUrl);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST'); // RAMSES uses POST for delete
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($deleteParams));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);

        // Handle SSL certificate
        $plugin = \ilAIChatPageComponentPlugin::getInstance();
        $ca_cert_path = realpath($plugin->getDirectory()) . '/certs/RAMSES.pem';
        if (file_exists($ca_cert_path)) {
            curl_setopt($curl, CURLOPT_CAINFO, $ca_cert_path);
        } else {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        // Handle curl_exec returning false on failure
        if ($response === false) {
            $this->logger->error("RAMSES RAG cURL execution failed during deletion", [
                'curl_error' => $error,
                'remote_file_id' => $remoteFileId,
                'entity_id' => $entityId
            ]);
            return false;
        }

        // Accept 200, 204, and 400 (Moodle compatibility - see chatclient.php:370)
        $success = in_array($httpCode, [200, 204, 400]);

        if ($success) {
            $this->logger->info("File deleted from RAMSES RAG", [
                'remote_file_id' => $remoteFileId,
                'entity_id' => $entityId,
                'http_code' => $httpCode
            ]);
        } else {
            $responsePreview = is_string($response) ? substr($response, 0, 500) : '(non-string response)';
            $this->logger->warning("RAMSES RAG file deletion failed", [
                'http_code' => $httpCode,
                'response' => $responsePreview,
                'curl_error' => $error
            ]);
        }

        return $success;
    }

    /**
     * Send chat with RAG collections
     *
     * @param array $messages Messages array
     * @param array $collectionIds RAG collection IDs
     * @param array|null $contextResources Optional additional context (hybrid mode)
     * @return string AI response
     * @throws AIChatPageComponentException
     */
    public function sendRagChat(array $messages, array $collectionIds, ?array $contextResources = null): string
    {
        $ragApiUrl = $this->getEndpointUrl(self::ENDPOINT_RAG_CHAT);

        // Build messages array
        $messagesArray = [];

        // System message
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }

        // Optional context resources
        // In RAG mode: Only include text files (images/PDFs are in RAG collection)
        // In hybrid mode: Could include both RAG + Base64, but for now skip Base64 to avoid duplication
        if (!empty($contextResources)) {
            $contextContent = [];
            $hasTextFiles = false;

            foreach ($contextResources as $resource) {
                if ($resource['kind'] === 'text_file') {
                    if (!$hasTextFiles) {
                        $contextContent[] = [
                            'type' => 'text',
                            'text' => '[ADDITIONAL TEXT CONTEXT]\n'
                        ];
                        $hasTextFiles = true;
                    }
                    $contextContent[] = [
                        'type' => 'text',
                        'text' => "**{$resource['title']}**\n{$resource['content']}"
                    ];
                }
                // Skip Base64 images/PDFs in RAG mode - they're already in the collection
            }

            if ($hasTextFiles) {
                $messagesArray[] = [
                    'role' => 'user',
                    'content' => $contextContent
                ];
            }
        }

        // Add conversation messages
        $messagesArray = array_merge($messagesArray, $messages);

        // Build RAG request payload
        $payload = [
            'model' => $this->model,
            'messages' => $messagesArray,
            'collection_ids' => $collectionIds,
            'stream' => $this->streaming
        ];

        // Add model-specific parameters (e.g., temperature)
        $modelParams = $this->getModelParameters();
        $payload = array_merge($payload, $modelParams);

        // Log complete request for debugging
        $this->logger->debug("RAMSES RAG Chat Request: Model=" . $this->model .
                           " | Collections=" . json_encode($collectionIds) .
                           " | Messages=" . count($messagesArray) .
                           " | Stream=" . ($this->streaming ? 'yes' : 'no') .
                           " | Parameters=" . json_encode($modelParams) .
                           " | Full Payload: " . json_encode($payload, JSON_PRETTY_PRINT));

        return $this->executeApiRequest($ragApiUrl, json_encode($payload));
    }

    // ============================================
    // Existing Methods
    // ============================================

    /**
     * Send chat to RAMSES API
     * Accepts both legacy AIChatPageComponentChat and new Chat objects
     * @throws AIChatPageComponentException
     */
    /**
     * Send messages with separated context structure
     */
    public function sendMessagesArray(array $messages, ?array $contextResources = null): string
    {
        global $DIC;

        $apiUrl = $this->getEndpointUrl(self::ENDPOINT_CHAT);


        // Build messages array with separated context structure
        $messagesArray = [];

        // System message (clean, without background context)
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }

        // Context resources message (as assistant introducing available resources)
        if (!empty($contextResources)) {
            $contextContent = [];

            // Add context introduction
            $contextContent[] = [
                'type' => 'text',
                'text' => '[BEGIN KNOWLEDGE BASE CONTEXT]\n'
            ];

            // Add structured resources as OpenAI-compatible content
            foreach ($contextResources as $resource) {
                // Add resource description as text
                $resourceDesc = "**{$resource['title']}** ({$resource['kind']})";

                // Add metadata if available
                $metadata = [];
                if (isset($resource['mime_type'])) {
                    $metadata[] = "Type: {$resource['mime_type']}";
                }
                if (isset($resource['page_number'])) {
                    $metadata[] = "Page: {$resource['page_number']}";
                }
                if (isset($resource['source_file'])) {
                    $metadata[] = "Source: {$resource['source_file']}";
                }
                if (!empty($metadata)) {
                    $resourceDesc .= " [" . implode(", ", $metadata) . "]";
                }

                $contextContent[] = [
                    'type' => 'text',
                    'text' => $resourceDesc
                ];

                // Add content based on kind (OpenAI-compatible)
                switch ($resource['kind']) {
                    case 'page_context':
                    case 'text_file':
                        // Add text content
                        $contextContent[] = [
                            'type' => 'text',
                            'text' => "Content:\n" . $resource['content']
                        ];
                        break;

                    case 'image_file':
                    case 'pdf_page':
                        // Add image content
                        $contextContent[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $resource['url'],
                                'detail' => 'high'
                            ]
                        ];
                        break;
                }

                // Add separator for readability
                $contextContent[] = [
                    'type' => 'text',
                    'text' => "---"
                ];
            }

            // Add closing message
            $contextContent[] = [
                'type' => 'text',
                'text' => '[END KNOWLEDGE BASE CONTEXT]\nYou may refer to this context when answering future questions.'
            ];

            // Assistant role with structured context (currently user role, assistent does not accept files)
            $messagesArray[] = [
                'role' => 'user',//'assistant',
                'content' => $contextContent
            ];
        }

        // 3. Add the actual conversation messages
        $messagesArray = array_merge($messagesArray, $messages);

        // Build payload with model-specific parameters
        $payloadArray = [
            "messages" => $messagesArray,
            "model" => $this->model,
            "stream" => $this->isStreaming()
        ];

        // Add model-specific parameters (e.g., temperature)
        $modelParams = $this->getModelParameters();
        $payloadArray = array_merge($payloadArray, $modelParams);

        $payload = json_encode($payloadArray);

        if ($payload === false) {
            throw new AIChatPageComponentException("Failed to encode API payload: " . json_last_error_msg());
        }

        // Log complete request for debugging
        $this->logger->debug("RAMSES Chat Request (Multimodal): Model=" . $this->model .
                           " | Messages=" . count($messagesArray) .
                           " | Has Context=" . (!empty($contextResources) ? 'yes' : 'no') .
                           " | Stream=" . ($this->isStreaming() ? 'yes' : 'no') .
                           " | Parameters=" . json_encode($modelParams) .
                           " | Full Payload: " . json_encode($payloadArray, JSON_PRETTY_PRINT));

        return $this->executeApiRequest($apiUrl, $payload);
    }


    /**
     * Execute API request to RAMSES
     */
    private function executeApiRequest(string $apiUrl, string $payload): string
    {
        $curlSession = curl_init();

        // Get certificate path from this plugin
        $plugin = \ilAIChatPageComponentPlugin::getInstance();
        $plugin_path = $plugin->getDirectory();
        $absolute_plugin_path = realpath($plugin_path);
        $ca_cert_path = $absolute_plugin_path . '/certs/RAMSES.pem';

        if (file_exists($ca_cert_path)) {
            curl_setopt($curlSession, CURLOPT_CAINFO, $ca_cert_path);
        } else {
            // Fallback: disable SSL verification for development/testing
            // WARNING: This should not be used in production
            curl_setopt($curlSession, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlSession, CURLOPT_SSL_VERIFYHOST, false);
        }

        curl_setopt($curlSession, CURLOPT_URL, $apiUrl);
        curl_setopt($curlSession, CURLOPT_POST, true);
        curl_setopt($curlSession, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, !$this->isStreaming());
        curl_setopt($curlSession, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->getApiKey()
        ]);

        // Handle proxy settings
        if (class_exists('ilProxySettings') && \ilProxySettings::_getInstance()->isActive()) {
            $proxyHost = \ilProxySettings::_getInstance()->getHost();
            $proxyPort = \ilProxySettings::_getInstance()->getPort();
            $proxyURL = $proxyHost . ":" . $proxyPort;
            curl_setopt($curlSession, CURLOPT_PROXY, $proxyURL);
        }

        $responseContent = '';

        if ($this->isStreaming()) {
            curl_setopt($curlSession, CURLOPT_WRITEFUNCTION, function ($curlSession, $chunk) use (&$responseContent) {
                $responseContent .= $chunk;

                // Parse and reformat the chunk for Server-Sent Events
                $lines = explode("\n", $chunk);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    if (strpos($line, 'data: ') === 0) {
                        $jsonData = substr($line, strlen('data: '));
                        if ($jsonData === '[DONE]') {
                            continue; // Skip [DONE] marker
                        }

                        $json = json_decode($jsonData, true);
                        if ($json && isset($json['choices'][0]['delta']['content'])) {
                            $content = $json['choices'][0]['delta']['content'];
                            // Output as Server-Sent Event format
                            echo "data: " . json_encode(['type' => 'chunk', 'content' => $content]) . "\n\n";
                            ob_flush();
                            flush();
                        }
                    }
                }

                return strlen($chunk);
            });
        }

        $response = curl_exec($curlSession);
        $httpcode = curl_getinfo($curlSession, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($curlSession, CURLINFO_TOTAL_TIME);
        $connectTime = curl_getinfo($curlSession, CURLINFO_CONNECT_TIME);
        $errNo = curl_errno($curlSession);
        $errMsg = curl_error($curlSession);
        curl_close($curlSession);

        // Handle curl_exec returning false on failure
        if ($response === false || $errNo) {
            $this->logger->error("RAMSES API cURL execution failed", [
                'curl_error' => $errMsg,
                'curl_errno' => $errNo,
                'url' => $apiUrl,
                'total_time' => round($totalTime, 3),
                'connect_time' => round($connectTime, 3)
            ]);
            throw new AIChatPageComponentException("cURL Error: " . $errMsg, $errNo);
        }

        if ($httpcode != 200) {
            // In streaming mode, use captured response content
            $errorBody = $this->isStreaming() ? $responseContent : $response;
            $responsePreview = is_string($errorBody) && !empty($errorBody) ? substr($errorBody, 0, 500) : '(no body)';

            $this->logger->error("RAMSES API request failed: HTTP " . $httpcode . " | URL: " . $apiUrl . " | Response: " . $responsePreview . " | Time: " . round($totalTime, 2) . "s");

            $this->logger->error("RAMSES API Error Details", [
                'http_code' => $httpcode,
                'total_time' => round($totalTime, 3),
                'connect_time' => round($connectTime, 3),
                'response' => $errorBody,
                'payload' => $payload,
                'api_url' => $apiUrl,
                'has_api_key' => !empty($this->apiKey),
                'streaming' => $this->isStreaming()
            ]);

            // Try to parse error response for more details (only if response is string)
            $errorData = is_string($response) ? json_decode($response, true) : null;
            $errorMessage = $errorData['error']['message'] ?? "HTTP Error: " . $httpcode;

            if ($httpcode === 401) {
                throw new AIChatPageComponentException("Invalid API key: " . $errorMessage, 401);
            } else {
                throw new AIChatPageComponentException($errorMessage, $httpcode);
            }
        }

        if (!$this->isStreaming()) {
            $decodedResponse = json_decode($response, true);
            if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new AIChatPageComponentException("Invalid JSON response from RAMSES API: " . json_last_error_msg());
            }
            if (!isset($decodedResponse['choices'][0]['message']['content'])) {
                throw new AIChatPageComponentException("Unexpected API response structure from RAMSES" . $response);
            }

            // Log complete response for debugging
            $content = $decodedResponse['choices'][0]['message']['content'];
            $usage = $decodedResponse['usage'] ?? [];
            $this->logger->debug("RAMSES Chat Response: HTTP " . $httpcode .
                               " | Content Length=" . strlen($content) .
                               " | Tokens: " . json_encode($usage) .
                               " | Full Response: " . json_encode($decodedResponse, JSON_PRETTY_PRINT));

            return $content;
        }

        // Process streaming response
        $messages = explode("\n", $responseContent);
        $completeMessage = '';

        foreach ($messages as $message) {
            if (trim($message) !== '' && strpos($message, 'data: ') === 0) {
                $jsonData = substr($message, strlen('data: '));
                if ($jsonData === '[DONE]') {
                    continue;
                }
                $json = json_decode($jsonData, true);
                if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                    continue; // Skip invalid JSON chunks
                }
                if (is_array($json) && isset($json['choices'][0]['delta']['content'])) {
                    $completeMessage .= $json['choices'][0]['delta']['content'];
                }
            }
        }

        // Log complete streaming response
        $this->logger->debug("RAMSES Chat Response (Streaming): HTTP " . $httpcode .
                           " | Content Length=" . strlen($completeMessage) .
                           " | Chunks Processed=" . count($messages) .
                           " | Complete Message: " . substr($completeMessage, 0, 1000) . (strlen($completeMessage) > 1000 ? '...' : ''));

        return $completeMessage;
    }

    /**
     * Factory method to create RAMSES instance with plugin configuration
     *
     * @return self Configured RAMSES instance
     * @throws AIChatPageComponentException If configuration loading fails
     */
    public static function fromConfig(): self
    {
        try {
            // Get model, API key and streaming setting from plugin configuration
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
            $apiKey = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
            $streaming = (\platform\AIChatPageComponentConfig::get('ramses_streaming_enabled') ?? '1') === '1';

            if (empty($apiKey)) {
                throw new AIChatPageComponentException("RAMSES API token not configured");
            }

            $ramses = new self($model);
            $ramses->setApiKey($apiKey);
            $ramses->setStreaming($streaming);

            return $ramses;
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to create RAMSES instance from plugin config: " . $e->getMessage());
        }
    }

    /**
     * Refresh available models from RAMSES API
     *
     * @return array ['success' => bool, 'message' => string, 'models' => array|null]
     */
    public function refreshModels(): array
    {
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        try {
            // Use endpoint URL from constant
            $models_api_url = $this->getEndpointUrl(self::ENDPOINT_MODELS);

            $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');

            // Handle potential Password object conversion
            if (is_object($api_token) && method_exists($api_token, 'toString')) {
                $api_token = $api_token->toString();
            }

            if (empty($api_token)) {
                return [
                    'success' => false,
                    'message' => $plugin->txt('refresh_models_no_token'),
                    'models' => null
                ];
            }

            // Try to fetch models from API
            $ch = curl_init();

            // Get certificate path from this plugin
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

            // Handle curl_exec returning false on failure
            if ($response === false) {
                $this->logger->error("RAMSES models API cURL execution failed", [
                    'curl_error' => $error,
                    'url' => $models_api_url
                ]);
                return [
                    'success' => false,
                    'message' => $plugin->txt('refresh_models_api_error') . ': cURL ' . $error,
                    'models' => null
                ];
            }

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

                        return [
                            'success' => true,
                            'message' => $plugin->txt('refresh_models_success') . ' (' . count($models) . ' ' . $plugin->txt('refresh_models_count') . ')',
                            'models' => $models
                        ];
                    } else {
                        return [
                            'success' => false,
                            'message' => $plugin->txt('refresh_models_no_models'),
                            'models' => null
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'message' => $plugin->txt('refresh_models_invalid_response'),
                        'models' => null
                    ];
                }
            } else {
                $error_msg = $plugin->txt('refresh_models_api_error') . ' (HTTP ' . $httpCode . ')';
                if ($error) {
                    $error_msg .= ': ' . $error;
                }

                // Add debug information for HTTP 401
                if ($httpCode === 401) {
                    $error_msg .= ' - ' . $plugin->txt('refresh_models_no_token');
                    $this->logger->error("RAMSES Models API 401 Error", [
                        'api_url' => $models_api_url,
                        'token_length' => strlen($api_token),
                        'token_starts_with' => substr($api_token, 0, 8) . '...'
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $error_msg,
                    'models' => null
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $plugin->txt('refresh_models_exception') . ': ' . $e->getMessage(),
                'models' => null
            ];
        }
    }
}
