<?php declare(strict_types=1);

use ILIAS\Plugin\pcaic\Model\ChatConfig;

/**
 * AIChatPageComponent Exporter
 * 
 * Handles exporting of AI chat configurations and background files
 * during ILIAS content export processes.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0
 * @ingroup ServicesPlugin
 */
class ilAIChatPageComponentExporter extends ilPageComponentPluginExporter
{
    /** @var string Current schema version for export compatibility */
    const SCHEMA_VERSION = '9.0';
    
    private ilAIChatPageComponentPlugin $plugin;

    public function init(): void
    {
        $this->plugin = ilAIChatPageComponentPlugin::getInstance();
    }

    /**
     * Get supported schema versions
     */
    public function getSupportedSchemaVersions(): array
    {
        return [
            self::SCHEMA_VERSION
        ];
    }

    /**
     * Get valid schema versions (required by ilXmlExporter)
     */
    public function getValidSchemaVersions(string $a_entity): array
    {
        return [
            self::SCHEMA_VERSION => [
                "namespace" => "http://www.ilias.de/Services/COPage/PageComponent/AIChatPageComponent/pcaic/1_0",
                "xsd_file" => "",
                "uses_dataset" => false,
                "min" => "9.0.0",
                "max" => ""
            ]
        ];
    }

    /**
     * Export page component data
     */
    public function getXmlRepresentation(string $a_entity, string $a_schema_version, string $a_id): string
    {
        // Clean any potential output buffer to prevent XML declaration errors
        if (ob_get_level()) {
            ob_clean();
        }
        
        global $DIC;

        try {
            // Get chat_id from PageComponent properties (not the PageContent ID)
            $properties = self::getPCProperties($a_id);
            if (!$properties || !isset($properties['chat_id'])) {
                $DIC->logger()->comp('pcaic')->warning('No chat_id found in PageComponent properties', ['pc_id' => $a_id, 'properties' => $properties]);
                return $this->createEmptyXml();
            }
            
            $chatId = $properties['chat_id'];
            
            // Load chat configuration using the actual chat_id
            $chatConfig = new ChatConfig($chatId);
            if (!$chatConfig->exists()) {
                $DIC->logger()->comp('pcaic')->warning('Chat config not found for export', ['chat_id' => $chatId, 'pc_id' => $a_id]);
                return $this->createEmptyXml();
            }

            // Create XML structure
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $root = $xml->createElement('ai_chat_page_component');
            $root->setAttribute('schema_version', self::SCHEMA_VERSION);
            $root->setAttribute('export_date', date('Y-m-d H:i:s'));
            $xml->appendChild($root);

            // Add chat configuration (includes background files)
            $this->addChatConfig($xml, $root, $chatConfig);

            $DIC->logger()->comp('pcaic')->info('Export completed successfully', [
                'chat_id' => $a_id,
                'schema_version' => self::SCHEMA_VERSION
            ]);

            // Return XML content without declaration (ILIAS adds its own)
            return $xml->saveXML($root);

        } catch (Exception $e) {
            $DIC->logger()->comp('pcaic')->error('Export failed', [
                'chat_id' => $a_id,
                'error' => $e->getMessage()
            ]);
            return $this->createEmptyXml();
        }
    }

