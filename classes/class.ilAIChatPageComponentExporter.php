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
     * Get valid schema versions (required by ilXmlExporter)
     */
    public function getValidSchemaVersions(string $a_entity): array
    {
        return [
            self::SCHEMA_VERSION
        ];
    }

    /**
     * Export page component data
     */
    public function getXmlRepresentation(string $a_entity, string $a_schema_version, string $a_id): string
    {
        global $DIC;

        try {
            // Load chat configuration
            $chatConfig = new ChatConfig($a_id);
            if (!$chatConfig->exists()) {
                $DIC->logger()->comp('pcaic')->warning('Chat config not found for export', ['chat_id' => $a_id]);
                return $this->createEmptyXml();
            }

            // Create XML structure
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $root = $xml->createElement('ai_chat_page_component');
            $root->setAttribute('schema_version', self::SCHEMA_VERSION);
            $root->setAttribute('export_date', date('Y-m-d H:i:s'));
            $xml->appendChild($root);

            // Add chat configuration
            $this->addChatConfig($xml, $root, $chatConfig);
            
            // Add background files
            $this->addBackgroundFiles($xml, $root, $chatConfig);

            $DIC->logger()->comp('pcaic')->info('Export completed successfully', [
                'chat_id' => $a_id,
                'schema_version' => self::SCHEMA_VERSION
            ]);

            return $xml->saveXML();

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
        $configElement->appendChild($xml->createElement('system_prompt', $xml->createCDATASection($chatConfig->getSystemPrompt())));
        $configElement->appendChild($xml->createElement('ai_service', htmlspecialchars($chatConfig->getAiService())));
        $configElement->appendChild($xml->createElement('max_memory', (string)$chatConfig->getMaxMemory()));
        $configElement->appendChild($xml->createElement('char_limit', (string)$chatConfig->getCharLimit()));
        $configElement->appendChild($xml->createElement('persistent', $chatConfig->isPersistent() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('include_page_context', $chatConfig->isIncludePageContext() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('enable_chat_uploads', $chatConfig->isEnableChatUploads() ? '1' : '0'));
        
        if ($chatConfig->getDisclaimer()) {
            $configElement->appendChild($xml->createElement('disclaimer', $xml->createCDATASection($chatConfig->getDisclaimer())));
        }

        // Background files references
        $backgroundFiles = $chatConfig->getBackgroundFiles();
        if (!empty($backgroundFiles)) {
            $filesElement = $xml->createElement('background_files');
            $configElement->appendChild($filesElement);

            foreach ($backgroundFiles as $file) {
                $fileElement = $xml->createElement('file');
                $fileElement->setAttribute('resource_id', $file['resource_id'] ?? '');
                $fileElement->setAttribute('filename', $file['filename'] ?? '');
                $fileElement->setAttribute('mime_type', $file['mime_type'] ?? '');
                $fileElement->setAttribute('description', $file['description'] ?? '');
                $filesElement->appendChild($fileElement);
            }
        }
    }

    /**
     * Export background files
     */
    private function addBackgroundFiles(DOMDocument $xml, DOMElement $root, ChatConfig $chatConfig): void
    {
        global $DIC;
        
        $backgroundFiles = $chatConfig->getBackgroundFiles();
        if (empty($backgroundFiles)) {
            return;
        }

        $filesElement = $xml->createElement('background_files');
        $root->appendChild($filesElement);

        $irss = $DIC->resourceStorage();

        foreach ($backgroundFiles as $file) {
            try {
                $resourceId = $file['resource_id'] ?? '';
                if (empty($resourceId)) {
                    continue;
                }

                $identification = $irss->manage()->find($resourceId);
                if (!$identification) {
                    $DIC->logger()->comp('pcaic')->warning('Resource not found for export', ['resource_id' => $resourceId]);
                    continue;
                }

                // Export file
                $filename = $file['filename'] ?? $identification->getCurrentRevision()->getTitle();
                $exportPath = $this->exportFile($identification, $filename);

                if ($exportPath) {
                    $fileElement = $xml->createElement('file');
                    $fileElement->setAttribute('resource_id', $resourceId);
                    $fileElement->setAttribute('filename', $filename);
                    $fileElement->setAttribute('mime_type', $file['mime_type'] ?? '');
                    $fileElement->setAttribute('description', $file['description'] ?? '');
                    $fileElement->setAttribute('original_path', $exportPath);
                    $filesElement->appendChild($fileElement);

                    $DIC->logger()->comp('pcaic')->debug('Background file exported', [
                        'filename' => $filename,
                        'export_path' => $exportPath
                    ]);
                }

            } catch (Exception $e) {
                $DIC->logger()->comp('pcaic')->error('Failed to export background file', [
                    'resource_id' => $resourceId ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
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

            // Create export subdirectory
            $exportDir = $this->getExportDirectory() . '/AIChatPageComponent';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            $exportPath = $exportDir . '/' . $filename;
            file_put_contents($exportPath, $content);

            return 'AIChatPageComponent/' . $filename;

        } catch (Exception $e) {
            $DIC->logger()->comp('pcaic')->error('File export failed', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create empty XML structure for failed exports
     */
    private function createEmptyXml(): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElement('ai_chat_page_component');
        $root->setAttribute('schema_version', self::SCHEMA_VERSION);
        $root->setAttribute('export_date', date('Y-m-d H:i:s'));
        $root->setAttribute('status', 'empty');
        $xml->appendChild($root);

        return $xml->saveXML();
    }
}