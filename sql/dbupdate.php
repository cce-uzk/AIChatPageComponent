/**
 * AIChatPageComponent Plugin Database Update Script
 *
 * This script is executed when the plugin is activated or updated.
 * It handles database table creation and updates.
 */

<#1>
<?php
/**
 * Step 1: Create initial tables
 */
global $DIC;
$db = $DIC->database();

// Create pcaic_data table for additional plugin data
if (!$db->tableExists('pcaic_data')) {
    $fields = array(
        'id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'data' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        )
    );

    $db->createTable('pcaic_data', $fields);
    $db->addPrimaryKey('pcaic_data', array('id'));
    $db->createSequence('pcaic_data');
}

// Create pcaic_chats table for chat configurations
if (!$db->tableExists('pcaic_chats')) {
    $fields = array(
        'chat_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true
        ),
        'page_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 0
        ),
        'parent_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 0
        ),
        'parent_type' => array(
            'type' => 'text',
            'length' => 50,
            'notnull' => false
        ),
        'title' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ),
        'system_prompt' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ),
        'ai_service' => array(
            'type' => 'text',
            'length' => 50,
            'notnull' => false,
            'default' => 'ramses'
        ),
        'max_memory' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 10
        ),
        'char_limit' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 2000
        ),
        'background_files' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ),
        'persistent' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ),
        'include_page_context' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ),
        'enable_chat_uploads' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ),
        'disclaimer' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        )
    );

    $db->createTable('pcaic_chats', $fields);
    $db->addPrimaryKey('pcaic_chats', array('chat_id'));
    $db->addIndex('pcaic_chats', array('page_id'), 'i1');
    $db->addIndex('pcaic_chats', array('parent_id'), 'i2');
}

// Create pcaic_sessions table for user chat sessions
if (!$db->tableExists('pcaic_sessions')) {
    $fields = array(
        'session_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true
        ),
        'user_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'chat_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true
        ),
        'session_name' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ),
        'is_active' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'last_activity' => array(
            'type' => 'timestamp',
            'notnull' => true
        )
    );

    $db->createTable('pcaic_sessions', $fields);
    $db->addPrimaryKey('pcaic_sessions', array('session_id'));
    $db->addIndex('pcaic_sessions', array('user_id', 'chat_id'), 'i1');
    $db->addIndex('pcaic_sessions', array('chat_id'), 'i2');
    $db->addIndex('pcaic_sessions', array('user_id', 'chat_id', 'is_active'), 'i3');
}

// Create pcaic_messages table for chat messages
if (!$db->tableExists('pcaic_messages')) {
    $fields = array(
        'message_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'session_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true
        ),
        'role' => array(
            'type' => 'text',
            'length' => 20,
            'notnull' => true
        ),
        'message' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ),
        'timestamp' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'attachments' => array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        )
    );

    $db->createTable('pcaic_messages', $fields);
    $db->addPrimaryKey('pcaic_messages', array('message_id'));
    $db->addIndex('pcaic_messages', array('session_id'), 'i1');
    $db->createSequence('pcaic_messages');
}

// Create pcaic_attachments table for message attachments
if (!$db->tableExists('pcaic_attachments')) {
    $fields = array(
        'id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'message_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 0
        ),
        'chat_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ),
        'user_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ),
        'resource_id' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true
        ),
        'timestamp' => array(
            'type' => 'timestamp',
            'notnull' => true
        )
    );

    $db->createTable('pcaic_attachments', $fields);
    $db->addPrimaryKey('pcaic_attachments', array('id'));
    $db->addIndex('pcaic_attachments', array('message_id'), 'i1');
    $db->addIndex('pcaic_attachments', array('chat_id'), 'i2');
    $db->addIndex('pcaic_attachments', array('resource_id'), 'i3');
    $db->createSequence('pcaic_attachments');
}
?>

<#2>
<?php
/**
 * Step 2: Update existing tables for new architecture
 */
global $DIC;
$db = $DIC->database();

// Update pcaic_sessions table if columns are missing
if ($db->tableExists('pcaic_sessions')) {
    if (!$db->tableColumnExists('pcaic_sessions', 'session_name')) {
        $db->addTableColumn('pcaic_sessions', 'session_name', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));
    }

    if (!$db->tableColumnExists('pcaic_sessions', 'is_active')) {
        $db->addTableColumn('pcaic_sessions', 'is_active', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ));
    }
}

