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
        'type' => 'clob',
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
                continue;
            }

            // Insert as background file (message_id = NULL, background_file = 1 if column exists)
            $nextId = $db->nextId('pcaic_attachments');
            $insertData = array(
                'id' => array('integer', $nextId),
                'message_id' => array('integer', null),  // NULL = Background File
                'chat_id' => array('text', $chatId),
                'user_id' => array('integer', null),
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
    $query = "SELECT config_value FROM pcaic_config WHERE config_key = " . $db->quote('enable_rag', 'text');
    $result = $db->query($query);
    $old_enable_rag = $db->fetchAssoc($result);

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

<#8>
<?php
/**
 * Step 8: Add RAG metadata and token usage tracking (v1.3.0)
 *
 * Adds columns to store:
 * - metadata: RAG source citations (filename, page_numbers, text excerpts)
 * - usage: Token consumption data (prompt_tokens, completion_tokens, total_tokens)
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_messages')) {
    // Add metadata column for RAG source citations (JSON)
    if (!$db->tableColumnExists('pcaic_messages', 'metadata')) {
        $db->addTableColumn('pcaic_messages', 'metadata', array(
            'type' => 'clob',
            'notnull' => false,
            'default' => null
        ));
    }

    // Add usage column for token tracking (JSON)
    if (!$db->tableColumnExists('pcaic_messages', 'usage')) {
        $db->addTableColumn('pcaic_messages', 'usage', array(
            'type' => 'clob',
            'notnull' => false,
            'default' => null
        ));
    }
}
?>

<#9>
<?php
/**
 * Step 9: Add online/offline visibility toggle per chat (v1.4.0)
 *
 * Adds is_online column to pcaic_chats.
 * Default 1 (online) so existing chats remain visible after migration.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'is_online')) {
        $db->addTableColumn('pcaic_chats', 'is_online', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ));
    }
}
?>

<#10>
<?php
/**
 * Step 10: Add per-chat source visibility and download permission flags (v1.5.0)
 *
 * show_sources:           controls whether RAG citations are returned to the client at all
 * allow_source_downloads: controls whether secure download URLs are included in citations
 *
 * Both default to 1 (enabled) so existing chats keep their current behaviour.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'show_sources')) {
        $db->addTableColumn('pcaic_chats', 'show_sources', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ));
    }

    if (!$db->tableColumnExists('pcaic_chats', 'allow_source_downloads')) {
        $db->addTableColumn('pcaic_chats', 'allow_source_downloads', array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ));
    }
}
?>

<#11>
<?php
/**
 * Step 11: Add per-chat temperature override (v1.7.0)
 *
 * temperature: nullable float; NULL = use global AI service default; a value
 *              overrides the globally configured temperature for this chat only.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'temperature')) {
        $db->addTableColumn('pcaic_chats', 'temperature', array(
            'type' => 'float',
            'notnull' => false,
        ));
    }
}
?>

<#12>
<?php
/**
 * Step 12: Add per-chat model override (v1.8.0)
 *
 * model: nullable text; NULL = use global model configured for the AI service;
 *        a value overrides the globally configured model for this chat only.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_chats')) {
    if (!$db->tableColumnExists('pcaic_chats', 'model')) {
        $db->addTableColumn('pcaic_chats', 'model', array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false,
        ));
    }
}
?>

<#13>
<?php
/**
 * Step 13: Separate RAG service from the RAMSES chat service (v1.9.0)
 *
 * RAG (upload, delete, retrieval) now has its own configuration (rag_*), so any
 * AI service can be combined with the RAG. Existing RAMSES RAG settings are copied.
 * The RAG service is only enabled automatically if the RAMSES URL does not point to
 * the legacy RAMSES host, which does not offer the retrieval endpoint.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_config')) {
    $read = static function (string $key) use ($db): ?string {
        $result = $db->query("SELECT config_value FROM pcaic_config WHERE config_key = " . $db->quote($key, 'text'));
        $row = $db->fetchAssoc($result);
        return $row !== null ? (string) $row['config_value'] : null;
    };

    $current_time = date('Y-m-d H:i:s');
    $insertIfMissing = static function (string $key, string $value) use ($db, $read, $current_time): void {
        if ($read($key) !== null) {
            return;
        }
        $db->insert('pcaic_config', array(
            'config_key' => array('text', $key),
            'config_value' => array('clob', $value),
            'created_at' => array('timestamp', $current_time),
            'updated_at' => array('timestamp', $current_time)
        ));
    };

    $ramses_url = rtrim(trim((string) ($read('ramses_api_url') ?? '')), '/');
    $ramses_token = trim((string) ($read('ramses_api_token') ?? ''));
    $ramses_rag = $read('ramses_enable_rag') ?? '1';

    $file_types = $read('ramses_rag_allowed_file_types') ?? 'txt,csv,pdf';
    $decoded = json_decode($file_types, true);
    if (is_array($decoded)) {
        $file_types = implode(',', $decoded);
    }

    $legacy_host = $ramses_url === '' || str_contains($ramses_url, 'ramses-oski.itcc.uni-koeln.de');
    $rag_url = $legacy_host ? 'https://oski-rag.itcc.uni-koeln.de' : $ramses_url;
    $rag_key = $legacy_host ? '' : $ramses_token;
    $rag_enabled = (!$legacy_host && $ramses_rag === '1' && $ramses_token !== '') ? '1' : '0';

    $insertIfMissing('rag_service_enabled', $rag_enabled);
    $insertIfMissing('rag_api_url', $rag_url);
    $insertIfMissing('rag_client_key', $rag_key);
    $insertIfMissing('rag_application_id', $read('ramses_application_id') ?? 'ILIAS');
    $insertIfMissing('rag_instance_id', $read('ramses_instance_id') ?? 'ilias9');
    $insertIfMissing('rag_allowed_file_types', $file_types ?: 'txt,csv,pdf');
    $insertIfMissing('rag_top_k', '10');
    $insertIfMissing('openai_enable_rag', '0');

    // Remove obsolete RAMSES RAG keys (now rag_*)
    foreach (['ramses_rag_config', 'ramses_rag_api_url', 'ramses_file_upload_url', 'ramses_file_delete_url',
              'ramses_application_id', 'ramses_instance_id', 'ramses_rag_allowed_file_types'] as $obsolete) {
        $db->manipulate("DELETE FROM pcaic_config WHERE config_key = " . $db->quote($obsolete, 'text'));
    }
}
?>

<#14>
<?php
/**
 * Step 14: Processing state of files in the RAG service (v1.10.0)
 *
 * pcaic_attachments:
 *   rag_status        processing | completed | failed | skipped (not a RAG file type);
 *                     NULL = not handled yet
 *   rag_status_error  error message of the RAG if processing failed
 *   rag_failed_count  failed uploads/processings in a row, used for the retry delay
 *   rag_retry_at      no new upload before this time
 * pcaic_chats:
 *   rag_status_checked_at  last status check of the chat, limits checks to one per minute
 * pcaic_rag_deletions:
 *   files the RAG did not delete yet (still processing), deleted later
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('pcaic_attachments')) {
    $columns = [
        'rag_status' => ['type' => 'text', 'length' => 20, 'notnull' => false],
        'rag_status_error' => ['type' => 'text', 'length' => 1000, 'notnull' => false],
        'rag_failed_count' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'rag_retry_at' => ['type' => 'timestamp', 'notnull' => false],
    ];
    foreach ($columns as $name => $definition) {
        if (!$db->tableColumnExists('pcaic_attachments', $name)) {
            $db->addTableColumn('pcaic_attachments', $name, $definition);
        }
    }
    // Files already in the RAG: state unknown, checked once
    $db->manipulate("UPDATE pcaic_attachments SET rag_status = 'processing' WHERE rag_remote_file_id IS NOT NULL AND rag_status IS NULL");
}

if ($db->tableExists('pcaic_chats') && !$db->tableColumnExists('pcaic_chats', 'rag_status_checked_at')) {
    $db->addTableColumn('pcaic_chats', 'rag_status_checked_at', ['type' => 'timestamp', 'notnull' => false]);
}

if (!$db->tableExists('pcaic_rag_deletions')) {
    $db->createTable('pcaic_rag_deletions', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'remote_file_id' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'entity_id' => ['type' => 'text', 'length' => 128, 'notnull' => true],
        'attempts' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'next_try' => ['type' => 'timestamp', 'notnull' => false],
        'created_at' => ['type' => 'timestamp', 'notnull' => false],
    ]);
    $db->addPrimaryKey('pcaic_rag_deletions', ['id']);
    $db->createSequence('pcaic_rag_deletions');
}
?>
