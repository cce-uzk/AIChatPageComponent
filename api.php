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
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentLLMRegistry.php');
require_once(__DIR__ . '/src/Model/ChatConfig.php');
require_once(__DIR__ . '/src/Model/ChatSession.php');
require_once(__DIR__ . '/src/Model/ChatMessage.php');
require_once(__DIR__ . '/src/Model/Attachment.php');

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;
use ILIAS\Plugin\pcaic\Model\ChatMessage;
use ILIAS\Plugin\pcaic\Model\Attachment;

// Restrict CORS to the ILIAS installation's own origin
$allowed_origin = defined('ILIAS_HTTP_PATH') ? rtrim(ILIAS_HTTP_PATH, '/') : '';
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($allowed_origin !== '' && $request_origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
} elseif ($allowed_origin === '') {
    // Fallback when ILIAS_HTTP_PATH is not yet defined
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? ''));
}
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

$action = $data['action'] ?? '';
$chat_id = $data['chat_id'] ?? '';
$user_id = (int) ($DIC->user()->getId() ?? 0);
$is_anonymous = $DIC->user()->isAnonymous();
$allow_anonymous = (\platform\AIChatPageComponentConfig::get('allow_anonymous_access') === '1');

// Block all access for anonymous users when globally disabled
if ($is_anonymous && !$allow_anonymous) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'anonymous_access_blocked']);
    exit;
}

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

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            // Daily message rate limit (authenticated users only)
            if (!$is_anonymous) {
                $limit_error = checkDailyMessageLimit($chat_id, $user_id);
                if ($limit_error !== null) {
                    http_response_code(429);
                    echo json_encode(['error' => $limit_error]);
                    exit;
                }
            }

            // Create LLM instance and delegate (respect force default service)
            $llm = createLLMInstance(getEffectiveAiService($chatConfig));
            $llm->setStreaming(false);

            if ($is_anonymous) {
                // Stateless mode: no DB session, history comes from frontend
                $conversation_history = $data['conversation_history'] ?? [];
                if (is_string($conversation_history)) {
                    $conversation_history = json_decode($conversation_history, true) ?: [];
                }
                $conversation_history = sanitizeConversationHistory($conversation_history);
                $response = $llm->handleStatelessMessage($chat_id, $conversation_history, $message);
            } else {
                $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);
            }

            // Strip sources from response text when show_sources is disabled
            if (!$chatConfig->isShowSources()) {
                $response = stripSourcesFromResponse($response);
            }

            // Build response with optional metadata
            $jsonResponse = [
                'success' => true,
                'message' => $response
            ];

            // Include RAG sources if enabled for this chat
            if ($chatConfig->isShowSources()) {
                $metadata = $llm->getLastResponseMetadata();
                if ($metadata !== null && !empty($metadata)) {
                    $bgUrls = $chatConfig->isAllowSourceDownloads()
                        ? getBackgroundFileDownloadUrls($chat_id)
                        : [];
                    $jsonResponse['sources'] = array_map(function($source) use ($bgUrls, $chat_id) {
                        $filename = $source['filename'] ?? 'Unknown';
                        $attachmentId = $bgUrls[$filename] ?? null;
                        return [
                            'filename'     => $filename,
                            'pages'        => $source['page_numbers'] ?? [],
                            'excerpt'      => isset($source['text']) ? mb_substr($source['text'], 0, 200) . '...' : null,
                            'download_url' => $attachmentId ? buildSecureDownloadUrl($chat_id, $attachmentId) : null,
                        ];
                    }, $metadata);
                }
            }

            // Include token usage if available
            $usage = $llm->getLastResponseUsage();
            if ($usage !== null) {
                $jsonResponse['usage'] = $usage;
            }

            echo json_encode($jsonResponse);
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

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo "data: " . json_encode(['error' => 'Access denied']) . "\n\n";
                exit;
            }

            // Daily message rate limit (authenticated users only)
            // Note: EventSource uses HTTP 200 always – errors must be sent as SSE events.
            if (!$is_anonymous) {
                $limit_error = checkDailyMessageLimit($chat_id, $user_id);
                if ($limit_error !== null) {
                    echo "data: " . json_encode(['type' => 'error', 'error' => $limit_error]) . "\n\n";
                    flush();
                    exit;
                }
            }

            // Get effective AI service (respects force default)
            $aiService = getEffectiveAiService($chatConfig);

            // Check hierarchical streaming settings (central → LLM → chat)
            $streamingEnabled = isStreamingEnabledForChat($chatConfig, $aiService);

            // Flush start event BEFORE creating the LLM instance so that headers_sent()
            // is true when createLLMInstance() throws (e.g. no_service_available).
            // EventSource only fires onmessage for HTTP 200 responses; a 503 would only
            // trigger onerror where the body is inaccessible.
            echo "data: " . json_encode(['type' => 'start']) . "\n\n";
            flush();

            // Create LLM instance and delegate
            $llm = createLLMInstance($aiService);
            $llm->setStreaming($streamingEnabled);

            if ($is_anonymous) {
                // Stateless mode: no DB session, history comes from frontend
                $conversation_history = $data['conversation_history'] ?? [];
                if (is_string($conversation_history)) {
                    $conversation_history = json_decode($conversation_history, true) ?: [];
                }
                $conversation_history = sanitizeConversationHistory($conversation_history);
                $response = $llm->handleStatelessMessage($chat_id, $conversation_history, $message);
            } else {
                $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);
            }

            // Strip sources from response text when show_sources is disabled
            if (!$chatConfig->isShowSources()) {
                $response = stripSourcesFromResponse($response);
            }

            // Build complete response with optional metadata
            $completeData = ['type' => 'complete', 'message' => $response];

            // Include RAG sources if enabled for this chat
            if ($chatConfig->isShowSources()) {
                $metadata = $llm->getLastResponseMetadata();
                if ($metadata !== null && !empty($metadata)) {
                    $bgUrls = $chatConfig->isAllowSourceDownloads()
                        ? getBackgroundFileDownloadUrls($chat_id)
                        : [];
                    $completeData['sources'] = array_map(function($source) use ($bgUrls, $chat_id) {
                        $filename = $source['filename'] ?? 'Unknown';
                        $attachmentId = $bgUrls[$filename] ?? null;
                        return [
                            'filename'     => $filename,
                            'pages'        => $source['page_numbers'] ?? [],
                            'excerpt'      => isset($source['text']) ? mb_substr($source['text'], 0, 200) . '...' : null,
                            'download_url' => $attachmentId ? buildSecureDownloadUrl($chat_id, $attachmentId) : null,
                        ];
                    }, $metadata);
                }
            }

            // Include token usage if available
            $usage = $llm->getLastResponseUsage();
            if ($usage !== null) {
                $completeData['usage'] = $usage;
            }

            echo "data: " . json_encode($completeData) . "\n\n";
            flush();
            break;

        // ========================================
        // Upload File
        // ========================================
        case 'upload_file':
            header('Content-Type: application/json');

            // File uploads are never permitted for anonymous users – regardless of the
            // allow_anonymous_access setting. Anonymous can chat (stateless) but never upload.
            if ($is_anonymous) {
                http_response_code(403);
                echo json_encode(['error' => 'File uploads are not available for anonymous users']);
                exit;
            }

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

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            // Check hierarchical file handling (global → service)
            $aiService = getEffectiveAiService($chatConfig);
            if (!isFileHandlingEnabledForService($aiService)) {
                echo json_encode(['error' => 'File handling is disabled for this AI service']);
                exit;
            }

            // Validate file upload
            $upload_info = $_FILES['file'];
            if ($upload_info['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'File upload failed']);
                exit;
            }

            // Get LLM instance and check if RAG is enabled (service + global + chat)
            $llm = createLLMInstance(getEffectiveAiService($chatConfig));
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
                $llm = createLLMInstance(getEffectiveAiService($chatConfig));
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
                echo json_encode(['error' => 'File upload failed. Please try again.']);
            }
            break;

        // ========================================
        // Load Chat History
        // ========================================
        case 'load_chat':
            header('Content-Type: application/json');

            // Anonymous sessions are stateless – no server-side history exists
            if ($is_anonymous) {
                echo json_encode(['success' => true, 'messages' => [], 'session' => null, 'config' => []]);
                exit;
            }

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

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
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

                $msgText = $msg->getMessage();
                $showSources = $chatConfig->isShowSources();

                // Strip sources from assistant messages when show_sources is disabled
                if ($msg->getRole() === 'assistant' && !$showSources) {
                    $msgText = stripSourcesFromResponse($msgText);
                }

                $formatted_msg = [
                    'role' => $msg->getRole(),
                    'message' => $msgText,
                    'timestamp' => $msg->getTimestamp(),
                    'attachments' => $formatted_attachments
                ];

                // Include sources for assistant messages (RAG citations)
                if ($msg->getRole() === 'assistant' && $showSources && $msg->hasSources()) {
                    $bgUrls = $chatConfig->isAllowSourceDownloads()
                        ? getBackgroundFileDownloadUrls($chat_id)
                        : [];
                    $formatted_msg['sources'] = array_map(function($source) use ($bgUrls, $chat_id) {
                        $filename = $source['filename'] ?? 'Unknown';
                        $attachmentId = $bgUrls[$filename] ?? null;
                        return array_merge($source, [
                            'download_url' => $attachmentId ? buildSecureDownloadUrl($chat_id, $attachmentId) : null,
                        ]);
                    }, $msg->getFormattedSources());
                }

                // Include usage data if available
                $usage = $msg->getUsage();
                if ($usage !== null) {
                    $formatted_msg['usage'] = $usage;
                }

                $formatted_messages[] = $formatted_msg;
            }

            echo json_encode([
                'success' => true,
                'config' => $chatConfig->toArray(),
                'session' => $session->toArray(),
                'messages' => $formatted_messages
            ]);

            // Lazy session cleanup: run with ~5% probability, restricted to users with
            // write access on the parent object so it never runs in a plain user context.
            if (mt_rand(1, 20) === 1 && checkChatAccess($chatConfig, 'write')) {
                $cleanup_days = (int)(\platform\AIChatPageComponentConfig::get('session_cleanup_days') ?? 90);
                if ($cleanup_days > 0) {
                    try {
                        $plugin = ilAIChatPageComponentPlugin::getInstance();
                        $plugin->cleanupInactiveSessions($cleanup_days);
                    } catch (\Exception $e) {
                        $logger->warning("Lazy session cleanup failed", ['error' => $e->getMessage()]);
                    }
                }
            }
            break;

        // ========================================
        // Clear Chat History
        // ========================================
        case 'clear_chat':
            header('Content-Type: application/json');

            // Anonymous sessions are stateless – nothing to clear server-side
            if ($is_anonymous) {
                echo json_encode(['success' => true]);
                exit;
            }

            if (empty($chat_id)) {
                echo json_encode(['error' => 'Missing chat_id']);
                exit;
            }

            $chatConfig = new ChatConfig($chat_id);
            if (!$chatConfig->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
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

            // Anonymous users never get upload access
            if ($DIC->user()->isAnonymous()) {
                $chatConfig = new ChatConfig($data['chat_id'] ?? '');
                $ai_service = getEffectiveAiService($chatConfig);
                $llm = createLLMInstance($ai_service);
                $rag_enabled = isRagEnabledForChat($chatConfig, $llm);
                $allowed_extensions = $llm->getAllowedFileTypes($rag_enabled);
                $streaming_enabled = isStreamingEnabledForChat($chatConfig, $ai_service);

                echo json_encode([
                    'success' => true,
                    'upload_enabled' => false,
                    'allowed_extensions' => $allowed_extensions,
                    'allowed_mime_types' => [],
                    'allowed_accept_values' => [],
                    'rag_mode' => $rag_enabled,
                    'streaming_enabled' => $streaming_enabled,
                    'file_handling_enabled' => false,
                    'max_file_size_mb' => 0,
                    'max_attachments_per_message' => 0,
                    'max_char_limit' => (int)(\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000),
                    'max_memory_limit' => (int)(\platform\AIChatPageComponentConfig::get('max_memory_messages') ?: 10)
                ]);
                exit;
            }

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
            if (!$chatConfig->exists()) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chatConfig)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $ai_service = getEffectiveAiService($chatConfig);

            // Get LLM instance and check if RAG is enabled (service + global + chat)
            $llm = createLLMInstance($ai_service);
            $rag_enabled = isRagEnabledForChat($chatConfig, $llm);

            // Get allowed file types from LLM service based on actual RAG mode
            $allowed_extensions = $llm->getAllowedFileTypes($rag_enabled);

            // Convert extensions to MIME types for browser file input accept attribute
            $allowed_mime_types = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::extensionsToMimeTypes($allowed_extensions);

            // Also get combined accept values (MIME types + extensions) for maximum compatibility
            $allowed_accept_values = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::extensionsToAcceptValues($allowed_extensions);

            // Check hierarchical streaming settings (central → LLM → chat)
            $streaming_enabled = isStreamingEnabledForChat($chatConfig, $ai_service);

            // Check hierarchical file handling settings (central → LLM)
            $file_handling_enabled = isFileHandlingEnabledForService($ai_service);

            echo json_encode([
                'success' => true,
                'upload_enabled' => $upload_enabled && $file_handling_enabled,
                'allowed_extensions' => $allowed_extensions,
                'allowed_mime_types' => $allowed_mime_types,
                'allowed_accept_values' => $allowed_accept_values,
                'rag_mode' => $rag_enabled,
                'streaming_enabled' => $streaming_enabled,
                'file_handling_enabled' => $file_handling_enabled,
                'max_file_size_mb' => (int)(\platform\AIChatPageComponentConfig::get('max_upload_size_mb') ?: 10),
                'max_attachments_per_message' => (int)(\platform\AIChatPageComponentConfig::get('max_attachments_per_message') ?: 5),
                'max_char_limit' => (int)(\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000),
                'max_memory_limit' => (int)(\platform\AIChatPageComponentConfig::get('max_memory_messages') ?: 10)
            ]);
            break;

        // ========================================
        // Download Background File (secure, session-checked)
        // ========================================
        case 'download_background_file':
            // Must be logged in
            if ($is_anonymous) {
                http_response_code(403);
                exit;
            }

            $attachment_id = (int)($data['attachment_id'] ?? 0);
            if (!$attachment_id || !$chat_id) {
                http_response_code(400);
                exit;
            }

            // Permission: user must have read access to this chat
            $dlChatConfig = new ChatConfig($chat_id);
            if (!checkChatAccess($dlChatConfig, 'read')) {
                http_response_code(403);
                exit;
            }

            // Downloads must be enabled for this chat
            if (!$dlChatConfig->isAllowSourceDownloads()) {
                http_response_code(403);
                exit;
            }

            // Load attachment and verify it belongs to this chat as a background file
            try {
                $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);
                if (!$attachment->isBackgroundFile() || $attachment->getChatId() !== $chat_id) {
                    http_response_code(403);
                    exit;
                }

                $resource_id = $attachment->getResourceIdentification();
                if (!$resource_id) {
                    http_response_code(404);
                    exit;
                }

                // Deliver via IRSS download consumer (forces download disposition)
                $download_consumer = $DIC->resourceStorage()->consume()->download($resource_id);
                $download_consumer->run();
            } catch (\Exception $e) {
                $logger->warning("Background file download failed", [
                    'attachment_id' => $attachment_id,
                    'error'         => $e->getMessage()
                ]);
                http_response_code(500);
            }
            exit;

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
    $is_no_service = ($e->getMessage() === 'no_service_available');

    $logger->error("API Error", [
        'action' => $action,
        'chat_id' => $chat_id,
        'error' => $e->getMessage(),
        'trace' => $is_no_service ? '' : $e->getTraceAsString()
    ]);

    $client_message = $is_no_service
        ? 'no_service_available'
        : 'An internal error occurred. Please try again.';

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($is_no_service ? 503 : 500);
        echo json_encode(['error' => $client_message]);
    } else {
        echo "data: " . json_encode(['type' => 'error', 'error' => $client_message]) . "\n\n";
        flush();
    }
}

