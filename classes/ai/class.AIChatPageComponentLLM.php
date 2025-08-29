<?php declare(strict_types=1);

namespace ai;

use ILIAS\Plugin\pcaic\Model\Chat;
use platform\AIChatPageComponentException;

/**
 * Abstract Class AIChatPageComponentLLM
 * Based on LLM from AIChat plugin, adapted for PageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
abstract class AIChatPageComponentLLM
{
    public abstract function sendChat($chat);
    
    protected ?int $max_memory_messages = null;
    protected ?string $prompt = null;
    protected \ilLogger $logger;
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->comp('pcaic');
    }

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

    /**
    * Send messages array directly
    * @param array $messages Array of messages with 'role' and 'content'
    * @param array|null $contextResources Optional context resources
    * @return string AI response
    */
    abstract public function sendMessagesArray(array $messages, ?array $contextResources = null): string;

    /**
     * Convert chat to messages array for API
     * @throws AIChatPageComponentException
     */
    protected function chatToMessagesArray($chat): array
    {
        $this->logger->debug("Chat to messages array conversion started");
        
        if (!($chat instanceof Chat)) {
            throw new AIChatPageComponentException('Invalid chat object type');
        }
        
        $messages = [];

        foreach ($chat->getMessages() as $message) {
            $messageContent = $this->formatMessageContent($message);
            
            $messages[] = [
                "role" => $message->getRole(),
                "content" => $messageContent
            ];
        }

        $max_memory_messages = $this->getMaxMemoryMessages();

        if (isset($max_memory_messages)) {
            $max_memory_messages = intval($max_memory_messages);
        } else {
            $max_memory_messages = 0;
        }

        if ($max_memory_messages > 0) {
            $messages = array_slice($messages, -$max_memory_messages);
        }

        // Add background files as system messages before user messages
        $this->logger->debug("Getting background files messages");
        $background_files_messages = $this->getBackgroundFilesMessages($chat);
        $this->logger->debug("Background files messages retrieved", ['count' => count($background_files_messages)]);
        
        if (!empty($background_files_messages)) {
            // Insert background files messages after system prompt but before user messages
            $messages = array_merge($background_files_messages, $messages);
            $this->logger->debug("Background files messages merged with chat messages");
        }

        $prompt = $this->getPrompt();

        if (isset($prompt) && !empty(trim($prompt))) {
            array_unshift($messages, [
                "role" => "system",
                "content" => $prompt
            ]);
        }

        $this->logger->debug("Final messages array prepared", ['count' => count($messages)]);
        
        // Log the structure of messages being sent to AI
        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'unknown';
            $content_type = is_array($message['content']) ? 'array(' . count($message['content']) . ' items)' : 'string(' . strlen($message['content']) . ' chars)';
            $this->logger->debug("Message structure", [
                'index' => $index,
                'role' => $role,
                'content_type' => $content_type
            ]);
            
            // Log details of multimodal content
            if (is_array($message['content'])) {
                foreach ($message['content'] as $content_index => $content_item) {
                    $item_type = $content_item['type'] ?? 'unknown';
                    if ($item_type === 'image_url') {
                        $url_preview = substr($content_item['image_url']['url'] ?? '', 0, 50) . '...';
                        $this->logger->debug("Multimodal content: image", [
                            'content_index' => $content_index,
                            'url_preview' => $url_preview
                        ]);
                    } elseif ($item_type === 'text') {
                        $text_preview = substr($content_item['text'] ?? '', 0, 100) . '...';
                        $this->logger->debug("Multimodal content: text", [
                            'content_index' => $content_index,
                            'text_preview' => $text_preview
                        ]);
                    }
                }
            }
        }
        
        return $messages;
    }
    
    /**
     * Format message content for API, handling both text and image attachments
     * @param mixed $message AIChatPageComponentMessage instance
     * @return string|array String for text-only messages, array for messages with images
     */
    protected function formatMessageContent($message)
    {
        $attachments = $message->getAttachments();
        $hasImages = false;
        
        // Check if there are any image attachments or PDFs
        foreach ($attachments as $attachment) {
            if ($attachment->isImage() || $attachment->isPdf()) {
                $hasImages = true;
                break;
            }
        }
        
        // If no images, return simple text content
        if (!$hasImages) {
            return $message->getMessage();
        }
        
        // Build content array for OpenAI format with images
        $content = [];
        
        // Add text content if not empty
        $textContent = trim($message->getMessage());
        if (!empty($textContent)) {
            $content[] = [
                "type" => "text",
                "text" => $textContent
            ];
        }
        
        // Add image attachments with configurable limits
        $totalImageSize = 0;
        $maxTotalImageSizeMBConfig = \platform\AIChatPageComponentConfig::get('max_total_image_data_mb');
        $maxTotalImageSizeMB = $maxTotalImageSizeMBConfig ? (int)$maxTotalImageSizeMBConfig : 15;
        $maxTotalImageSize = $maxTotalImageSizeMB * 1024 * 1024;
        $imageCount = 0;
        $maxImagesConfig = \platform\AIChatPageComponentConfig::get('max_images_per_message');
        $maxImages = $maxImagesConfig ? (int)$maxImagesConfig : 5;
        
        // Log configuration source
        $this->logger->debug("AI processing limits configuration", [
            'total_image_mb' => [
                'source' => $maxTotalImageSizeMBConfig !== null ? 'central_config' : 'fallback',
                'config_value' => $maxTotalImageSizeMBConfig,
                'effective_mb' => $maxTotalImageSizeMB
            ],
            'max_images' => [
                'source' => $maxImagesConfig !== null ? 'central_config' : 'fallback', 
                'config_value' => $maxImagesConfig,
                'effective_count' => $maxImages
            ]
        ]);
        
        foreach ($attachments as $attachment) {
            if ($attachment->isImage() || $attachment->isPdf()) {
                if ($imageCount >= $maxImages) {
                    $file_type = $attachment->isImage() ? 'image' : 'PDF';
                    $this->logger->debug("Skipping attachment - maximum images reached", [
                        'file_type' => $file_type,
                        'max_images' => $maxImages
                    ]);
                    break;
                }
                
                $dataUrl = $attachment->getDataUrl();
                
                $this->logger->debug("Processing attachment for AI", [
                    'type' => $attachment->isPdf() ? 'PDF' : 'Image',
                    'data_url_type' => is_array($dataUrl) ? 'array(' . count($dataUrl) . ')' : (is_string($dataUrl) ? 'string' : 'null')
                ]);
                
                // Handle PDFs (which return array of page URLs) vs Images (which return single URL)
                if ($attachment->isPdf() && is_array($dataUrl)) {
                    // Process all PDF pages
                    foreach ($dataUrl as $page_data_url) {
                        if ($imageCount >= $maxImages) {
                            $this->logger->debug("Skipping PDF page - maximum images reached", ['max_images' => $maxImages]);
                            break 2; // Break out of both loops
                        }
                        
                        $imageSize = strlen($page_data_url);
                        
                        // Check if adding this page would exceed total size limit
                        if ($totalImageSize + $imageSize > $maxTotalImageSize) {
                            $this->logger->debug("Skipping PDF page - size limit exceeded", [
                                'current_size' => $totalImageSize,
                                'image_size' => $imageSize,
                                'limit' => $maxTotalImageSize
                            ]);
                            break 2; // Break out of both loops
                        }
                        
                        $content[] = [
                            "type" => "image_url",
                            "image_url" => [
                                "url" => $page_data_url
                            ]
                        ];
                        
                        $totalImageSize += $imageSize;
                        $imageCount++;
                    }
                    
                    $this->logger->debug("Added PDF to content", [
                        'page_count' => count($dataUrl),
                        'mime_type' => $attachment->getMimeType(),
                        'total_size_chars' => $totalImageSize
                    ]);
                    
                } elseif ($dataUrl && !is_array($dataUrl)) {
                    // Handle single image
                    $imageSize = strlen($dataUrl);
                    
                    // Check if adding this image would exceed total size limit
                    if ($totalImageSize + $imageSize > $maxTotalImageSize) {
                        $file_type = $attachment->isImage() ? 'image' : 'PDF';
                        $this->logger->debug("Skipping attachment - size limit exceeded", [
                            'file_type' => $file_type,
                            'current_size' => $totalImageSize,
                            'image_size' => $imageSize,
                            'limit' => $maxTotalImageSize
                        ]);
                        break;
                    }
                    
                    $content[] = [
                        "type" => "image_url",
                        "image_url" => [
                            "url" => $dataUrl
                        ]
                    ];
                    
                    $totalImageSize += $imageSize;
                    $imageCount++;
                    
                    $file_type = $attachment->isImage() ? 'image' : 'PDF (converted)';
                    $this->logger->debug("Added attachment to content", [
                        'file_type' => $file_type,
                        'mime_type' => $attachment->getMimeType(),
                        'size_chars' => $imageSize,
                        'total_chars' => $totalImageSize
                    ]);
                } else {
                    $file_type = $attachment->isImage() ? 'image' : 'PDF';
                    $this->logger->warning("Failed to get data URL for attachment", [
                        'file_type' => $file_type,
                        'attachment_id' => $attachment->getId()
                    ]);
                }
            }
        }
        
        if ($imageCount > 0) {
            $this->logger->debug("Images processed summary", [
                'total_images' => $imageCount,
                'total_size_mb' => number_format($totalImageSize / 1024 / 1024, 2)
            ]);
        }
        
        return $content;
    }
    
    /**
     * Get background files as messages for multimodal AI input
     * @param Chat $chat
     * @return array
     */
    protected function getBackgroundFilesMessages($chat): array
    {
        $messages = [];
        
        try {
            $this->logger->debug("Getting background files messages", ['chat_id' => $chat->getId()]);
            
            // Get background files from PageComponent Settings (NEW ARCHITECTURE)
            $properties = $chat->getProperties();
            $this->logger->debug("Chat properties available", ['keys' => array_keys($properties)]);
            
            // Load background files from PageComponent settings instead of chat properties
            $background_files = $this->getBackgroundFilesFromPageComponent($properties);
            
            $this->logger->debug("Background files raw data", ['data' => $background_files]);
            
            if (is_string($background_files)) {
                $background_files = json_decode($background_files, true);
                $this->logger->debug("Background files after JSON decode", ['data' => $background_files]);
            }
            
            if (!is_array($background_files) || empty($background_files)) {
                $this->logger->debug("No background files found or invalid format", [
                    'type' => gettype($background_files),
                    'empty' => empty($background_files)
                ]);
                return [];
            }
            
            $this->logger->debug("Processing background files", ['count' => count($background_files)]);
            
            global $DIC;
            $irss = $DIC->resourceStorage();
            
            $text_files = [];
            $image_files = [];
            
            foreach ($background_files as $file_id) {
                try {
                    $identification = $irss->manage()->find($file_id);
                    if ($identification === null) {
                        continue;
                    }
                    
                    $revision = $irss->manage()->getCurrentRevision($identification);
                    if ($revision === null) {
                        continue;
                    }
                    
                    $mime_type = $revision->getInformation()->getMimeType();
                    $suffix = strtolower($revision->getInformation()->getSuffix());
                    
                    // Check if it's an image
                    if (in_array($suffix, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        try {
                            // Use IRSS to get the file content directly
                            $consumer = $irss->consume();
                            $stream = $consumer->stream($identification)->getStream();
                            $imageContent = $stream->getContents();
                            
                            // Optimize image using same method as chat attachments for consistency
                            require_once(__DIR__ . '/../../src/Service/ImageOptimizer.php');
                            $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                                $imageContent, 
                                $mime_type
                            );
                            
                            $base64Image = base64_encode($optimized['data']);
                            
                            $this->logger->debug("Background image optimized", [
                                'original_bytes' => strlen($imageContent),
                                'optimized_bytes' => strlen($optimized['data'])
                            ]);
                            
                            $image_files[] = [
                                "type" => "image_url",
                                "image_url" => [
                                    "url" => "data:" . $optimized['mime_type'] . ";base64," . $base64Image
                                ]
                            ];
                        } catch (Exception $stream_e) {
                            $this->logger->warning("Error reading image file", [
                                'filename' => $revision->getTitle(),
                                'error' => $stream_e->getMessage()
                            ]);
                            continue;
                        }
                    } else {
                        // Handle text files, PDFs, etc.
                        try {
                            $consumer = $irss->consume();
                            $stream = $consumer->stream($identification)->getStream();
                            $content = $stream->getContents();
                            
                            switch ($suffix) {
                                case 'txt':
                                case 'md':
                                    $text_files[] = "File: " . $revision->getTitle() . "\nContent: " . $content;
                                    break;
                                case 'pdf':
                                    // Convert PDF pages to images for AI analysis
                                    // Support both IRSS Collections caching and direct conversion
                                    $this->logger->debug("Starting PDF processing", ['filename' => $revision->getTitle()]);
                                    
                                    $pdf_data_urls = $this->getPdfPagesForAI($identification, $revision, $irss, $file_id);
                                    
                                    if ($pdf_data_urls && is_array($pdf_data_urls)) {
                                        $pages_processed = 0;
                                        $max_pages_per_pdf = 20; // Limit to avoid overwhelming AI
                                        
                                        foreach ($pdf_data_urls as $page_data_url) {
                                            if ($pages_processed >= $max_pages_per_pdf) {
                                                $this->logger->debug("PDF page limit reached", [
                                                    'filename' => $revision->getTitle(),
                                                    'max_pages' => $max_pages_per_pdf
                                                ]);
                                                break;
                                            }
                                            
                                            if ($page_data_url) {
                                                $image_files[] = [
                                                    "type" => "image_url",
                                                    "image_url" => [
                                                        "url" => $page_data_url
                                                    ]
                                                ];
                                                
                                                $pages_processed++;
                                            }
                                        }
                                        
                                        $this->logger->debug("PDF processed for AI", [
                                            'filename' => $revision->getTitle(),
                                            'pages_processed' => $pages_processed
                                        ]);
                                        
                                        if ($pages_processed == 0) {
                                            // Fallback if no pages could be processed
                                            $text_files[] = "PDF Document: " . $revision->getTitle();
                                        }
                                    } else {
                                        // Fallback: just mention the PDF name
                                        $text_files[] = "PDF Document: " . $revision->getTitle();
                                        $this->logger->warning("PDF processing failed, using filename fallback", [
                                            'filename' => $revision->getTitle()
                                        ]);
                                    }
                                    break;
                                default:
                                    $text_files[] = "File: " . $revision->getTitle() . " (Type: $mime_type)";
                            }
                        } catch (Exception $stream_e) {
                            $this->logger->warning("Error reading text file", [
                                'filename' => $revision->getTitle(),
                                'error' => $stream_e->getMessage()
                            ]);
                            // Add basic file info even if content reading failed
                            $text_files[] = "File: " . $revision->getTitle() . " (Type: $mime_type, Content: Unable to read)";
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->warning("Error processing background file", [
                        'file_id' => $file_id,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
            
            // Add text files as system message if any
            if (!empty($text_files)) {
                $messages[] = [
                    "role" => "system", 
                    "content" => "Background Files: The following files have been uploaded as background context for this conversation:\n\n" . implode("\n---\n", $text_files)
                ];
            }
            
            // Add images as user message if any (so AI can analyze them)
            if (!empty($image_files)) {
                $content = [
                    [
                        "type" => "text",
                        "text" => "Background Images: The following images have been uploaded as background context. Please analyze them and be ready to answer questions about their content:"
                    ]
                ];
                
                $content = array_merge($content, $image_files);
                
                $messages[] = [
                    "role" => "user",
                    "content" => $content
                ];
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Error getting background files messages", ['error' => $e->getMessage()]);
        }
        
        return $messages;
    }
    
    /**
     * Get background files from PageComponent Settings (NEW ARCHITECTURE)
     */
    private function getBackgroundFilesFromPageComponent(array $chat_props): array
    {
        try {
            $page_id = $chat_props['page_id'] ?? 0;
            $parent_id = $chat_props['parent_id'] ?? 0;
            $parent_type = $chat_props['parent_type'] ?? '';
            
            $this->logger->debug("Looking for PageComponent", [
                'page_id' => $page_id,
                'parent_id' => $parent_id,
                'parent_type' => $parent_type
            ]);
            
            if (!$page_id || !$parent_id || !$parent_type) {
                $this->logger->warning("Missing page context properties for background files");
                return [];
            }
            
            // Map to COPage type
            $copage_type_map = [
                'crs' => 'cont',
                'grp' => 'cont',
                'lm' => 'lm',
                'wpg' => 'wpg',
                'wiki' => 'wpg',
                'glo' => 'glo',
                'blp' => 'blp',
                'frm' => 'frm'
            ];
            
            $copage_type = $copage_type_map[$parent_type] ?? null;
            if (!$copage_type) {
                $this->logger->warning("Unsupported parent_type", ['parent_type' => $parent_type]);
                return [];
            }
            
            // Get the page
            global $DIC;
            $page_manager = $DIC->copage()->internal()->domain()->page();
            $page = $page_manager->get($copage_type, $parent_id);
            
            if (!$page) {
                $this->logger->warning("Page not found", [
                    'copage_type' => $copage_type,
                    'parent_id' => $parent_id
                ]);
                return [];
            }
            
            // Get page content XML to find our component
            $page_xml = $page->getXMLContent();
            $this->logger->debug("Page XML status", ['xml_available' => strlen($page_xml) > 0]);
            
            // Parse XML to find AIChatPageComponent instances and their properties
            if (strlen($page_xml) > 0) {
                $background_files = $this->parseBackgroundFilesFromPageXML($page_xml);
                $this->logger->debug("Background files found in page XML", ['count' => count($background_files)]);
                return $background_files;
            }
            
            $this->logger->debug("No background files found");
            return [];
            
        } catch (\Exception $e) {
            $this->logger->warning("Error getting background files from PageComponent", ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Parse background files from page XML content
     */
    private function parseBackgroundFilesFromPageXML(string $page_xml): array
    {
        try {
            // Load XML and find AIChatPageComponent instances
            $dom = new DOMDocument();
            $dom->loadXML($page_xml);
            
            $xpath = new DOMXPath($dom);
            
            // Look for our plugin components - this may need adjustment based on actual XML structure
            $nodes = $xpath->query('//PageComponent[@Type="pcaic"]');
            
            $all_background_files = [];
            
            foreach ($nodes as $node) {
                // Get the component properties
                $props_node = $xpath->query('.//Properties', $node)->item(0);
                if ($props_node) {
                    $props_text = $props_node->nodeValue;
                    
                    // Parse properties (assuming JSON format)
                    $props = json_decode($props_text, true);
                    if ($props && isset($props['background_files'])) {
                        $background_files = $props['background_files'];
                        
                        if (is_string($background_files)) {
                            $background_files = json_decode($background_files, true);
                        }
                        
                        if (is_array($background_files)) {
                            $all_background_files = array_merge($all_background_files, $background_files);
                        }
                    }
                }
            }
            
            // Remove duplicates
            $all_background_files = array_unique($all_background_files);
            
            $this->logger->debug("Background files extracted from XML", ['count' => count($all_background_files)]);
            return $all_background_files;
            
        } catch (\Exception $e) {
            $this->logger->warning("Error parsing XML", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Convert PDF to image for AI analysis using ILIAS ResourceStorage Flavours
     */
    private function convertPdfToImageForAI($identification, $revision, $irss): ?string
    {
        try {
            $this->logger->debug("Converting PDF to image for AI", ['filename' => $revision->getTitle()]);
            
            // Create PDF-to-Image flavour definition
            $pdf_flavour_definition = new \ILIAS\ResourceStorage\Flavour\Definition\PagesToExtract(
                true,    // persist = true for caching
                1024,    // max_size = 1024px - good resolution for AI text recognition
                50,      // max_pages = 50 - process up to 50 pages (should cover most PDFs)
                false,   // fill = false - maintain aspect ratio
                85       // quality = 85% - good balance between quality and size
            );
            $this->logger->debug("PagesToExtract flavour definition created");
            
            // Ensure flavour exists (create if needed) with caching
            $this->logger->debug("Ensuring PDF flavour exists", ['filename' => $revision->getTitle()]);
            $irss->flavours()->ensure($identification, $pdf_flavour_definition);
            $this->logger->debug("PDF flavour ensure completed");
            
            // Get the flavour
            $this->logger->debug("Getting PDF flavour");
            $pdf_flavour = $irss->flavours()->get($identification, $pdf_flavour_definition);
            $this->logger->debug("PDF flavour get result", ['success' => $pdf_flavour ? true : false]);
            
            if (!$pdf_flavour) {
                $this->logger->warning("Failed to get PDF flavour", ['filename' => $revision->getTitle()]);
                return null;
            }
            
            // Get flavour URLs (these point to the converted PNG images)
            $this->logger->debug("Getting flavour URLs");
            $flavour_urls_obj = $irss->consume()->flavourUrls($pdf_flavour);
            $flavour_urls = $flavour_urls_obj->getURLsAsArray(true); // signed URLs
            $this->logger->debug("Flavour URLs generated", ['count' => count($flavour_urls)]);
            
            if (empty($flavour_urls)) {
                $this->logger->warning("No flavour URLs generated for PDF", ['filename' => $revision->getTitle()]);
                return null;
            }
            
            // Return array of all page URLs for caller to handle multiple pages
            // For background files, we'll return the URLs so caller can process all pages
            $this->logger->debug("Returning flavour URLs", ['count' => count($flavour_urls)]);
            return $flavour_urls;
            
        } catch (\Exception $e) {
            $this->logger->warning("Failed to convert PDF to image for AI", [
                'filename' => $revision->getTitle(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get PDF pages for AI processing with configurable caching strategy
     * Supports both IRSS Collections caching and direct Ghostscript conversion
     * 
     * @param mixed $identification IRSS resource identification
     * @param mixed $revision IRSS resource revision  
     * @param mixed $irss IRSS service
     * @param string $file_id Resource ID for caching
     * @return array|null Array of data URLs for PDF pages
     */
    private function getPdfPagesForAI($identification, $revision, $irss, string $file_id): ?array
    {
        $this->logger->debug("Processing background PDF with ILIAS Flavours", ['filename' => $revision->getTitle()]);
        
        try {
            // Use ILIAS Flavours for PDF processing (same approach as chat uploads)
            $pdf_flavour_definition = $this->createAiPdfFlavourDefinition();
            
            // Ensure flavour exists (create if needed) with caching
            $this->logger->debug("Ensuring PDF flavour exists for background file", ['file_id' => $file_id]);
            $irss->flavours()->ensure($identification, $pdf_flavour_definition);
            
            // Get the flavour
            $pdf_flavour = $irss->flavours()->get($identification, $pdf_flavour_definition);
            
            if (!$pdf_flavour) {
                $this->logger->warning("Failed to get PDF flavour for background file");
                throw new \Exception('Failed to get PDF flavour');
            }
            
            // Use direct StreamResolvers for efficient cached PDF page access
            $stream_resolvers = $pdf_flavour->getStreamResolvers();
            
            if (empty($stream_resolvers)) {
                $this->logger->warning("No PDF stream resolvers available for background file");
                throw new \Exception('No PDF stream resolvers available');
            }
            
            // Process pages using direct stream access (more efficient than URL-based)
            $pdf_pages_data = $this->processPdfFlavourPages($stream_resolvers);
            
            if (!$pdf_pages_data) {
                $this->logger->warning("No PDF page data retrieved from cached flavours for background file");
                throw new \Exception('No PDF page data retrieved');
            }
            
            $this->logger->debug("Background PDF converted to cached page images", ['page_count' => count($pdf_pages_data)]);
            return $pdf_pages_data;
            
        } catch (\Exception $e) {
            $this->logger->warning("ILIAS Flavour processing failed for background PDF, using direct conversion", [
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct Ghostscript conversion (without caching)
            $pdf_page_images = $this->convertPdfToImagesDirectly($identification, $revision, $irss);
            
            if (!$pdf_page_images || !is_array($pdf_page_images)) {
                return null;
            }
            
            // Convert binary image data to data URLs
            $data_urls = [];
            foreach ($pdf_page_images as $page_image_data) {
                if ($page_image_data) {
                    // Optimize each page
                    require_once(__DIR__ . '/../../src/Service/ImageOptimizer.php');
                    $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                        $page_image_data, 
                        'image/png'
                    );
                    
                    $base64Content = base64_encode($optimized['data']);
                    $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
                    $data_urls[] = $data_url;
                }
            }
            
            $this->logger->debug("Fallback direct conversion completed", ['data_url_count' => count($data_urls)]);
            return !empty($data_urls) ? $data_urls : null;
        }
    }
    
    /**
     * Convert PDF to images directly using Ghostscript
     * Returns array of image data (binary) for each page
     */
    private function convertPdfToImagesDirectly($identification, $revision, $irss): ?array
    {
        try {
            $this->logger->debug("Converting PDF to images directly", ['filename' => $revision->getTitle()]);
            
            // Get PDF content from IRSS
            $stream = $irss->consume()->stream($identification)->getStream();
            $pdf_content = $stream->getContents();
            
            if (empty($pdf_content)) {
                $this->logger->warning("PDF content is empty");
                return null;
            }
            
            $this->logger->debug("PDF content loaded", ['size_bytes' => strlen($pdf_content)]);
            
            // Create temporary file for PDF
            $temp_dir = sys_get_temp_dir();
            $temp_pdf = $temp_dir . '/aichat_pdf_' . uniqid() . '.pdf';
            $temp_png_pattern = $temp_dir . '/aichat_pdf_' . uniqid() . '_page_%03d.png';
            
            file_put_contents($temp_pdf, $pdf_content);
            $this->logger->debug("Created temporary PDF", ['temp_file' => $temp_pdf]);
            
            // Use Ghostscript to convert PDF to PNG images
            $gs_command = "gs -dNOPAUSE -dBATCH -sDEVICE=png16m -dUseCropBox -r150 -sOutputFile=" . escapeshellarg($temp_png_pattern) . " " . escapeshellarg($temp_pdf) . " 2>&1";
            
            $this->logger->debug("Executing Ghostscript command", ['command' => $gs_command]);
            
            $output = shell_exec($gs_command);
            $this->logger->debug("Ghostscript execution completed", ['output' => $output ?: 'no output']);
            
            // Find generated PNG files
            $base_pattern = str_replace('%03d', '*', $temp_png_pattern);
            $png_files = glob($base_pattern);
            
            $this->logger->debug("Found PNG files", ['count' => count($png_files)]);
            
            if (empty($png_files)) {
                // Cleanup
                @unlink($temp_pdf);
                $this->logger->warning("No PNG files generated");
                return null;
            }
            
            // Read image data
            $page_images = [];
            $max_pages = 20; // Limit to avoid overwhelming AI
            
            // Sort files to ensure correct page order
            sort($png_files);
            
            foreach ($png_files as $index => $png_file) {
                if ($index >= $max_pages) {
                    $this->logger->debug("Limiting to maximum pages", ['max_pages' => $max_pages]);
                    break;
                }
                
                if (file_exists($png_file)) {
                    $image_data = file_get_contents($png_file);
                    if ($image_data !== false) {
                        $page_images[] = $image_data;
                        $this->logger->debug("Loaded PDF page", [
                            'page' => $index + 1,
                            'size_bytes' => strlen($image_data)
                        ]);
                    }
                    @unlink($png_file); // Clean up temp file
                }
            }
            
            // Cleanup temp PDF
            @unlink($temp_pdf);
            
            $this->logger->debug("Successfully converted PDF to images", ['image_count' => count($page_images)]);
            
            return !empty($page_images) ? $page_images : null;
            
        } catch (\Exception $e) {
            $this->logger->warning("Exception in convertPdfToImagesDirectly", ['error' => $e->getMessage()]);
            return null;
        }
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
     * Process PDF pages using direct StreamResolvers (most efficient)
     */
    private function processPdfFlavourPages(array $stream_resolvers): ?array
    {
        $pages_data = [];
        $max_pages = 20; // Limit to avoid overwhelming AI
        $pages_processed = 0;
        
        foreach ($stream_resolvers as $i => $resolver) {
            if ($pages_processed >= $max_pages) {
                $this->logger->debug("Background PDF limiting to maximum pages", ['max_pages' => $max_pages]);
                break;
            }
            
            try {
                $stream = $resolver->getStream();
                
                // MIME detection from first bytes (like example code)
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
                    require_once(__DIR__ . '/../../src/Service/ImageOptimizer.php');
                    $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                        $page_content, 
                        $mime
                    );
                    
                    $base64Content = base64_encode($optimized['data']);
                    $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
                    
                    $pages_data[] = $data_url;
                    $pages_processed++;
                    
                    $this->logger->debug("Background PDF page processed via stream resolver", ['page' => $i + 1]);
                } else {
                    $this->logger->warning("Empty page content from stream resolver", ['resolver_index' => $i]);
                }
                
            } catch (\Exception $e) {
                $this->logger->warning("Failed to process background PDF page via stream", [
                    'page' => $i + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return !empty($pages_data) ? $pages_data : null;
    }

    /**
     * Process PDF pages from cached flavour URLs efficiently (DEPRECATED - use stream resolvers)
     */
    private function processPdfFlavourPagesLegacy(array $flavour_urls): ?array
    {
        $pages_data = [];
        $max_pages = 20; // Limit to avoid overwhelming AI
        $pages_processed = 0;
        
        foreach ($flavour_urls as $page_url) {
            if ($pages_processed >= $max_pages) {
                $this->logger->debug("PDF limiting to maximum pages for chat", ['max_pages' => $max_pages]);
                break;
            }
            
            // Download page data (this accesses ILIAS cached flavour data)
            $page_content = $this->downloadImageFromUrl($page_url);
            if ($page_content) {
                // Optimize the page image for AI (final optimization step)
                require_once(__DIR__ . '/../../src/Service/ImageOptimizer.php');
                $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                    $page_content, 
                    'image/png'
                );
                
                $base64Content = base64_encode($optimized['data']);
                $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64Content;
                
                $pages_data[] = $data_url;
                $pages_processed++;
                
                $this->logger->debug("Background PDF page processed from cached ILIAS flavour", ['page' => $pages_processed]);
            } else {
                $this->logger->warning("Failed to download PDF page from cached flavour URL");
            }
        }
        
        return !empty($pages_data) ? $pages_data : null;
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
}