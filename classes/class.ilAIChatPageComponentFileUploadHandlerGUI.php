<?php declare(strict_types=1);

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;

/**
 * IRSS-based file upload handler for AIChatPageComponent background files
 * Following ILIAS Core patterns like ilObjFileUploadHandlerGUI
 *
 * @ilCtrl_isCalledBy ilAIChatPageComponentFileUploadHandlerGUI: ilAIChatPageComponentPluginGUI
 * @ilCtrl_IsCalledBy ilAIChatPageComponentFileUploadHandlerGUI: ilUIPluginRouterGUI
 */
class ilAIChatPageComponentFileUploadHandlerGUI extends AbstractCtrlAwareIRSSUploadHandler
{
    protected function getStakeholder(): ResourceStakeholder
    {
        return new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
    }

    protected function getClassPath(): array
    {
        return [ilUIPluginRouterGUI::class, self::class];
    }

    public function getFileIdentifierParameterName(): string
    {
        return 'background_files';
    }

    public function supportsChunkedUploads(): bool
    {
        return false;
    }
}
