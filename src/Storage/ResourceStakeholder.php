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

namespace ILIAS\Plugin\pcaic\Storage;

use ILIAS\ResourceStorage\Stakeholder\AbstractResourceStakeholder;

/**
 * Resource Storage stakeholder of the plugin
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ResourceStakeholder extends AbstractResourceStakeholder
{
    public function __construct()
    {
        global $DIC;
    }

    public function getId(): string
    {
        return \ilAIChatPageComponentPlugin::getPluginId();
    }

    /**
     * The current user is the owner of new resources
     */
    public function getOwnerOfNewResources(): int
    {
        global $DIC;
        return $DIC->user()->getId();
    }
}
