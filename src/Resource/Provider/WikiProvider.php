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
 * A whole wiki (type "wiki") of the environment, or the wiki containing the page of the chat:
 * one text file per active page, named "Wiki › Page"
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class WikiProvider extends PageObjectProvider
{
    public const TYPE = 'obj_wiki';

    public function getType(): string
    {
        return self::TYPE;
    }

    protected function objectType(): string
    {
        return 'wiki';
    }

    protected function pageType(): string
    {
        return 'wpg';
    }

    public function getSourceKind(): ?string
    {
        return 'wiki';
    }

    /**
     * Active pages of a wiki in the main language, by title
     */
    protected function pages(int $obj_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $result = $db->query(
            "SELECT p.page_id, p.last_change, w.title FROM page_object p"
            . " JOIN il_wiki_page w ON w.id = p.page_id AND w.lang = p.lang"
            . " WHERE p.parent_type = " . $db->quote('wpg', 'text')
            . " AND p.parent_id = " . $db->quote($obj_id, 'integer')
            . " AND p.lang = " . $db->quote('-', 'text')
            . " AND p.active = 1"
            . " ORDER BY w.title"
        );
        $pages = [];
        while ($row = $db->fetchAssoc($result)) {
            $pages[] = ['id' => (int) $row['page_id'], 'title' => (string) $row['title'], 'last_change' => (string) $row['last_change']];
        }
        return $pages;
    }
}
