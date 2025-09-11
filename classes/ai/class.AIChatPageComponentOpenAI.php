<?php declare(strict_types=1);

namespace ai;

use objects\AIChatPageComponentChat;
use ILIAS\Plugin\pcaic\Model\Chat;
use platform\AIChatPageComponentException;

/**
 * Class AIChatPageComponentOpenAI
 * Based on OpenAI from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentOpenAI extends AIChatPageComponentLLM
{
    private string $model;
    private string $apiKey;
    private bool $streaming = false;
    
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

    public function __construct(string $model)
    {
        parent::__construct();
        $this->model = $model;
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
     * Send messages array directly
     */
    public function sendMessagesArray(array $messages, ?array $contextResources = null): string
    {
        // Add system prompt if set
        $messagesArray = [];
        if (!empty($this->prompt)) {
            $messagesArray[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }
        
        // Add the provided messages
        $messagesArray = array_merge($messagesArray, $messages);
        
        $apiUrl = \platform\AIChatPageComponentConfig::get('openai_api_url') ?: 'https://api.openai.com/v1/chat/completions';

        $payload = json_encode([
            "messages" => $messagesArray,
            "model" => $this->model,
            "temperature" => 0.5,
            "stream" => $this->isStreaming()
        ]);

        // Implementation is similar to RAMSES but with OpenAI specifics (tbd)
        // For now, throw an exception to indicate it's not implemented
        throw new AIChatPageComponentException('OpenAI sendMessagesArray not yet implemented');
    }

    /**
     * Send chat to OpenAI API
     * Accepts both legacy AIChatPageComponentChat and new Chat objects
     * @throws AIChatPageComponentException
     */
    public function sendChat($chat)
    {
        // Accept both old and new chat types
        if (!($chat instanceof AIChatPageComponentChat || $chat instanceof Chat)) {
            throw new AIChatPageComponentException('Invalid chat object type');
        }
        
        $apiUrl = \platform\AIChatPageComponentConfig::get('openai_api_url') ?: 'https://api.openai.com/v1/chat/completions';

        $payload = json_encode([
            "messages" => $this->chatToMessagesArray($chat),
            "model" => $this->model,
            "temperature" => 0.5,
            "stream" => $this->isStreaming()
        ]);

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
                echo $chunk;
                ob_flush();
                flush();
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
            // Try to parse error response for more details
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? "HTTP Error: " . $httpcode;
            
            // Enhanced debugging for HTTP 460 error
            $this->logger->error("OpenAI API Error", [
                'http_code' => $httpcode,
                'response' => $response,
                'payload' => $payload
            ]);
            
            if ($httpcode === 401) {
                throw new AIChatPageComponentException("Invalid API key: " . $errorMessage, 401);
            } else {
                throw new AIChatPageComponentException($errorMessage, $httpcode);
            }
        }

        if (!$this->isStreaming()) {
            $decodedResponse = json_decode($response, true);
            if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new AIChatPageComponentException("Invalid JSON response from OpenAI API: " . json_last_error_msg());
            }
            if (!isset($decodedResponse['choices'][0]['message']['content'])) {
                throw new AIChatPageComponentException("Unexpected API response structure from OpenAI");
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
     * Initialize OpenAI with AIChat configuration
     * @throws AIChatPageComponentException
     */
    /**
     * Factory method to create OpenAI instance with plugin configuration
     * 
     * @return self Configured OpenAI instance
     * @throws AIChatPageComponentException If configuration loading fails
     */
    public static function fromConfig(): self
    {
        try {
            // Get model, API key and streaming from plugin configuration
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