/**
 * Check whether the authenticated user has exceeded their daily message limit.
 *
 * Counts 'user' role messages sent today (UTC) for this user+chat combination.
 * Returns null when within limits, or a translated error string when exceeded.
 *
 * @param string $chat_id  Chat identifier
 * @param int    $user_id  Authenticated user ID
 * @return string|null     null = OK, string = error message to return to client
 */
function checkDailyMessageLimit(string $chat_id, int $user_id): ?string
{
    $max = (int)(\platform\AIChatPageComponentConfig::get('max_messages_per_day') ?? 50);
    if ($max <= 0) {
        return null; // 0 = unlimited
    }

    global $DIC;
    $db = $DIC->database();

    $today = date('Y-m-d');

    $result = $db->query(
        "SELECT COUNT(*) AS cnt " .
        "FROM pcaic_messages m " .
        "INNER JOIN pcaic_sessions s ON m.session_id = s.session_id " .
        "WHERE s.user_id = " . $db->quote($user_id, 'integer') . " " .
        "AND s.chat_id = "  . $db->quote($chat_id, 'text')    . " " .
        "AND m.role = 'user' " .
        "AND DATE(m.timestamp) = " . $db->quote($today, 'text')
    );

    $row = $db->fetchAssoc($result);
    $count = (int) ($row['cnt'] ?? 0);

    if ($count >= $max) {
        $plugin = ilAIChatPageComponentPlugin::getInstance();
        return sprintf($plugin->txt('error_rate_limit_exceeded'), $max);
    }

    return null;
}

