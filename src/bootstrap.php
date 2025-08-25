<?php declare(strict_types=1);

/**
 * Bootstrap file for AIChatPageComponentPlugin
 * Loads the new namespaced classes
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */

// Load core classes first
require_once __DIR__ . '/Exception/PluginException.php';
require_once __DIR__ . '/Storage/ResourceStakeholder.php';
require_once __DIR__ . '/Service/ImageOptimizer.php';

// Load new architecture models
require_once __DIR__ . '/Model/ChatConfig.php';
require_once __DIR__ . '/Model/ChatSession.php';
require_once __DIR__ . '/Model/ChatMessage.php';

// Load legacy models for backward compatibility
require_once __DIR__ . '/Model/Message.php';
require_once __DIR__ . '/Model/Attachment.php';
require_once __DIR__ . '/Model/Chat.php';

// Create aliases for backward compatibility
if (!class_exists('AIChatPageComponentAttachment')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Attachment', 'AIChatPageComponentAttachment');
}

if (!class_exists('AIChatPageComponentChat')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Chat', 'AIChatPageComponentChat');
}

if (!class_exists('AIChatPageComponentMessage')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Message', 'AIChatPageComponentMessage');
}

// Create namespace alias for the objects namespace
if (!class_exists('objects\\AIChatPageComponentChat')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Chat', 'objects\\AIChatPageComponentChat');
}

if (!class_exists('objects\\AIChatPageComponentMessage')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Message', 'objects\\AIChatPageComponentMessage');
}

if (!class_exists('objects\\AIChatPageComponentAttachment')) {
    class_alias('\\ILIAS\\Plugin\\pcaic\\Model\\Attachment', 'objects\\AIChatPageComponentAttachment');
}