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

/**
 * A whole wiki (type "wiki") of the environment: one text file per page
 *
 * The file of a page is named "Wiki › Page", so the source list names the page. Only with RAG.
 * Who may not read the wiki in ILIAS does not get its content.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class WikiProvider implements ResourceProvider
{
    public const TYPE = 'obj_wiki';

    /** Pages taken into a snapshot; larger wikis are cut off (in the order of the page titles) */
    public const MAX_PAGES = 500;

    public function __construct(private ?PageTextExtractor $extractor = null)
    {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

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
        $policy = \ILIAS\Plugin\pcaic\Validation\FileTypePolicy::forService(
            \ai\AIChatPageComponentLLMRegistry::getEffectiveServiceId($context->getChat()->getAiService())
        );
        if (!$policy->isRagType('txt')) {
            return [];
        }
        $added = ContainerFileProvider::addedRefIds($context, self::TYPE);
        $candidates = [];
        foreach ((new EnvironmentObjects())->find($env_ref_id, 'wiki', $context->getUserId()) as $object) {
            $candidates[] = new Candidate(self::TYPE, $object['ref_id'], $object['obj_id'], $object['label'], isset($added[$object['ref_id']]));
        }
        return $candidates;
    }

    public function createSnapshot(ResourceContext $context, ChatResource $resource): array
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return [];
        }
        $wiki_title = (string) \ilObject::_lookupTitle(\ilObject::_lookupObjId($ref_id));

        $documents = [];
        foreach (array_slice($this->pages(\ilObject::_lookupObjId($ref_id)), 0, self::MAX_PAGES) as $page) {
            $text = $this->extractor()->textOfPage('wpg', $page['id']);
            if (trim($text) === '') {
                continue;
            }
            // The page title heads the text, so passages keep their page in the search
            $documents[] = SnapshotDocument::ofText(
                self::filename($wiki_title, $page['title']),
                $page['title'] . "\n\n" . $text
            );
        }
        return $documents;
    }

    /**
     * File name of a page, e.g. "Glossar › Begriffe A-Z.txt"
     */
    public static function filename(string $wiki_title, string $page_title): string
    {
        // A slash would be read as directory in the file name
        $clean = static fn(string $title): string => trim(str_replace(['/', '\\'], '-', $title));
        return $clean($wiki_title) . ' › ' . $clean($page_title) . '.txt';
    }

    /**
     * Number of active pages and newest change: a page added, removed or changed changes it
     */
    public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        if ($ref_id === null || !EnvironmentObjects::exists($ref_id)) {
            return null;
        }
        return self::fingerprint($this->pages(\ilObject::_lookupObjId($ref_id)));
    }

    /**
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

    public function isAccessible(ChatResource $resource, int $user_id): bool
    {
        return EnvironmentObjects::isReadable($resource->getSourceRefId(), $user_id);
    }

    public function getSourceLink(ChatResource $resource): ?string
    {
        $ref_id = $resource->getSourceRefId();
        return $ref_id === null ? null : \ilLink::_getStaticLink($ref_id, 'wiki');
    }

    /**
     * Active pages of a wiki in the main language, by title
     *
     * @return array<int, array{id: int, title: string, last_change: string}>
     */
    protected function pages(int $wiki_obj_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $result = $db->query(
            "SELECT p.page_id, p.last_change, w.title FROM page_object p"
            . " JOIN il_wiki_page w ON w.id = p.page_id AND w.lang = p.lang"
            . " WHERE p.parent_type = " . $db->quote('wpg', 'text')
            . " AND p.parent_id = " . $db->quote($wiki_obj_id, 'integer')
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

    private function extractor(): PageTextExtractor
    {
        return $this->extractor ??= new PageTextExtractor();
    }

    /**
     * "Wiki › Page" without the extension of the text file
     */
    public function getSourceName(ChatResource $resource, string $filename): string
    {
        return preg_replace('/\\.txt$/i', '', $filename) ?? $filename;
    }

    public function getSourceKind(): ?string
    {
        return 'wiki';
    }

    public function getSourceIcon(ChatResource $resource): ?string
    {
        $obj_id = $resource->getSourceObjId()
            ?? ($resource->getSourceRefId() !== null ? \ilObject::_lookupObjId($resource->getSourceRefId()) : 0);
        return EnvironmentObjects::iconOf((int) $obj_id, 'wiki');
    }
}
