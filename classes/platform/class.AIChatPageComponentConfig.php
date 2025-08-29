<?php

namespace platform;

/**
 * AIChatPageComponentConfig - Configuration bridge to AIChat plugin
 * 
 * This class provides access to AIChat plugin configuration values
 * for use in AIChatPageComponent.
 */
class AIChatPageComponentConfig
{
    /**
     * Get configuration value from AIChat plugin
     * 
     * @param string $key Configuration key
     * @return mixed Configuration value or null if not found
     */
    public static function get(string $key)
    {
        // Default values for basic functionality
        $defaults = [
            'prompt' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.',
            'characters_limit' => 2000,
            'max_memory_messages' => 10,
            'disclaimer' => '',
            'available_services' => [
                'ramses' => '1',
                'openai' => '1'
            ],
            
            // File upload and processing limits
            'max_file_size_mb' => 5,
            'max_images_per_message' => 5,
            'max_pdf_pages' => 20,
            'max_total_image_data_mb' => 15,
            'max_page_context_chars' => 50000,
            'image_max_dimension' => 1024,
            'pdf_image_quality' => 85
        ];
        
        try {
            // Try to load from AIChat plugin if available
            $aichat_config_path = '/var/www/html/ilias/Customizing/global/plugins/Services/Repository/RepositoryObject/AIChat/classes/platform/class.AIChatConfig.php';
            
            if (file_exists($aichat_config_path)) {
                require_once($aichat_config_path);
                
                if (class_exists('\\platform\\AIChatConfig')) {
                    $value = \platform\AIChatConfig::get($key);
                    if ($value !== null) {
                        // Successfully loaded from central AIChat config
                        try {
                            global $DIC;
                            $DIC->logger()->comp('pcaic')->debug("Config loaded from AIChat plugin", [
                                'key' => $key,
                                'value' => $value,
                                'source' => 'aichat_plugin'
                            ]);
                        } catch (\Exception $e) {
                            // Ignore logging errors during early bootstrap
                        }
                        return $value;
                    }
                }
            }
            
            // Fallback to defaults
            $default_value = $defaults[$key] ?? null;
            try {
                global $DIC;
                $DIC->logger()->comp('pcaic')->debug("Config using built-in default", [
                    'key' => $key,
                    'default_value' => $default_value,
                    'source' => 'built_in_default',
                    'aichat_file_exists' => file_exists($aichat_config_path),
                    'aichat_class_exists' => class_exists('\\platform\\AIChatConfig')
                ]);
            } catch (\Exception $e) {
                // Ignore logging errors during early bootstrap
            }
            return $default_value;
            
        } catch (\Exception $e) {
            // Return default value on error
            return $defaults[$key] ?? null;
        }
    }
}