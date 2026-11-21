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

namespace ILIAS\Plugin\pcaic\Tests;

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatResource;
use ILIAS\Plugin\pcaic\Resource\PageTextExtractor;
use ILIAS\Plugin\pcaic\Resource\Provider\PageContextProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\UploadedFileProvider;
use ILIAS\Plugin\pcaic\Resource\ResourceAccessFilter;
use ILIAS\Plugin\pcaic\Resource\ResourceContext;
use ILIAS\Plugin\pcaic\Resource\ResourceProvider;
use ILIAS\Plugin\pcaic\Resource\ResourceProviderRegistry;
use ILIAS\Plugin\pcaic\Resource\SnapshotDocument;
use ILIAS\Plugin\pcaic\Resource\SourceStateService;
use ILIAS\Plugin\pcaic\Resource\Provider\ContainerFileProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\WikiProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\PageObjectProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\LearningModuleProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\ContentPageProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Kinds of resources behind one interface: registry, permission filter, page text, snapshots
 */
class ResourceProviderTest extends TestCase
{
    private static function resource(int $id, string $type): ChatResource
    {
        return ChatResource::fromRow(['id' => $id, 'chat_id' => 'chat_1', 'type' => $type]);
    }

    /**
     * A kind of the environment that only the person with ID 7 may use
     */
    private static function objectProvider(): ResourceProvider
    {
        return new class () implements ResourceProvider {
            public function getType(): string
            {
                return 'obj_test';
            }

            public function requiresRag(): bool
            {
                return true;
            }

            public function getCandidates(ResourceContext $context): array
            {
                return [];
            }

            public function createSnapshot(ResourceContext $context, ChatResource $resource): array
            {
                return [];
            }

            public function getSourceVersion(ResourceContext $context, ChatResource $resource): ?string
            {
                return null;
            }

            public function isAccessible(ChatResource $resource, int $user_id): bool
            {
                return $user_id === 7;
            }

            public function getSourceLink(ChatResource $resource): ?string
            {
                return null;
            }

            public function getSourceName(ChatResource $resource, string $filename): string
            {
                return $filename;
            }

            public function getSourceKind(): ?string
            {
                return null;
            }

            public function getSourceIcon(ChatResource $resource): ?string
            {
                return null;
            }

            public function getSourceOffer(): string
            {
                return self::OFFER_NONE;
            }
        };
    }

    public function testRegistryKnowsTheKindsAndHidesThoseThatNeedTheRag(): void
    {
        $registry = new ResourceProviderRegistry([new UploadedFileProvider(), new PageContextProvider(), self::objectProvider()]);

        $this->assertInstanceOf(PageContextProvider::class, $registry->get(ChatResource::TYPE_PAGE_CONTEXT));
        $this->assertNull($registry->get('unknown'));
        $this->assertCount(3, $registry->all());

        $types = fn(array $providers): array => array_map(fn(ResourceProvider $p): string => $p->getType(), $providers);
        $this->assertSame(['file', 'page_context', 'obj_test'], $types($registry->available(true)));
        $this->assertSame(['file', 'page_context'], $types($registry->available(false)));

        // The default registry: the two kinds without RAG, the files of the environment with it
        $this->assertSame(['file', 'page_context', 'obj_file', 'obj_wiki', 'obj_copa', 'obj_lm'], $types(ResourceProviderRegistry::default()->all()));
        $this->assertSame(['file', 'page_context'], $types(ResourceProviderRegistry::default()->available(false)));
    }

    public function testResourcesThePersonMayNotReadAreLeftOut(): void
    {
        $filter = new ResourceAccessFilter(new ResourceProviderRegistry([new UploadedFileProvider(), new PageContextProvider(), self::objectProvider()]));
        $resources = [
            self::resource(1, ChatResource::TYPE_FILE),
            self::resource(2, ChatResource::TYPE_PAGE_CONTEXT),
            self::resource(3, 'obj_test'),
            // A kind this version does not know (e.g. after a downgrade): never used
            self::resource(4, 'obj_unknown'),
        ];

        $this->assertSame([3, 4], $filter->excludedIds($resources, 42));
        $this->assertSame([4], $filter->excludedIds($resources, 7));
    }

    public function testExcludedResourcesLeaveTheUsableFiles(): void
    {
        $this->assertSame(
            '(resource_ref IS NULL OR resource_ref IN (SELECT id FROM pcaic_resources WHERE enabled = 1))',
            ChatResource::usableAttachmentCondition()
        );
        $this->assertSame(
            '(a.resource_ref IS NULL OR a.resource_ref IN (SELECT id FROM pcaic_resources WHERE enabled = 1 AND id NOT IN (3,4)))',
            ChatResource::usableAttachmentCondition('a.', [3, '4', 3])
        );
    }