/**
 * Check whether the current user has read access to the parent ILIAS object
 * that contains the given chat.
 *
 * parent_id is stored as obj_id (ilPageObject::getParentId()).
 * All matching ref_ids are checked; access is granted if any passes.
 *
 * @param ChatConfig $chatConfig Chat configuration with parent context
 * @return bool True if access is permitted
 */
function checkChatAccess(ChatConfig $chatConfig, string $permission = 'read'): bool
{
    global $DIC;

    $parent_id = $chatConfig->getParentId();
    if ($parent_id <= 0) {
        return false;
    }

    // parent_id is obj_id – resolve to ref_ids for the access check
    $refs = ilObject::_getAllReferences($parent_id);

    if (empty($refs)) {
        // Fallback: treat parent_id directly as ref_id (some page types store it that way)
        $refs = [$parent_id];
    }

    foreach ($refs as $ref_id) {
        if ($DIC->access()->checkAccess($permission, '', (int)$ref_id)) {
            return true;
        }
    }

    return false;
}

/**
 * Sanitize conversation history from anonymous frontend
 *
 * Enforces hard limits to prevent memory exhaustion and token abuse.
 * Only 'user' and 'assistant' roles are allowed; each entry is truncated
 * to the configured character limit.
 *
 * @param array $history Raw history array from request
 * @return array Sanitized history
 */
