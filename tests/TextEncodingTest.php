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

use ILIAS\Plugin\pcaic\Model\Attachment;
use PHPUnit\Framework\TestCase;

/**
 * Tests the conversion of text file content to UTF-8
 */
class TextEncodingTest extends TestCase
{
    public function testUtf8IsKeptUnchanged(): void
    {
        $this->assertSame('Prüfung;Größe', Attachment::toUtf8('Prüfung;Größe'));
    }

    public function testWindows1252IsConverted(): void
    {
        $windows_1252 = "Pr\xFCfung;Gr\xF6\xDFe;\x80";

        $this->assertSame('Prüfung;Größe;€', Attachment::toUtf8($windows_1252));
        $this->assertNotFalse(json_encode(Attachment::toUtf8($windows_1252)));
    }

    public function testByteOrderMarkIsRemoved(): void
    {
        $this->assertSame('Text', Attachment::toUtf8("\xEF\xBB\xBFText"));
    }
}
