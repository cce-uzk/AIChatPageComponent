<?php declare(strict_types=1);

namespace ai;

use objects\AIChatPageComponentChat;
use ILIAS\Plugin\pcaic\Model\Chat;
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
    /** @var string AI model identifier (e.g., 'mistral-small-3-2-24b-instruct-2506') */
    private string $model;
    
    /** @var string API key for RAMSES service authentication */
    private string $apiKey;
    
    /** @var bool Whether to use streaming responses (currently not implemented) */
    private bool $streaming = false;
    
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
        
        // No fallback models - models must be fetched from API
        return [];
    }

    /**
     * Constructor - initializes RAMSES service with configured model and API settings
     * 
     * @param string|null $model Optional model identifier, uses configured model if not provided
     */
    public function __construct(string $model = null)
    {
        parent::__construct();
        
        // Use configured model if none provided
        if ($model === null) {
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
        }
        
        $this->model = $model;
        
        // Load API token from configuration
        $this->apiKey = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
    }

    /**
     * Returns the configured API key for RAMSES service
     * 
     * @return string API key for authentication
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Sets the API key for RAMSES service authentication
     * 
     * @param string $apiKey Valid RAMSES API key
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Configures streaming mode for responses
     * 
     * @param bool $streaming Whether to enable streaming (not yet implemented)
     * @todo Implement streaming response handling
     */
    public function setStreaming(bool $streaming): void
    {
        $this->streaming = $streaming;
    }

    /**
     * Returns current streaming configuration
     * 
     * @return bool Whether streaming is enabled
     */
    public function isStreaming(): bool
    {
        return $this->streaming;
    }

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

        $apiUrl = \platform\AIChatPageComponentConfig::get('ramses_chat_api_url') ?: 'https://ramses-oski.itcc.uni-koeln.de/v1/chat/completions';

        $this->logger->debug("RAMSES sendMessagesArray called");
        $this->logger->debug("Processing context resources", ['count' => count($contextResources)]);
        
        // Build messages array with separated context structure
        $messagesArray = [];
        
        // System message (clean, without background context)
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
            $this->logger->debug("System message added to payload");
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
            
            $this->logger->debug("Context resources message added", [
                'resource_count' => count($contextResources),
                'resource_kinds' => implode(', ', array_unique(array_column($contextResources, 'kind')))
            ]);
        }
        
        // 3. Add the actual conversation messages
        $messagesArray = array_merge($messagesArray, $messages);
        
        $this->logger->debug("Final messages array prepared", ['count' => count($messagesArray)]);
        
        $payload = json_encode([
            "messages" => $messagesArray,
            "model" => $this->model,
            "temperature" => 0.5,
            "stream" => $this->isStreaming()
        ]);
        
        if ($payload === false) {
            throw new AIChatPageComponentException("Failed to encode API payload: " . json_last_error_msg());
        }
        
        $this->logger->debug("RAMSES API payload prepared", ['payload' => $payload]);

        return $this->executeApiRequest($apiUrl, $payload);
    }

    /**
     * Send messages array directly
     */
    /*public function sendMessagesArray(array $messages): string
    {
        global $DIC;

        $apiUrl = \platform\AIChatPageComponentConfig::get('ramses_chat_api_url') ?: 'https://ramses-oski.itcc.uni-koeln.de/v1/chat/completions';

        $this->logger->debug("RAMSES sendMessagesArray prompt check", [
            'prompt_value' => $this->prompt,
            'prompt_length' => strlen($this->prompt ?? ''),
            'prompt_empty' => empty($this->prompt)
        ]);

        // Add system prompt if set
        $messagesArray = [];
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
            $this->logger->debug("RAMSES - System message added to payload");
        } else {
            $this->logger->debug("RAMSES - System message NOT added (prompt is empty)");
        }
        
        // Add the provided messages
        $messagesArray = array_merge($messagesArray, $messages);
        
        $this->logger->debug("RAMSES - Final messagesArray count", ['count' => count($messagesArray)]);
        
        $payload = json_encode([
            "messages" => $messagesArray,
            "model" => $this->model,
            "temperature" => 0.5,
            "stream" => $this->isStreaming()
        ]);
        
        $this->logger->debug("RAMSES payload", ['payload' => $payload]);

        return $this->executeApiRequest($apiUrl, $payload);
    }*/

    public function sendChat($chat)
    {
        // Accept both old and new chat types
        if (!($chat instanceof AIChatPageComponentChat || $chat instanceof Chat)) {
            throw new AIChatPageComponentException('Invalid chat object type');
        }
        
        $messagesArray = $this->chatToMessagesArray($chat);
        return $this->sendMessagesArray($messagesArray);
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
            $this->logger->debug("SSL certificate not found, disabling SSL verification");
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
        $errNo = curl_errno($curlSession);
        $errMsg = curl_error($curlSession);
        curl_close($curlSession);

        if ($errNo) {
            throw new AIChatPageComponentException("HTTP Error: " . $errMsg);
        }

        if ($httpcode != 200) {
            // Enhanced debugging for RAMSES HTTP 460 error - write to debug.log directly
            $debug_file = __DIR__ . '/../../debug.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($debug_file, "$timestamp - RAMSES API Error - HTTP Code: $httpcode\n", FILE_APPEND);
            file_put_contents($debug_file, "$timestamp - RAMSES API Response: $response\n", FILE_APPEND);
            file_put_contents($debug_file, "$timestamp - RAMSES Payload sent: $payload\n", FILE_APPEND);
            file_put_contents($debug_file, "$timestamp - RAMSES API URL: $apiUrl\n", FILE_APPEND);
            file_put_contents($debug_file, "$timestamp - RAMSES API Key set: " . (empty($this->apiKey) ? 'NO' : 'YES (length: ' . strlen($this->apiKey) . ')') . "\n", FILE_APPEND);
            
            $this->logger->error("RAMSES API Error", [
                'http_code' => $httpcode,
                'response' => $response,
                'payload' => $payload,
                'api_url' => $apiUrl
            ]);
            
            // Try to parse error response for more details
            $errorData = json_decode($response, true);
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
            return $decodedResponse['choices'][0]['message']['content'];
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
            // Get model and API key from plugin configuration
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
            $apiKey = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
            
            if (empty($apiKey)) {
                throw new AIChatPageComponentException("RAMSES API token not configured");
            }
            
            $ramses = new self($model);
            $ramses->setApiKey($apiKey);
            
            return $ramses;
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to create RAMSES instance from plugin config: " . $e->getMessage());
        }
    }
}