    public function testPageTextIsTheTextOfItsParagraphs(): void
    {
        $xml = '<PageObject><PageContent><Paragraph Characteristic="Headline1">Die Mitternacht</Paragraph></PageContent>'
            . '<PageContent><Paragraph>Wenn alle schlafen,</Paragraph><Paragraph>  </Paragraph></PageContent>'
            . '<PageContent><Plugged PluginName="AIChatPageComponent"/></PageContent></PageObject>';

        $this->assertSame("Die Mitternacht\n\nWenn alle schlafen,", PageTextExtractor::textOfXml($xml));
        $this->assertSame('', PageTextExtractor::textOfXml(''));
        $this->assertSame('', PageTextExtractor::textOfXml('<broken'));
        $this->assertSame('cont', PageTextExtractor::copageTypeOf('crs'));
        $this->assertSame('wpg', PageTextExtractor::copageTypeOf('wiki'));
        $this->assertNull(PageTextExtractor::copageTypeOf('xyz'));
    }

    public function testPageContentProviderTakesTheTextAndLastChangeOfThePage(): void
    {
        $extractor = new class () extends PageTextExtractor {
            public string $text = '';

            public function textOfChatPage(ChatConfig $chat): string
            {
                return $this->text;
            }

            public function lastChangeOfChatPage(ChatConfig $chat): ?string
            {
                return '2026-11-20 10:00:00';
            }
        };
        $provider = new PageContextProvider($extractor);
        $chat = (new ReflectionClass(ChatConfig::class))->newInstanceWithoutConstructor();
        $context = new ResourceContext($chat, 86, 6);
        $resource = self::resource(1, ChatResource::TYPE_PAGE_CONTEXT);

        $extractor->text = "Die Mitternacht der Gummibärchen";
        $documents = $provider->createSnapshot($context, $resource);
        $this->assertCount(1, $documents);
        $this->assertSame(ChatResource::PAGE_CONTEXT_FILENAME, $documents[0]->getFilename());
        $this->assertSame('Die Mitternacht der Gummibärchen', $documents[0]->getText());
        $this->assertNull($documents[0]->getStoredId());
        $this->assertSame('2026-11-20 10:00:00', $provider->getSourceVersion($context, $resource));

        // A page without text gives no snapshot, the resource stays as it is
        $extractor->text = "  \n ";
        $this->assertSame([], $provider->createSnapshot($context, $resource));

        $this->assertFalse($provider->requiresRag());
        $this->assertTrue($provider->isAccessible($resource, 42));
    }

    public function testSnapshotDocumentsAreTextOrStoredFile(): void
    {
        $file = SnapshotDocument::ofStoredFile('Skript.pdf', 'abc-123');
        $this->assertSame('abc-123', $file->getStoredId());
        $this->assertNull($file->getText());

        $text = SnapshotDocument::ofText('Wiki › Start.txt', 'Inhalt');
        $this->assertSame('Inhalt', $text->getText());
        $this->assertSame('Wiki › Start.txt', $text->getFilename());
    }

    public function testNewColumnsSurviveTheRow(): void
    {
        $resource = ChatResource::fromRow([
            'id' => 9, 'chat_id' => 'chat_1', 'type' => 'obj_test',
            'source_ref_id' => 120, 'source_obj_id' => 340, 'source_version' => '7',
            'options' => '{"pages":[1,4]}', 'source_state' => 'changed', 'env_ref_id' => 86,
        ]);
        $this->assertSame(340, $resource->getSourceObjId());
        $this->assertSame('7', $resource->getSourceVersion());
        $this->assertSame(['pages' => [1, 4]], $resource->getOptions());
        $this->assertSame(ChatResource::STATE_CHANGED, $resource->getSourceState());
        $this->assertSame(86, $resource->getEnvRefId());

        // Rows of earlier versions and unknown states
        $old = ChatResource::fromRow(['id' => 1, 'chat_id' => 'c', 'type' => 'file', 'source_state' => 'bogus']);
        $this->assertSame([], $old->getOptions());
        $this->assertNull($old->getSourceState());
        $this->assertNull($old->getEnvRefId());
    }

    private static function objectResource(array $row): ChatResource
    {
        return ChatResource::fromRow($row + ['id' => 5, 'chat_id' => 'chat_1', 'type' => 'obj_file', 'source_ref_id' => 120]);
    }

