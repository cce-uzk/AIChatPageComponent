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

use ILIAS\Plugin\pcaic\Resource\Provider\PageContextProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\UploadedFileProvider;

/**
 * The kinds of resources the plugin offers
 *
 * A new kind is added here; the core finds it by its type.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
final class ResourceProviderRegistry
{
    /** @var array<string, ResourceProvider> */
    private array $providers = [];

    /**
     * @param ResourceProvider[] $providers
     */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getType()] = $provider;
        }
    }

    public static function default(): self
    {
        return new self([
            new UploadedFileProvider(),
            new PageContextProvider(),
        ]);
    }

    public function get(string $type): ?ResourceProvider
    {
        return $this->providers[$type] ?? null;
    }

    /**
     * @return ResourceProvider[]
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * Kinds that can be added to a chat
     *
     * @param bool $rag_active The chat uses the RAG; kinds of the environment need it
     * @return ResourceProvider[]
     */
    public function available(bool $rag_active): array
    {
        return array_values(array_filter(
            $this->providers,
            fn(ResourceProvider $provider): bool => $rag_active || !$provider->requiresRag()
        ));
    }
}