function sanitizeConversationHistory(array $history): array
{
    $char_limit = (int)(\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000);
    $max_entries = 40; // Hard cap: 20 exchanges max regardless of max_memory setting

    $sanitized = [];
    foreach (array_slice($history, -$max_entries) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = $entry['role'] ?? '';
        $message = $entry['message'] ?? '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        if (!is_string($message)) {
            continue;
        }
        $sanitized[] = [
            'role' => $role,
            'message' => mb_substr($message, 0, $char_limit)
        ];
    }
    return $sanitized;
}

/**
 * Get effective AI service for a chat, respecting force default setting
 *
 * @param ChatConfig $chatConfig Chat configuration
 * @return string Service identifier (e.g., ramses, openai, claude, etc.)
 */
function getEffectiveAiService(ChatConfig $chatConfig): string
{
    // Check if force default service is enabled
    $force_default = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

    if ($force_default === '1') {
        // Force default service for all chats
        return \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
    }

    // Use chat's configured service
    return $chatConfig->getAiService();
}

/**
 * Create LLM instance based on service name using LLMRegistry
 *
 * @param string $service Service identifier (e.g., ramses, openai, claude, etc.)
 * @return \ai\AIChatPageComponentLLM LLM instance
 * @throws \Exception If service not found in registry
 */
