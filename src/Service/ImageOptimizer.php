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

namespace ILIAS\Plugin\pcaic\Service;

/**
 * Reduces image size before images are sent to an AI service
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ImageOptimizer
{
    private const MAX_DIMENSION = 1024; // Maximum width or height in pixels
    private const JPEG_QUALITY = 85;

    /**
     * @param string $image_data Binary image data
     * @return array{data: string, mime_type: string}
     */
    public static function optimize(string $image_data, string $mime_type): array
    {
        try {
            $image = imagecreatefromstring($image_data);
            if ($image === false) {
                // Unsupported format: keep the original
                return ['data' => $image_data, 'mime_type' => $mime_type];
            }

            $original_width = imagesx($image);
            $original_height = imagesy($image);

            $new_dimensions = self::calculateOptimalSize($original_width, $original_height);

            if ($new_dimensions['width'] === $original_width &&
                $new_dimensions['height'] === $original_height &&
                $mime_type === 'image/jpeg') {
                imagedestroy($image);
                return ['data' => $image_data, 'mime_type' => $mime_type];
            }

            $resized_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);

            if ($mime_type === 'image/png') {
                imagealphablending($resized_image, false);
                imagesavealpha($resized_image, true);
                $transparent = imagecolorallocatealpha($resized_image, 255, 255, 255, 127);
                imagefill($resized_image, 0, 0, $transparent);
            }

            imagecopyresampled(
                $resized_image,
                $image,
                0,
                0,
                0,
                0,
                $new_dimensions['width'],
                $new_dimensions['height'],
                $original_width,
                $original_height
            );

            ob_start();

            // JPEG compresses better; PNG is kept only if it has transparency
            if ($mime_type === 'image/png' && self::hasTransparency($image)) {
                imagepng($resized_image, null, 6);
                $final_mime_type = 'image/png';
            } else {
                imagejpeg($resized_image, null, self::JPEG_QUALITY);
                $final_mime_type = 'image/jpeg';
            }

            $optimized_data = ob_get_clean();

            imagedestroy($image);
            imagedestroy($resized_image);

            $original_size = strlen($image_data);
            $optimized_size = strlen($optimized_data);

            global $DIC;
            $DIC->logger()->pcaic()->debug("Image optimized", [
                'original_width' => $original_width,
                'original_height' => $original_height,
                'original_size' => $original_size,
                'new_width' => $new_dimensions['width'],
                'new_height' => $new_dimensions['height'],
                'optimized_size' => $optimized_size
            ]);

            return ['data' => $optimized_data, 'mime_type' => $final_mime_type];

        } catch (\Exception $e) {
            global $DIC;
            $DIC->logger()->pcaic()->warning("Image optimization failed", ['error' => $e->getMessage()]);
            return ['data' => $image_data, 'mime_type' => $mime_type];
        }
    }

    private static function calculateOptimalSize(int $width, int $height): array
    {
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return ['width' => $width, 'height' => $height];
        }

        $ratio = $width / $height;

        if ($width > $height) {
            $new_width = self::MAX_DIMENSION;
            $new_height = (int) round($new_width / $ratio);
        } else {
            $new_height = self::MAX_DIMENSION;
            $new_width = (int) round($new_height * $ratio);
        }

        return ['width' => $new_width, 'height' => $new_height];
    }

    private static function hasTransparency($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Checks a few sample pixels only
        $sample_points = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int) ($width / 2), (int) ($height / 2)]
        ];

        foreach ($sample_points as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Size of the data after Base64 encoding
     */
    public static function estimateBase64Size(int $binary_size): int
    {
        return (int) ceil($binary_size * 4 / 3);
    }
}