// Update pcaic_messages table if it has old structure
if ($db->tableExists('pcaic_messages')) {
    if (!$db->tableColumnExists('pcaic_messages', 'session_id')) {
        // Add new columns for new architecture
        $db->addTableColumn('pcaic_messages', 'session_id', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));

        $db->addTableColumn('pcaic_messages', 'attachments', array(
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ));
    }
}

// Update pcaic_attachments table to match new schema
if ($db->tableExists('pcaic_attachments')) {
    if ($db->tableColumnExists('pcaic_attachments', 'attachment_id')) {
        // Rename attachment_id to id
        $db->renameTableColumn('pcaic_attachments', 'attachment_id', 'id');
    }

    if (!$db->tableColumnExists('pcaic_attachments', 'chat_id')) {
        $db->addTableColumn('pcaic_attachments', 'chat_id', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));
    }

    if (!$db->tableColumnExists('pcaic_attachments', 'user_id')) {
        $db->addTableColumn('pcaic_attachments', 'user_id', array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ));
    }

    if ($db->tableColumnExists('pcaic_attachments', 'file_id') && !$db->tableColumnExists('pcaic_attachments', 'resource_id')) {
        // Rename file_id to resource_id
        $db->renameTableColumn('pcaic_attachments', 'file_id', 'resource_id');
    }

    if ($db->tableColumnExists('pcaic_attachments', 'created_at') && !$db->tableColumnExists('pcaic_attachments', 'timestamp')) {
        // Rename created_at to timestamp
        $db->renameTableColumn('pcaic_attachments', 'created_at', 'timestamp');
    }

    // Remove old columns that are no longer needed
    if ($db->tableColumnExists('pcaic_attachments', 'file_name')) {
        $db->dropTableColumn('pcaic_attachments', 'file_name');
    }
    if ($db->tableColumnExists('pcaic_attachments', 'file_type')) {
        $db->dropTableColumn('pcaic_attachments', 'file_type');
    }
    if ($db->tableColumnExists('pcaic_attachments', 'file_size')) {
        $db->dropTableColumn('pcaic_attachments', 'file_size');
    }
}
?>

<#3>
<?php
/**
 * Step 3: Fix message column size issue (v1.0.4)
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_messages')) {
    // Upgrade message column from TEXT (65KB) to LONGTEXT (4GB) to handle long AI responses
    $db->modifyTableColumn('pcaic_messages', 'message', array(
        'type' => 'clob',  // ILIAS clob type maps to MySQL LONGTEXT
        'notnull' => false
    ));
}
?>

<#4>
<?php
/**
 * Step 4: Create dedicated plugin configuration table (v1.0.6)
 */
global $DIC;
$db = $DIC->database();

// Create pcaic_config table for plugin configuration
if (!$db->tableExists('pcaic_config')) {
    $fields = array(
        'config_key' => array(
            'type' => 'text',
            'length' => 250,
            'notnull' => true
        ),
        'config_value' => array(
            'type' => 'clob', // Support for JSON arrays and long text values
            'notnull' => false
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        )
    );

    $db->createTable('pcaic_config', $fields);
    $db->addPrimaryKey('pcaic_config', array('config_key'));

    // Insert default configuration values
    $default_configs = array(
        // Default chat settings
        array(
            'config_key' => 'default_prompt',
            'config_value' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.'
        ),
        array(
            'config_key' => 'default_disclaimer',
            'config_value' => ''
        ),
        array(
            'config_key' => 'characters_limit',
            'config_value' => '2000'
        ),
        array(
            'config_key' => 'max_memory_messages',
            'config_value' => '10'
        ),

        // AI service availability (JSON format)
        array(
            'config_key' => 'available_services',
            'config_value' => '{"ramses":"1","openai":"1"}'
        ),

        // File upload constraints
        array(
            'config_key' => 'max_file_size_mb',
            'config_value' => '5'
        ),
        array(
            'config_key' => 'max_attachments_per_message',
            'config_value' => '5'
        ),
        array(
            'config_key' => 'max_total_upload_size_mb',
            'config_value' => '25'
        ),

        // Processing limits
        array(
            'config_key' => 'pdf_pages_processed',
            'config_value' => '20'
        ),
        array(
            'config_key' => 'max_image_data_mb',
            'config_value' => '15'
        )
    );

    $current_time = date('Y-m-d H:i:s');

    foreach ($default_configs as $config) {
        $db->insert('pcaic_config', array(
            'config_key' => array('text', $config['config_key']),
            'config_value' => array('clob', $config['config_value']),
            'created_at' => array('timestamp', $current_time),
            'updated_at' => array('timestamp', $current_time)
        ));
    }
}
?>

