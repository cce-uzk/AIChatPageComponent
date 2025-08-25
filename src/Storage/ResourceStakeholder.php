<?php declare(strict_types=1);

namespace ILIAS\Plugin\pcaic\Storage;

use ILIAS\ResourceStorage\Stakeholder\AbstractResourceStakeholder;

/**
 * ResourceStakeholder for AIChatPageComponent Plugin
 * Required Class for Integrated-Resource-Storage-Service (IRSS) usage
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ResourceStakeholder extends AbstractResourceStakeholder
{
    public function __construct()
    {
        global $DIC;
    }

    /**
     * Get IRSS-ProviderId (in this case: PluginId)
     */
    public function getId(): string
    {
        return \ilAIChatPageComponentPlugin::getPluginId();
    }

    /**
     * Get ResourceOwnerId (in this case: Current-User-Id)
     */
    public function getOwnerOfNewResources(): int
    {
        global $DIC;
        return $DIC->user()->getId();
    }
}