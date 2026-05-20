<?php declare(strict_types=1);

use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;
use ILIAS\Data\Range;
use ILIAS\Data\Order;

/**
 * Data retrieval for the AIChatPageComponent statistics table.
 *
 * Provides all chat instances on the installation with aggregated metrics.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatStatisticsDataRetrieval implements DataRetrieval
{
    private ilAIChatPageComponentPlugin $plugin;

    public function __construct(ilAIChatPageComponentPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function getRows(
        DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): Generator {
        $rows = $this->queryData($filter_data ?? []);

        // Sorting
        [$sort_field, $sort_direction] = $order->join(
            [null, null],
            fn($carry, $field, $dir) => [$field, $dir]
        );
        if ($sort_field !== null) {
            // 'created_at' displays a formatted string – sort on the raw timestamp instead
            $sort_key = ($sort_field === 'created_at') ? 'created_at_ts' : $sort_field;
            usort($rows, function (array $a, array $b) use ($sort_key, $sort_direction): int {
                $result = $a[$sort_key] <=> $b[$sort_key];
                return $sort_direction === 'DESC' ? -$result : $result;
            });
        }

        // Pagination
        $rows = array_slice($rows, $range->getStart(), $range->getLength());

        foreach ($rows as $row) {
            $data_row = $row_builder->buildDataRow($row['chat_id'], [
                'title'                  => $row['title'],
                'obj_id'                 => $row['obj_id'],
                'ref_id'                 => $row['ref_id'],
                'created_at'             => $row['created_at'],
                'is_online'              => $row['is_online_icon'],
                'session_count'          => $row['session_count'],
                'enable_rag'             => $row['enable_rag_icon'],
                'background_files_count' => $row['background_files_count'],
            ]);

            // Disable the action that makes no sense for the current status
            if ($row['is_online']) {
                $data_row = $data_row->withDisabledAction('set_online');
            } else {
                $data_row = $data_row->withDisabledAction('set_offline');
            }

            yield $data_row;
        }
    }

    public function getTotalRowCount(?array $filter_data, ?array $additional_parameters): ?int
    {
        global $DIC;
        $db = $DIC->database();

        $where = $this->buildWhereClause($filter_data ?? [], $db);

        $sql = "
            SELECT COUNT(DISTINCT c.chat_id) AS cnt
            FROM pcaic_chats c
            LEFT JOIN (
                SELECT MIN(ref_id) AS ref_id, obj_id
                FROM object_reference
                GROUP BY obj_id
            ) orf ON orf.obj_id = c.parent_id
            $where
        ";

        $result = $db->query($sql);
        $row    = $db->fetchAssoc($result);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Load all chats with aggregated session and background-file counts,
     * optionally filtered by title, obj_id, or ref_id.
     *
     * @param  array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    private function queryData(array $filter): array
    {
        global $DIC;
        $db  = $DIC->database();
        $ui  = $DIC->ui()->factory();

        $where = $this->buildWhereClause($filter, $db);

        $query = "
            SELECT
                c.chat_id,
                c.title,
                c.created_at,
                c.parent_id,
                c.enable_rag,
                COALESCE(c.is_online, 1)     AS is_online,
                COALESCE(orf.ref_id, 0)      AS ref_id,
                COUNT(DISTINCT s.session_id) AS session_count,
                COUNT(DISTINCT a.id)         AS background_files_count
            FROM pcaic_chats c
            LEFT JOIN (
                SELECT MIN(ref_id) AS ref_id, obj_id
                FROM object_reference
                GROUP BY obj_id
            ) orf ON orf.obj_id = c.parent_id
            LEFT JOIN pcaic_sessions    s ON s.chat_id = c.chat_id
            LEFT JOIN pcaic_attachments a ON a.chat_id = c.chat_id AND a.background_file = 1
            $where
            GROUP BY c.chat_id, c.title, c.created_at, c.parent_id, c.enable_rag, c.is_online, orf.ref_id
            ORDER BY c.title ASC
        ";

        $result = $db->query($query);
        $rows   = [];

        while ($row = $db->fetchAssoc($result)) {
            // Formatted display string and raw timestamp for correct sorting
            $created_at    = '';
            $created_at_ts = 0;
            if (!empty($row['created_at'])) {
                try {
                    $dt            = new \DateTime($row['created_at']);
                    $created_at    = $dt->format('d.m.Y H:i');
                    $created_at_ts = $dt->getTimestamp();
                } catch (\Exception $e) {
                    $created_at = (string) $row['created_at'];
                }
            }

            $is_rag      = (bool) $row['enable_rag'];
            $rag_icon    = $ui->symbol()->icon()->custom(
                ilUtil::getImagePath($is_rag ? 'standard/icon_ok.svg' : 'standard/icon_not_ok.svg'),
                $is_rag ? $this->plugin->txt('stat_yes') : $this->plugin->txt('stat_no'),
                \ILIAS\UI\Component\Symbol\Icon\Icon::SMALL
            );

            $is_online   = (bool) $row['is_online'];
            $online_icon = $ui->symbol()->icon()->custom(
                ilUtil::getImagePath($is_online ? 'standard/icon_ok.svg' : 'standard/icon_not_ok.svg'),
                $is_online ? $this->plugin->txt('stat_yes') : $this->plugin->txt('stat_no'),
                \ILIAS\UI\Component\Symbol\Icon\Icon::SMALL
            );

            $rows[] = [
                'chat_id'                => (string) $row['chat_id'],
                'title'                  => $row['title'] ?: '(' . $this->plugin->txt('stat_no_title') . ')',
                'obj_id'                 => (int) $row['parent_id'],
                'ref_id'                 => (int) $row['ref_id'],
                'created_at'             => $created_at,
                'created_at_ts'          => $created_at_ts,
                'session_count'          => (int) $row['session_count'],
                'enable_rag_icon'        => $rag_icon,
                'is_online'              => $is_online,
                'is_online_icon'         => $online_icon,
                'background_files_count' => (int) $row['background_files_count'],
            ];
        }

        return $rows;
    }

    /**
     * Build SQL WHERE clause from filter data.
     * Handles title (LIKE), obj_id (exact), ref_id (via object_reference subquery).
     */
    private function buildWhereClause(array $filter, \ilDBInterface $db): string
    {
        $conditions = [];

        $title = trim((string) ($filter['title'] ?? ''));
        if ($title !== '') {
            $conditions[] = 'c.title LIKE ' . $db->quote('%' . $title . '%', 'text');
        }

        $obj_id = (int) ($filter['obj_id'] ?? 0);
        if ($obj_id > 0) {
            $conditions[] = 'c.parent_id = ' . $db->quote($obj_id, 'integer');
        }

        $ref_id = (int) ($filter['ref_id'] ?? 0);
        if ($ref_id > 0) {
            $conditions[] = 'c.parent_id IN ('
                . 'SELECT obj_id FROM object_reference WHERE ref_id = '
                . $db->quote($ref_id, 'integer')
                . ')';
        }

        return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
    }
}
