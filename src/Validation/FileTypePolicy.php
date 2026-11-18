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

namespace ILIAS\Plugin\pcaic\Validation;

/**
 * Which file types may be uploaded and where a file goes
 *
 * Three lists decide, by file extension:
 * - G: the file types ILIAS allows at all (Administration > Files: positive list without the negative list)
 * - S: the file types that may be sent to the AI service directly (setting of the service)
 * - R: the file types that may be sent to the RAG service (setting of the RAG service)
 *
 * Upload: (S ∪ R) ∩ G with RAG, S ∩ G without RAG.
 *
 * Target of a file, at upload and each time it is sent, also for files uploaded before:
 * 1. RAG active and extension in R ∩ G: RAG only (searched, not sent directly)
 * 2. otherwise, extension in S ∩ G: sent to the AI service directly
 * 3. otherwise: not used
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class FileTypePolicy
{
    public const TARGET_RAG = 'rag';
    public const TARGET_DIRECT = 'direct';
    public const TARGET_NONE = 'none';

    /** Types the plugin can pass to an AI service directly: as text, as image or as page images (PDF) */
    public const DIRECT_SUPPORTED = ['txt', 'csv', 'md', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Default of the list of a service */
    public const DEFAULT_SERVICE_TYPES = ['txt', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

    /** @var string[]|null Null: ILIAS sets no positive list (or it could not be read) */
    private ?array $global_whitelist;

    /** @var string[] */
    private array $global_blacklist;

    /** @var string[] */
    private array $service_types;

    /** @var string[] */
    private array $rag_types;

    /**
     * @param string[]|null $global_whitelist
     * @param string[] $global_blacklist
     * @param string[] $service_types
     * @param string[] $rag_types
     */
    public function __construct(?array $global_whitelist, array $global_blacklist, array $service_types, array $rag_types)
    {
        $this->global_whitelist = $global_whitelist === null ? null : self::normalize($global_whitelist);
        $this->global_blacklist = self::normalize($global_blacklist);
        $this->service_types = array_values(array_intersect(self::normalize($service_types), self::DIRECT_SUPPORTED));
        // Not limited in the code: which types the RAG service accepts is a setting, it may change
        $this->rag_types = self::normalize($rag_types);
    }

    /**
     * Policy with the current settings of ILIAS, the AI service and the RAG service
     */
    public static function forService(string $service_id): self
    {
        [$whitelist, $blacklist] = self::readGlobalLists();
        return new self($whitelist, $blacklist, self::getServiceTypes($service_id), self::getConfiguredRagTypes());
    }

    /**
     * Configured list of a service; services saved before the list existed use the former
     * list of the general configuration, so that an update changes nothing
     *
     * @return string[]
     */
    public static function getServiceTypes(string $service_id): array
    {
        $configured = \platform\AIChatPageComponentConfig::get($service_id . '_allowed_file_types');
        if ($configured === null || $configured === '' || $configured === []) {
            $legacy = \platform\AIChatPageComponentConfig::get('file_upload_restrictions');
            $configured = is_array($legacy) && !empty($legacy['allowed_file_types'])
                ? $legacy['allowed_file_types']
                : self::DEFAULT_SERVICE_TYPES;
        }

        return array_values(array_intersect(self::normalize($configured), self::DIRECT_SUPPORTED));
    }

    /**
     * Configured list of the RAG service
     *
     * @return string[]
     */
    public static function getConfiguredRagTypes(): array
    {
        return self::normalize(\ai\AIChatPageComponentRAG::getFileTypes());
    }

    /**
     * Positive and negative list of the file types of ILIAS
     *
     * @return array{0: string[]|null, 1: string[]}
     */
    private static function readGlobalLists(): array
    {
        try {
            global $DIC;
            $settings = $DIC->fileServiceSettings();
            // Independent of the user: the target of a stored file must not depend on who sends the chat
            // request or runs the cron job, so the right to bypass the negative list is not considered
            $whitelist = $settings->getWhiteListedSuffixes();
            return [empty($whitelist) ? null : $whitelist, $settings->getBlackListedSuffixes()];
        } catch (\Throwable $e) {
            // Not restricted by ILIAS rather than losing every file, e.g. if the file settings are not initialised
            try {
                global $DIC;
                $DIC->logger()->pcaic()->warning("File types of ILIAS could not be read: " . $e->getMessage());
            } catch (\Throwable $ignored) {
            }
            return [null, []];
        }
    }

    /**
     * Lower-case extensions without dots and duplicates, from a comma-separated string or a list
     *
     * @param string|string[]|null $types
     * @return string[]
     */
    public static function normalize($types): array
    {
        if ($types === null) {
            return [];
        }
        if (is_string($types)) {
            $decoded = json_decode($types, true);
            $types = is_array($decoded) ? $decoded : explode(',', $types);
        }
        if (!is_array($types)) {
            return [];
        }

        $normalized = [];
        foreach ($types as $type) {
            $type = ltrim(strtolower(trim((string) $type)), '.');
            if ($type !== '' && !in_array($type, $normalized, true)) {
                $normalized[] = $type;
            }
        }
        return $normalized;
    }

    public function isGloballyAllowed(string $suffix): bool
    {
        $suffix = strtolower($suffix);
        if (in_array($suffix, $this->global_blacklist, true)) {
            return false;
        }
        return $this->global_whitelist === null || in_array($suffix, $this->global_whitelist, true);
    }

    /**
     * Where a file goes, see the class description
     *
     * @param bool $rag_active RAG is enabled for the AI service and the chat
     * @return string One of the TARGET_ constants
     */
    public function target(string $suffix, bool $rag_active): string
    {
        $suffix = strtolower($suffix);
        if ($suffix === '' || !$this->isGloballyAllowed($suffix)) {
            return self::TARGET_NONE;
        }
        if ($rag_active && in_array($suffix, $this->rag_types, true)) {
            return self::TARGET_RAG;
        }
        if (in_array($suffix, $this->service_types, true)) {
            return self::TARGET_DIRECT;
        }
        return self::TARGET_NONE;
    }

    /**
     * Whether a file of this type belongs in the RAG, independent of whether RAG is switched on
     */
    public function isRagType(string $suffix): bool
    {
        return $this->target($suffix, true) === self::TARGET_RAG;
    }

    /**
     * File types that may be uploaded
     *
     * @return string[]
     */
    public function uploadTypes(bool $rag_active): array
    {
        $types = $rag_active ? array_merge($this->rag_types, $this->service_types) : $this->service_types;
        return array_values(array_filter(
            array_unique($types),
            fn(string $type): bool => $this->isGloballyAllowed($type)
        ));
    }

    public function isUploadAllowed(string $suffix, bool $rag_active): bool
    {
        return $this->target($suffix, $rag_active) !== self::TARGET_NONE;
    }

    /**
     * @return string[] R ∩ G
     */
    public function ragTypes(): array
    {
        return array_values(array_filter($this->rag_types, fn(string $type): bool => $this->isGloballyAllowed($type)));
    }

    /**
     * Fingerprint of R ∩ G: changes when files have to be moved into or out of the RAG
     */
    public function ragTypesFingerprint(): string
    {
        $types = $this->ragTypes();
        sort($types);
        return md5(implode(',', $types));
    }
}
