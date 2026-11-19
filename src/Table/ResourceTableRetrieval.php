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

namespace ILIAS\Plugin\pcaic\Table;

use Generator;
use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

/**
 * Rows of the resource table of a chat
 *
 * The remaining parameters of getRows() and getTotalRowCount() differ between ILIAS versions
 * (filter and additional parameters in ILIAS 9, also view control data from ILIAS 10); they are
 * not used here and taken as variadic parameter, which is compatible with all of them.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ResourceTableRetrieval implements DataRetrieval
{
    /**
     * @param array<string, array<string, mixed>> $records Row ID => record
     * @param array<string, string[]> $disabled_actions Row ID => actions not available for the row
     */
    public function __construct(
        private array $records,
        private array $disabled_actions = []
    ) {
    }

    public function getRows(
        DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        mixed ...$unused
    ): Generator {
        $records = array_slice($this->records, $range->getStart(), $range->getLength(), true);
        foreach ($records as $row_id => $record) {
            $row = $row_builder->buildDataRow((string) $row_id, $record);
            foreach ($this->disabled_actions[$row_id] ?? [] as $action) {
                $row = $row->withDisabledAction($action);
            }
            yield $row;
        }
    }

    public function getTotalRowCount(mixed ...$unused): ?int
    {
        return count($this->records);
    }
}
