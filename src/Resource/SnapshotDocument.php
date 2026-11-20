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
 * One document of a snapshot: a text to store, or a file already in the Resource Storage
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class SnapshotDocument
{
    private function __construct(
        private string $filename,
        private ?string $text,
        private ?string $stored_id
    ) {
    }

    /**
     * @param string $filename Name of the file, shown as source, e.g. "Wiki › Page.txt"
     */
    public static function ofText(string $filename, string $text): self
    {
        return new self($filename, $text, null);
    }

    /**
     * @param string $stored_id Identification of a file in the Resource Storage (plugin stakeholder)
     */
    public static function ofStoredFile(string $filename, string $stored_id): self
    {
        return new self($filename, null, $stored_id);
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getStoredId(): ?string
    {
        return $this->stored_id;
    }
}