    public function testStateOfTheSourceIsShownNeverActedUponAlone(): void
    {
        // Object of the environment
        $file = self::objectResource(['source_version' => 'v2', 'env_ref_id' => 86]);
        $this->assertSame(ChatResource::STATE_OK, SourceStateService::determine($file, 'v2', 86));
        $this->assertSame(ChatResource::STATE_CHANGED, SourceStateService::determine($file, 'v3', 86));
        $this->assertSame(ChatResource::STATE_MISSING, SourceStateService::determine($file, null, 86));
        $this->assertSame(ChatResource::STATE_OUTSIDE, SourceStateService::determine($file, 'v3', 99));
        // Environment unknown (e.g. no ref_id in the call): not judged as moved
        $this->assertSame(ChatResource::STATE_OK, SourceStateService::determine($file, 'v2', null));

        // Page content: no object, only the fingerprint of the page
        $page = ChatResource::fromRow(['id' => 2, 'chat_id' => 'chat_1', 'type' => 'page_context', 'source_version' => '2026-11-20 10:00:00']);
        $this->assertSame(ChatResource::STATE_CHANGED, SourceStateService::determine($page, '2026-11-21 08:00:00', 99));
        $this->assertSame(ChatResource::STATE_OK, SourceStateService::determine($page, '2026-11-20 10:00:00', 99));
        // Snapshots from before fingerprints existed are not judged
        $old_page = ChatResource::fromRow(['id' => 3, 'chat_id' => 'chat_1', 'type' => 'page_context']);
        $this->assertNull(SourceStateService::determine($old_page, '2026-11-21 08:00:00', 86));

        // Uploads have no source
        $this->assertNull(SourceStateService::determine(self::resource(1, ChatResource::TYPE_FILE), null, 86));
    }

    public function testGoneOrMovedSourcesAreNotUsedEvenIfReadable(): void
    {
        $readable = new class () extends UploadedFileProvider {
            public function getType(): string
            {
                return 'obj_file';
            }
        };
        $filter = new ResourceAccessFilter(new ResourceProviderRegistry([$readable]));
        $resources = [
            self::objectResource(['id' => 1, 'source_state' => 'ok']),
            self::objectResource(['id' => 2, 'source_state' => 'changed']),
            self::objectResource(['id' => 3, 'source_state' => 'missing']),
            self::objectResource(['id' => 4, 'source_state' => 'outside']),
        ];
        $this->assertSame([3, 4], $filter->excludedIds($resources, 6));
    }

    public function testFilesOfTheEnvironment(): void
    {
        $this->assertSame('Woche 1 › Unterlagen › Folien.pdf', ContainerFileProvider::label(['Woche 1', 'Unterlagen'], 'Folien.pdf'));
        $this->assertSame('Folien.pdf', ContainerFileProvider::label([], 'Folien.pdf'));
        $this->assertTrue((new ContainerFileProvider())->requiresRag());
        // File objects without content are not offered (ILIAS returns "" or "-", depending on the version)
        $this->assertFalse(ContainerFileProvider::hasContent(''));
        $this->assertFalse(ContainerFileProvider::hasContent('-'));
        $this->assertTrue(ContainerFileProvider::hasContent('c7e5783d-471f-4f2e-9466-92daa7fc69a3'));

        // What the source list offers is declared by each kind, not by the core
        $registry = ResourceProviderRegistry::default();
        $offers = [];
        foreach ($registry->all() as $provider) {
            $offers[$provider->getType()] = $provider->getSourceOffer();
        }
        $this->assertSame([
            'file' => ResourceProvider::OFFER_DOWNLOAD,
            'page_context' => ResourceProvider::OFFER_NONE,
            'obj_file' => ResourceProvider::OFFER_LINK,
            'obj_wiki' => ResourceProvider::OFFER_LINK,
            'obj_copa' => ResourceProvider::OFFER_LINK,
            'obj_lm' => ResourceProvider::OFFER_LINK,
        ], $offers);
        $this->assertSame(ResourceProvider::OFFER_LINK, $registry->offerOf(self::objectResource([])));
        // Kinds this version does not know offer nothing
        $this->assertSame(ResourceProvider::OFFER_NONE, $registry->offerOf(self::resource(9, 'obj_unknown')));
    }

