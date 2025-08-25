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
            ]
        ];
        
        try {
            // Try to load from AIChat plugin if available
            $aichat_config_path = '/var/www/html/ilias/Customizing/global/plugins/Services/Repository/RepositoryObject/AIChat/classes/platform/class.AIChatConfig.php';
            
            if (file_exists($aichat_config_path)) {
                require_once($aichat_config_path);
                
                if (class_exists('\\platform\\AIChatConfig')) {
                    $value = \platform\AIChatConfig::get($key);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
            
            // Fallback to defaults
            return $defaults[$key] ?? null;
            
        } catch (\Exception $e) {
            global $DIC;
            $DIC->logger()->comp('pcaic')->warning("Error loading config", ['error' => $e->getMessage()]);
            // Return default value
            return $defaults[$key] ?? null;
        }
    }
}