    /**
     * Add chat configuration to XML
     */
    private function addChatConfig(DOMDocument $xml, DOMElement $root, ChatConfig $chatConfig): void
    {
        $configElement = $xml->createElement('chat_config');
        $root->appendChild($configElement);

        // Basic configuration
        $configElement->appendChild($xml->createElement('title', htmlspecialchars($chatConfig->getTitle())));
        
        // System prompt with CDATA
        $systemPromptElement = $xml->createElement('system_prompt');
        $systemPromptElement->appendChild($xml->createCDATASection($chatConfig->getSystemPrompt()));
        $configElement->appendChild($systemPromptElement);
        
        $configElement->appendChild($xml->createElement('ai_service', htmlspecialchars($chatConfig->getAiService())));
        $configElement->appendChild($xml->createElement('max_memory', (string)$chatConfig->getMaxMemory()));
        $configElement->appendChild($xml->createElement('char_limit', (string)$chatConfig->getCharLimit()));
        $configElement->appendChild($xml->createElement('persistent', $chatConfig->isPersistent() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('include_page_context', $chatConfig->isIncludePageContext() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('enable_chat_uploads', $chatConfig->isEnableChatUploads() ? '1' : '0'));
        
        if ($chatConfig->getDisclaimer()) {
            $disclaimerElement = $xml->createElement('disclaimer');
            $disclaimerElement->appendChild($xml->createCDATASection($chatConfig->getDisclaimer()));
            $configElement->appendChild($disclaimerElement);
        }

        // Background files references
        $backgroundFiles = $chatConfig->getBackgroundFiles();
        if (!empty($backgroundFiles)) {
            $filesElement = $xml->createElement('background_files');
            $configElement->appendChild($filesElement);

            global $DIC;
            $irss = $DIC->resourceStorage();
            
            foreach ($backgroundFiles as $file) {
                // Handle both formats: resource_id string or full file array
                if (is_string($file)) {
                    $resourceId = $file;
                    $fileMetadata = $this->loadFileMetadata($resourceId);
                    if (!$fileMetadata) {
                        continue;
                    }
                } else {
                    $resourceId = $file['resource_id'] ?? '';
                    $fileMetadata = [
                        'resource_id' => $resourceId,
                        'filename' => $file['filename'] ?? '',
                        'mime_type' => $file['mime_type'] ?? '',
                        'description' => $file['description'] ?? ''
                    ];
                }
                
                if (empty($resourceId)) {
                    continue;
                }
                
                // Export physical file and get export path
                $exportPath = null;
                try {
                    $identification = $irss->manage()->find($resourceId);
                    if ($identification) {
                        $filename = $fileMetadata['filename'] ?: $irss->manage()->getCurrentRevision($identification)->getTitle();
                        $exportPath = $this->exportFile($identification, $filename);
                        
                        $DIC->logger()->comp('pcaic')->debug('Background file export attempt', [
                            'resource_id' => $resourceId,
                            'filename' => $filename,
                            'export_path' => $exportPath
                        ]);
                        
                        if (!$exportPath) {
                            $DIC->logger()->comp('pcaic')->warning('Export path is null for background file', [
                                'resource_id' => $resourceId,
                                'filename' => $filename
                            ]);
                        }
                    } else {
                        $DIC->logger()->comp('pcaic')->warning('Resource identification not found', [
                            'resource_id' => $resourceId
                        ]);
                    }
                } catch (Exception $e) {
                    $DIC->logger()->comp('pcaic')->error('Exception during background file export', [
                        'resource_id' => $resourceId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
                
                // Add to XML with all metadata including export path
                $fileElement = $xml->createElement('file');
                $fileElement->setAttribute('resource_id', $fileMetadata['resource_id']);
                $fileElement->setAttribute('filename', $fileMetadata['filename']);
                $fileElement->setAttribute('mime_type', $fileMetadata['mime_type']);
                $fileElement->setAttribute('description', $fileMetadata['description']);
                if ($exportPath) {
                    $fileElement->setAttribute('original_path', $exportPath);
                }
                $filesElement->appendChild($fileElement);
            }
        }
    }


    /**
     * Export individual file to export directory
     */
    private function exportFile($identification, string $filename): ?string
    {
        global $DIC;

        try {
            $irss = $DIC->resourceStorage();
            $stream = $irss->consume()->stream($identification);
            $content = $stream->getStream()->getContents();

            // Use ILIAS export directory directly (no subdirectory needed)
            $exportDir = $this->getAbsoluteExportDirectory();
            $exportPath = $exportDir . '/' . $filename;
            
            // Ensure export directory exists and is writable
            if (!is_dir($exportDir)) {
                if (!mkdir($exportDir, 0755, true)) {
                    throw new Exception('Failed to create export directory: ' . $exportDir);
                }
            }
            
            if (!is_writable($exportDir)) {
                throw new Exception('Export directory is not writable: ' . $exportDir);
            }
            
            $result = file_put_contents($exportPath, $content);
            if ($result === false) {
                throw new Exception('Failed to write file to: ' . $exportPath);
            }

            return $filename;

        } catch (Exception $e) {
            $DIC->logger()->comp('pcaic')->error('File export failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Create empty XML structure for failed exports
     */
    private function createEmptyXml(): string
    {
        // Clean any potential output buffer to prevent XML declaration errors
        if (ob_get_level()) {
            ob_clean();
        }
        
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElement('ai_chat_page_component');
        $root->setAttribute('schema_version', self::SCHEMA_VERSION);
        $root->setAttribute('export_date', date('Y-m-d H:i:s'));
        $root->setAttribute('status', 'empty');
        $xml->appendChild($root);

        // Return XML content without declaration (ILIAS adds its own)
        return $xml->saveXML($root);
    }

    /**
     * Load file metadata from IRSS by resource ID
     */
    private function loadFileMetadata(string $resourceId): ?array
    {
        global $DIC;
        
        try {
            $irss = $DIC->resourceStorage();
            $identification = $irss->manage()->find($resourceId);
            
            if (!$identification) {
                return null;
            }
            
            $revision = $irss->manage()->getCurrentRevision($identification);
            $info = $revision->getInformation();
            
            return [
                'resource_id' => $resourceId,
                'filename' => $revision->getTitle(),
                'mime_type' => $info->getMimeType(),
                'description' => ''  // No description stored in IRSS
            ];
            
        } catch (Exception $e) {
            $DIC->logger()->comp('pcaic')->warning('Failed to load file metadata', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}