<?php declare(strict_types=1);

/**
 * AIChatPageComponent Importer
 * 
 * Handles importing of AI chat configurations and background files
 * during ILIAS content import processes.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0
 * @ingroup ServicesPlugin
 */
class ilAIChatPageComponentImporter extends ilPageComponentPluginImporter
{
    /** @var string Current schema version for import compatibility */
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
     * Import page component data
     */
    public function importXmlRepresentation(string $a_entity, string $a_id, string $a_xml, ilImportMapping $a_mapping): void
    {
        global $DIC;

        try {
            $xml = simplexml_load_string($a_xml);
            if (!$xml) {
                throw new Exception('Invalid XML structure');
            }

            // Get mapped PageComponent ID
            $mappedId = self::getPCMapping($a_id, $a_mapping);
            
            // Import background files from chat_config (no separate section anymore)
            $this->importBackgroundFiles($xml, $a_mapping);
            
            // Create new chat configuration from import data
            $newChatId = $this->createChatFromImport($xml, $a_mapping);
            
            // Update PageComponent properties with new chat_id
            $this->updatePageComponentProperties($mappedId, $xml, $newChatId);
            
            $DIC->logger()->comp('pcaic')->info('Import completed successfully', [
                'entity' => $a_entity,
                'original_id' => $a_id,
                'mapped_id' => $mappedId,
                'new_chat_id' => $newChatId,
                'schema' => (string)$xml['schema_version'] ?? 'unknown'
            ]);

        } catch (Exception $e) {
            $DIC->logger()->comp('pcaic')->error('Import failed', [
                'entity' => $a_entity,
                'id' => $a_id,
                'error' => $e->getMessage()
            ]);
            throw new ilImportException('AIChatPageComponent import failed: ' . $e->getMessage());
        }
    }

