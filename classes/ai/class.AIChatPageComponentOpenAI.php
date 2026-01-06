<?php declare(strict_types=1);

namespace ai;
use platform\AIChatPageComponentException;

/**
 * Class AIChatPageComponentOpenAI
 * Based on OpenAI from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentOpenAI extends AIChatPageComponentLLM
{
    /** @var string Chat completions endpoint */
    private const ENDPOINT_CHAT = '/v1/chat/completions';

    /** @var string Models listing endpoint */
    private const ENDPOINT_MODELS = '/v1/models';

    private string $model;
    private string $apiKey;

    public const MODEL_TYPES = [
        "gpt-4.5-preview" => "GPT-4.5 Preview",
        "gpt-4o" => "GPT-4o",
        "gpt-4o-mini" => "GPT-4o mini",
        "gpt-4-turbo" => "GPT-4 Turbo",
        "gpt-4.5-preview-2025-02-27" => "GPT-4.5 Preview 2025-02-27",
        "gpt-4-0125-preview" => "GPT-4 0125 Preview",
        "gpt-4-turbo-preview" => "GPT-4 Turbo Preview",
        "gpt-3.5-turbo-1106" => "GPT-3.5 Turbo 1106",
        "gpt-4" => "GPT-4",
        "gpt-3.5-turbo" => "GPT-3.5 Turbo"
    ];

    // ============================================
    // Service Metadata Implementation
    // ============================================

    public static function getServiceId(): string
    {
        return 'openai';
    }

    public static function getServiceName(): string
    {
        return 'OpenAI GPT';
    }

    public static function getServiceDescription(): string
    {
        return 'OpenAI GPT Service';
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
        $openai_enabled = \platform\AIChatPageComponentConfig::get('openai_service_enabled') ?? '0';
        $inputs['openai_service_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_service_enabled'),
            $plugin->txt('config_service_enabled_info')
        )->withValue($openai_enabled === '1');

        // API URL - ensure string
        $api_url = \platform\AIChatPageComponentConfig::get('openai_api_url');
        $inputs['openai_api_url'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_api_url'),
            $plugin->txt('config_api_url_info')
        )->withValue((string)($api_url ?: 'https://api.openai.com'))->withRequired(true);

        // API Token - ensure string
        $api_token = \platform\AIChatPageComponentConfig::get('openai_api_token');
        $inputs['openai_api_token'] = $ui_factory->input()->field()->password(
            $plugin->txt('config_api_token'),
            $plugin->txt('config_api_token_info')
        )->withValue((string)($api_token ?: ''))->withRequired(true);

        // Model Selection - use cached models from API
        $selected_model = \platform\AIChatPageComponentConfig::get('openai_selected_model');
        $cached_models = \platform\AIChatPageComponentConfig::get('openai_cached_models');

        if (is_array($cached_models) && !empty($cached_models)) {
            // Use cached models from API (already in correct format: ['model-id' => 'Display Name'])
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

            $inputs['openai_selected_model'] = $select_field;
        } else {
            // Fallback to hardcoded list if models not yet loaded
            $select_field = $ui_factory->input()->field()->select(
                $plugin->txt('config_selected_model'),
                self::MODEL_TYPES,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            // Set value: use saved value if exists in options, otherwise use default
            $value_to_use = ($selected_model && isset(self::MODEL_TYPES[$selected_model])) ? $selected_model : 'gpt-4o';
            $select_field = $select_field->withValue($value_to_use);

            $inputs['openai_selected_model'] = $select_field;
        }

        // Temperature - use text field with custom validation to support comma/dot
        $temperature = \platform\AIChatPageComponentConfig::get('openai_temperature');
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

        $inputs['openai_temperature'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_temperature'),
            $plugin->txt('config_temperature_info')
        )->withValue($temp_value)
         ->withAdditionalTransformation($temp_constraint)
         ->withAdditionalTransformation($temp_trafo);

        // File handling enabled
        $file_handling_enabled = \platform\AIChatPageComponentConfig::get('openai_file_handling_enabled') ?? '1';
        $inputs['openai_file_handling_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_file_handling'),
            $plugin->txt('config_file_handling_info')
        )->withValue($file_handling_enabled === '1');

        return $inputs;
    }

    public function saveConfiguration(array $formData): void
    {
        foreach ($formData as $key => $value) {
            // Handle ILIAS Password object
            if ($value instanceof \ILIAS\Data\Password) {
                $value = $value->toString();
            }

            // Handle checkbox boolean conversion
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            // Handle numeric temperature with decimal separator normalization
            if ($key === 'openai_temperature' && is_numeric($value)) {
                $value = (float)$value;
            }

            \platform\AIChatPageComponentConfig::set($key, $value);
        }
    }

    public static function getDefaultConfiguration(): array
    {
        return [
            'openai_service_enabled' => '0',
            'openai_api_url' => 'https://api.openai.com',
            'openai_api_token' => '',
            'openai_selected_model' => 'gpt-4o',
            'openai_temperature' => 0.7,
            'openai_file_handling_enabled' => '1',
        ];
    }

    // ============================================
    // Service Capabilities Implementation
    // ============================================

    public function getCapabilities(): array
    {
        return [
            'streaming' => false, // OpenAI streaming not yet implemented
            'rag' => false, // OpenAI doesn't support RAG in this plugin
            'multimodal' => true,
            'file_types' => ['txt', 'md', 'csv', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            'rag_file_types' => [],
            'max_tokens' => 128000, // GPT-4o context window
        ];
    }

    // ============================================
    // Existing OpenAI Methods
    // ============================================

    public function __construct(string $model = null)
    {
        parent::__construct();

        if ($model === null) {
            $model = \platform\AIChatPageComponentConfig::get('openai_selected_model') ?: 'gpt-3.5-turbo';
        }

        $this->model = $model;
    }

    /**
     * Construct full API endpoint URL from base URL and endpoint path
     *
     * @param string $endpoint Endpoint path constant (e.g., self::ENDPOINT_CHAT)
     * @return string Complete API URL
     */
    private function getEndpointUrl(string $endpoint): string
    {
        $baseUrl = \platform\AIChatPageComponentConfig::get('openai_api_url') ?: 'https://api.openai.com';

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
     * Get model-specific API parameters
     *
     * OpenAI o1/o1-mini/o1-preview models don't support temperature parameter.
     * Other models support temperature 0-2.
     * Uses configured value from plugin settings.
     *
     * @return array Associative array of API parameters
     */
    protected function getModelParameters(): array
    {
        // Check if model is o1 series (doesn't support temperature)
        if (str_starts_with($this->model, 'o1') || str_starts_with($this->model, 'o3')) {
            // o1, o1-mini, o1-preview, o3 models don't support temperature
            return [];
        }

        // All other models support temperature - use configured value
        $temperature = \platform\AIChatPageComponentConfig::get('openai_temperature') ?: 0.7;

        return [
            'temperature' => (float)$temperature
        ];
    }

    /**
     * Get allowed file types based on RAG mode
     *
     * OpenAI supports multimodal (vision) for all GPT-4 models.
     * RAG would typically be implemented via Assistants API with file search.
     *
     * @param bool $ragEnabled Whether RAG mode is enabled
     * @return array Array of allowed file extensions
     */
    public function getAllowedFileTypes(bool $ragEnabled): array
    {
        if ($ragEnabled) {
            // RAG Mode: Text-based files (would use Assistants API file search)
            return ['txt', 'md', 'csv', 'pdf'];
        } else {
            // Multimodal Mode: Images supported by GPT-4 Vision
            return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf', 'txt', 'md', 'csv'];
        }
    }

    /**
     * Send messages array directly
     *
     * @param array $messages Array of message objects
     * @param array|null $contextResources Optional context resources
     * @return string AI response
     * @throws AIChatPageComponentException Not yet implemented
     */
    public function sendMessagesArray(array $messages, ?array $contextResources = null): string
    {
        $messagesArray = [];
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }

        // Add optional context resources (background files, page context)
        if (!empty($contextResources)) {
            $contextContent = [];

            foreach ($contextResources as $resource) {
                if ($resource['kind'] === 'text_file') {
                    $contextContent[] = [
                        'type' => 'text',
                        'text' => "**{$resource['title']}**\n{$resource['content']}"
                    ];
                } elseif ($resource['kind'] === 'image_url') {
                    $contextContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $resource['content']
                        ]
                    ];
                }
            }

            if (!empty($contextContent)) {
                $messagesArray[] = [
                    'role' => 'user',
                    'content' => $contextContent
                ];
            }
        }

        $messagesArray = array_merge($messagesArray, $messages);

        $apiUrl = $this->getEndpointUrl(self::ENDPOINT_CHAT);

        // Build payload with model-specific parameters
        $payload = [
            "messages" => $messagesArray,
            "model" => $this->model,
            "stream" => $this->isStreaming()
        ];

        // Add model-specific parameters (e.g., temperature, top_p)
        $modelParams = $this->getModelParameters();
        $payload = array_merge($payload, $modelParams);

        // Log complete request for debugging
        if ($this->logger) {
            $this->logger->debug("OpenAI Chat Request: Model=" . $this->model .
                               " | Messages=" . count($messagesArray) .
                               " | Stream=" . ($this->isStreaming() ? 'yes' : 'no') .
                               " | Parameters=" . json_encode($modelParams) .
                               " | Full Payload: " . json_encode($payload, JSON_PRETTY_PRINT));
        }

        return $this->executeApiRequest($apiUrl, json_encode($payload));
    }

    /**
     * Execute OpenAI API request with streaming support
     *
     * @param string $apiUrl API endpoint URL
     * @param string $payload JSON payload
     * @return string AI response content
     * @throws AIChatPageComponentException
     */
    private function executeApiRequest(string $apiUrl, string $payload): string
    {
        $curlSession = curl_init();

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

        if ($errNo) {
            if ($this->logger) {
                $this->logger->error("OpenAI cURL Error", [
                    'error_no' => $errNo,
                    'error_msg' => $errMsg,
                    'api_url' => $apiUrl,
                    'has_api_key' => !empty($this->apiKey)
                ]);
            }
            throw new AIChatPageComponentException("HTTP Error: " . $errMsg);
        }

        if ($httpcode != 200) {
            // In streaming mode, use captured response content
            $errorBody = $this->isStreaming() ? $responseContent : $response;
            $responsePreview = is_string($errorBody) && !empty($errorBody) ? substr($errorBody, 0, 500) : '(no body)';

            if ($this->logger) {
                $this->logger->error("OpenAI API request failed: HTTP " . $httpcode . " | URL: " . $apiUrl . " | Response: " . $responsePreview . " | Time: " . round($totalTime, 2) . "s");

                $this->logger->error("OpenAI API Error Details", [
                    'http_code' => $httpcode,
                    'total_time' => round($totalTime, 3),
                    'connect_time' => round($connectTime, 3),
                    'response' => $errorBody,
                    'payload' => $payload,
                    'api_url' => $apiUrl,
                    'has_api_key' => !empty($this->apiKey),
                    'streaming' => $this->isStreaming()
                ]);
            }

            // Try to parse error response for more details
            $errorData = is_string($errorBody) ? json_decode($errorBody, true) : null;
            $errorMessage = $errorData['error']['message'] ?? "HTTP Error: " . $httpcode;

            if ($httpcode === 401) {
                throw new AIChatPageComponentException("Invalid OpenAI API key: " . $errorMessage, 401);
            } else {
                throw new AIChatPageComponentException("OpenAI API Error: " . $errorMessage, $httpcode);
            }
        }

        if (!$this->isStreaming()) {
            $decodedResponse = json_decode($response, true);
            if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($this->logger) {
                    $this->logger->error("Invalid JSON response from OpenAI", [
                        'json_error' => json_last_error_msg(),
                        'response_preview' => substr($response, 0, 500)
                    ]);
                }
                throw new AIChatPageComponentException("Invalid JSON response from OpenAI API: " . json_last_error_msg());
            }
            if (!isset($decodedResponse['choices'][0]['message']['content'])) {
                if ($this->logger) {
                    $this->logger->error("Unexpected OpenAI API response structure", [
                        'response' => $decodedResponse
                    ]);
                }
                throw new AIChatPageComponentException("Unexpected API response structure from OpenAI: " . $response);
            }

            // Log complete response for debugging
            $content = $decodedResponse['choices'][0]['message']['content'];
            $usage = $decodedResponse['usage'] ?? [];
            if ($this->logger) {
                $this->logger->debug("OpenAI Chat Response: HTTP " . $httpcode .
                                   " | Content Length=" . strlen($content) .
                                   " | Tokens: " . json_encode($usage) .
                                   " | Full Response: " . json_encode($decodedResponse, JSON_PRETTY_PRINT));
            }

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
        if ($this->logger) {
            $this->logger->debug("OpenAI Chat Streaming Response: HTTP " . $httpcode .
                               " | Content Length=" . strlen($completeMessage) .
                               " | Time: " . round($totalTime, 2) . "s");
        }

        return $completeMessage;
    }

    /**
     * Factory method to create OpenAI instance with plugin configuration
     *
     * @return self Configured OpenAI instance
     * @throws AIChatPageComponentException If configuration loading fails
     */
    public static function fromConfig(): self
    {
        try {
            $model = \platform\AIChatPageComponentConfig::get('openai_selected_model') ?: 'gpt-3.5-turbo';
            $apiKey = \platform\AIChatPageComponentConfig::get('openai_api_token') ?: '';
            $streaming = (\platform\AIChatPageComponentConfig::get('openai_streaming_enabled') ?? '0') === '1';

            if (empty($apiKey)) {
                throw new AIChatPageComponentException("OpenAI API token not configured");
            }

            $openai = new self($model);
            $openai->setApiKey($apiKey);
            $openai->setStreaming($streaming);

            return $openai;
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to create OpenAI instance from plugin config: " . $e->getMessage());
        }
    }
}
