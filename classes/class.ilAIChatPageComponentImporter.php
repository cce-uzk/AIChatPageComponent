<?php

/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\Attachment;

/**
 * Import of chats: creates a new chat with its own chat ID and background files
 *
 * Missing values of older export files are filled with defaults.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
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
     * @throws ilImportException
     */
    public function importXmlRepresentation(string $a_entity, string $a_id, string $a_xml, ilImportMapping $a_mapping): void
    {
        global $DIC;

        try {
            $xml = simplexml_load_string($a_xml);
            if (!$xml) {
                throw new Exception('Invalid XML structure');
            }

            $mapped_id = self::getPCMapping($a_id, $a_mapping);

            $this->importBackgroundFiles($xml, $a_mapping);
            $new_chat_id = $this->createChatFromImport($xml, $a_mapping);
            $this->updatePageComponentProperties($mapped_id, $xml, $new_chat_id);

            $DIC->logger()->pcaic()->info('Import completed', [
                'entity' => $a_entity,
                'original_id' => $a_id,
                'mapped_id' => $mapped_id,
                'new_chat_id' => $new_chat_id,
                'schema' => (string) ($xml['schema_version'] ?? 'unknown')
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
     * Store the exported background files in the IRSS and map old to new resource IDs
     */
    private function importBackgroundFiles(\SimpleXMLElement $xml, ilImportMapping $a_mapping): void
    {
        if (!isset($xml->chat_config->background_files->file)) {
            return;
        }

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($xml->chat_config->background_files->file as $file_xml) {
            $original_path = (string) $file_xml['original_path'];
            $filename = (string) $file_xml['filename'];

            $import_file = $this->findImportFile($filename);
            if (!$import_file) {
                $DIC->logger()->pcaic()->warning('Import: Background file not found', [
                    'filename' => $filename,
                    'original_path' => $original_path
                ]);
                continue;
            }

            try {
                $stream = \ILIAS\Filesystem\Stream\Streams::ofResource(fopen($import_file, 'r'));
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                $identifier = $irss->manage()->stream($stream, $stakeholder, $filename);

                if (isset($file_xml['resource_id'])) {
                    $old_resource_id = (string) $file_xml['resource_id'];
                    $a_mapping->addMapping('Services/ResourceStorage', 'resource_id', $old_resource_id, $identifier->serialize());
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

    private function findImportFile(string $filename): ?string
    {
        $import_dir = $this->getImportDirectory();

        $search_paths = [
            $import_dir . '/AIChatPageComponent/' . $filename,
            $import_dir . '/ai_chat_page_component/' . $filename,
            $import_dir . '/background_files/' . $filename,
            $import_dir . '/' . $filename
        ];

        foreach ($search_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $this->recursiveFileSearch($import_dir, $filename);
    }

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
     * Create the chat configuration; missing values of older export files get defaults
     */
    private function createChatFromImport(\SimpleXMLElement $xml, ilImportMapping $a_mapping): string
    {
        global $DIC;

        $new_chat_id = 'chat_' . uniqid() . '.' . time();

        if (!isset($xml->chat_config)) {
            $DIC->logger()->pcaic()->warning('Import: No chat_config in XML');
            return $new_chat_id;
        }

        $cfg = $xml->chat_config;
        $new_chat = new ChatConfig($new_chat_id);

        $new_chat->setTitle(isset($cfg->title) ? (string) $cfg->title : '');
        $new_chat->setSystemPrompt(isset($cfg->system_prompt) ? (string) $cfg->system_prompt : '');
        $new_chat->setAiService(isset($cfg->ai_service) ? (string) $cfg->ai_service : 'ramses');

        $new_chat->setMaxMemory(isset($cfg->max_memory) ? (int) (string) $cfg->max_memory : 10);
        $new_chat->setCharLimit(isset($cfg->char_limit) ? (int) (string) $cfg->char_limit : 2000);

        $new_chat->setPersistent(isset($cfg->persistent) ? (string) $cfg->persistent === '1' : true);
        $new_chat->setIncludePageContext(isset($cfg->include_page_context) ? (string) $cfg->include_page_context === '1' : true);
        $new_chat->setEnableChatUploads(isset($cfg->enable_chat_uploads) ? (string) $cfg->enable_chat_uploads === '1' : false);
        $new_chat->setEnableStreaming(isset($cfg->enable_streaming) ? (string) $cfg->enable_streaming === '1' : true);
        $new_chat->setEnableRag(isset($cfg->enable_rag) ? (string) $cfg->enable_rag === '1' : false);
        $new_chat->setShowSources(isset($cfg->show_sources) ? (string) $cfg->show_sources === '1' : true);
        $new_chat->setAllowSourceDownloads(isset($cfg->allow_source_downloads) ? (string) $cfg->allow_source_downloads === '1' : true);

        if (isset($cfg->disclaimer)) {
            $new_chat->setDisclaimer((string) $cfg->disclaimer);
        }

        $new_chat->save();

        $background_files_count = $this->createBackgroundFileAttachments($new_chat_id, $xml, $a_mapping);

        $DIC->logger()->pcaic()->info('Import: Chat configuration created', [
            'new_chat_id' => $new_chat_id,
            'title' => $new_chat->getTitle(),
            'background_files_count' => $background_files_count
        ]);

        return $new_chat_id;
    }

    /**
     * @return int Number of created attachments
     */
    private function createBackgroundFileAttachments(string $chat_id, \SimpleXMLElement $xml, ilImportMapping $a_mapping): int
    {
        global $DIC;

        if (!isset($xml->chat_config->background_files->file)) {
            return 0;
        }

        $count = 0;
        $user_id = $DIC->user()->getId();

        foreach ($xml->chat_config->background_files->file as $file_xml) {
            $old_resource_id = (string) $file_xml['resource_id'];
            $new_resource_id = $a_mapping->getMapping('Services/ResourceStorage', 'resource_id', $old_resource_id);

            if (!$new_resource_id) {
                $DIC->logger()->pcaic()->warning('Import: No resource mapping for background file', [
                    'old_resource_id' => $old_resource_id
                ]);
                continue;
            }

            try {
                $attachment = new Attachment();
                $attachment->setChatId($chat_id);
                $attachment->setUserId($user_id);
                $attachment->setResourceId($new_resource_id);
                $attachment->setBackgroundFile(true);
                $attachment->setTimestamp(date('Y-m-d H:i:s'));
                $attachment->save();

                $count++;

                $DIC->logger()->pcaic()->debug('Import: Background file attachment created', [
                    'chat_id' => $chat_id,
                    'resource_id' => $new_resource_id,
                    'attachment_id' => $attachment->getId()
                ]);

            } catch (\Exception $e) {
                $DIC->logger()->pcaic()->error('Import: Failed to create background file attachment', [
                    'chat_id' => $chat_id,
                    'resource_id' => $new_resource_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    /**
     * Set the new chat ID in the properties of the page element
     */
    private function updatePageComponentProperties(string $mapped_id, \SimpleXMLElement $xml, string $new_chat_id): void
    {
        global $DIC;

        $current_properties = self::getPCProperties($mapped_id) ?? [];

        $updated_properties = $current_properties;
        $updated_properties['chat_id'] = $new_chat_id;

        if (isset($xml->chat_config->title)) {
            $updated_properties['chat_title'] = (string) $xml->chat_config->title;
        }

        self::setPCProperties($mapped_id, $updated_properties);

        $DIC->logger()->pcaic()->info('Import: PageComponent properties updated', [
            'mapped_id' => $mapped_id,
            'new_chat_id' => $new_chat_id
        ]);
    }
}
