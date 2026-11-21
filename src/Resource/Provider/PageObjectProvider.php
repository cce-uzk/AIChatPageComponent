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

use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Resource\Candidate;
use ILIAS\Plugin\pcaic\Resource\EnvironmentObjects;
use ILIAS\Plugin\pcaic\Resource\PageTextExtractor;
use ILIAS\Plugin\pcaic\Resource\ResourceContext;
use ILIAS\Plugin\pcaic\Resource\ResourceProvider;
use ILIAS\Plugin\pcaic\Resource\SnapshotDocument;
use ILIAS\Plugin\pcaic\Validation\FileTypePolicy;

/**
 * An ILIAS object made of pages (wiki, content page, learning module): one text file per page
 *
 * A subclass names the object type, the page type and lists the pages; snapshot, fingerprint,
 * permission, link, name and icon are the same for all. The file of a page is named after the
 * object and the page ("Wiki › Page"), so the source list names the page. Only with RAG.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
abstract class PageObjectProvider implements ResourceProvider
{
    /** Pages taken into a snapshot; larger objects are cut off (in the order of their pages) */
    public const MAX_PAGES = 500;

    public function __construct(private ?PageTextExtractor $extractor = null)
    {
    }

    /**
     * ILIAS type of the object, e.g. "wiki"
     */
    abstract protected function objectType(): string;

    /**
     * Page type of its pages in COPage, e.g. "wpg"
     */
    abstract protected function pageType(): string;

    /**
     * Active pages in reading order
     *
     * @return array<int, array{id: int, title: string, last_change: string, path?: string[]}>
     *         path: titles between object and page, e.g. the chapters of a learning module
     */
    abstract protected function pages(int $obj_id): array;

    public function requiresRag(): bool
    {
        return true;
    }

    public function getCandidates(ResourceContext $context): array
    {
        $env_ref_id = $context->getEnvRefId();
        if ($env_ref_id === null) {
            return [];
        }
        // The pages are stored as text files: without TXT among the RAG file types they would never be searched
        $policy = FileTypePolicy::forService(
            \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($context->getChat()->getAiService())
        );
        if (!$policy->isRagType('txt')) {
            return [];
        }
        $added = ContainerFileProvider::addedRefIds($context, $this->getType());
        $candidates = [];
        foreach ((new EnvironmentObjects())->find($env_ref_id, $this->objectType(), $context->getUserId()) as $object) {
            $candidates[] = new Candidate($this->getType(), $object['ref_id'], $object['obj_id'], $object['label'], isset($added[$object['ref_id']]));
        }
        return $candidates;
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return [];
        }
        $obj_id = \ilObject::_lookupObjId($ref_id);
        $object_title = (string) \ilObject::_lookupTitle($obj_id);

        $documents = [];
        foreach (array_slice($this->pages($obj_id), 0, self::MAX_PAGES) as $page) {
            $text = $this->extractor()->textOfPage($this->pageType(), $page['id']);
            if (trim($text) === '') {
                continue;
            }
            $parts = [$object_title, ...($page['path'] ?? [])];
            if ($page['title'] !== '') {
                $parts[] = $page['title'];
            }
            // The name heads the text, so passages keep their page in the search
            $documents[] = SnapshotDocument::ofText(
                self::filename($parts),
                implode(' › ', $parts) . "\n\n" . $text
            );
        }
        return $documents;
    }

    /**
     * File name of a page, e.g. "Lernmodul › Kapitel 1 › Einführung.txt"
     *
     * @param string[] $parts Object title, path and page title
     */
    public static function filename(array $parts): string
    {
        // A slash would be read as directory in the file name
        $clean = static fn(string $title): string => trim(str_replace(['/', '\\'], '-', $title));
        return implode(' › ', array_map($clean, $parts)) . '.txt';
    }

    /**
     * Number of pages and newest change: a page added, removed or changed changes it
     *
     * @param array<int, array{id: int, title: string, last_change: string}> $pages
     */
    public static function fingerprint(array $pages): string
    {
        $newest = '';
        foreach ($pages as $page) {
            $newest = max($newest, $page['last_change']);
        }
        return count($pages) . '|' . $newest;
    }

    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return null;
        }
        return self::fingerprint($this->pages(\ilObject::_lookupObjId($ref_id)));
    }

    public function isAccessible(ChatResource $resource, int $user_id): bool
    {
        return EnvironmentObjects::isReadable($resource->getSourceRefId(), $user_id);
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        return $ref_id === null ? null : \ilLink::_getStaticLink($ref_id, $this->objectType());
    }

    /**
     * "Object › Page" without the extension of the text file
     */
    public function getSourceName(ChatResource $resource, string $filename): string
    {
        return preg_replace('/\.txt$/i', '', $filename) ?? $filename;
    }

    public function getSourceKind(): ?string
    {
        return 'page';
    }

    public function getSourceIcon(ChatResource $resource): ?string
    {
        $obj_id = $resource->getSourceObjId()
            ?? ($resource->getSourceRefId() !== null ? \ilObject::_lookupObjId($resource->getSourceRefId()) : 0);
        return EnvironmentObjects::iconOf((int) $obj_id, $this->objectType());
    }

    /**
     * Active pages of a page parent in COPage that are not in a structure, by last change
     *
     * @return array<int, array{id: int, title: string, last_change: string}>
     */
    protected function pagesOfParent(string $page_type, int $parent_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $result = $db->query(
            "SELECT page_id, last_change FROM page_object"
            . " WHERE parent_type = " . $db->quote($page_type, 'text')
            . " AND parent_id = " . $db->quote($parent_id, 'integer')
            . " AND lang = " . $db->quote('-', 'text')
            . " AND active = 1"
        );
        $pages = [];
        while ($row = $db->fetchAssoc($result)) {
            $pages[] = ['id' => (int) $row['page_id'], 'title' => '', 'last_change' => (string) $row['last_change']];
        }
        return $pages;
    }

    protected function extractor(): PageTextExtractor
    {
        return $this->extractor ??= new PageTextExtractor();
    }

    public function getSourceOffer(): string
    {
        return self::OFFER_LINK;
    }
}
