<?php declare(strict_types=1);

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\Attachment;

/**
 * AIChatPageComponent Importer
 *
 * Imports AI chat PageComponent configurations and associated files during
 * ILIAS content import operations. Handles deserialization of chat settings,
 * background files, and recreation of all configuration options from XML.
 *
 * Import process:
 * 1. Parse XML and upload background files to IRSS
 * 2. Create new ChatConfig with unique chat_id
 * 3. Create Attachment records for background files
 * 4. Update PageComponent properties with new references
 *
 * Supports backward compatibility with older export formats by providing
 * sensible defaults for missing configuration values.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.1
 */
class ilAIChatPageComponentImporter extends ilPageComponentPluginImporter
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
     * Import PageComponent data from XML representation.
     *
     * @throws ilImportException On import failure
     */
    public function importXmlRepresentation(string $a_entity, string $a_id, string $a_xml, ilImportMapping $a_mapping): void
    {
        global $DIC;

        try {
            $xml = simplexml_load_string($a_xml);
            if (!$xml) {
                throw new Exception('Invalid XML structure');
            }

            $mappedId = self::getPCMapping($a_id, $a_mapping);

            $this->importBackgroundFiles($xml, $a_mapping);
            $newChatId = $this->createChatFromImport($xml, $a_mapping);
            $this->updatePageComponentProperties($mappedId, $xml, $newChatId);

            $DIC->logger()->pcaic()->info('Import completed', [
                'entity' => $a_entity,
                'original_id' => $a_id,
                'mapped_id' => $mappedId,
                'new_chat_id' => $newChatId,
                'schema' => (string)($xml['schema_version'] ?? 'unknown')
            ]);

        } catch (Exception $e) {
            $DIC->logger()->pcaic()->error('Import failed', [
                'entity' => $a_entity,
                'id' => $a_id,
                'error' => $e->getMessage()
            ]);
            throw new ilImportException('AIChatPageComponent import failed: ' . $e->getMessage());
        }
    }

    /**
     * Upload background files from export package to IRSS and create resource mappings.
     */
    private function importBackgroundFiles(\SimpleXMLElement $xml, ilImportMapping $a_mapping): void
    {
        if (!isset($xml->chat_config->background_files->file)) {
            return;
        }

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($xml->chat_config->background_files->file as $fileXml) {
            $originalPath = (string)$fileXml['original_path'];
            $filename = (string)$fileXml['filename'];

            $importFile = $this->findImportFile($filename);
            if (!$importFile) {
                $DIC->logger()->pcaic()->warning('Import: Background file not found', [
                    'filename' => $filename,
                    'original_path' => $originalPath
                ]);
                continue;
            }

            try {
                $stream = \ILIAS\Filesystem\Stream\Streams::ofResource(fopen($importFile, 'r'));
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                $identifier = $irss->manage()->stream($stream, $stakeholder, $filename);

                if (isset($fileXml['resource_id'])) {
                    $oldResourceId = (string)$fileXml['resource_id'];
                    $a_mapping->addMapping('Services/ResourceStorage', 'resource_id', $oldResourceId, $identifier->serialize());
                }

                $DIC->logger()->pcaic()->info('Import: Background file uploaded', [
                    'filename' => $filename,
                    'new_resource_id' => $identifier->serialize()
                ]);

            } catch (Exception $e) {
                $DIC->logger()->pcaic()->error('Import: Failed to upload background file', [
                    'filename' => $filename,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Locate file in import directory structure.
     */
    private function findImportFile(string $filename): ?string
    {
        $importDir = $this->getImportDirectory();

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

        return $this->recursiveFileSearch($importDir, $filename);
    }

    /**
     * Recursively search directory tree for file.
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
     * Create new ChatConfig from imported XML data.
     *
     * Applies sensible defaults for missing values to ensure backward
     * compatibility with older export formats.
     */
    private function createChatFromImport(\SimpleXMLElement $xml, ilImportMapping $a_mapping): string
    {
        global $DIC;

        $newChatId = 'chat_' . uniqid() . '.' . time();

        if (!isset($xml->chat_config)) {
            $DIC->logger()->pcaic()->warning('Import: No chat_config in XML');
            return $newChatId;
        }

        $cfg = $xml->chat_config;
        $newChat = new ChatConfig($newChatId);

        // Text fields with empty string defaults
        $newChat->setTitle(isset($cfg->title) ? (string)$cfg->title : '');
        $newChat->setSystemPrompt(isset($cfg->system_prompt) ? (string)$cfg->system_prompt : '');
        $newChat->setAiService(isset($cfg->ai_service) ? (string)$cfg->ai_service : 'ramses');

        // Numeric fields with sensible defaults
        $newChat->setMaxMemory(isset($cfg->max_memory) ? (int)(string)$cfg->max_memory : 10);
        $newChat->setCharLimit(isset($cfg->char_limit) ? (int)(string)$cfg->char_limit : 2000);

        // Boolean flags with appropriate defaults
        $newChat->setPersistent(isset($cfg->persistent) ? (string)$cfg->persistent === '1' : true);
        $newChat->setIncludePageContext(isset($cfg->include_page_context) ? (string)$cfg->include_page_context === '1' : true);
        $newChat->setEnableChatUploads(isset($cfg->enable_chat_uploads) ? (string)$cfg->enable_chat_uploads === '1' : false);
        $newChat->setEnableStreaming(isset($cfg->enable_streaming) ? (string)$cfg->enable_streaming === '1' : true);
        $newChat->setEnableRag(isset($cfg->enable_rag) ? (string)$cfg->enable_rag === '1' : false);

        // Optional disclaimer
        if (isset($cfg->disclaimer)) {
            $newChat->setDisclaimer((string)$cfg->disclaimer);
        }

        $newChat->save();

        $backgroundFilesCount = $this->createBackgroundFileAttachments($newChatId, $xml, $a_mapping);

        $DIC->logger()->pcaic()->info('Import: Chat configuration created', [
            'new_chat_id' => $newChatId,
            'title' => $newChat->getTitle(),
            'background_files_count' => $backgroundFilesCount
        ]);

        return $newChatId;
    }

    /**
     * Create Attachment records for imported background files.
     *
     * Background files are stored in pcaic_attachments with background_file=1
     * and linked to the chat via chat_id.
     *
     * @return int Number of attachments created
     */
    private function createBackgroundFileAttachments(string $chatId, \SimpleXMLElement $xml, ilImportMapping $a_mapping): int
    {
        global $DIC;

        if (!isset($xml->chat_config->background_files->file)) {
            return 0;
        }

        $count = 0;
        $userId = $DIC->user()->getId();

        foreach ($xml->chat_config->background_files->file as $fileXml) {
            $oldResourceId = (string)$fileXml['resource_id'];
            $newResourceId = $a_mapping->getMapping('Services/ResourceStorage', 'resource_id', $oldResourceId);

            if (!$newResourceId) {
                $DIC->logger()->pcaic()->warning('Import: No resource mapping for background file', [
                    'old_resource_id' => $oldResourceId
                ]);
                continue;
            }

            try {
                $attachment = new Attachment();
                $attachment->setChatId($chatId);
                $attachment->setUserId($userId);
                $attachment->setResourceId($newResourceId);
                $attachment->setBackgroundFile(true);
                $attachment->setTimestamp(date('Y-m-d H:i:s'));
                $attachment->save();

                $count++;

                $DIC->logger()->pcaic()->debug('Import: Background file attachment created', [
                    'chat_id' => $chatId,
                    'resource_id' => $newResourceId,
                    'attachment_id' => $attachment->getId()
                ]);

            } catch (\Exception $e) {
                $DIC->logger()->pcaic()->error('Import: Failed to create background file attachment', [
                    'chat_id' => $chatId,
                    'resource_id' => $newResourceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    /**
     * Update PageComponent XML properties with new chat_id reference.
     */
    private function updatePageComponentProperties(string $mappedId, \SimpleXMLElement $xml, string $newChatId): void
    {
        global $DIC;

        $currentProperties = self::getPCProperties($mappedId) ?? [];

        $updatedProperties = $currentProperties;
        $updatedProperties['chat_id'] = $newChatId;

        if (isset($xml->chat_config->title)) {
            $updatedProperties['chat_title'] = (string)$xml->chat_config->title;
        }

        self::setPCProperties($mappedId, $updatedProperties);

        $DIC->logger()->pcaic()->info('Import: PageComponent properties updated', [
            'mapped_id' => $mappedId,
            'new_chat_id' => $newChatId
        ]);
    }
}