function createLLMInstance(string $service): \ai\AIChatPageComponentLLM
{
    // Only use enabled services – disabled ones must not be instantiated
    $enabledServices = \ai\AIChatPageComponentLLMRegistry::getEnabledServices();

    if (empty($enabledServices)) {
        throw new \Exception("no_service_available");
    }

    // Try requested service first (only if it is enabled)
    if (isset($enabledServices[$service])) {
        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($service);
        if ($instance !== null) {
            return $instance;
        }
    }

    // Fallback: first enabled service
    $firstService = array_key_first($enabledServices);
    $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($firstService);

    if ($instance === null) {
        throw new \Exception("no_service_available");
    }

    return $instance;
}

/**
 * Check if streaming is enabled for this chat
 *
 * Streaming requires ALL three conditions to be met:
 * 1. Streaming globally enabled (central setting for all LLMs)
 * 2. Streaming enabled for the specific AI service (LLM-specific)
 * 3. Streaming enabled for the specific chat
 *
 * @param ChatConfig $chatConfig Chat configuration
 * @param string $aiService AI service identifier (e.g., ramses, openai, claude, etc.)
 * @return bool True if streaming should be used, false otherwise
 */
function isStreamingEnabledForChat(ChatConfig $chatConfig, string $aiService): bool
{
    // 1. Check central/global streaming setting (applies to all LLMs)
    $global_streaming = \platform\AIChatPageComponentConfig::get('enable_streaming') ?: '1';
    if ($global_streaming !== '1') {
        return false; // Centrally disabled
    }

    // 2. Check LLM-specific streaming setting
    $llm_streaming_key = $aiService . '_streaming_enabled';
    $llm_streaming = \platform\AIChatPageComponentConfig::get($llm_streaming_key);

    // Default: enabled for all services (can be disabled per service in config)
    $llm_streaming = $llm_streaming ?? '1';

    if ($llm_streaming !== '1') {
        return false; // LLM-specific disabled
    }

    // 3. Check chat-specific streaming setting
    if (!$chatConfig->isEnableStreaming()) {
        return false; // Chat-specific disabled
    }

    return true; // All three conditions met
}