    /**
     * Import background files from export package
     */
    private function importBackgroundFiles(\SimpleXMLElement $xml, ilImportMapping $a_mapping): void
    {
        if (!isset($xml->chat_config->background_files) || !isset($xml->chat_config->background_files->file)) {
            return;
        }

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($xml->chat_config->background_files->file as $fileXml) {
            $originalPath = (string)$fileXml['original_path'];
            $filename = (string)$fileXml['filename'];
            $mimeType = (string)$fileXml['mime_type'];

            // Look for file in import directory
            $importFile = $this->findImportFile($filename);
            if (!$importFile) {
                $DIC->logger()->comp('pcaic')->warning('Background file not found in import', [
                    'filename' => $filename,
                    'original_path' => $originalPath
                ]);
                continue;
            }

            try {
                // Upload to ILIAS ResourceStorage
                $stream = \ILIAS\Filesystem\Stream\Streams::ofResource(fopen($importFile, 'r'));
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                $identifier = $irss->manage()->stream($stream, $stakeholder, $filename);
                
                // Map old identifier to new one
                if (isset($fileXml['resource_id'])) {
                    $oldResourceId = (string)$fileXml['resource_id'];
                    $a_mapping->addMapping('Services/ResourceStorage', 'resource_id', $oldResourceId, $identifier->serialize());
                }

                $DIC->logger()->comp('pcaic')->info('Background file imported successfully', [
                    'filename' => $filename,
                    'new_resource_id' => $identifier->serialize()
                ]);

            } catch (Exception $e) {
                $DIC->logger()->comp('pcaic')->error('Failed to import background file', [
                    'filename' => $filename,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Find file in import directories
     */
    private function findImportFile(string $filename): ?string
    {
        $importDir = $this->getImportDirectory();
        
        // Search in various potential locations
        $searchPaths = [
            $importDir . '/AIChatPageComponent/' . $filename,
            $importDir . '/ai_chat_page_component/' . $filename,
            $importDir . '/background_files/' . $filename,
            $importDir . '/' . $filename
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Recursive search in subdirectories
        return $this->recursiveFileSearch($importDir, $filename);
    }

    /**
     * Recursively search for file in directory
     */
    private function recursiveFileSearch(string $dir, string $filename): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Create new chat configuration from imported XML data
     */
    private function createChatFromImport(\SimpleXMLElement $xml, ilImportMapping $a_mapping): string
    {
        global $DIC;
        
        // Generate new unique chat ID
        $newChatId = 'chat_' . uniqid() . '.' . time();
        
        if (!isset($xml->chat_config)) {
            $DIC->logger()->comp('pcaic')->warning('No chat_config found in import XML');
            return $newChatId;
        }
        
        $chatConfig = $xml->chat_config;
        
        // Create new ChatConfig with imported data
        $newChat = new \ILIAS\Plugin\pcaic\Model\ChatConfig($newChatId);
        
        // Set configuration from import
        $newChat->setTitle((string)$chatConfig->title ?? '');
        $newChat->setSystemPrompt((string)$chatConfig->system_prompt ?? '');
        $newChat->setAiService((string)$chatConfig->ai_service ?? 'ramses');
        $newChat->setMaxMemory((int)$chatConfig->max_memory ?? 10);
        $newChat->setCharLimit((int)$chatConfig->char_limit ?? 2000);
        $newChat->setPersistent((string)$chatConfig->persistent === '1');
        $newChat->setIncludePageContext((string)$chatConfig->include_page_context === '1');
        $newChat->setEnableChatUploads((string)$chatConfig->enable_chat_uploads === '1');
        
        if (isset($chatConfig->disclaimer)) {
            $newChat->setDisclaimer((string)$chatConfig->disclaimer);
        }
        
        // Import background files
        $backgroundFiles = $this->getImportedBackgroundFiles($xml, $a_mapping);
        if (!empty($backgroundFiles)) {
            $newChat->setBackgroundFiles($backgroundFiles);
        }
        
        // Save to database
        $newChat->save();
        
        $DIC->logger()->comp('pcaic')->info('Created new chat from import', [
            'new_chat_id' => $newChatId,
            'title' => $newChat->getTitle(),
            'background_files_count' => count($backgroundFiles)
        ]);
        
        return $newChatId;
    }
    
    /**
     * Get background files with updated resource IDs from import
     */
    private function getImportedBackgroundFiles(\SimpleXMLElement $xml, ilImportMapping $a_mapping): array
    {
        $backgroundFiles = [];
        
        if (!isset($xml->chat_config->background_files->file)) {
            return $backgroundFiles;
        }
        
        foreach ($xml->chat_config->background_files->file as $fileXml) {
            $oldResourceId = (string)$fileXml['resource_id'];
            $newResourceId = $a_mapping->getMapping('Services/ResourceStorage', 'resource_id', $oldResourceId);
            
            if ($newResourceId) {
                // Store only resource_id as string to match original format
                $backgroundFiles[] = $newResourceId;
            }
        }
        
        return $backgroundFiles;
    }
    
    /**
     * Update PageComponent properties with new chat_id and other data
     */
    private function updatePageComponentProperties(string $mappedId, \SimpleXMLElement $xml, string $newChatId): void
    {
        global $DIC;
        
        // Get current properties
        $currentProperties = self::getPCProperties($mappedId) ?? [];
        
        // Update with new chat_id and title from import
        $updatedProperties = $currentProperties;
        $updatedProperties['chat_id'] = $newChatId;
        
        if (isset($xml->chat_config->title)) {
            $updatedProperties['chat_title'] = (string)$xml->chat_config->title;
        }
        
        // Set updated properties back
        self::setPCProperties($mappedId, $updatedProperties);
        
        $DIC->logger()->comp('pcaic')->info('Updated PageComponent properties', [
            'mapped_id' => $mappedId,
            'new_chat_id' => $newChatId,
            'properties' => $updatedProperties
        ]);
    }
}