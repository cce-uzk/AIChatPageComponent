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

use ILIAS\Plugin\pcaic\Resource\Provider\ContainerFileProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\ContentPageProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\LearningModuleProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\PageContextProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\UploadedFileProvider;
use ILIAS\Plugin\pcaic\Resource\Provider\WikiProvider;

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
            new ContainerFileProvider(),
            new WikiProvider(),
            new ContentPageProvider(),
            new LearningModuleProvider(),
        ]);
    }

    public function get(string $type): ?ResourceProvider
    {
        return $this->providers[$type] ?? null;
    }

    /**
     * What the source list offers for a resource; nothing for kinds this version does not know
     *
     * @return string One of the ResourceProvider::OFFER_ constants
     */
    public function offerOf(\ILIAS\Plugin\pcaic\Model\ChatResource $resource): string
    {
        return $this->get($resource->getType())?->getSourceOffer() ?? ResourceProvider::OFFER_NONE;
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
