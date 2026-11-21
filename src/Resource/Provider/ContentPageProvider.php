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

namespace ILIAS\Plugin\pcaic\Resource\Provider;

/**
 * A content page (type "copa") of the environment: one text file named after the object
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ContentPageProvider extends PageObjectProvider
{
    public const TYPE = 'obj_copa';

    public function getType(): string
    {
        return self::TYPE;
    }

    protected function objectType(): string
    {
        return 'copa';
    }

    protected function pageType(): string
    {
        return 'copa';
    }

    /**
     * The page of the content page in the main language
     */
    protected function pages(int $obj_id): array
    {
        return $this->pagesOfParent('copa', $obj_id);
    }
}
