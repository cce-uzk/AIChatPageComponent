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

/**
 * Test bootstrap
 *
 * The tested classes only need the plugin configuration and ILIAS base classes.
 * They are replaced by the stubs, so the tests run without an ILIAS installation
 * or database. The plugin autoloader is not used, because it would load the
 * database-backed configuration class.
 */

require_once __DIR__ . '/stubs.stub';

$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/classes/platform/class.AIChatPageComponentException.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentLLM.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentRAMSES.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentOpenAI.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentLLMRegistry.php';
require_once $plugin_dir . '/src/Model/Attachment.php';
require_once $plugin_dir . '/src/Model/ChatMessage.php';
require_once $plugin_dir . '/src/Model/ChatResource.php';
require_once $plugin_dir . '/src/Model/ChatConfig.php';
require_once $plugin_dir . '/src/Resource/ResourceProvider.php';
require_once $plugin_dir . '/src/Resource/ResourceContext.php';
require_once $plugin_dir . '/src/Resource/SnapshotDocument.php';
require_once $plugin_dir . '/src/Resource/Candidate.php';
require_once $plugin_dir . '/src/Resource/PageTextExtractor.php';
require_once $plugin_dir . '/src/Resource/SnapshotService.php';
require_once $plugin_dir . '/src/Resource/ResourceAccessFilter.php';
require_once $plugin_dir . '/src/Resource/SourceStateService.php';
require_once $plugin_dir . '/src/Resource/EnvironmentObjects.php';
require_once $plugin_dir . '/src/Resource/Provider/UploadedFileProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/PageContextProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/ContainerFileProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/PageObjectProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/WikiProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/ContentPageProvider.php';
require_once $plugin_dir . '/src/Resource/Provider/LearningModuleProvider.php';
require_once $plugin_dir . '/src/Resource/ResourceProviderRegistry.php';
require_once $plugin_dir . '/src/Resource/MaterialList.php';
require_once $plugin_dir . '/src/Table/ResourceTableRetrieval.php';
require_once $plugin_dir . '/src/Validation/FileUploadValidator.php';
require_once $plugin_dir . '/src/Validation/FileTypePolicy.php';
require_once $plugin_dir . '/src/Service/StreamKeepAlive.php';
