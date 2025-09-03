<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Model;

use ILIAS\ResourceStorage\Services as ResourceStorage;
use ILIAS\Plugin\pcaic\Storage\ResourceStakeholder;
use Exception;

/**
 * File Attachment Model for AI Chat Page Component
 * 
 * Manages file attachments in AI chat conversations using ILIAS ResourceStorage Service.
 * Supports multimodal AI interactions through comprehensive file processing capabilities.
 * 
 * Supported file types:
 * - Images (JPG, PNG, GIF, WebP): Optimized for AI analysis with ILIAS Flavours
 * - PDFs: Page-by-page conversion to images via Ghostscript
 * - Text files (TXT, MD, CSV): Content integration into AI context
 * 
 * Features:
 * - ILIAS ResourceStorage integration for secure file handling
 * - Automatic image optimization and compression
 * - PDF-to-image conversion with caching
 * - Multimodal AI message formatting
 * - File persistence across sessions
 * - Download URL generation with fallbacks
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * 
 * @see \ILIAS\ResourceStorage\Services
 * @see \ILIAS\Plugin\pcaic\Service\ImageOptimizer
 * 
 * @package ILIAS\Plugin\pcaic\Model
 */
class Attachment
{
    /** @var int|null Database primary key identifier */
    protected ?int $id = null;
    
    /** @var int|null Associated message ID for conversation context */
    protected ?int $message_id = null;
    
    /** @var string|null Chat ID for session association */
    protected ?string $chat_id = null;
    
    /** @var int|null User ID who uploaded the file */
    protected ?int $user_id = null;
    
    /** @var string|null ILIAS ResourceStorage resource identifier */
    protected ?string $resource_id = null;
    
    /** @var string|null Upload timestamp in Y-m-d H:i:s format */
    protected ?string $timestamp = null;
    
    /** @var \ilDBInterface Database interface for persistence operations */
    protected \ilDBInterface $db;
    
    /** @var ResourceStorage ILIAS ResourceStorage service for file operations */
    protected ResourceStorage $resource_storage;
    
    /** @var \ilLogger Component-specific logger for debugging */
    protected \ilLogger $logger;
    
    /**
     * Constructor - initializes attachment with optional auto-loading
     * 
     * Sets up database connection, logging, and ResourceStorage service.
     * If an ID is provided, automatically loads the attachment data from database.
     * 
     * @param int|null $id Optional attachment ID for auto-loading
     */
    public function __construct(?int $id = null)
    {
        global $DIC;
        
        // Initialize core services
        $this->db = $DIC->database();
        $this->logger = $DIC->logger()->comp('pcaic');
        $this->resource_storage = $DIC->resourceStorage();
        
        // Auto-load if ID provided
        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }
    
    /**
     * Loads attachment data from database using the current ID
     * 
     * Populates all instance properties from the database record.
     * No-op if ID is not set.
     * 
     * @return void
     */
    public function load(): void
    {
        if (!$this->id) {
            return;
        }
        
        $query = "SELECT * FROM pcaic_attachments WHERE id = " . $this->db->quote($this->id, 'integer');
        $result = $this->db->query($query);
        
        if ($row = $this->db->fetchAssoc($result)) {
            $this->message_id = (int)$row['message_id'];
            $this->chat_id = $row['chat_id'];
            $this->user_id = (int)$row['user_id'];
            $this->resource_id = $row['resource_id'];
            $this->timestamp = $row['timestamp'];
        }
    }
    
    public function save(): void
    {
        if ($this->id) {
            // Update existing attachment
            $query = "UPDATE pcaic_attachments SET " .
                "message_id = " . $this->db->quote($this->message_id, 'integer') . ", " .
                "chat_id = " . $this->db->quote($this->chat_id, 'text') . ", " .
                "user_id = " . $this->db->quote($this->user_id, 'integer') . ", " .
                "resource_id = " . $this->db->quote($this->resource_id, 'text') . ", " .
                "timestamp = " . $this->db->quote($this->timestamp, 'timestamp') . " " .
                "WHERE id = " . $this->db->quote($this->id, 'integer');
        } else {
            // Insert new attachment
            $this->id = $this->db->nextId('pcaic_attachments');
            $query = "INSERT INTO pcaic_attachments (id, message_id, chat_id, user_id, resource_id, timestamp) " .
                "VALUES (" .
                $this->db->quote($this->id, 'integer') . ", " .
                $this->db->quote($this->message_id, 'integer') . ", " .
                $this->db->quote($this->chat_id, 'text') . ", " .
                $this->db->quote($this->user_id, 'integer') . ", " .
                $this->db->quote($this->resource_id, 'text') . ", " .
                $this->db->quote($this->timestamp, 'timestamp') . ")";
        }
        
        $this->db->manipulate($query);
    }
    