<#5>
<?php
/**
 * Step 5: Add streaming configuration support (v1.0.7)
 */
global $DIC;
$db = $DIC->database();

// Add enable_streaming column to pcaic_chats table
if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'enable_streaming')) {
        $db->addTableColumn('pcaic_chats', 'enable_streaming', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1  // Default to enabled
        ));
    }
}

// Add global streaming configuration to pcaic_config table
if ($db->tableExists('pcaic_config')) {
    // Check if enable_streaming config already exists
    $query = "SELECT config_key FROM pcaic_config WHERE config_key = " . $db->quote('enable_streaming', 'text');
    $result = $db->query($query);

    if (!$db->fetchAssoc($result)) {
        // Insert default streaming configuration
        $current_time = date('Y-m-d H:i:s');
        $db->insert('pcaic_config', array(
            'config_key' => array('text', 'enable_streaming'),
            'config_value' => array('clob', '1'),  // Default to enabled
            'created_at' => array('timestamp', $current_time),
            'updated_at' => array('timestamp', $current_time)
        ));
    }
}
?>

<#6>
<?php
/**
 * Step 6: Add RAG (Retrieval-Augmented Generation) support (v1.1.0)
 */
global $DIC;
$db = $DIC->database();

// Make message_id nullable first (to support background files with message_id = NULL)
if ($db->tableExists('pcaic_attachments')) {
    // Modify message_id to allow NULL values
    $db->modifyTableColumn('pcaic_attachments', 'message_id', array(
        'type' => 'integer',
        'length' => 4,
        'notnull' => false,  // Allow NULL for background files
        'default' => null
    ));
}

// Extend pcaic_attachments with RAG fields
if ($db->tableExists('pcaic_attachments')) {
    if (!$db->tableColumnExists('pcaic_attachments', 'rag_collection_id')) {
        $db->addTableColumn('pcaic_attachments', 'rag_collection_id', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));
    }

    if (!$db->tableColumnExists('pcaic_attachments', 'rag_remote_file_id')) {
        $db->addTableColumn('pcaic_attachments', 'rag_remote_file_id', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));
    }

    if (!$db->tableColumnExists('pcaic_attachments', 'rag_uploaded_at')) {
        $db->addTableColumn('pcaic_attachments', 'rag_uploaded_at', array(
            'type' => 'timestamp',
            'notnull' => false
        ));
    }

    // Add index for RAG queries
    if (!$db->indexExistsByFields('pcaic_attachments', array('rag_collection_id'))) {
        $db->addIndex('pcaic_attachments', array('rag_collection_id'), 'i4');
    }
}

// Add RAG fields to pcaic_chats
if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'rag_collection_id')) {
        $db->addTableColumn('pcaic_chats', 'rag_collection_id', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false
        ));
    }
}

// Migrate background_files from pcaic_chats to pcaic_attachments
if ($db->tableExists('pcaic_chats') && $db->tableColumnExists('pcaic_chats', 'background_files')) {
    $query = "SELECT chat_id, background_files FROM pcaic_chats WHERE background_files IS NOT NULL";
    $result = $db->query($query);

    $migrated_count = 0;
    while ($row = $db->fetchAssoc($result)) {
        $chatId = $row['chat_id'];
        $backgroundFilesJson = $row['background_files'];

        if (empty($backgroundFilesJson)) {
            continue;
        }

        // Parse JSON array of resource IDs
        // Example: ["8d59dc3d-70e4-491e-ba26-b86e813ae6ce","072193e8-ecc8-4f53-bc59-e39855f3c428"]
        $resourceIds = json_decode($backgroundFilesJson, true);
        if (!is_array($resourceIds)) {
            continue;
        }

        foreach ($resourceIds as $resourceId) {
            // Check if already migrated
            $checkQuery = "SELECT id FROM pcaic_attachments
                          WHERE chat_id = " . $db->quote($chatId, 'text') . "
                          AND resource_id = " . $db->quote($resourceId, 'text') . "
                          AND message_id IS NULL";
            $checkResult = $db->query($checkQuery);

            if ($db->fetchAssoc($checkResult)) {
                continue; // Already migrated
            }

            // Insert as background file (message_id = NULL, background_file = 1 if column exists)
            $nextId = $db->nextId('pcaic_attachments');
            $insertData = array(
                'id' => array('integer', $nextId),
                'message_id' => array('integer', null),  // NULL = Background File
                'chat_id' => array('text', $chatId),
                'user_id' => array('integer', null),  // Unknown for migrated files
                'resource_id' => array('text', $resourceId),
                'rag_collection_id' => array('text', null),
                'rag_remote_file_id' => array('text', null),
                'rag_uploaded_at' => array('timestamp', null),
                'timestamp' => array('timestamp', date('Y-m-d H:i:s'))
            );

            // Add background_file flag if column exists (added in step 8)
            if ($db->tableColumnExists('pcaic_attachments', 'background_file')) {
                $insertData['background_file'] = array('integer', 1);
            }

            $db->insert('pcaic_attachments', $insertData);

            $migrated_count++;
        }
    }
}

