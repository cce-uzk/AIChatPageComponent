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

use ILIAS\Plugin\pcaic\Model\ChatConfig;

/**
 * Visible text of ILIAS pages (COPage), shared by all kinds of resources made of pages
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class PageTextExtractor
{
    /** Page type of the page manager for the parent type of a chat */
    private const COPAGE_TYPES = [
        'crs' => 'cont',
        'grp' => 'cont',
        'cont' => 'cont',
        'cat' => 'cont',
        'lm' => 'lm',
        'wpg' => 'wpg',
        'wiki' => 'wpg',
        'copa' => 'copa',
        'glo' => 'glo',
        'blp' => 'blp',
        'frm' => 'frm',
        'tst' => 'tst',
        'qpl' => 'qpl',
    ];

    public static function copageTypeOf(string $parent_type): ?string
    {
        return self::COPAGE_TYPES[$parent_type] ?? null;
    }

    /**
     * Text of the paragraphs of a page, separated by blank lines
     */
    public static function textOfXml(string $page_xml): string
    {
        if (trim($page_xml) === '') {
            return '';
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($page_xml)) {
            return '';
        }
        $parts = [];
        foreach ((new \DOMXPath($dom))->query('//Paragraph') as $paragraph) {
            $text = trim($paragraph->textContent);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Text of the page containing the chat
     */
    public function textOfChatPage(ChatConfig $chat): string
    {
        $page = $this->chatPage($chat);
        return $page === null ? '' : self::textOfXml((string) $page->getXMLContent());
    }

    /**
     * Last change of the page containing the chat, as fingerprint of the page content
     */
    public function lastChangeOfChatPage(ChatConfig $chat): ?string
    {
        $page = $this->chatPage($chat);
        if ($page === null || !method_exists($page, 'getLastChange')) {
            return null;
        }
        $last_change = (string) $page->getLastChange();
        return $last_change === '' ? null : $last_change;
    }

    /**
     * @return \ilPageObject|null
     */
    protected function chatPage(ChatConfig $chat)
    {
        $page_id = (int) $chat->getPageId();
        $parent_id = (int) $chat->getParentId();
        $copage_type = self::copageTypeOf((string) $chat->getParentType());
        if ((!$page_id && !$parent_id) || $copage_type === null) {
            return null;
        }

        try {
            global $DIC;
            $page_manager = $DIC->copage()->internal()->domain()->page();
            // Wiki pages are addressed by their own ID, all other pages by their parent object
            return $copage_type === 'wpg' && $page_id > 0
                ? $page_manager->get('wpg', $page_id)
                : $page_manager->get($copage_type, $parent_id);
        } catch (\Throwable $e) {
            global $DIC;
            $DIC->logger()->pcaic()->warning("Page of chat " . $chat->getChatId() . " not readable: " . $e->getMessage());
            return null;
        }
    }
}
