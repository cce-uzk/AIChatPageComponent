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

use ILIAS\Plugin\pcaic\Validation\FileTypePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Upload and target of files by the lists of ILIAS (G), the AI service (S) and the RAG (R)
 */
class FileTypePolicyTest extends TestCase
{
    private function policy(
        ?array $whitelist = ['pdf', 'txt', 'csv', 'png', 'jpg', 'html'],
        array $blacklist = [],
        array $service = ['pdf', 'txt', 'csv', 'png', 'jpg'],
        array $rag = ['pdf', 'txt', 'html']
    ): FileTypePolicy {
        return new FileTypePolicy($whitelist, $blacklist, $service, $rag);
    }

    public function testRagTypesGoToTheRagOnlyWhileRagIsActive(): void
    {
        $policy = $this->policy();

        // In S and R: RAG only, not also directly
        $this->assertSame(FileTypePolicy::TARGET_RAG, $policy->target('pdf', true));
        $this->assertSame(FileTypePolicy::TARGET_DIRECT, $policy->target('pdf', false));

        // In S only
        $this->assertSame(FileTypePolicy::TARGET_DIRECT, $policy->target('png', true));
        $this->assertSame(FileTypePolicy::TARGET_DIRECT, $policy->target('csv', false));

        // In R only: not used without RAG
        $this->assertSame(FileTypePolicy::TARGET_RAG, $policy->target('html', true));
        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('html', false));
    }

    public function testIliasListsLimitEverything(): void
    {
        // Not in the positive list of ILIAS
        $policy = $this->policy(['pdf', 'txt']);
        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('png', false));
        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('html', true));
        $this->assertSame(FileTypePolicy::TARGET_RAG, $policy->target('pdf', true));

        // The negative list wins
        $policy = $this->policy(null, ['pdf']);
        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('pdf', true));
        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('pdf', false));

        // No positive list: not restricted by ILIAS
        $policy = $this->policy(null);
        $this->assertSame(FileTypePolicy::TARGET_DIRECT, $policy->target('png', false));
    }

    public function testUploadTypes(): void
    {
        $policy = $this->policy(['pdf', 'txt', 'png', 'html']);

        $without_rag = $policy->uploadTypes(false);
        sort($without_rag);
        $this->assertSame(['pdf', 'png', 'txt'], $without_rag);

        $with_rag = $policy->uploadTypes(true);
        sort($with_rag);
        $this->assertSame(['html', 'pdf', 'png', 'txt'], $with_rag);

        $this->assertTrue($policy->isUploadAllowed('html', true));
        $this->assertFalse($policy->isUploadAllowed('html', false));
        $this->assertFalse($policy->isUploadAllowed('', true));
    }

    public function testServiceListIsLimitedToWhatThePluginCanSendButTheRagListIsNot(): void
    {
        // docx cannot be sent directly; the RAG list is taken as configured (the RAG service may accept more types later)
        $policy = $this->policy(null, [], ['pdf', 'docx'], ['pdf', 'csv', 'docx']);

        $this->assertSame(FileTypePolicy::TARGET_NONE, $policy->target('docx', false));
        $this->assertSame(FileTypePolicy::TARGET_RAG, $policy->target('docx', true));
        $this->assertSame(FileTypePolicy::TARGET_RAG, $policy->target('csv', true));
        $this->assertSame(['pdf', 'csv', 'docx'], $policy->ragTypes());
    }

    public function testNormalize(): void
    {
        $this->assertSame(['pdf', 'txt'], FileTypePolicy::normalize(' .PDF , txt,,pdf'));
        $this->assertSame(['png', 'jpg'], FileTypePolicy::normalize(['PNG', ' jpg ']));
        $this->assertSame(['txt', 'csv'], FileTypePolicy::normalize('["txt","csv"]'));
        $this->assertSame([], FileTypePolicy::normalize(null));
    }

    public function testFingerprintFollowsTheRagTypesOnly(): void
    {
        $base = $this->policy()->ragTypesFingerprint();

        // Other service list or other order: same RAG types
        $this->assertSame($base, $this->policy(service: ['png'])->ragTypesFingerprint());
        $this->assertSame($base, $this->policy(rag: ['html', 'txt', 'pdf'])->ragTypesFingerprint());

        // Other RAG types, also by the list of ILIAS
        $this->assertNotSame($base, $this->policy(rag: ['pdf'])->ragTypesFingerprint());
        $this->assertNotSame($base, $this->policy(['pdf', 'txt'])->ragTypesFingerprint());
    }
}
