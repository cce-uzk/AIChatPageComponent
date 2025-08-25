<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;

/**
 * Background file upload handler for AIChatPageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_isCalledBy ilAIChatBackgroundFileUploadHandlerGUI: ilAIChatPageComponentPluginGUI
 */
class ilAIChatBackgroundFileUploadHandlerGUI extends AbstractCtrlAwareIRSSUploadHandler
{
    /**
     * @return ResourceStakeholder
     */
    protected function getStakeholder(): ResourceStakeholder
    {
        return new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
    }

    /**
     * @return array
     */
    protected function getClassPath(): array
    {
        return [ilAIChatPageComponentPluginGUI::class, self::class];
    }

    /**
     * @return bool
     */
    public function supportsChunkedUploads(): bool
    {
        return true;
    }
}