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
 * A learning module (type "lm") of the environment: one text file per active page in the order
 * of its chapters, named "Learning module › Chapter › Page"
 *
 * Pages outside the chapter structure and deactivated pages are left out, as for learners.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class LearningModuleProvider extends PageObjectProvider
{
    public const TYPE = 'obj_lm';

    public function getType(): string
    {
        return self::TYPE;
    }

    protected function objectType(): string
    {
        return 'lm';
    }

    protected function pageType(): string
    {
        return 'lm';
    }

    protected function pages(int $obj_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $active = [];
        foreach ($this->pagesOfParent('lm', $obj_id) as $page) {
            $active[$page['id']] = $page['last_change'];
        }

        $result = $db->query(
            "SELECT t.child, t.depth, d.type, d.title FROM lm_tree t"
            . " JOIN lm_data d ON d.obj_id = t.child"
            . " WHERE t.lm_id = " . $db->quote($obj_id, 'integer')
            . " ORDER BY t.lft"
        );
        $rows = [];
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = ['id' => (int) $row['child'], 'depth' => (int) $row['depth'], 'type' => (string) $row['type'], 'title' => (string) $row['title']];
        }
        return self::pagesInOrder($rows, $active);
    }

    /**
     * Pages of the chapter structure in reading order, each with the titles of its chapters
     *
     * @param array<int, array{id: int, depth: int, type: string, title: string}> $rows Tree in preorder ("st" chapter, "pg" page)
     * @param array<int, string> $active Last change of the active pages by page ID
     * @return array<int, array{id: int, title: string, last_change: string, path: string[]}>
     */
    public static function pagesInOrder(array $rows, array $active): array
    {
        $chapters = [];
        $pages = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'st') {
                $chapters = array_filter($chapters, fn(int $depth): bool => $depth < $row['depth'], ARRAY_FILTER_USE_KEY);
                $chapters[$row['depth']] = $row['title'];
                continue;
            }
            if ($row['type'] !== 'pg' || !isset($active[$row['id']])) {
                continue;
            }
            $path = array_values(array_filter($chapters, fn(int $depth): bool => $depth < $row['depth'], ARRAY_FILTER_USE_KEY));
            $pages[] = ['id' => $row['id'], 'title' => $row['title'], 'last_change' => $active[$row['id']], 'path' => $path];
        }
        return $pages;
    }
}