/**
 * Check if file handling is enabled for this AI service
 *
 * File handling requires both conditions to be met:
 * 1. File handling globally enabled (central setting for all services)
 * 2. File handling enabled for the specific AI service
 *
 * @param string $aiService AI service identifier (e.g., ramses, openai, claude, etc.)
 * @return bool True if file handling should be used, false otherwise
 */
function isFileHandlingEnabledForService(string $aiService): bool
{
    // 1. Check central/global file handling setting
    $global_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
    if ($global_file_handling !== '1') {
        return false; // Centrally disabled
    }

    // 2. Check service-specific file handling setting
    $service_file_handling_key = $aiService . '_file_handling_enabled';
    $service_file_handling = \platform\AIChatPageComponentConfig::get($service_file_handling_key);

    // Default values: Both RAMSES and OpenAI enabled by default
    $default_file_handling = '1';
    $service_file_handling = $service_file_handling ?? $default_file_handling;

    if ($service_file_handling !== '1') {
        return false; // Service-specific disabled
    }

    return true; // Both conditions met
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

    $ai_service = getEffectiveAiService($chatConfig);
    $rag_config_key = $ai_service . '_enable_rag';
    $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
    $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

    if (!$rag_globally_enabled) {
        return false;
    }

    return $chatConfig->isEnableRag();
}

