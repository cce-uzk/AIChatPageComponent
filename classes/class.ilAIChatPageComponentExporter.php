<?php declare(strict_types=1);

use ILIAS\Plugin\pcaic\Model\ChatConfig;

/**
 * AIChatPageComponent Exporter
 *
 * Exports AI chat PageComponent configurations and associated files during
 * ILIAS content export operations. Handles serialization of chat settings,
 * background files, and all configuration options to XML format.
 *
 * Exported data includes:
 * - Chat configuration (title, system prompt, AI service settings)
 * - Feature flags (persistence, streaming, RAG, uploads)
 * - Background files with IRSS resource references
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.1
 */
class ilAIChatPageComponentExporter extends ilPageComponentPluginExporter
{
    private const SCHEMA_VERSION = '9.0';

    private ilAIChatPageComponentPlugin $plugin;

    public function init(): void
    {
        $this->plugin = ilAIChatPageComponentPlugin::getInstance();
    }

    /**
     * @return string[]
     */
    public function getSupportedSchemaVersions(): array
    {
        return [self::SCHEMA_VERSION];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getValidSchemaVersions(string $a_entity): array
    {
        return [
            self::SCHEMA_VERSION => [
                'namespace' => 'http://www.ilias.de/Services/COPage/PageComponent/AIChatPageComponent/pcaic/1_0',
                'xsd_file' => '',
                'uses_dataset' => false,
                'min' => '9.0.0',
                'max' => ''
            ]
        ];
    }

    /**
     * Generate XML representation of PageComponent data for export.
     */
    public function getXmlRepresentation(string $a_entity, string $a_schema_version, string $a_id): string
    {
        if (ob_get_level()) {
            ob_clean();
        }

        global $DIC;

        try {
            $properties = self::getPCProperties($a_id);
            if (!$properties || !isset($properties['chat_id'])) {
                $DIC->logger()->pcaic()->warning('Export: No chat_id in PageComponent properties', [
                    'pc_id' => $a_id,
                    'properties' => $properties
                ]);
                return $this->createEmptyXml();
            }

            $chatId = $properties['chat_id'];
            $chatConfig = new ChatConfig($chatId);

            if (!$chatConfig->exists()) {
                $DIC->logger()->pcaic()->warning('Export: Chat configuration not found', [
                    'chat_id' => $chatId,
                    'pc_id' => $a_id
                ]);
                return $this->createEmptyXml();
            }

            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $root = $xml->createElement('ai_chat_page_component');
            $root->setAttribute('schema_version', self::SCHEMA_VERSION);
            $root->setAttribute('export_date', date('Y-m-d H:i:s'));
            $xml->appendChild($root);

            $this->addChatConfig($xml, $root, $chatConfig);

            $DIC->logger()->pcaic()->info('Export completed', [
                'chat_id' => $chatId,
                'schema_version' => self::SCHEMA_VERSION
            ]);

            return $xml->saveXML($root);

        } catch (Exception $e) {
            $DIC->logger()->pcaic()->error('Export failed', [
                'pc_id' => $a_id,
                'error' => $e->getMessage()
            ]);
            return $this->createEmptyXml();
        }
    }

    /**
     * Serialize chat configuration to XML elements.
     */
    private function addChatConfig(DOMDocument $xml, DOMElement $root, ChatConfig $chatConfig): void
    {
        $configElement = $xml->createElement('chat_config');
        $root->appendChild($configElement);

        // Basic settings
        $configElement->appendChild($xml->createElement('title', htmlspecialchars($chatConfig->getTitle())));

        $systemPromptElement = $xml->createElement('system_prompt');
        $systemPromptElement->appendChild($xml->createCDATASection($chatConfig->getSystemPrompt()));
        $configElement->appendChild($systemPromptElement);

        $configElement->appendChild($xml->createElement('ai_service', htmlspecialchars($chatConfig->getAiService())));
        $configElement->appendChild($xml->createElement('max_memory', (string)$chatConfig->getMaxMemory()));
        $configElement->appendChild($xml->createElement('char_limit', (string)$chatConfig->getCharLimit()));

        // Feature flags
        $configElement->appendChild($xml->createElement('persistent', $chatConfig->isPersistent() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('include_page_context', $chatConfig->isIncludePageContext() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('enable_chat_uploads', $chatConfig->isEnableChatUploads() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('enable_streaming', $chatConfig->isEnableStreaming() ? '1' : '0'));
        $configElement->appendChild($xml->createElement('enable_rag', $chatConfig->isEnableRag() ? '1' : '0'));

        // Optional disclaimer
        if ($chatConfig->getDisclaimer()) {
            $disclaimerElement = $xml->createElement('disclaimer');
            $disclaimerElement->appendChild($xml->createCDATASection($chatConfig->getDisclaimer()));
            $configElement->appendChild($disclaimerElement);
        }

        // Background files from pcaic_attachments table
        $this->addBackgroundFiles($xml, $configElement, $chatConfig);
    }

    /**
     * Export background files and add references to XML.
     */
    private function addBackgroundFiles(DOMDocument $xml, DOMElement $configElement, ChatConfig $chatConfig): void
    {
        $backgroundFiles = $chatConfig->getBackgroundFiles();
        if (empty($backgroundFiles)) {
            return;
        }

        $filesElement = $xml->createElement('background_files');
        $configElement->appendChild($filesElement);

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($backgroundFiles as $resourceId) {
            if (!is_string($resourceId) || empty($resourceId)) {
                continue;
            }

            $fileMetadata = $this->loadFileMetadata($resourceId);
            if (!$fileMetadata) {
                continue;
            }

            $exportPath = null;
            try {
                $identification = $irss->manage()->find($resourceId);
                if ($identification) {
                    $filename = $fileMetadata['filename'] ?: $irss->manage()->getCurrentRevision($identification)->getTitle();
                    $exportPath = $this->exportFile($identification, $filename);
                }
            } catch (Exception $e) {
                $DIC->logger()->pcaic()->warning('Export: Failed to export background file', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage()
                ]);
            }

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

    /**
     * Copy file from IRSS to export directory.
     *
     * @param mixed $identification IRSS resource identification
     * @return string|null Filename on success, null on failure
     */
    private function exportFile($identification, string $filename): ?string
    {
        global $DIC;

        try {
            $irss = $DIC->resourceStorage();
            $stream = $irss->consume()->stream($identification);
            $content = $stream->getStream()->getContents();

            $exportDir = $this->getAbsoluteExportDirectory();
            $exportPath = $exportDir . '/' . $filename;

            if (!is_dir($exportDir) && !mkdir($exportDir, 0755, true)) {
                throw new Exception('Cannot create export directory: ' . $exportDir);
            }

            if (!is_writable($exportDir)) {
                throw new Exception('Export directory not writable: ' . $exportDir);
            }

            if (file_put_contents($exportPath, $content) === false) {
                throw new Exception('Failed to write file: ' . $exportPath);
            }

            return $filename;

        } catch (Exception $e) {
            $DIC->logger()->pcaic()->error('Export: File write failed', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create minimal XML for failed or empty exports.
     */
    private function createEmptyXml(): string
    {
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

        return $xml->saveXML($root);
    }

    /**
     * Retrieve file metadata from IRSS.
     *
     * @return array{resource_id: string, filename: string, mime_type: string, description: string}|null
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
                'description' => ''
            ];

        } catch (Exception $e) {
            $DIC->logger()->pcaic()->warning('Export: Failed to load file metadata', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
