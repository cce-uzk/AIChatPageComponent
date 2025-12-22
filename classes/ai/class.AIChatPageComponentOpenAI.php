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

    public function __construct(string $model)
    {
        parent::__construct();
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

        $messagesArray = array_merge($messagesArray, $messages);

        $apiUrl = $this->getEndpointUrl(self::ENDPOINT_CHAT);

        $payload = json_encode([
            "messages" => $messagesArray,
            "model" => $this->model,
            "temperature" => 0.5,
            "stream" => $this->isStreaming()
        ]);

        throw new AIChatPageComponentException('OpenAI sendMessagesArray not yet implemented');
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
