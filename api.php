<?php
/**
 * AIChatPageComponent API Endpoint
 *
 * Handles all AJAX requests for AI chat functionality.
 * Routes requests to appropriate LLM service implementations.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */

$ilias_root = rtrim(dirname(__DIR__, 7), '/');
chdir($ilias_root);
require_once($ilias_root . '/Services/Init/classes/class.ilInitialisation.php');
ilContext::init(ilContext::CONTEXT_WEB);
ilInitialisation::initILIAS();

global $DIC;
$logger = $DIC->logger()->root();

require_once(__DIR__ . '/classes/platform/class.AIChatPageComponentConfig.php');
require_once(__DIR__ . '/classes/platform/class.AIChatPageComponentException.php');
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentLLM.php');
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentRAMSES.php');
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentOpenAI.php');
require_once(__DIR__ . '/src/Model/ChatConfig.php');
require_once(__DIR__ . '/src/Model/ChatSession.php');
require_once(__DIR__ . '/src/Model/ChatMessage.php');
require_once(__DIR__ . '/src/Model/Attachment.php');

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;
use ILIAS\Plugin\pcaic\Model\ChatMessage;
use ILIAS\Plugin\pcaic\Model\Attachment;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = [];
$method = $_SERVER['REQUEST_METHOD'];
$request = $DIC->http()->request();

if ($method === 'GET') {
    $queryParams = $request->getQueryParams();
    $data = is_array($queryParams) ? $queryParams : $queryParams->toArray();
} elseif ($method === 'POST') {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($content_type, 'multipart/form-data') !== false) {
        $postData = $request->getParsedBody();
        $data = is_array($postData) ? $postData : $postData->toArray();
    } else {
        $input = file_get_contents('php://input');
        $json_data = json_decode($input, true);
        if ($json_data !== null) {
            $data = $json_data;
        } else {
            $postData = $request->getParsedBody();
            $data = is_array($postData) ? $postData : $postData->toArray();
        }
    }
}

$action = $data['action'] ?? $_REQUEST['action'] ?? '';
$chat_id = $data['chat_id'] ?? $_REQUEST['chat_id'] ?? '';
$user_id = (int) ($DIC->user()->getId() ?? 0);

