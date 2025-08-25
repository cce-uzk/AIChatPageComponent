<?php declare(strict_types=1);

/**
 * Direct download handler for AIChatPageComponent attachments
 * Bypasses ILIAS FileDelivery token issues
 */

// Initialize ILIAS
chdir('../../../../../');
require_once('./include/inc.header.php');

if (!isset($_GET['resource_id'])) {
    http_response_code(400);
    echo 'Missing resource_id parameter';
    exit;
}

// Check if user is logged in
global $DIC;
if (!$DIC->user() || $DIC->user()->isAnonymous()) {
    http_response_code(401);
    echo 'Authentication required';
    exit;
}

$resource_id = $_GET['resource_id'];

try {
    global $DIC;
    $resource_storage = $DIC->resourceStorage();
    
    // Get resource identification
    $identification = $resource_storage->manage()->find($resource_id);
    if (!$identification) {
        http_response_code(404);
        echo 'Resource not found';
        exit;
    }
    
    // Get current revision
    $revision = $resource_storage->manage()->getCurrentRevision($identification);
    if (!$revision) {
        http_response_code(404);
        echo 'Resource revision not found';
        exit;
    }
    
    // Get file information
    $info = $revision->getInformation();
    $mime_type = $info->getMimeType();
    $filename = $info->getTitle();
    $size = $info->getSize();
    
    // Get file stream
    $stream_consumer = $resource_storage->consume()->stream($identification);
    $stream = $stream_consumer->getStream();
    
    // Set proper headers
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=3600');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    
    // Output file content
    if ($stream->isSeekable()) {
        $stream->rewind();
    }
    
    while (!$stream->eof()) {
        echo $stream->read(8192);
        if (connection_aborted()) {
            break;
        }
    }
    
    $stream->close();
    
} catch (Exception $e) {
    $DIC->logger()->comp('pcaic')->error("Download Error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo 'Download failed: ' . htmlspecialchars($e->getMessage());
}
?>