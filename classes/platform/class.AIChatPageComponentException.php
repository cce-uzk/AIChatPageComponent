<?php
declare(strict_types=1);

namespace platform;

use Exception;

/**
 * Class AIChatPageComponentException
 * Based on AIChatException from AIChat plugin
 */
class AIChatPageComponentException extends Exception
{
    public function __construct($message = "", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}