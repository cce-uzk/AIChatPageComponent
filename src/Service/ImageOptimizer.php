<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Service;

/**
 * Image Optimizer for reducing file size before AI analysis
 * Resizes images to reasonable dimensions while maintaining quality
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ImageOptimizer
{
    private const MAX_DIMENSION = 1024; // Max width or height
    private const JPEG_QUALITY = 85;    // JPEG compression quality
    
    /**
     * Optimize image for AI analysis
     * @param string $imageData Binary image data
     * @param string $mimeType Original MIME type
     * @return array ['data' => optimized_data, 'mime_type' => final_mime_type]
     */
    public static function optimize(string $imageData, string $mimeType): array
    {
        try {
            // Try to create image from data
            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                // Return original if we can't process it
                return ['data' => $imageData, 'mime_type' => $mimeType];
            }
            
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);
            
            // Calculate new dimensions
            $newDimensions = self::calculateOptimalSize($originalWidth, $originalHeight);
            
            // If no resize needed and it's already JPEG, return original
            if ($newDimensions['width'] === $originalWidth && 
                $newDimensions['height'] === $originalHeight && 
                $mimeType === 'image/jpeg') {
                imagedestroy($image);
                return ['data' => $imageData, 'mime_type' => $mimeType];
            }
            
            // Create resized image
            $resizedImage = imagecreatetruecolor($newDimensions['width'], $newDimensions['height']);
            
            // Preserve transparency for PNG
            if ($mimeType === 'image/png') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefill($resizedImage, 0, 0, $transparent);
            }
            
            // Resize image
            imagecopyresampled(
                $resizedImage, $image,
                0, 0, 0, 0,
                $newDimensions['width'], $newDimensions['height'],
                $originalWidth, $originalHeight
            );
            
            // Output optimized image
            ob_start();
            
            // Convert to JPEG for better compression (except for PNG with transparency)
            if ($mimeType === 'image/png' && self::hasTransparency($image)) {
                imagepng($resizedImage, null, 6); // PNG compression level 6
                $finalMimeType = 'image/png';
            } else {
                imagejpeg($resizedImage, null, self::JPEG_QUALITY);
                $finalMimeType = 'image/jpeg';
            }
            
            $optimizedData = ob_get_clean();
            
            // Cleanup
            imagedestroy($image);
            imagedestroy($resizedImage);
            
            $originalSize = strlen($imageData);
            $optimizedSize = strlen($optimizedData);
            
            global $DIC;
            $DIC->logger()->pcaic()->debug("Image optimized", [
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
                'original_size' => $originalSize,
                'new_width' => $newDimensions['width'],
                'new_height' => $newDimensions['height'],
                'optimized_size' => $optimizedSize
            ]);
            
            return ['data' => $optimizedData, 'mime_type' => $finalMimeType];
            
        } catch (\Exception $e) {
            global $DIC;
            $DIC->logger()->pcaic()->warning("Image optimization failed", ['error' => $e->getMessage()]);
            // Return original on error
            return ['data' => $imageData, 'mime_type' => $mimeType];
        }
    }
    
    /**
     * Calculate optimal image dimensions
     */
    private static function calculateOptimalSize(int $width, int $height): array
    {
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return ['width' => $width, 'height' => $height];
        }
        
        $ratio = $width / $height;
        
        if ($width > $height) {
            // Landscape
            $newWidth = self::MAX_DIMENSION;
            $newHeight = (int)round($newWidth / $ratio);
        } else {
            // Portrait or square
            $newHeight = self::MAX_DIMENSION;
            $newWidth = (int)round($newHeight * $ratio);
        }
        
        return ['width' => $newWidth, 'height' => $newHeight];
    }
    
    /**
     * Check if PNG has transparency
     */
    private static function hasTransparency($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Sample a few pixels to check for transparency
        $samplePoints = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int)($width / 2), (int)($height / 2)]
        ];
        
        foreach ($samplePoints as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > 0) {
                return true; // Has transparency
            }
        }
        
        return false;
    }
    
    /**
     * Get estimated Base64 size
     */
    public static function estimateBase64Size(int $binarySize): int
    {
        return (int)ceil($binarySize * 4 / 3);
    }
}