try {
    // ============================================
    // Action Routing
    // ============================================

    switch ($action) {

        // ========================================
        // Send Message (Non-Streaming)
        // ========================================
        case 'send_message':
            header('Content-Type: application/json');

            $message = $data['message'] ?? '';
            $attachment_ids = $data['attachment_ids'] ?? [];

            // Handle attachment_ids: may come as JSON string or array
            if (is_string($attachment_ids)) {
                $attachment_ids = json_decode($attachment_ids, true) ?: [];
            }
            if (!is_array($attachment_ids)) {
                $attachment_ids = [];
            }

            if (empty($chat_id) || empty($message)) {
                echo json_encode(['error' => 'Missing required parameters']);
                exit;
            }

            // Load chat config to get AI service
            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            // Create LLM instance and delegate
            $llm = createLLMInstance($chatConfig->getAiService());
            $llm->setStreaming(false);

            $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);

            echo json_encode([
                'success' => true,
                'message' => $response
            ]);
            break;

        // ========================================
        // Send Message (Streaming)
        // ========================================
        case 'send_message_stream':
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            $message = $data['message'] ?? '';
            $attachment_ids = $data['attachment_ids'] ?? [];

            // Handle attachment_ids: may come as JSON string or array
            if (is_string($attachment_ids)) {
                $attachment_ids = json_decode($attachment_ids, true) ?: [];
            }
            if (!is_array($attachment_ids)) {
                $attachment_ids = [];
            }

            if (empty($chat_id) || empty($message)) {
                echo "data: " . json_encode(['error' => 'Missing required parameters']) . "\n\n";
                exit;
            }

            // Load chat config to get AI service
            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                echo "data: " . json_encode(['error' => 'Chat not found']) . "\n\n";
                exit;
            }

            // Create LLM instance and delegate
            $llm = createLLMInstance($chatConfig->getAiService());
            $llm->setStreaming(true);

            echo "data: " . json_encode(['type' => 'start']) . "\n\n";
            flush();

            $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);

            echo "data: " . json_encode(['type' => 'complete', 'message' => $response]) . "\n\n";
            flush();
            break;

        // ========================================
        // Upload File
        // ========================================
        case 'upload_file':
            header('Content-Type: application/json');

            if (!isset($_FILES['file'])) {
                echo json_encode(['error' => 'No file uploaded']);
                exit;
            }

            // Load chat config
            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            // Validate file upload
            $upload_info = $_FILES['file'];
            if ($upload_info['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'File upload failed']);
                exit;
            }

            // Get LLM instance and check if RAG is enabled (service + global + chat)
            $llm = createLLMInstance($chatConfig->getAiService());
            $rag_enabled = isRagEnabledForChat($chatConfig, $llm);

            // Validate file type based on RAG mode
            $file_extension = strtolower(pathinfo($upload_info['name'], PATHINFO_EXTENSION));
            if (!$llm->isFileTypeAllowed($file_extension, $rag_enabled)) {
                $mode = $rag_enabled ? 'RAG' : 'Multimodal';
                $allowed = $llm->getAllowedFileTypesDescription($rag_enabled);
                echo json_encode([
                    'error' => "File type .{$file_extension} not allowed in {$mode} mode. Allowed: {$allowed}"
                ]);
                exit;
            }

            try {
                // Store file using ILIAS Resource Storage
                $resource_storage = $DIC->resourceStorage();
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();

                // Use ILIAS FileUpload service
                $upload_service = $DIC->upload();
                $upload_service->process();

                if (!$upload_service->hasUploads()) {
                    throw new \Exception('No valid uploads found');
                }

                $upload_results = $upload_service->getResults();
                $upload_result = $upload_results[array_keys($upload_results)[0]];

                if (!$upload_result->isOK()) {
                    throw new \Exception('Upload validation failed');
                }

                // Store in IRSS
                $resource_id = $resource_storage->manage()->upload($upload_result, $stakeholder);

                // Create attachment record
                $attachment = new Attachment();
                $attachment->setChatId($chat_id);
                $attachment->setUserId($user_id);
                $attachment->setResourceId($resource_id->serialize());
                $attachment->setMessageId(null); // Unbound until sent with message
                $attachment->setTimestamp(date('Y-m-d H:i:s'));
                $attachment->save();

                // Check if we should upload to RAG (use chat-specific setting)
                $llm = createLLMInstance($chatConfig->getAiService());
                $enable_rag = $chatConfig->isEnableRag() && $llm->supportsRAG();

                // Get file info
                $revision = $resource_storage->manage()->getCurrentRevision($resource_id);
                $suffix = strtolower($revision->getInformation()->getSuffix());

                // CRITICAL: If RAG is active, ALL chat uploads must go to RAG
                // Mixing RAG + Base64 in same chat is not supported by RAMSES
                // File type validation already done by FileUpload service
                if ($enable_rag) {
                    try {
                        // Download file from IRSS to temp location
                        $stream = $resource_storage->consume()->stream($resource_id);
                        $content = $stream->getStream()->getContents();

                        // Use original filename for RAMSES validation (signature check needs correct filename)
                        $original_filename = $revision->getTitle();
                        $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original_filename);
                        // Add unique prefix to avoid collisions
                        $temp_file = sys_get_temp_dir() . '/' . uniqid() . '_' . $safe_filename;
                        file_put_contents($temp_file, $content);

                        $logger->debug("Prepared temp file for RAG upload", [
                            'temp_file' => $temp_file,
                            'original_filename' => $original_filename,
                            'size' => strlen($content),
                            'suffix' => $suffix
                        ]);

                        // Get or create session for this upload
                        // IMPORTANT: Each session gets its own RAG collection to separate user uploads from background files
                        $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);
                        $session_id = $session->getSessionId();

                        // Upload to RAG using session_id as entityId (not chat_id!)
                        // This ensures user uploads are isolated from background files
                        $rag_response = $llm->uploadFileToRAG($temp_file, $session_id);

                        // Update attachment with RAG info
                        $attachment->setRAGCollectionId($rag_response['collection_id']);
                        $attachment->setRAGRemoteFileId($rag_response['remote_file_id']);
                        $attachment->setRAGUploadedAt(date('Y-m-d H:i:s'));
                        $attachment->save();

                        // Cleanup
                        @unlink($temp_file);

                    } catch (\Exception $e) {
                        $logger->error("RAG upload failed for chat upload", [
                            'attachment_id' => $attachment->getId(),
                            'chat_id' => $chat_id,
                            'suffix' => $suffix,
                            'error' => $e->getMessage()
                        ]);
                        // RAG upload failed - this is critical because RAG mode is active
                        // File will NOT be available as base64 fallback to avoid mixing RAG + Base64
                        throw new \Exception('RAG upload failed: ' . $e->getMessage());
                    }
                }

                // Return attachment info
                echo json_encode([
                    'success' => true,
                    'attachment' => $attachment->toArray()
                ]);

            } catch (\Exception $e) {
                $logger->error("File upload failed", [
                    'error' => $e->getMessage()
                ]);
                echo json_encode(['error' => 'File upload failed: ' . $e->getMessage()]);
            }
            break;

        // ========================================
        // Load Chat History
        // ========================================
        case 'load_chat':
            header('Content-Type: application/json');

            if (empty($chat_id)) {
                echo json_encode(['error' => 'Missing chat_id']);
                exit;
            }

            // Load chat configuration
            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                echo json_encode(['error' => 'Chat configuration not found']);
                exit;
            }

            // Get or create session
            $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);

            // Get recent messages
            $messages = $session->getRecentMessages(50);

            // Format messages for frontend
            $formatted_messages = [];
            foreach ($messages as $msg) {
                $attachments = $msg->getAttachments();
                $formatted_attachments = [];

                foreach ($attachments as $att) {
                    $formatted_attachments[] = $att->toArray();
                }

                $formatted_messages[] = [
                    'role' => $msg->getRole(),
                    'message' => $msg->getMessage(),
                    'timestamp' => $msg->getTimestamp(),
                    'attachments' => $formatted_attachments
                ];
            }

            echo json_encode([
                'success' => true,
                'config' => $chatConfig->toArray(),
                'session' => $session->toArray(),
                'messages' => $formatted_messages
            ]);
            break;

        // ========================================
        // Clear Chat History
        // ========================================
        case 'clear_chat':
            header('Content-Type: application/json');

            if (empty($chat_id)) {
                echo json_encode(['error' => 'Missing chat_id']);
                exit;
            }

            // Delete user session for this chat
            $db = $DIC->database();

            // Get session ID
            $query = "SELECT session_id FROM pcaic_sessions " .
                     "WHERE user_id = " . $db->quote($user_id, 'integer') . " " .
                     "AND chat_id = " . $db->quote($chat_id, 'text');

            $result = $db->query($query);
            if ($row = $db->fetchAssoc($result)) {
                $session_id = $row['session_id'];

                // First, explicitly delete all attachments (triggers RAG cleanup)
                $attachments_query = "SELECT a.id FROM pcaic_attachments a " .
                                    "INNER JOIN pcaic_messages m ON a.message_id = m.message_id " .
                                    "WHERE m.session_id = " . $db->quote($session_id, 'text');
                $attachments_result = $db->query($attachments_query);

                while ($attachment_row = $db->fetchAssoc($attachments_result)) {
                    try {
                        $attachment = new Attachment((int)$attachment_row['id']);
                        $attachment->delete(); // This triggers RAG cleanup
                    } catch (\Exception $e) {
                        $logger->warning("Failed to delete attachment during clear_chat", [
                            'attachment_id' => $attachment_row['id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Delete messages
                $db->manipulate("DELETE FROM pcaic_messages WHERE session_id = " . $db->quote($session_id, 'text'));

                // Delete session
                $db->manipulate("DELETE FROM pcaic_sessions WHERE session_id = " . $db->quote($session_id, 'text'));
            }

            echo json_encode(['success' => true]);
            break;

        // ========================================
        // Get Upload Configuration
        // ========================================
        case 'get_upload_config':
            header('Content-Type: application/json');

            $max_file_size = \platform\AIChatPageComponentConfig::get('max_upload_size_mb') ?: 10;
            $allowed_types = \platform\AIChatPageComponentConfig::get('allowed_file_types') ?: 'txt,md,csv,pdf,jpg,jpeg,png,gif,webp';

            echo json_encode([
                'success' => true,
                'max_file_size_mb' => (int)$max_file_size,
                'allowed_types' => explode(',', $allowed_types)
            ]);
            break;

        // ========================================
        // Get Global Configuration
        // ========================================
        case 'get_global_config':
            header('Content-Type: application/json');

            // Check if uploads are globally enabled
            $upload_enabled = true; // Default to enabled
            $enable_uploads_setting = \platform\AIChatPageComponentConfig::get('enable_file_uploads');
            if ($enable_uploads_setting !== null) {
                $upload_enabled = ($enable_uploads_setting === '1' || $enable_uploads_setting === 'true');
            }

            // Determine allowed file types based on chat's RAG mode
            $chat_id = $data['chat_id'] ?? null;

            if (!$chat_id) {
                http_response_code(400);
                echo json_encode(['error' => 'chat_id is required for get_global_config']);
                exit;
            }

            $chatConfig = new ChatConfig($chat_id);
            $ai_service = $chatConfig->getAiService();

            // Get LLM instance and check if RAG is enabled (service + global + chat)
            $llm = createLLMInstance($ai_service);
            $rag_enabled = isRagEnabledForChat($chatConfig, $llm);

            // Get allowed file types from LLM service based on actual RAG mode
            $allowed_extensions = $llm->getAllowedFileTypes($rag_enabled);

            echo json_encode([
                'success' => true,
                'upload_enabled' => $upload_enabled,
                'allowed_extensions' => $allowed_extensions,
                'rag_mode' => $rag_enabled,
                'max_file_size_mb' => (int)(\platform\AIChatPageComponentConfig::get('max_upload_size_mb') ?: 10),
                'max_attachments_per_message' => (int)(\platform\AIChatPageComponentConfig::get('max_attachments_per_message') ?: 5),
                'max_char_limit' => (int)(\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000),
                'max_memory_limit' => (int)(\platform\AIChatPageComponentConfig::get('max_memory_messages') ?: 10)
            ]);
            break;

        // ========================================
        // Test Endpoint
        // ========================================
        case 'test':
            header('Content-Type: application/json');

            echo json_encode([
                'success' => true,
                'message' => 'API is working',
                'user_id' => $user_id,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        // ========================================
        // Unknown Action
        // ========================================
        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'error' => 'Unknown action: ' . $action
            ]);
            break;
    }

} catch (\Exception $e) {
    $logger->error("API Error", [
        'action' => $action,
        'chat_id' => $chat_id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    // Only set headers if they haven't been sent yet (e.g., not in streaming mode)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    } else {
        // In streaming mode, send error as SSE event
        echo "data: " . json_encode([
            'type' => 'error',
            'error' => 'Server error: ' . $e->getMessage()
        ]) . "\n\n";
        flush();
    }
}

/**
 * Create LLM instance based on service name
 *
 * @param string $service Service identifier (ramses|openai)
 * @return \ai\AIChatPageComponentLLM LLM instance
 */
function createLLMInstance(string $service): \ai\AIChatPageComponentLLM
{
    switch ($service) {
        case 'ramses':
            return \ai\AIChatPageComponentRAMSES::fromConfig();
        case 'openai':
            return \ai\AIChatPageComponentOpenAI::fromConfig();
        default:
            return \ai\AIChatPageComponentRAMSES::fromConfig();
    }
}

/**
 * Check if RAG mode is enabled for chat
 *
 * RAG requires three conditions:
 * 1. Service supports RAG
 * 2. RAG globally enabled in plugin config
 * 3. RAG enabled for specific chat
 *
 * @param ChatConfig $chatConfig Chat configuration
 * @param \ai\AIChatPageComponentLLM $llm LLM instance
 * @return bool True if RAG enabled, false for multimodal mode
 */
function isRagEnabledForChat(ChatConfig $chatConfig, \ai\AIChatPageComponentLLM $llm): bool
{
    if (!$llm->supportsRAG()) {
        return false;
    }

    $ai_service = $chatConfig->getAiService();
    $rag_config_key = $ai_service . '_enable_rag';
    $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
    $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

    if (!$rag_globally_enabled) {
        return false;
    }

    return $chatConfig->isEnableRag();
}
