/**
 * AIChatPageComponent Plugin Database Update Script
 * 
 * This script is executed when the plugin is activated or updated.
 * It handles database table creation and updates.
 */

<#1>
<?php
// Step 1: Create initial tables
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
// Step 2: Update existing tables for new architecture
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
// Step 3: Fix message column size issue (v1.0.4)
// Error: SQLSTATE(22001): Data too long for column message
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