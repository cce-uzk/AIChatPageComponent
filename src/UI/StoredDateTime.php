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

/**
 * Display of a date and time stored by the plugin
 *
 * The plugin stores times as ILIAS does (ilUtil::now(), date()): in the time zone of the server,
 * set by "timezone" in ilias.ini.php. Read as UTC, they appeared an hour (two in summer) late
 * on a server in Central Europe.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class StoredDateTime
{
    /**
     * In the time zone and date format of the user
     *
     * @param string $value "Y-m-d H:i:s" in the time zone of the server
     */
    public static function format(string $value): string
    {
        // Without a time zone, ilDateTime reads the value in the time zone of the server
        return \ilDatePresentation::formatDate(new \ilDateTime($value, IL_CAL_DATETIME));
    }
}