// Add RAG configuration to pcaic_config
if ($db->tableExists('pcaic_config')) {
    // Simplified RAG configuration (removed complex mode settings)
    $rag_configs = array(
        array('config_key' => 'enable_rag', 'config_value' => '1'),  // Enable RAG by default
        array('config_key' => 'ramses_rag_api_url', 'config_value' => 'https://ramses-oski.itcc.uni-koeln.de/v1/rag/completions'),
        array('config_key' => 'ramses_file_upload_url', 'config_value' => 'https://ramses-oski.itcc.uni-koeln.de/v1/rag/upload'),
        array('config_key' => 'ramses_file_delete_url', 'config_value' => 'https://ramses-oski.itcc.uni-koeln.de/v1/rag/delete'),
        array('config_key' => 'ramses_application_id', 'config_value' => 'ILIAS'),
        array('config_key' => 'ramses_instance_id', 'config_value' => 'ilias9')
    );

    $current_time = date('Y-m-d H:i:s');

    foreach ($rag_configs as $config) {
        // Check if exists
        $query = "SELECT config_key FROM pcaic_config WHERE config_key = " .
                 $db->quote($config['config_key'], 'text');
        $result = $db->query($query);

        if (!$db->fetchAssoc($result)) {
            $db->insert('pcaic_config', array(
                'config_key' => array('text', $config['config_key']),
                'config_value' => array('clob', $config['config_value']),
                'created_at' => array('timestamp', $current_time),
                'updated_at' => array('timestamp', $current_time)
            ));
        }
    }
}

// Drop deprecated background_files column
if ($db->tableExists('pcaic_chats') && $db->tableColumnExists('pcaic_chats', 'background_files')) {
    $db->dropTableColumn('pcaic_chats', 'background_files');
}

// Migrate enable_rag to LLM-specific ramses_enable_rag
// RAG control is now per-LLM: ramses_enable_rag for RAMSES, openai_enable_rag for OpenAI, etc.
if ($db->tableExists('pcaic_config')) {
    // Check if old enable_rag exists
    $query = "SELECT config_value FROM pcaic_config WHERE config_key = " . $db->quote('enable_rag', 'text');
    $result = $db->query($query);
    $old_enable_rag = $db->fetchAssoc($result);

    // Migrate to ramses_enable_rag
    $current_time = date('Y-m-d H:i:s');
    $query_check = "SELECT config_key FROM pcaic_config WHERE config_key = " . $db->quote('ramses_enable_rag', 'text');
    $result_check = $db->query($query_check);

    if (!$db->fetchAssoc($result_check)) {
        // Insert ramses_enable_rag with value from old enable_rag (or default '1')
        $ramses_rag_value = $old_enable_rag ? $old_enable_rag['config_value'] : '1';
        $db->insert('pcaic_config', array(
            'config_key' => array('text', 'ramses_enable_rag'),
            'config_value' => array('clob', $ramses_rag_value),
            'created_at' => array('timestamp', $current_time),
            'updated_at' => array('timestamp', $current_time)
        ));
    }

    // Remove old enable_rag
    if ($old_enable_rag) {
        $db->manipulate("DELETE FROM pcaic_config WHERE config_key = " . $db->quote('enable_rag', 'text'));
    }
}

// Add background_file flag to distinguish background files from chat uploads
if ($db->tableExists('pcaic_attachments')) {
    if (!$db->tableColumnExists('pcaic_attachments', 'background_file')) {
        $db->addTableColumn('pcaic_attachments', 'background_file', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ));
        // Mark existing background files (message_id IS NULL AND chat_id IS NOT NULL)
        $db->manipulate("UPDATE pcaic_attachments SET background_file = 1 WHERE message_id IS NULL AND chat_id IS NOT NULL");
    }
}
?>

<#7>
<?php
/**
 * Step 7: Add per-chat RAG enable flag (v1.2.0)
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'enable_rag')) {
        $db->addTableColumn('pcaic_chats', 'enable_rag', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ));
    }
}
?>
