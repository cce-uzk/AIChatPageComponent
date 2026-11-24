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

namespace ILIAS\Plugin\pcaic\UI;

use ILIAS\UI\Component\Input\Field\Textarea;

/**
 * Textarea with a limit of characters whose counter shows what is left from the start
 *
 * ILIAS renders the counter of remaining characters with strlen(), i.e. in bytes: an umlaut
 * counts twice, a line break of a saved text "\r\n" counts twice. While typing, the browser
 * counts characters. So the counter jumped up at the first input after saving. Here, the
 * counter is set in the browser right away, and line breaks are saved as "\n" (as the browser
 * counts them), also before the check of the limit.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class LimitedTextarea
{
    public static function apply(Textarea $field, int $max_limit): Textarea
    {
        global $DIC;
        return $field
            ->withAdditionalTransformation($DIC->refinery()->custom()->transformation(
                static fn($value) => is_string($value) ? self::normalizeLineBreaks($value) : $value
            ))
            ->withMaxLimit($max_limit)
            ->withAdditionalOnLoadCode(static fn(string $id): string => self::remainderScript($id));
    }

    public static function normalizeLineBreaks(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Sets the counter as the browser counts while typing: limit minus characters of the value
     */
    public static function remainderScript(string $id): string
    {
        $json_id = json_encode($id);
        return "(function () {"
            . " var field = document.getElementById($json_id);"
            . " var remainder = field && field.closest('.ui-input-textarea')"
            . " ? field.closest('.ui-input-textarea').querySelector('[data-action=\"remainder\"]') : null;"
            . " if (remainder && field.maxLength > 0) { remainder.textContent = String(field.maxLength - field.value.length); }"
            . " })();";
    }
}