/**
 * Strip RAG source citations from an AI response text.
 *
 * Removes:
 * - Inline footnote superscripts (¹²³⁴⁵⁶⁷⁸⁹⁰ and [1][2]… variants, ^N)
 * - The entire sources/references section at the end of the response
 *   (triggered by headings like "### Quellen", "### Sources", "### Références",
 *    "### References", optionally bold-wrapped, preceded by ---)
 */
function stripSourcesFromResponse(string $text): string
{
    // Remove trailing sources section (--- separator or heading variants)
    // Matches from the first occurrence of a sources heading to end of string
    $sectionPattern = '/\s*(?:---\s*)?#{1,4}\s*\*{0,2}(?:Quellen|Sources?|R[eé]f[eé]rences?|Literatur(?:verzeichnis)?|Bibliography)\*{0,2}\s*:?.*$/us';
    $text = preg_replace($sectionPattern, '', $text);

    // Remove numbered reference list at end (lines like "1. filename, S. 3")
    // Only if they appear to be a citation block (number + dot + text pattern, multiple lines)
    $text = preg_replace('/(?:\n\d+\.\s+[^\n]+){2,}\s*$/u', '', $text);

    // Remove inline Unicode superscript footnote markers (¹²³⁴⁵⁶⁷⁸⁹⁰ combinations)
    $text = preg_replace('/[\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+/u', '', $text);

    // Remove ^N caret-style markers
    $text = preg_replace('/\^[\d,\s]+/', '', $text);

    // Remove [N] bracket-style markers
    $text = preg_replace('/\[\d+\]/', '', $text);

    // Convert markdown links [label](url) → label (keep readable text, drop URL)
    $text = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^)]+\)/', '$1', $text);

    // Remove bare URLs
    $text = preg_replace('/https?:\/\/\S+/', '', $text);

    return rtrim($text);
}

/**
 * Build a filename → attachment_id map for all background files of a chat.
 * Used to enrich RAG source citations with secure download links.
 *
 * @param string $chat_id
 * @return array<string, int>  e.g. ['report.pdf' => 42]
 */
function getBackgroundFileDownloadUrls(string $chat_id): array
{
    $map = [];
    try {
        $attachments = \ILIAS\Plugin\pcaic\Model\Attachment::getByChatId($chat_id);
        foreach ($attachments as $attachment) {
            if (!$attachment->isBackgroundFile()) {
                continue;
            }
            $title = $attachment->getTitle();
            $id    = $attachment->getId();
            if ($title && $id) {
                $map[$title] = $id;
                // RAMSES replaces spaces with underscores in filenames
                $map[str_replace(' ', '_', $title)] = $id;
            }
        }
    } catch (\Exception $e) {
        // Non-critical – sources are still shown, just without download links
    }
    return $map;
}

/**
 * Build a secure download URL for a background file attachment.
 * Routes through api.php so ILIAS session + read permission are enforced.
 */
function buildSecureDownloadUrl(string $chat_id, int $attachment_id): string
{
    global $DIC;
    $base = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
    $plugin_path = '/Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent/api.php';
    return $base . $plugin_path
        . '?action=download_background_file'
        . '&chat_id=' . urlencode($chat_id)
        . '&attachment_id=' . (int)$attachment_id;
}
