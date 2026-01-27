<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Validation;

/**
 * File Upload Validation for AIChatPageComponent
 * 
 * Provides centralized file upload validation based on admin configuration.
 * Implements file type restrictions, size limits, and separate controls for
 * background files vs chat uploads.
 * 
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0
 */
class FileUploadValidator 
{
    /**
     * Get default allowed file extensions from platform configuration
     * 
     * @return array Default allowed file extensions
     */
    private static function getDefaultAllowedExtensions(): array
    {
        $default_types = \platform\AIChatPageComponentConfig::get('default_allowed_file_types');
        return is_array($default_types) ? $default_types : ['txt', 'md', 'pdf', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'gif'];
    }
    
    /**
     * @var array Default allowed MIME types mapping
     */
    private const EXTENSION_TO_MIME = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    
    /**
     * Validate file upload based on admin configuration
     * 
     * @param array $upload_info File upload information from $_FILES
     * @param string $upload_type Either 'background' or 'chat' 
     * @param string|null $chat_id Optional chat ID for context-specific validation
     * @return array Validation result ['success' => bool, 'error' => string|null]
     */
    public static function validateUpload(array $upload_info, string $upload_type, ?string $chat_id = null): array
    {
        // Get file handling configuration
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];
        
        // If file handling is disabled, don't allow any file uploads for AI processing
        if (!($file_restrictions['enabled'] ?? false)) {
            return ['success' => false, 'error' => 'File handling is disabled by administrator. Files cannot be processed by AI.'];
        }
        
        // Check if upload type is allowed
        if ($upload_type === 'background' && !($file_restrictions['allow_background_files'] ?? true)) {
            return ['success' => false, 'error' => 'Background file uploads are disabled by administrator.'];
        }
        
        if ($upload_type === 'chat' && !($file_restrictions['allow_chat_uploads'] ?? true)) {
            return ['success' => false, 'error' => 'Chat file uploads are disabled by administrator.'];
        }
        
        // Validate file size
        $size_validation = self::validateFileSize($upload_info);
        if (!$size_validation['success']) {
            return $size_validation;
        }
        
        // Validate file type against whitelist
        $type_validation = self::validateFileType($upload_info, $file_restrictions);
        if (!$type_validation['success']) {
            return $type_validation;
        }
        