    public function delete(): void
    {
        if (!$this->id) {
            return;
        }
        
        // Remove from ResourceStorage
        if ($this->resource_id) {
            try {
                $resource_id = $this->resource_storage->manage()->find($this->resource_id);
                if ($resource_id) {
                    $stakeholder = new ResourceStakeholder();
                    $this->resource_storage->manage()->remove($resource_id, $stakeholder);
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to remove resource during attachment deletion", [
                    'resource_id' => $this->resource_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Remove from database
        $query = "DELETE FROM pcaic_attachments WHERE id = " . $this->db->quote($this->id, 'integer');
        $this->db->manipulate($query);
    }
    
    /**
     * @return Attachment[]
     */
    public static function getByMessageId(int $message_id): array
    {
        global $DIC;
        $db = $DIC->database();
        
        $attachments = [];
        $query = "SELECT id FROM pcaic_attachments WHERE message_id = " . $db->quote($message_id, 'integer') . " ORDER BY timestamp ASC";
        $result = $db->query($query);
        
        while ($row = $db->fetchAssoc($result)) {
            $attachments[] = new self((int)$row['id']);
        }
        
        return $attachments;
    }
    
    /**
     * @return Attachment[]
     */
    public static function getByChatId(string $chat_id): array
    {
        global $DIC;
        $db = $DIC->database();
        
        $attachments = [];
        $query = "SELECT id FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text') . " ORDER BY timestamp ASC";
        $result = $db->query($query);
        
        while ($row = $db->fetchAssoc($result)) {
            $attachments[] = new self((int)$row['id']);
        }
        
        return $attachments;
    }
    
    /**
     * Factory method to create attachment from file upload
     * 
     * Processes an uploaded file through ILIAS ResourceStorage and creates
     * a new attachment record linked to a specific message and chat.
     * 
     * @param \ILIAS\FileUpload\DTO\UploadResult $upload_result File upload result from ILIAS
     * @param int $message_id Message ID to associate with
     * @param string $chat_id Chat ID for context
     * @param int $user_id User ID who uploaded the file
     * 
     * @return self New attachment instance
     * 
     * @throws Exception If upload failed or ResourceStorage operations fail
     */
    public static function createFromUpload(\ILIAS\FileUpload\DTO\UploadResult $upload_result, int $message_id, string $chat_id, int $user_id): self
    {
        global $DIC;
        
        if (!$upload_result->isOK()) {
            $logger = $DIC->logger()->comp('pcaic');
            $logger->debug("Upload failed: " . $upload_result->getStatus()->getMessage());
            throw new Exception("Upload failed");
        }
        
        $resource_storage = $DIC->resourceStorage();
        
        // Create stakeholder using our dedicated class
        $stakeholder = new ResourceStakeholder();
        
        // Store the uploaded file in ResourceStorage
        $resource_id = $resource_storage->manage()->upload($upload_result, $stakeholder);
        
        // Create attachment record
        $attachment = new self();
        $attachment->setMessageId($message_id);
        $attachment->setChatId($chat_id);
        $attachment->setUserId($user_id);
        $attachment->setResourceId($resource_id->serialize());
        $attachment->setTimestamp(date('Y-m-d H:i:s'));
        $attachment->save();
        
        return $attachment;
    }
    
    public function getResourceIdentification(): ?\ILIAS\ResourceStorage\Identification\ResourceIdentification
    {
        if (!$this->resource_id) {
            return null;
        }
        
        try {
            return $this->resource_storage->manage()->find($this->resource_id);
        } catch (Exception $e) {
            $this->logger->warning("Failed to find resource", [
                'resource_id' => $this->resource_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function getCurrentRevision(): ?\ILIAS\ResourceStorage\Revision\Revision
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }
        
        try {
            return $this->resource_storage->manage()->getCurrentRevision($resource_id);
        } catch (Exception $e) {
            $this->logger->warning("Failed to get current revision", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function getDownloadUrl(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }
        
        try {
            // Use proper IRSS getSrc() method - this should be the standard way
            $src_consumer = $this->resource_storage->consume()->src($resource_id);
            $download_url = $src_consumer->getSrc();
            
            if ($download_url) {
                // Fix URL if it contains plugin path (wrong working directory issue)
                if (strpos($download_url, '/Customizing/global/plugins/') !== false) {
                    // Extract the FileDelivery path and token
                    $pattern = '/.*\/(src\/FileDelivery\/deliver\.php\/.+)$/';
                    if (preg_match($pattern, $download_url, $matches)) {
						$iliasBase = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
                        $corrected_url = $iliasBase . '/' . $matches[1];
                        
                        $this->logger->debug("Corrected IRSS URL", [
                            'original_url' => $download_url,
                            'corrected_url' => $corrected_url
                        ]);
                        return $corrected_url;
                    }
                }
                
                $this->logger->debug("Using original IRSS URL", ['url' => $download_url]);
                return $download_url;
            } else {
                $this->logger->warning("IRSS getSrc returned empty URL");
            }
            
        } catch (Exception $e) {
            $this->logger->warning("IRSS getSrc failed", ['error' => $e->getMessage()]);
        }
        
        // Fallback only if IRSS fails
        $iliasBase = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
        $plugin_download_url = $iliasBase . '/Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent/download.php?resource_id=' . urlencode($this->resource_id);
        
        $this->logger->debug("Using plugin download URL as fallback", ['url' => $plugin_download_url]);
        return $plugin_download_url;
    }
    
    public function getPreviewUrl(): ?string
    {
        if (!$this->resource_id) {
            return null;
        }
        
        // For PDFs, don't return a preview URL since we can't generate thumbnails yet
        if ($this->getMimeType() === 'application/pdf') {
            $this->logger->debug("Skipping preview URL for PDF");
            return null;
        }
        
        // Try to get optimized thumbnail using ILIAS Flavours first (for images)
        $resource_id = $this->getResourceIdentification();
        if ($resource_id) {
            $flavour_url = $this->getThumbnailFlavourUrl($resource_id, $this->resource_storage);
            if ($flavour_url) {
                $this->logger->debug("Using ILIAS Flavour URL", ['url' => $flavour_url]);
                return $flavour_url;
            }
        }
        
        // Fallback to simple URL generation (for images)
		$iliasBase = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
        $delivery_url = $iliasBase . '/src/FileDelivery/deliver.php/' . $this->resource_id;
        
        $this->logger->debug("Using simple preview URL fallback", ['url' => $delivery_url]);
        return $delivery_url;
    }
    
    /**
     * Get thumbnail flavour URL for optimized chat display using proper ILIAS Flavours
     */
    private function getThumbnailFlavourUrl($identification, $resource_storage): ?string
    {
        try {
            $mime_type = $this->getMimeType();
            
            if (strpos($mime_type, 'image/') === 0) {
                // Create a custom thumbnail flavour definition for chat interface
                $thumbnail_definition = $this->createChatThumbnailFlavourDefinition();
                
                // Ensure thumbnail exists (create if needed)
                $resource_storage->flavours()->ensure($identification, $thumbnail_definition);
                
                // Get thumbnail flavour
                $thumbnail_flavour = $resource_storage->flavours()->get($identification, $thumbnail_definition);
                
                if ($thumbnail_flavour) {
                    // Use proper IRSS consumer to get flavour URLs with WebAccessChecker support
                    $flavour_urls_obj = $resource_storage->consume()->flavourUrls($thumbnail_flavour);
                    $flavour_urls = $flavour_urls_obj->getURLsAsArray(true); // signed URLs
                    
                    if (!empty($flavour_urls)) {
                        $thumbnail_url = $flavour_urls[0]; // Get first URL
                        
                        // Check if flavour URL also contains plugin path (indicates incorrect URL generation)
                        if (strpos($thumbnail_url, '/Customizing/global/plugins/') !== false) {
                            $this->logger->debug("Flavour URL contains plugin path, skipping", ['url' => $thumbnail_url]);
                            // Don't return flavour URL, fall through to fallback
                        } else {
                            $this->logger->debug("Generated thumbnail flavour URL", ['url' => $thumbnail_url]);
                            return $thumbnail_url;
                        }
                    }
                }
                
            } elseif ($mime_type === 'application/pdf') {
                // For PDFs: try to create preview of first page
                try {
                    $this->logger->debug("PDF preview disabled (complex flavours)");
                    // Temporarily disable complex PDF flavour generation to avoid breaking chat
                    // TODO: Re-enable when we can properly debug PDF flavour issues
                } catch (\Exception $e) {
                    $this->logger->warning("PDF preview flavour failed", ['error' => $e->getMessage()]);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Failed to create thumbnail flavour", ['error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * Create a custom flavour definition for chat thumbnails using proper ILIAS CropToSquare
     */
    private function createChatThumbnailFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        // Use the built-in ILIAS CropToSquare definition for proper square thumbnails
        return new \ILIAS\ResourceStorage\Flavour\Definition\CropToSquare(
            true,  // persist = true for caching
            150,   // max_size = 150px for square thumbnails
            75     // quality = 75%
        );
    }
    
    /**
     * Get optimized image flavour data directly as binary string (no URL needed)
     */
    private function getOptimizedImageFlavourData($identification): ?string
    {
        try {
            $mime_type = $this->getMimeType();
            
            if (strpos($mime_type, 'image/') === 0) {
                // Create AI-optimized flavour definition (similar to ImageOptimizer settings)
                $ai_optimized_definition = $this->createAiOptimizedImageFlavourDefinition();
                
                // Ensure flavour exists (create if needed) with caching
                $this->resource_storage->flavours()->ensure($identification, $ai_optimized_definition);
                
                // Get AI-optimized flavour
                $ai_optimized_flavour = $this->resource_storage->flavours()->get($identification, $ai_optimized_definition);
                
                if ($ai_optimized_flavour) {
                    // Get ResourceIdentification from flavour to access stream
                    $flavour_resource_id = $ai_optimized_flavour->getResourceId();
                    
                    // Direct stream access using the flavour's resource identification
                    $stream_consumer = $this->resource_storage->consume()->stream($flavour_resource_id);
                    $optimized_data = $stream_consumer->getStream()->getContents();
                    
                    if ($optimized_data !== false && !empty($optimized_data)) {
                        $this->logger->debug("Retrieved ILIAS Flavour data", ['size_bytes' => strlen($optimized_data)]);
                        return $optimized_data;
                    } else {
                        $this->logger->warning("ILIAS Flavour stream returned empty data");
                    }
                } else {
                    $this->logger->warning("Failed to get AI-optimized flavour object");
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Failed to get AI-optimized flavour data", ['error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * Create AI-optimized flavour definition matching ImageOptimizer settings
     */
    private function createAiOptimizedImageFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        // Use the built-in ILIAS FitToSquare definition for AI optimization with caching
        // FitToSquare scales down images while preserving aspect ratio within a square boundary
        return new \ILIAS\ResourceStorage\Flavour\Definition\FitToSquare(
            true,  // persist = true for caching
            1024,  // max_size = 1024px (matches ImageOptimizer::MAX_DIMENSION)
            85     // quality = 85% (matches ImageOptimizer::JPEG_QUALITY)
        );
    }
    
    /**
     * Create a custom flavour definition for PDF previews
     */
    private function createPdfPreviewFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        return new class implements \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition {
            public function getId(): string
            {
                return hash('sha256', 'aichat_pdf_preview_first_page');
            }
            
            public function getFlavourMachineId(): string
            {
                // Use ILIAS ExtractPages machine for PDF first page
                return \ILIAS\ResourceStorage\Flavour\Machine\DefaultMachines\ExtractPages::ID;
            }
            
            public function getInternalName(): string
            {
                return 'aichat_pdf_preview';
            }
            
            public function getVariantName(): ?string
            {
                return json_encode([
                    'pages' => [1], // Extract only first page
                    'format' => 'png',
                    'quality' => 85
                ]);
            }
            
            public function persist(): bool
            {
                return true;
            }
        };
    }
    
    /**
     * Create a simpler PDF preview flavour definition as fallback
     */
    private function createSimplePdfPreviewFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        return new class implements \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition {
            public function getId(): string
            {
                return hash('sha256', 'aichat_simple_pdf_preview');
            }
            
            public function getFlavourMachineId(): string
            {
                // Try a different approach - use PagesToExtract definition
                return hash('sha256', 'simple_pdf_extractor');
            }
            
            public function getInternalName(): string
            {
                return 'aichat_simple_pdf';
            }
            
            public function getVariantName(): ?string
            {
                return '1'; // Extract page 1
            }
            
            public function persist(): bool
            {
                return true;
            }
        };
    }
    
    public function isImage(): bool
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return false;
        }
        
        $info = $revision->getInformation();
        $mime_type = $info->getMimeType();
        return strpos($mime_type, 'image/') === 0;
    }
    
    public function getTitle(): string
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 'Unknown';
        }
        
        return $revision->getInformation()->getTitle();
    }
    
    public function getSize(): int
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 0;
        }
        
        return $revision->getInformation()->getSize();
    }
    
    public function getMimeType(): string
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 'application/octet-stream';
        }
        
        return $revision->getInformation()->getMimeType();
    }
    
    /**
     * Get file content as base64 encoded string
     */
    public function getContentAsBase64(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }
        
        try {
            $stream = $this->resource_storage->consume()->stream($resource_id);
            $content = $stream->getStream()->getContents();
            if ($content !== false) {
                return base64_encode($content);
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to read attachment content", ['error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * Returns data URL(s) for AI multimodal processing
     * 
     * Generates base64-encoded data URLs suitable for AI analysis:
     * - Images: Single data URL string with optimized content
     * - PDFs: Array of data URLs (one per page, up to 20 pages)
     * - Other files: null (not supported for AI analysis)
     * 
     * Uses ILIAS Flavours for caching and optimization when possible.
     * 
     * @return string|array|null Single data URL (images), array of URLs (PDFs), or null
     */
    public function getDataUrl()
    {
        if (!$this->isImage() && !$this->isPdf()) {
            return null;
        }
        
        if ($this->isPdf()) {
            return $this->getPdfPagesAsDataUrls();
        }
        
        $base64Content = $this->getOptimizedContentAsBase64();
        if (!$base64Content) {
            return null;
        }
        
        return $base64Content; // Already includes data URL prefix
    }
    
    /**
     * Get optimized content as base64 data URL - supports both images and PDFs
     * For PDFs: returns first page only (for single-image contexts)
     * For full PDF processing: use getPdfPagesAsDataUrls() instead
     */
    public function getOptimizedContentAsBase64(): ?string
    {
        if ($this->isImage()) {
            return $this->getOptimizedImageAsBase64();
        } elseif ($this->getMimeType() === 'application/pdf') {
            // For single-string context, return first page only
            $pdf_pages = $this->getOptimizedPdfAsBase64();
            if (is_array($pdf_pages) && !empty($pdf_pages)) {
                return $pdf_pages[0]; // Return first page as string
            } elseif (is_string($pdf_pages)) {
                return $pdf_pages; // Return fallback text
            }
            return null;
        }
        
        return null;
    }
    
    /**
     * Get optimized image content as base64 data URL using ILIAS Flavours for caching
     */
    private function getOptimizedImageAsBase64(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }
        
        try {
            // Try to use ILIAS Flavours for caching first - direct stream access (no URL needed)
            $optimized_flavour_data = $this->getOptimizedImageFlavourData($resource_id);
            if ($optimized_flavour_data) {
                $base64Content = base64_encode($optimized_flavour_data);
                
                // Detect MIME type from optimized data
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $optimized_mime = $finfo->buffer($optimized_flavour_data) ?: 'image/jpeg';
                
                $this->logger->debug("Using ILIAS Flavour optimized image", [
                    'size_bytes' => strlen($optimized_flavour_data),
                    'base64_chars' => strlen($base64Content)
                ]);
                
                return 'data:' . $optimized_mime . ';base64,' . $base64Content;
            }
            
            $this->logger->debug("ILIAS Flavour failed, using ImageOptimizer fallback");
            
            // Fallback to custom ImageOptimizer (without caching)
            $stream = $this->resource_storage->consume()->stream($resource_id);
            $originalData = $stream->getStream()->getContents();
            if ($originalData === false) {
                return null;
            }
            
            require_once(__DIR__ . '/../Service/ImageOptimizer.php');
            $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                $originalData, 
                $this->getMimeType()
            );
            
            $base64Content = base64_encode($optimized['data']);
            
            $this->logger->debug("Using ImageOptimizer fallback", [
                'original_bytes' => strlen($originalData),
                'optimized_bytes' => strlen($optimized['data']),
                'base64_chars' => strlen($base64Content)
            ]);
            
            return 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to optimize image", ['error' => $e->getMessage()]);
            // Final fallback to original method
            $base64Content = $this->getContentAsBase64();
            if ($base64Content) {
                return 'data:' . $this->getMimeType() . ';base64,' . $base64Content;
            }
        }
        
        return null;
    }
    
    /**
     * Get optimized PDF content as base64 data URLs for AI context
     * Uses ILIAS PagesToExtract flavour with direct stream access (cached)
     * Returns: array of data URLs for pages, or string for fallback text
     */
    private function getOptimizedPdfAsBase64()
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            $this->logger->warning("No resource ID for PDF optimization");
            return null;
        }
        
        try {
            // Create PDF-to-Image flavour definition
            $pdf_flavour_definition = $this->createAiPdfFlavourDefinition();
            
            // Ensure flavour exists (create if needed) with caching
            $this->logger->debug("Ensuring PDF flavour exists", ['resource_id' => $this->resource_id]);
            $this->resource_storage->flavours()->ensure($resource_id, $pdf_flavour_definition);
            
            // Get the flavour
            $pdf_flavour = $this->resource_storage->flavours()->get($resource_id, $pdf_flavour_definition);
            
            if (!$pdf_flavour) {
                $this->logger->warning("Failed to get PDF flavour");
                return null;
            }
            
            // Use direct StreamResolvers for efficient cached PDF page access
            $stream_resolvers = $pdf_flavour->getStreamResolvers();
            
            if (empty($stream_resolvers)) {
                $this->logger->debug("No PDF stream resolvers, using text fallback");
                // Activate text fallback instead of returning null
                $title = $this->getTitle();
                $fallback_text = "PDF Document: {$title}";
                $fallback_data_url = 'data:text/plain;base64,' . base64_encode($fallback_text);
                $this->logger->debug("Using PDF text fallback", ['text' => $fallback_text]);
                return $fallback_data_url;
            }
            
            // Process pages using direct stream access (more efficient than URL-based)
            $pdf_pages_data = $this->processPdfFlavourStreams($stream_resolvers);
            
            if (!$pdf_pages_data) {
                $this->logger->warning("No PDF page data retrieved from cached flavours");
                return null;
            }
            
            // Return processed page data for AI
            $this->logger->debug("PDF converted to page images", ['page_count' => count($pdf_pages_data)]);
            return $pdf_pages_data;
            
        } catch (\Exception $e) {
            $this->logger->warning("Failed to convert PDF to image for AI", ['error' => $e->getMessage()]);
            
            // Fallback: Return PDF filename for AI context
            $title = $this->getTitle();
            $fallback_text = "PDF Document: {$title}";
            $fallback_data_url = 'data:text/plain;base64,' . base64_encode($fallback_text);
            $this->logger->debug("PDF conversion failed, using text fallback", ['text' => $fallback_text]);
            return $fallback_data_url;
        }
    }
    
    /**
     * Process PDF pages using direct StreamResolvers (most efficient)
     */
    private function processPdfFlavourStreams(array $stream_resolvers): ?array
    {
        $pages_data = [];
        $max_pages = 20; // Limit to avoid overwhelming AI
        $pages_processed = 0;
        
        foreach ($stream_resolvers as $i => $resolver) {
            if ($pages_processed >= $max_pages) {
                $this->logger->debug("PDF limiting to maximum pages", ['max_pages' => $max_pages]);
                break;
            }
            
            try {
                $stream = $resolver->getStream();
                
                // MIME detection from first bytes (like your example)
                $head = $stream->read(16);
                $mime = (strncmp($head, "\x89PNG", 4) === 0) ? 'image/png'
                    : ((strncmp($head, "\xFF\xD8\xFF", 3) === 0) ? 'image/jpeg' : 'image/png');
                
                // Read complete data
                $page_content = $head;
                while (!$stream->eof()) {
                    $page_content .= $stream->read(8192);
                }
                $stream->close();
                
                if ($page_content) {
                    // Final optimization for AI
                    require_once(__DIR__ . '/../Service/ImageOptimizer.php');
                    $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                        $page_content, 
                        $mime
                    );
                    
                    $base64Content = base64_encode($optimized['data']);
                    $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
                    
                    $pages_data[] = $data_url;
                    $pages_processed++;
                    
                    $this->logger->debug("PDF page processed via stream resolver", ['page' => $i + 1]);
                } else {
                    $this->logger->warning("Empty page content from stream resolver", ['resolver_index' => $i]);
                }
                
            } catch (\Exception $e) {
                $this->logger->warning("Failed to process PDF page via stream", [
                    'page' => $i + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return !empty($pages_data) ? $pages_data : null;
    }
    
    /**
     * Process PDF pages from cached flavour URLs efficiently (DEPRECATED - use processPdfFlavourStreams)
     */
    private function processPdfFlavourPages(array $flavour_urls): ?array
    {
        $pages_data = [];
        $max_pages = 20; // Limit to avoid overwhelming AI
        $pages_processed = 0;
        
        foreach ($flavour_urls as $page_url) {
            if ($pages_processed >= $max_pages) {
                $this->logger->debug("PDF limiting to maximum pages (URL method)", ['max_pages' => $max_pages]);
                break;
            }
            
            // Download page data (this accesses ILIAS cached flavour data)
            $page_content = $this->downloadImageFromUrl($page_url);
            if ($page_content) {
                // Optimize the page image for AI (final optimization step)
                require_once(__DIR__ . '/../Service/ImageOptimizer.php');
                $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                    $page_content, 
                    'image/png'
                );
                
                $base64Content = base64_encode($optimized['data']);
                $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
                
                $pages_data[] = $data_url;
                $pages_processed++;
                
                $this->logger->debug("PDF page processed from cached ILIAS flavour", ['page' => $pages_processed]);
            } else {
                $this->logger->warning("Failed to download PDF page from cached flavour URL");
            }
        }
        
        return !empty($pages_data) ? $pages_data : null;
    }
    
    /**
     * Create flavour definition for PDF-to-Image conversion optimized for AI context
     */
    private function createAiPdfFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        // Use the built-in ILIAS PagesToExtract for PDF page extraction
        return new \ILIAS\ResourceStorage\Flavour\Definition\PagesToExtract(
            true,    // persist = true for caching
            1024,    // max_size = 1024px - good resolution for AI text recognition
            50,      // max_pages = 50 - process up to 50 pages (should cover most PDFs)
            false,   // fill = false - maintain aspect ratio
            85       // quality = 85% - good balance between quality and size
        );
    }
    
    /**
     * Download image content from URL with proper error handling
     */
    private function downloadImageFromUrl(string $url): ?string
    {
        try {
            // Use curl for better error handling
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development
            curl_setopt($ch, CURLOPT_USERAGENT, 'ILIAS AIChatPageComponent/1.0');
            
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($content === false || !empty($error)) {
                $this->logger->warning("cURL error downloading image", ['error' => $error]);
                return null;
            }
            
            if ($http_code !== 200) {
                $this->logger->warning("HTTP error downloading image", ['http_code' => $http_code]);
                return null;
            }
            
            if (empty($content)) {
                $this->logger->warning("Empty content downloaded", ['url' => $url]);
                return null;
            }
            
            return $content;
            
        } catch (\Exception $e) {
            $this->logger->warning("Exception downloading image", ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Get all PDF pages as data URLs for AI processing (using cached flavours)
     */
    public function getPdfPagesAsDataUrls(): ?array
    {
        if (!$this->isPdf()) {
            return null;
        }
        
        // Use the new cached flavour system
        $pdf_data_urls = $this->getOptimizedPdfAsBase64();
        
        if ($pdf_data_urls && is_array($pdf_data_urls)) {
            $this->logger->debug("PDF processed using cached flavours", [
                'filename' => $this->getTitle(),
                'page_count' => count($pdf_data_urls)
            ]);
            return $pdf_data_urls;
        } elseif ($pdf_data_urls && is_string($pdf_data_urls)) {
            // Handle fallback case: convert single string to array
            $this->logger->debug("PDF using fallback text representation", ['filename' => $this->getTitle()]);
            return [$pdf_data_urls]; // Wrap string in array
        }
        
        return null;
    }
    
    /**
     * Check if attachment is a PDF
     */
    public function isPdf(): bool
    {
        return $this->getMimeType() === 'application/pdf';
    }
    
    // Getters and Setters
    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    
    public function getMessageId(): ?int { return $this->message_id; }
    public function setMessageId(?int $message_id): void { $this->message_id = $message_id; }
    
    public function getChatId(): ?string { return $this->chat_id; }
    public function setChatId(?string $chat_id): void { $this->chat_id = $chat_id; }
    
    public function getUserId(): ?int { return $this->user_id; }
    public function setUserId(?int $user_id): void { $this->user_id = $user_id; }
    
    public function getResourceId(): ?string { return $this->resource_id; }
    public function setResourceId(?string $resource_id): void { $this->resource_id = $resource_id; }
    
    public function getTimestamp(): ?string { return $this->timestamp; }
    public function setTimestamp(?string $timestamp): void { $this->timestamp = $timestamp; }
    
    /**
     * Converts attachment to array format for API responses
     * 
     * Generates a comprehensive array representation suitable for JSON API responses
     * and frontend consumption. Includes URLs, metadata, and file type information.
     * 
     * Array structure:
     * - id, title, filename, size, mime_type, file_type
     * - download_url: Original file download
     * - preview_url: Optimized thumbnail/preview (null for PDFs)
     * - src: Best available URL for display (null for PDFs = show icon)
     * - data_url: Base64 fallback for images
     * 
     * @return array Attachment data for API consumption
     */
    public function toArray(): array
    {
        $download_url = $this->getDownloadUrl();
        $preview_url = $this->getPreviewUrl(); // Now uses ILIAS Flavours with fallback
        
        // Determine file type for frontend handling
        $file_type = $this->getFileType();
        
        // For images, provide data_url as reliable fallback
        $data_url = null;
        if ($file_type === 'image') {
            $data_url = $this->getDataUrl();
        }
        
        // Priority order depends on file type
        $src_url = null;
        if ($file_type === 'image') {
            // For images: preview_url (ILIAS Flavour) > data_url (Base64) > download_url (original)
            $src_url = $preview_url ?: ($data_url ?: $download_url);
        } elseif ($file_type === 'pdf') {
            // For PDFs: no src_url, will show icon and use download_url for opening
            $src_url = null;
        } else {
            // For other files: use download_url
            $src_url = $download_url;
        }
        
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'filename' => $this->getTitle(), // Alias for compatibility
            'size' => $this->getSize(),
            'mime_type' => $this->getMimeType(),
            'file_type' => $file_type, // 'image', 'pdf', 'document', 'other'
            'is_image' => $this->isImage(),
            'download_url' => $download_url, // Full-size original file
            'preview_url' => $preview_url, // ILIAS Flavour optimized thumbnail/preview (null for PDFs)
            'thumbnail_url' => $preview_url, // Alias for chat thumbnails (null for PDFs)
            'src' => $src_url, // Best available URL for display (null for PDFs = show icon)
            'data_url' => $data_url // Base64 data URL for ultimate fallback
        ];
    }
    
    /**
     * Get simplified file type for frontend handling
     */
    private function getFileType(): string
    {
        $mime_type = $this->getMimeType();
        
        if (strpos($mime_type, 'image/') === 0) {
            return 'image';
        } elseif ($mime_type === 'application/pdf') {
            return 'pdf';
        } elseif (strpos($mime_type, 'text/') === 0) {
            return 'text';
        }
        
        return 'other';
    }
}