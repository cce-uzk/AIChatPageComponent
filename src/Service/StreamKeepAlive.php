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
 * Keeps a streamed answer (server-sent events) open while nothing is sent to the browser
 *
 * The document search of the RAG and the first part of an answer can take more than a
 * minute. Proxies and web servers often close a connection after 60 seconds without
 * data (e.g. nginx proxy_read_timeout). While waiting for an external service, a comment
 * line is therefore sent every INTERVAL seconds; EventSource ignores comment lines.
 *
 * Limits of the total duration of a request (e.g. PHP-FPM request_terminate_timeout)
 * are not affected and must allow the full waiting time.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class StreamKeepAlive
{
    /** Seconds without output after which a comment line is sent; below the usual 60 seconds */
    public const INTERVAL = 15;

    /** Time of the last output; null while no stream is open */
    private static ?float $last_output = null;

    /**
     * Start sending comment lines, for the current request
     */
    public static function enable(): void
    {
        self::$last_output = microtime(true);
    }

    public static function disable(): void
    {
        self::$last_output = null;
    }

    public static function isEnabled(): bool
    {
        return self::$last_output !== null;
    }

    /**
     * Data was sent to the browser
     */
    public static function touch(): void
    {
        if (self::$last_output !== null) {
            self::$last_output = microtime(true);
        }
    }

    /**
     * Send a comment line if nothing was sent for INTERVAL seconds
     *
     * Called while waiting for an external service, e.g. by the progress function of cURL,
     * which runs about once per second also while no data arrives.
     */
    public static function beat(): void
    {
        if (self::$last_output === null || microtime(true) - self::$last_output < self::INTERVAL) {
            return;
        }

        echo ": keepalive\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        self::$last_output = microtime(true);
    }

    /**
     * Set the progress function of a cURL request so that comment lines are sent while it waits
     */
    public static function attach(\CurlHandle $curl): void
    {
        if (self::$last_output === null) {
            return;
        }
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_setopt($curl, self::progressOption(), static function (): int {
            self::beat();
            return 0;
        });
    }

    /**
     * CURLOPT_XFERINFOFUNCTION is available from PHP 8.2, the older CURLOPT_PROGRESSFUNCTION works the same way here
     */
    public static function progressOption(): int
    {
        return defined('CURLOPT_XFERINFOFUNCTION') ? (int) constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
    }
}