        return ['success' => true, 'error' => null];
    }
    
    /**
     * Validate file size against configured limits
     * 
     * @param array $upload_info File upload information
     * @return array Validation result
     */
    private static function validateFileSize(array $upload_info): array
    {
        $max_file_size_mb = \platform\AIChatPageComponentConfig::get('max_file_size_mb') ?? 5;
        $max_size = $max_file_size_mb * 1024 * 1024;
        
        if ($upload_info['size'] > $max_size) {
            return ['success' => false, 'error' => "File too large. Maximum size is {$max_file_size_mb}MB."];
        }
        
        return ['success' => true, 'error' => null];
    }
    
    /**
     * Validate file type against admin-configured whitelist
     * 
     * @param array $upload_info File upload information
     * @param array $file_restrictions Configuration array
     * @return array Validation result
     */
    private static function validateFileType(array $upload_info, array $file_restrictions): array
    {
        $allowed_extensions = $file_restrictions['allowed_file_types'] ?? self::getDefaultAllowedExtensions();
        
        // If no allowed types configured, allow defaults
        if (empty($allowed_extensions)) {
            $allowed_extensions = self::getDefaultAllowedExtensions();
        }
        
        // Convert extensions to lowercase for comparison
        $allowed_extensions = array_map('strtolower', $allowed_extensions);
        
        // Get file extension from filename
        $filename = $upload_info['name'] ?? '';
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $allowed_list = implode(', ', $allowed_extensions);
            return ['success' => false, 'error' => "File type '{$file_extension}' not allowed. Allowed types: {$allowed_list}"];
        }
        
        // Additional MIME type validation
        $expected_mime = self::EXTENSION_TO_MIME[$file_extension] ?? null;
        if ($expected_mime) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $actual_mime = finfo_file($finfo, $upload_info['tmp_name']);
            finfo_close($finfo);
            
            if ($actual_mime !== $expected_mime) {
                return ['success' => false, 'error' => "File content does not match extension '{$file_extension}'."];
            }
        }
        
        return ['success' => true, 'error' => null];
    }
    
    /**
     * Get list of allowed file extensions for display
     * 
     * @param string $upload_type Either 'background' or 'chat'
     * @return array List of allowed extensions
     */
    public static function getAllowedExtensions(string $upload_type): array
    {
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];
        
        // If file handling is disabled, no extensions are allowed for AI processing
        if (!($file_restrictions['enabled'] ?? false)) {
            return [];
        }
        
        // Check if upload type is allowed
        if ($upload_type === 'background' && !($file_restrictions['allow_background_files'] ?? true)) {
            return [];
        }
        
        if ($upload_type === 'chat' && !($file_restrictions['allow_chat_uploads'] ?? true)) {
            return [];
        }
        
        $allowed_extensions = $file_restrictions['allowed_file_types'] ?? self::getDefaultAllowedExtensions();
        
        // If no allowed types configured, return defaults
        if (empty($allowed_extensions)) {
            return self::getDefaultAllowedExtensions();
        }
        
        return $allowed_extensions;
    }
    
    /**
     * Check if file uploads are enabled for specific type
     *
     * @param string $upload_type Either 'background' or 'chat'
     * @return bool True if uploads are enabled
     */
    public static function isUploadEnabled(string $upload_type): bool
    {
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        // If file handling is disabled, no uploads are allowed for AI processing
        if (!($file_restrictions['enabled'] ?? false)) {
            return false;
        }

        if ($upload_type === 'background') {
            return $file_restrictions['allow_background_files'] ?? true;
        }

        if ($upload_type === 'chat') {
            return $file_restrictions['allow_chat_uploads'] ?? true;
        }

        return false;
    }

    /**
     * Convert file extensions to MIME types
     *
     * @param array $extensions Array of file extensions (e.g., ['pdf', 'txt', 'png'])
     * @return array Array of MIME types (e.g., ['application/pdf', 'text/plain', 'image/png'])
     */
    public static function extensionsToMimeTypes(array $extensions): array
    {
        $mime_types = [];
        foreach ($extensions as $ext) {
            $ext = strtolower($ext);
            if (isset(self::EXTENSION_TO_MIME[$ext])) {
                $mime_types[] = self::EXTENSION_TO_MIME[$ext];
            }
        }
        // Return unique MIME types (e.g., jpg and jpeg both map to image/jpeg)
        return array_values(array_unique($mime_types));
    }

    /**
     * Convert file extensions to accept attribute values (MIME types + extensions)
     *
     * Returns both MIME types and file extensions for maximum browser compatibility.
     * Some browsers don't recognize certain MIME types (e.g., text/markdown),
     * so we include both the MIME type and the extension.
     *
     * @param array $extensions Array of file extensions (e.g., ['pdf', 'txt', 'md', 'png'])
     * @return array Array of MIME types and extensions for HTML accept attribute
     */
    public static function extensionsToAcceptValues(array $extensions): array
    {
        $accept_values = [];

        foreach ($extensions as $ext) {
            $ext = strtolower($ext);

            // Add MIME type if known
            if (isset(self::EXTENSION_TO_MIME[$ext])) {
                $accept_values[] = self::EXTENSION_TO_MIME[$ext];
            }

            // Always add the extension for browser compatibility
            // (some MIME types like text/markdown are not well-supported)
            $accept_values[] = '.' . $ext;
        }

        // Return unique values
        return array_values(array_unique($accept_values));
    }

    /**
     * Get the extension to MIME type mapping
     *
     * @return array Associative array of extension => MIME type
     */
    public static function getExtensionToMimeMapping(): array
    {
        return self::EXTENSION_TO_MIME;
    }
}