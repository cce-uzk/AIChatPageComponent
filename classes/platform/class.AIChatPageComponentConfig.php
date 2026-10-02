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

namespace platform;

/**
 * Plugin configuration stored in pcaic_config, with built-in defaults
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentConfig
{
    /**
     * @return mixed Stored value, built-in default or null; JSON arrays are decoded
     */
    public static function get(string $key)
    {
        $defaults = [
            'default_prompt' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.',
            'prompt' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.',
            'characters_limit' => 2000,
            'max_memory_messages' => 10,
            'default_disclaimer' => '',
            'disclaimer' => '',
            'available_services' => [
                'ramses' => '1',
                'openai' => '1'
            ],

            'max_file_size_mb' => 5,
            'max_attachments_per_message' => 5,
            'max_total_upload_size_mb' => 25,
            'pdf_pages_processed' => 20,
            'max_image_data_mb' => 15,
            'max_page_context_chars' => 50000,
            'image_max_dimension' => 1024,
            'pdf_image_quality' => 85,

            'global_max_char_limit' => null,
            'global_max_memory_limit' => null,

            'default_allowed_file_types' => ['txt', 'pdf', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'gif'],

            // File handling is enabled globally and per AI service
            'enable_file_handling' => '1',

            'selected_ai_service' => 'ramses',
            'force_default_ai_service' => '0',

            'ramses_api_url' => 'https://chat.kiconnect.nrw/api/v1',
            'ramses_service_enabled' => '0',
            'ramses_selected_model' => '',
            'ramses_force_model' => '0',
            'ramses_temperature' => 0.7,
            'ramses_force_temperature' => '0',
            'ramses_streaming_enabled' => '1',
            'ramses_file_handling_enabled' => '1',
            'ramses_enable_rag' => '1',

            'openai_api_url' => 'https://api.openai.com',
            'openai_service_enabled' => '0',
            'openai_selected_model' => 'gpt-4o',
            'openai_force_model' => '0',
            'openai_temperature' => 0.7,
            'openai_force_temperature' => '0',
            'openai_file_handling_enabled' => '1',
            'openai_enable_rag' => '0',

            // RAG service (independent of the chat AI service)
            'rag_service_enabled' => '0',
            'rag_api_url' => 'https://oski-rag.itcc.uni-koeln.de',
            'rag_client_key' => '',
            'rag_application_id' => 'ILIAS',
            'rag_instance_id' => 'ilias9',
            'rag_allowed_file_types' => 'txt,csv,pdf',
            'rag_top_k' => 10
        ];

        try {
            $stored_value = self::getFromDatabase($key);
            if ($stored_value !== null) {
                return $stored_value;
            }

            $default_value = $defaults[$key] ?? null;

            try {
                global $DIC;
                $DIC->logger()->pcaic()->debug("Config using built-in default", [
                    'key' => $key,
                    'default_value' => $default_value,
                    'source' => 'built_in_default'
                ]);
            } catch (\Exception $e) {
            }

            return $default_value;

        } catch (\Exception $e) {
            try {
                global $DIC;
                $DIC->logger()->pcaic()->error("Failed to get config value", [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $log_error) {
                // Ignore logging errors
            }
            return $defaults[$key] ?? null;
        }
    }

    /**
     * @param mixed $value Arrays are stored as JSON
     * @return bool False if the value could not be stored
     */
    public static function set(string $key, $value): bool
    {
        try {
            return self::saveToDatabase($key, $value);
        } catch (\Exception $e) {
            try {
                global $DIC;
                $DIC->logger()->pcaic()->error("Failed to set config value", [
                    'key' => $key,
                    'value' => self::maskForLog($key, $value),
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $log_error) {
                // Ignore logging errors
            }
            return false;
        }
    }

    private static function saveToDatabase(string $key, $value): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $current_time = date('Y-m-d H:i:s');

            $query = "SELECT config_value FROM pcaic_config WHERE config_key = %s";
            $result = $db->queryF($query, ['text'], [$key]);

            if ($db->numRows($result) > 0) {
                $update_query = "UPDATE pcaic_config SET config_value = %s, updated_at = %s WHERE config_key = %s";
                $db->manipulateF($update_query, ['clob', 'timestamp', 'text'], [(string) $value, $current_time, $key]);
            } else {
                $db->insert('pcaic_config', array(
                    'config_key' => array('text', $key),
                    'config_value' => array('clob', (string) $value),
                    'created_at' => array('timestamp', $current_time),
                    'updated_at' => array('timestamp', $current_time)
                ));
            }

            try {
                $DIC->logger()->pcaic()->debug("Config saved to plugin database", [
                    'key' => $key,
                    'value' => self::maskForLog($key, $value),
                    'source' => 'pcaic_config_table'
                ]);
            } catch (\Exception $e) {
                // Ignore logging errors
            }

            return true;

        } catch (\Exception $e) {
            try {
                global $DIC;
                $DIC->logger()->pcaic()->error("Failed to save to plugin config table", [
                    'key' => $key,
                    'value' => self::maskForLog($key, $value),
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $log_error) {
                // Ignore logging errors
            }
            return false;
        }
    }

    private static function getFromDatabase(string $key)
    {
        try {
            global $DIC;
            $db = $DIC->database();

            $query = "SELECT config_value FROM pcaic_config WHERE config_key = %s";
            $result = $db->queryF($query, ['text'], [$key]);

            if ($db->numRows($result) > 0) {
                $row = $db->fetchAssoc($result);
                $value = $row['config_value'];

                // Arrays are stored as JSON
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }

                try {
                    $DIC->logger()->pcaic()->debug("Config loaded from plugin database", [
                        'key' => $key,
                        'value' => self::maskForLog($key, $value),
                        'source' => 'pcaic_config_table'
                    ]);
                } catch (\Exception $e) {
                    // Ignore logging errors
                }

                return $value;
            }

            return null;

        } catch (\Exception $e) {
            try {
                global $DIC;
                $DIC->logger()->pcaic()->error("Failed to read from plugin config table", [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $log_error) {
                // Ignore logging errors
            }
            return null;
        }
    }

    /**
     * @return array<string, mixed> All stored values; JSON arrays are decoded
     */
    public static function getAll(): array
    {
        try {
            global $DIC;
            $db = $DIC->database();

            $query = "SELECT config_key, config_value FROM pcaic_config";
            $result = $db->query($query);

            $config = [];
            while ($row = $db->fetchAssoc($result)) {
                $value = $row['config_value'];

                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }

                $config[$row['config_key']] = $value;
            }

            return $config;

        } catch (\Exception $e) {
            try {
                global $DIC;
                $DIC->logger()->pcaic()->error("Failed to load all config values", [
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $log_error) {
                // Ignore logging errors
            }
            return [];
        }
    }

    /**
     * Replace secrets (tokens, keys) in log output
     *
     * @param mixed $value
     * @return mixed
     */
    private static function maskForLog(string $key, $value)
    {
        if (preg_match('/token|key|secret|password/i', $key)) {
            return (is_string($value) && $value !== '') ? '***' : $value;
        }
        return $value;
    }
}
