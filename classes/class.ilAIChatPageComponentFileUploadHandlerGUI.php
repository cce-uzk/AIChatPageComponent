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

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;

/**
 * IRSS upload handler for background files of a chat
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
