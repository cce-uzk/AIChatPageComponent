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

namespace ai;

use platform\AIChatPageComponentException;

/**
 * The RAG rejected collections stored for a chat, e.g. because they belong to
 * another tenant; the files have to be uploaded again
 */
class AIChatPageComponentRAGBindingLostException extends AIChatPageComponentException
{
    /** @var string[] */
    private array $collection_ids;

    /**
     * @param string[] $collection_ids Rejected collections
     */
    public function __construct(array $collection_ids, string $message = 'RAG collections not accessible')
    {
        parent::__construct($message, 400);
        $this->collection_ids = $collection_ids;
    }

    /**
     * @return string[]
     */
    public function getCollectionIds(): array
    {
        return $this->collection_ids;
    }
}
