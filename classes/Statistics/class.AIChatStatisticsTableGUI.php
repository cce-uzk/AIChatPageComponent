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

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use ILIAS\HTTP\Services as HttpServices;

/**
 * Statistics table listing all chats of the installation
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatStatisticsTableGUI
{
    private Factory $ui_factory;
    private Renderer $renderer;
    private HttpServices $http;
    private ilCtrlInterface $ctrl;
    private ilAIChatPageComponentPlugin $plugin;
    private object $parent_obj;

    public function __construct(object $parent_obj)
    {
        global $DIC;

        $this->ui_factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->http = $DIC->http();
        $this->ctrl = $DIC->ctrl();
        $this->plugin = ilAIChatPageComponentPlugin::getInstance();
        $this->parent_obj = $parent_obj;
    }

    /**
     * @param array<string, mixed>|null $filter_data Values of the filter bar
     */
    public function getHTML(?array $filter_data = null): string
    {
        $data_retrieval = new AIChatStatisticsDataRetrieval($this->plugin);
        $table = $this->buildTable($data_retrieval, $filter_data);

        return $this->renderer->render($table);
    }

    private function buildTable(
        AIChatStatisticsDataRetrieval $data_retrieval,
        ?array $filter_data
    ): \ILIAS\UI\Component\Table\Data {
        $f = $this->ui_factory;

        $columns = [
            'title' => $f->table()->column()->text($this->plugin->txt('stat_title'))
                ->withIsSortable(true),
            'obj_id' => $f->table()->column()->number($this->plugin->txt('stat_obj_id'))
                ->withIsSortable(true),
            'ref_id' => $f->table()->column()->number($this->plugin->txt('stat_ref_id'))
                ->withIsSortable(true),
            'created_at' => $f->table()->column()->text($this->plugin->txt('stat_created_at'))
                ->withIsSortable(true),
            'is_online' => $f->table()->column()->statusIcon($this->plugin->txt('stat_is_online'))
                ->withIsSortable(false),
            'session_count' => $f->table()->column()->number($this->plugin->txt('stat_session_count'))
                ->withIsSortable(true),
            'enable_rag' => $f->table()->column()->statusIcon($this->plugin->txt('stat_rag'))
                ->withIsSortable(false),
            'background_files_count' => $f->table()->column()->number($this->plugin->txt('stat_background_files'))
                ->withIsSortable(true),
        ];

        $table = $f->table()->data(
            $this->plugin->txt('tab_statistics'),
            $columns,
            $data_retrieval
        )->withActions($this->buildActions());

        if ($filter_data !== null) {
            $table = $table->withFilter($filter_data);
        }

        return $table->withRequest($this->http->request());
    }

    private function buildActions(): array
    {
        $f = $this->ui_factory;
        $base = ILIAS_HTTP_PATH . '/';

        $make_action = function (string $cmd) use ($base): array {
            $ub = new \ILIAS\UI\URLBuilder(
                new \ILIAS\Data\URI($base . $this->ctrl->getLinkTarget($this->parent_obj, $cmd))
            );
            [$ub, $at, $it] = $ub->acquireParameters(['pcaic', 'stat'], 'action', 'chat_id');
            return [$ub, $at, $it];
        };

        [$ub_goto,   $at_goto,   $it_goto] = $make_action('gotoPage');
        [$ub_on,     $at_on,     $it_on] = $make_action('setChatOnline');
        [$ub_off,    $at_off,    $it_off] = $make_action('setChatOffline');
        [$ub_clear,  $at_clear,  $it_clear] = $make_action('clearChatHistory');
        [$ub_delete, $at_delete, $it_delete] = $make_action('deleteChat');

        return [
            'goto' => $f->table()->action()->single(
                $this->plugin->txt('stat_goto_page'),
                $ub_goto->withParameter($at_goto, 'goto'),
                $it_goto
            ),
            'set_online' => $f->table()->action()->single(
                $this->plugin->txt('stat_toggle_online'),
                $ub_on->withParameter($at_on, 'setOnline'),
                $it_on
            ),
            'set_offline' => $f->table()->action()->single(
                $this->plugin->txt('stat_toggle_offline'),
                $ub_off->withParameter($at_off, 'setOffline'),
                $it_off
            ),
            'clear_history' => $f->table()->action()->single(
                $this->plugin->txt('stat_clear_history'),
                $ub_clear->withParameter($at_clear, 'clear'),
                $it_clear
            ),
            'delete_chat' => $f->table()->action()->single(
                $this->plugin->txt('stat_delete_chat'),
                $ub_delete->withParameter($at_delete, 'delete'),
                $it_delete
            ),
        ];
    }
}
