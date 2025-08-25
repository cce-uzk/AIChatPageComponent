<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Exception;

use Exception;

/**
 * Custom Exception for AIChatPageComponent Plugin
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class PluginException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct("AIChatPageComponent: " . $message, $code, $previous);
    }
}