    public function testWholeWikisOneTextFilePerPage(): void
    {
        $extractor = new class () extends PageTextExtractor {
            public function textOfPage(string $copage_type, int $page_id): string
            {
                return $page_id === 3 ? '' : "Text of page $page_id";
            }
        };
        $provider = new class ($extractor) extends WikiProvider {
            public array $pages = [];

            protected function pages(int $wiki_obj_id): array
            {
                return $this->pages;
            }
        };
        $provider->pages = [
            ['id' => 1, 'title' => 'Begriffe A/B', 'last_change' => '2026-11-18 10:00:00'],
            ['id' => 2, 'title' => 'Start', 'last_change' => '2026-11-20 09:00:00'],
            ['id' => 3, 'title' => 'Leer', 'last_change' => '2026-11-19 09:00:00'],
        ];

        // Fingerprint: number of pages and newest change, so adding or removing a page counts too
        $this->assertSame('3|2026-11-20 09:00:00', WikiProvider::fingerprint($provider->pages));
        $this->assertSame('0|', WikiProvider::fingerprint([]));

        // File names name the page; a slash is no directory
        $this->assertSame('Glossar › Begriffe A-B.txt', PageObjectProvider::filename(['Glossar', 'Begriffe A/B']));

        $this->assertTrue($provider->requiresRag());
        $this->assertSame(500, WikiProvider::MAX_PAGES);
    }

    public function testSourcesAreNamedByTheirKind(): void
    {
        $wiki = ChatResource::fromRow(['id' => 1, 'chat_id' => 'c', 'type' => 'obj_wiki', 'title' => 'Mein Wiki']);
        $this->assertSame('Mein Wiki › Elche', (new WikiProvider())->getSourceName($wiki, 'Mein Wiki › Elche.txt'));
        $this->assertSame('wiki', (new WikiProvider())->getSourceKind());

        $page = ChatResource::fromRow(['id' => 2, 'chat_id' => 'c', 'type' => 'page_context', 'title' => 'Inhalt dieser Seite']);
        $this->assertSame('Inhalt dieser Seite', (new PageContextProvider())->getSourceName($page, 'page_content.txt'));
        $this->assertSame('page', (new PageContextProvider())->getSourceKind());

        // Files keep their names, the icon follows the extension
        $file = self::resource(3, ChatResource::TYPE_FILE);
        $this->assertSame('Skript.pdf', (new UploadedFileProvider())->getSourceName($file, 'Skript.pdf'));
        $this->assertNull((new ContainerFileProvider())->getSourceKind());
    }

    public function testLearningModulePagesInReadingOrderWithTheirChapters(): void
    {
        // Preorder of lm_tree: root, chapter 1 with a sub-chapter, chapter 2
        $rows = [
            ['id' => 1, 'depth' => 1, 'type' => 'du', 'title' => 'dummy'],
            ['id' => 10, 'depth' => 2, 'type' => 'st', 'title' => 'Grundlagen'],
            ['id' => 11, 'depth' => 3, 'type' => 'pg', 'title' => 'Was ist RAG?'],
            ['id' => 12, 'depth' => 3, 'type' => 'st', 'title' => 'Vertiefung'],
            ['id' => 13, 'depth' => 4, 'type' => 'pg', 'title' => 'Embeddings'],
            ['id' => 14, 'depth' => 3, 'type' => 'pg', 'title' => 'Entwurf'],
            ['id' => 20, 'depth' => 2, 'type' => 'st', 'title' => 'Praxis'],
            ['id' => 21, 'depth' => 3, 'type' => 'pg', 'title' => 'Übung'],
        ];
        // Page 14 is deactivated
        $active = [11 => '2026-11-20 10:00:00', 13 => '2026-11-21 09:00:00', 21 => '2026-11-19 08:00:00'];

        $pages = LearningModuleProvider::pagesInOrder($rows, $active);
        $this->assertSame([11, 13, 21], array_column($pages, 'id'));
        $this->assertSame(['Grundlagen'], $pages[0]['path']);
        $this->assertSame(['Grundlagen', 'Vertiefung'], $pages[1]['path']);
        // A later chapter replaces the earlier ones of its level
        $this->assertSame(['Praxis'], $pages[2]['path']);

        $this->assertSame('KI-Modul › Grundlagen › Vertiefung › Embeddings.txt',
            PageObjectProvider::filename(['KI-Modul', ...$pages[1]['path'], $pages[1]['title']]));
        $this->assertSame('3|2026-11-21 09:00:00', PageObjectProvider::fingerprint($pages));
    }

    public function testContentPagesAndLearningModulesNeedTheRagAndArePages(): void
    {
        foreach ([new ContentPageProvider(), new LearningModuleProvider()] as $provider) {
            $this->assertTrue($provider->requiresRag());
            $this->assertSame('page', $provider->getSourceKind());
        }
        $copa = ChatResource::fromRow(['id' => 1, 'chat_id' => 'c', 'type' => 'obj_copa']);
        $this->assertSame('Hinweise zur Prüfung', (new ContentPageProvider())->getSourceName($copa, 'Hinweise zur Prüfung.txt'));
        // A content page has one page without title: the file is named after the object
        $this->assertSame('Hinweise zur Prüfung.txt', PageObjectProvider::filename(['Hinweise zur Prüfung']));
    }
}
