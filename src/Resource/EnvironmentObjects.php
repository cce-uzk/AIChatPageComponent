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

namespace ILIAS\Plugin\pcaic\Resource;

/**
 * Objects of one type in the environment of a chat that a person may read
 *
 * The environment is the object containing the page of the chat: a container (course, group,
 * category, folder) with its folders, or the object itself, e.g. the wiki of a wiki page.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class EnvironmentObjects
{
    /** Folders below the container that are searched */
    private const MAX_DEPTH = 5;

    /**
     * @return array<int, array{ref_id: int, obj_id: int, title: string, label: string}> Sorted by label
     */
    public function find(int $env_ref_id, string $type, int $user_id): array
    {
        global $DIC;
        $found = [];

        // The environment itself, e.g. the wiki containing the page of the chat
        $env_obj_id = \ilObject::_lookupObjId($env_ref_id);
        if (\ilObject::_lookupType($env_obj_id) === $type) {
            if ($DIC->access()->checkAccessOfUser($user_id, 'read', '', $env_ref_id)) {
                $title = (string) \ilObject::_lookupTitle($env_obj_id);
                $found[] = ['ref_id' => $env_ref_id, 'obj_id' => $env_obj_id, 'title' => $title, 'label' => $title];
            }
            return $found;
        }

        $this->collect($env_ref_id, $type, [], 0, $user_id, $found);
        usort($found, fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $found;
    }

    /**
     * @param string[] $path Titles of the folders down to this container
     * @param array<int, array{ref_id: int, obj_id: int, title: string, label: string}> $found
     */
    private function collect(int $ref_id, string $type, array $path, int $depth, int $user_id, array &$found): void
    {
        global $DIC;
        foreach ($DIC->repositoryTree()->getChildsByTypeFilter($ref_id, [$type, 'fold']) as $child) {
            $child_ref = (int) $child['child'];
            if (!$DIC->access()->checkAccessOfUser($user_id, 'read', '', $child_ref)) {
                continue;
            }
            if ($child['type'] === 'fold' && $type !== 'fold') {
                if ($depth < self::MAX_DEPTH) {
                    $this->collect($child_ref, $type, [...$path, (string) $child['title']], $depth + 1, $user_id, $found);
                }
                continue;
            }
            $title = (string) $child['title'];
            $found[] = ['ref_id' => $child_ref, 'obj_id' => (int) $child['obj_id'], 'title' => $title, 'label' => self::label($path, $title)];
        }
    }

    /**
     * Title with the folders it is in, e.g. "Week 1 › Slides.pdf"
     *
     * @param string[] $path
     */
    public static function label(array $path, string $title): string
    {
        return implode(' › ', [...$path, $title]);
    }

    /**
     * The reference is in the repository tree and not in the trash
     */
    public static function exists(int $ref_id): bool
    {
        global $DIC;
        return $DIC->repositoryTree()->isInTree($ref_id) && !\ilObject::_isInTrash($ref_id);
    }

    /**
     * Whether the person may read the object of the reference now
     */
    public static function isReadable(?int $ref_id, int $user_id): bool
    {
        if ($ref_id === null || !self::exists($ref_id)) {
            return false;
        }
        global $DIC;
        return $DIC->access()->checkAccessOfUser($user_id, 'read', '', $ref_id);
    }
}
