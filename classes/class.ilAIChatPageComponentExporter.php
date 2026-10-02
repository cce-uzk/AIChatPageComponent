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

/**
 * Export of chats: configuration and background files
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
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

            $chat_id = $properties['chat_id'];
            $chat_config = new ChatConfig($chat_id);

            if (!$chat_config->exists()) {
                $DIC->logger()->pcaic()->warning('Export: Chat configuration not found', [
                    'chat_id' => $chat_id,
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

            $this->addChatConfig($xml, $root, $chat_config);

            $DIC->logger()->pcaic()->info('Export completed', [
                'chat_id' => $chat_id,
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

    private function addChatConfig(DOMDocument $xml, DOMElement $root, ChatConfig $chat_config): void
    {
        $config_element = $xml->createElement('chat_config');
        $root->appendChild($config_element);

        $config_element->appendChild($xml->createElement('title', htmlspecialchars($chat_config->getTitle())));

        $system_prompt_element = $xml->createElement('system_prompt');
        $system_prompt_element->appendChild($xml->createCDATASection($chat_config->getSystemPrompt()));
        $config_element->appendChild($system_prompt_element);

        $config_element->appendChild($xml->createElement('ai_service', htmlspecialchars($chat_config->getAiService())));
        $config_element->appendChild($xml->createElement('max_memory', (string) $chat_config->getMaxMemory()));
        $config_element->appendChild($xml->createElement('char_limit', (string) $chat_config->getCharLimit()));

        $config_element->appendChild($xml->createElement('persistent', $chat_config->isPersistent() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('include_page_context', $chat_config->isIncludePageContext() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('enable_chat_uploads', $chat_config->isEnableChatUploads() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('enable_streaming', $chat_config->isEnableStreaming() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('enable_rag', $chat_config->isEnableRag() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('show_sources', $chat_config->isShowSources() ? '1' : '0'));
        $config_element->appendChild($xml->createElement('allow_source_downloads', $chat_config->isAllowSourceDownloads() ? '1' : '0'));

        if ($chat_config->getDisclaimer()) {
            $disclaimer_element = $xml->createElement('disclaimer');
            $disclaimer_element->appendChild($xml->createCDATASection($chat_config->getDisclaimer()));
            $config_element->appendChild($disclaimer_element);
        }

        $this->addBackgroundFiles($xml, $config_element, $chat_config);
    }

    /**
     * Copy the background files into the export directory and reference them in the XML
     */
    private function addBackgroundFiles(DOMDocument $xml, DOMElement $config_element, ChatConfig $chat_config): void
    {
        $background_files = $chat_config->getBackgroundFiles();
        if (empty($background_files)) {
            return;
        }

        $files_element = $xml->createElement('background_files');
        $config_element->appendChild($files_element);

        global $DIC;
        $irss = $DIC->resourceStorage();

        foreach ($background_files as $resource_id) {
            if (!is_string($resource_id) || empty($resource_id)) {
                continue;
            }

            $file_metadata = $this->loadFileMetadata($resource_id);
            if (!$file_metadata) {
                continue;
            }

            $export_path = null;
            try {
                $identification = $irss->manage()->find($resource_id);
                if ($identification) {
                    $filename = $file_metadata['filename'] ?: $irss->manage()->getCurrentRevision($identification)->getTitle();
                    $export_path = $this->exportFile($identification, $filename);
                }
            } catch (Exception $e) {
                $DIC->logger()->pcaic()->warning('Export: Failed to export background file', [
                    'resource_id' => $resource_id,
                    'error' => $e->getMessage()
                ]);
            }

            $file_element = $xml->createElement('file');
            $file_element->setAttribute('resource_id', $file_metadata['resource_id']);
            $file_element->setAttribute('filename', $file_metadata['filename']);
            $file_element->setAttribute('mime_type', $file_metadata['mime_type']);
            $file_element->setAttribute('description', $file_metadata['description']);
            if ($export_path) {
                $file_element->setAttribute('original_path', $export_path);
            }
            $files_element->appendChild($file_element);
        }
    }

    /**
     * @param mixed $identification IRSS resource identification
     * @return string|null File name, or null if the file could not be copied
     */
    private function exportFile($identification, string $filename): ?string
    {
        global $DIC;

        try {
            $irss = $DIC->resourceStorage();
            $stream = $irss->consume()->stream($identification);
            $content = $stream->getStream()->getContents();

            $export_dir = $this->getAbsoluteExportDirectory();
            $export_path = $export_dir . '/' . $filename;

            if (!is_dir($export_dir) && !mkdir($export_dir, 0755, true)) {
                throw new Exception('Cannot create export directory: ' . $export_dir);
            }

            if (!is_writable($export_dir)) {
                throw new Exception('Export directory not writable: ' . $export_dir);
            }

            if (file_put_contents($export_path, $content) === false) {
                throw new Exception('Failed to write file: ' . $export_path);
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
     * XML without chat data, used if the chat does not exist or the export fails
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
     * @return array{resource_id: string, filename: string, mime_type: string, description: string}|null
     */
    private function loadFileMetadata(string $resource_id): ?array
    {
        global $DIC;

        try {
            $irss = $DIC->resourceStorage();
            $identification = $irss->manage()->find($resource_id);

            if (!$identification) {
                return null;
            }

            $revision = $irss->manage()->getCurrentRevision($identification);
            $info = $revision->getInformation();

            return [
                'resource_id' => $resource_id,
                'filename' => $revision->getTitle(),
                'mime_type' => $info->getMimeType(),
                'description' => ''
            ];

        } catch (Exception $e) {
            $DIC->logger()->pcaic()->warning('Export: Failed to load file metadata', [
                'resource_id' => $resource_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
