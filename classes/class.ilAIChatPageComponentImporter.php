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
    const SCHEMA_VERSION = '1.0.0';
    
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

            // Import background files if they exist
            $this->importBackgroundFiles($xml, $a_mapping);
            
            // Update file references in chat configuration
            $this->updateFileReferences($xml, $a_mapping);
            
            $DIC->logger()->comp('pcaic')->info('Import completed successfully', [
                'entity' => $a_entity,
                'id' => $a_id,
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
        if (!isset($xml->background_files) || !isset($xml->background_files->file)) {
            return;
        }

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($xml->background_files->file as $fileXml) {
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
                $identifier = $irss->manage()->stream($stream, null, $filename);
                
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
     * Update file references in chat configuration after import
     */
    private function updateFileReferences(\SimpleXMLElement $xml, ilImportMapping $a_mapping): void
    {
        if (!isset($xml->chat_config)) {
            return;
        }

        $chatConfig = $xml->chat_config;
        $backgroundFiles = [];

        if (isset($chatConfig->background_files->file)) {
            foreach ($chatConfig->background_files->file as $fileXml) {
                $oldResourceId = (string)$fileXml['resource_id'];
                $newResourceId = $a_mapping->getMapping('Services/ResourceStorage', 'resource_id', $oldResourceId);

                if ($newResourceId) {
                    $backgroundFiles[] = [
                        'resource_id' => $newResourceId,
                        'filename' => (string)$fileXml['filename'],
                        'mime_type' => (string)$fileXml['mime_type'],
                        'description' => (string)$fileXml['description']
                    ];
                }
            }
        }

        // Note: The actual chat configuration update will be handled by the 
        // PageComponent GUI when the page is edited/saved after import
        global $DIC;
        $DIC->logger()->comp('pcaic')->debug('File references updated for import', [
            'background_files_count' => count($backgroundFiles)
        ]);
    }
}