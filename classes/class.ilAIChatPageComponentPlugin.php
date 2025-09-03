<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

/**
 * ilAIChatPageComponentPlugin
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilAIChatPageComponentPlugin extends ilPageComponentPlugin
{
    /** @var string */
    const PLUGIN_ID = "pcaic";

    /** @var string */
    const PLUGIN_NAME = "AIChatPageComponent";

    /** @var string */
    const CTYPE = "Services";

    /** @var string */
    const CNAME = "COPage";

    /** @var string */
    const SLOT_ID = "pgcp";
	
    private static $instance;
	
	/**
     * Get plugin instance
     * @return self
     */
    public static function getInstance() : self
    {
        if (!isset(self::$instance)) {
            global $DIC;

            $component_repository = $DIC["component.repository"];

            $info = $component_repository->getPluginByName(self::PLUGIN_NAME);

            $component_factory = $DIC["component.factory"];

            $plugin_obj = $component_factory->getPlugin($info->getId());

            self::$instance = $plugin_obj;
        }

        return self::$instance;
    }
	
    /**
     * Get plugin name
     * @return string
     */
    public function getPluginName() : string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Get plugin id
     * @return string
     */
    public static function getPluginId(): string
    {
        return self::PLUGIN_ID;
    }
	
	/**
	 * Absolute filesystem directory of this plugin.
	 * e.g. Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent
	 */
	public function getPluginBaseDir() : string
	{
		return rtrim($this->getDirectory(), '/');
	}
    
    /**
	 * Base url of this plugin.
	 * e.g. https://ilias.example.org/ilias/Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent
	 */
    public function getPluginBaseUrl() : string
    {
        $base = rtrim(ILIAS_HTTP_PATH, '/'); // e.g. https://ilias.example.org/ilias
        $rel  = $this->getPluginBaseDir(); // Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent

        return $base . '/' . $rel;
    }

    /**
     * Check if parent type is valid and user has AIChat permissions
     */
    public function isValidParentType(string $a_parent_type) : bool
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');

        // First check if parent type is supported
        $supported_types = ['lm', 'crs', 'grp', 'copa', 'wpg', 'cont', 'cat'];
        if (!in_array($a_parent_type, $supported_types)) {
            return false;
        }

        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC['component.repository'];
        /** @var ilComponentFactory $component_factory */
        $component_factory = $DIC['component.factory'];

        if (isset($component_factory) && isset($component_repository)) {
            $ai_chat_repository_plugin = $component_repository
                ->getPluginById("xaic");

            if (isset($ai_chat_repository_plugin)) {
                $is_active = $ai_chat_repository_plugin->isActive();

                if (!$is_active) {
                    $logger->warning("PageComponent access denied: AIChat Repository plugin not active");
                    return false;
                }

                // Get current parent object reference ID
                $parent_ref_id = $this->getCurrentParentRefId();

                if (!$parent_ref_id) {
                    $logger->warning("PageComponent access denied: No parent context found");
                    return false;
                }

                // Check if user can create AIChat objects in current context
                $access = $DIC->access();
                $has_create_access = $access->checkAccess('create_xaic', '', $parent_ref_id);

                if (!$has_create_access) {
                    $logger->info("PageComponent access denied: User lacks AIChat creation permission in context {ref_id}", ['ref_id' => $parent_ref_id]);
                }

                return $has_create_access;
            }
            else {
                $logger->error("PageComponent validation failed: AIChat Repository plugin not found");
                return false;
            }
        }
        return false;
    }
    
    /**
     * Get current parent object reference ID for permission checks
     */
    private function getCurrentParentRefId(): ?int
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');

        // Strategy 1: Direct GET parameter (most common case)
        if (isset($_GET['ref_id']) && is_numeric($_GET['ref_id'])) {
            return (int)$_GET['ref_id'];
        }
        
        // Strategy 2: HTTP request object query parameters
        $request = $DIC->http()->request();
        $query_params = $request->getQueryParams();
        
        if (isset($query_params['ref_id']) && is_numeric($query_params['ref_id'])) {
            return (int)$query_params['ref_id'];
        }

        // Strategy 3: Extract from page object context
        if (isset($this->page_obj)) {
            try {
                $parent_id = $this->page_obj->getParentId();
                $parent_type = $this->page_obj->getParentType();

                // Convert object_id to ref_id based on parent type
                $ref_id = $this->getRefIdFromObjectId($parent_id, $parent_type);
                return $ref_id;
            } catch (\Exception $e) {
                $logger->debug("Failed to resolve ref_id from page object", ['error' => $e->getMessage()]);
            }
        }

        $logger->warning("Cannot determine parent context for permission check");
        return null;
    }
    
    /**
     * Convert object_id to ref_id for permission checks
     */
    private function getRefIdFromObjectId(int $obj_id, string $obj_type): ?int
    {
        try {
            $ref_ids = ilObject::_getAllReferences($obj_id);
            
            if (!empty($ref_ids)) {
                return (int)array_shift($ref_ids);
            }
        } catch (\Exception $e) {
            global $DIC;
            $logger = $DIC->logger()->comp('pcaic');
            $logger->warning("Failed to resolve references for object", [
                'obj_id' => $obj_id, 
                'obj_type' => $obj_type,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Handle an event
     * @param string $a_component
     * @param string $a_event
     * @param mixed  $a_parameter
     */
    public function handleEvent(string $a_component, string $a_event, $a_parameter) : void
    {
        $_SESSION['pcaic_listened_event'] = array('time' => time(), 'event' => $a_event);
    }

    /**
     * This function is called when the page content is cloned
     * @param array  $a_properties     properties saved in the page, (should be modified if neccessary)
     * @param string $a_plugin_version plugin version of the properties
     */
    public function onClone(array &$a_properties, string $a_plugin_version) : void
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');

        // Clone additional data if it exists
        if ($additional_data_id = ($a_properties['additional_data_id'] ?? null)) {
            $data = $this->getData($additional_data_id);
            if ($data) {
                $id = $this->saveData($data);
                $a_properties['additional_data_id'] = $id;
            }
        }
        
        // Handle ChatConfig for cloned component
        if (isset($a_properties['chat_id'])) {
            $old_chat_id = $a_properties['chat_id'];
            
            try {
                require_once(__DIR__ . '/../src/bootstrap.php');
                
                $is_cut_paste = $this->isCutPasteOperation($old_chat_id);
                $oldConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($old_chat_id);
                
                if ($oldConfig->exists()) {
                    if ($is_cut_paste) {
                        // Cut/paste: Keep the same chat_id and all data intact
                        $logger->debug("PageComponent moved - preserving chat data");
                    } else {
                        // Real copy: Create new chat_id and clone all data
                        $new_chat_id = uniqid('chat_', true);
                        
                        $cloned_background_files = $this->cloneBackgroundFiles($oldConfig->getBackgroundFiles());
                        
                        $newConfig = new \ILIAS\Plugin\pcaic\Model\ChatConfig($new_chat_id);
                        $newConfig->setPageId($oldConfig->getPageId());
                        $newConfig->setParentId($oldConfig->getParentId());
                        $newConfig->setParentType($oldConfig->getParentType());
                        $newConfig->setTitle($oldConfig->getTitle());
                        $newConfig->setSystemPrompt($oldConfig->getSystemPrompt());
                        $newConfig->setAiService($oldConfig->getAiService());
                        $newConfig->setMaxMemory($oldConfig->getMaxMemory());
                        $newConfig->setCharLimit($oldConfig->getCharLimit());
                        $newConfig->setBackgroundFiles($cloned_background_files);
                        $newConfig->setPersistent($oldConfig->isPersistent());
                        $newConfig->setIncludePageContext($oldConfig->isIncludePageContext());
                        $newConfig->setEnableChatUploads($oldConfig->isEnableChatUploads());
                        $newConfig->setEnableStreaming($oldConfig->isEnableStreaming());
                        $newConfig->setDisclaimer($oldConfig->getDisclaimer());
                        $newConfig->save();
                        
                        $a_properties['chat_id'] = $new_chat_id;
                        $logger->info("PageComponent copied - created new chat configuration", [
                            'original_chat' => $old_chat_id, 
                            'new_chat' => $new_chat_id,
                            'background_files_cloned' => count($cloned_background_files)
                        ]);
                    }
                } else {
                    $new_chat_id = uniqid('chat_', true);
                    $a_properties['chat_id'] = $new_chat_id;
                    $logger->warning("PageComponent cloning: Original chat configuration not found, created new one", [
                        'original_chat' => $old_chat_id,
                        'new_chat' => $new_chat_id
                    ]);
                }
            } catch (\Exception $e) {
                $logger->error("PageComponent cloning failed", [
                    'chat_id' => $old_chat_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * This function is called before the page content is deleted
     * @param array  $a_properties     properties saved in the page (will be deleted afterwards)
     * @param string $a_plugin_version plugin version of the properties
     */
    public function onDelete(array $a_properties, string $a_plugin_version, bool $move_operation = false) : void
    {
        if ($move_operation) {
            // Mark cut/paste operation for later detection
            if ($chat_id = ($a_properties['chat_id'] ?? null)) {
                $this->markCutPasteOperation($chat_id);
            }
            return;
        }

        // Real delete operation - clean up all data
        if ($additional_data_id = ($a_properties['additional_data_id'] ?? null)) {
            $this->deleteData($additional_data_id);
        }
        
        if ($chat_id = ($a_properties['chat_id'] ?? null)) {
            $this->deleteCompleteChat($chat_id);
        }
    }

    /**
     * Recursively copy directory (taken from php manual)
     * @param string $src
     * @param string $dst
     */
    private function rCopy(string $src, string $dst) : void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst);
        }
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->rCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Get additional data by id
     */
    public function getData(int $id) : ?string
    {
        global $DIC;
        $db = $DIC->database();


        $query = "SELECT data FROM pcaic_data WHERE id = " . $db->quote($id, 'integer');
        $result = $db->query($query);
        if ($row = $db->fetchAssoc($result)) {
            return $row['data'];
        }
        return null;
    }

    /**
     * Save new additional data
     */
    public function saveData(string $data) : int
    {
        global $DIC;
        $db = $DIC->database();

        $id = $db->nextId('pcaic_data');
        $db->insert(
            'pcaic_data',
            array(
                'id' => array('integer', $id),
                'data' => array('text', $data)
            )
        );
        return $id;
    }

    /**
     * Update additional data
     */
    public function updateData(int $id, string $data) : void
    {
        global $DIC;
        $db = $DIC->database();

        $db->update(
            'pcaic_data',
            array(
                'data' => array('text', $data)
            ),
            array(
                'id' => array('integer', $id)
            )
        );
    }

    /**
     * Delete additional data
     */
    public function deleteData(int $id) : void
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_data WHERE id = " . $db->quote($id, 'integer');
        $db->manipulate($query);
    }

    /**
     * Delete complete chat with all related data (config, sessions, messages, attachments)
     */
    public function deleteCompleteChat(string $chat_id) : void
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');
        $db = $DIC->database();

        try {
            // Delete background files from IRSS
            $bg_files_query = "SELECT background_files FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text');
            $bg_files_result = $db->query($bg_files_query);
            
            $background_files_deleted = 0;
            if ($row = $db->fetchAssoc($bg_files_result)) {
                $background_files = json_decode($row['background_files'] ?? '[]', true);
                if (is_array($background_files)) {
                    $irss = $DIC->resourceStorage();
                    foreach ($background_files as $file_id) {
                        try {
                            $identification = $irss->manage()->find($file_id);
                            if ($identification) {
                                $irss->manage()->remove($identification, new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder());
                                $background_files_deleted++;
                            }
                        } catch (\Exception $e) {
                            $logger->warning("Failed to delete background file during chat cleanup", [
                                'chat_id' => $chat_id,
                                'file_id' => $file_id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

            // Delete attachments from IRSS and database
            $attachments_query = "SELECT resource_id FROM pcaic_attachments WHERE message_id IN (
                SELECT message_id FROM pcaic_messages WHERE session_id IN (
                    SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text') . "
                )
            )";
            $attachments_result = $db->query($attachments_query);
            
            $attachment_files_deleted = 0;
            $irss = $DIC->resourceStorage();
            while ($row = $db->fetchAssoc($attachments_result)) {
                try {
                    $identification = $irss->manage()->find($row['resource_id']);
                    if ($identification) {
                        $irss->manage()->remove($identification, new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder());
                        $attachment_files_deleted++;
                    }
                } catch (\Exception $e) {
                    $logger->warning("Failed to delete attachment file during chat cleanup", [
                        'chat_id' => $chat_id,
                        'resource_id' => $row['resource_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Delete database records in cascade order
            $db->manipulate("DELETE FROM pcaic_attachments WHERE message_id IN (
                SELECT message_id FROM pcaic_messages WHERE session_id IN (
                    SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text') . "
                ))");
            
            $db->manipulate("DELETE FROM pcaic_messages WHERE session_id IN (
                SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text') . ")");
            
            $db->manipulate("DELETE FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text'));
            
            $db->manipulate("DELETE FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text'));

            $logger->info("Chat configuration deleted successfully", [
                'chat_id' => $chat_id,
                'background_files_deleted' => $background_files_deleted,
                'attachment_files_deleted' => $attachment_files_deleted
            ]);

        } catch (\Exception $e) {
            $logger->error("Chat deletion failed", [
                'chat_id' => $chat_id,
                'error' => $e->getMessage()
            ]);
            throw new ilException("Failed to delete chat");
        }
    }

    /**
     * Delete chat messages for a specific chat ID (legacy function)
     */
    public function deleteChatMessages(string $chat_id) : void
    {
        global $DIC;
        $db = $DIC->database();

        // Delete messages via session_id since messages don't have direct chat_id
        $query = "DELETE FROM pcaic_messages WHERE session_id IN (
            SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text') . "
        )";
        $db->manipulate($query);
    }

    /**
     * Get current page ID for context extraction
     */
    /*public function getPageId() : int
    {
        global $DIC;
        
        // Try to get page ID from various ILIAS contexts
        if (isset($_GET['page_id']) && is_numeric($_GET['page_id'])) {
            return (int)$_GET['page_id'];
        }
        
        // Try to get from current request
        $request = $DIC->http()->request();
        $query_params = $request->getQueryParams();
        
        if (isset($query_params['page_id']) && is_numeric($query_params['page_id'])) {
            return (int)$query_params['page_id'];
        }
        
        // Try to get from COPage context if available
        try {
            if (class_exists('ilPageObjectGUI')) {
                $page_gui = $DIC['ilPageObjectGUI'] ?? null;
                if ($page_gui && method_exists($page_gui, 'getId')) {
                    return (int)$page_gui->getId();
                }
            }
        } catch (Exception $e) {
            // Ignore and continue
        }
        
        return 0;
    }*/

    /**
     * Get parent object ID (e.g., course ID, learning module ID)
     */
    /*public function getParentId() : int
    {
        global $DIC;
        
        // Try common ILIAS reference ID parameters
        $ref_id_params = ['ref_id', 'target', 'obj_id', 'course_id', 'crs_id'];
        
        foreach ($ref_id_params as $param) {
            if (isset($_GET[$param]) && is_numeric($_GET[$param])) {
                $ref_id = (int)$_GET[$param];
                
                // If it's a ref_id, get the object_id
                if ($param === 'ref_id' && $ref_id > 0) {
                    try {
                        return ilObject::_lookupObjectId($ref_id);
                    } catch (Exception $e) {
                        continue;
                    }
                }
                
                return $ref_id;
            }
        }
        
        // Try to get from HTTP request
        $request = $DIC->http()->request();
        $query_params = $request->getQueryParams();
        
        foreach ($ref_id_params as $param) {
            if (isset($query_params[$param]) && is_numeric($query_params[$param])) {
                return (int)$query_params[$param];
            }
        }
        
        return 0;
    }*/

    /**
     * Get parent object type (e.g., 'crs', 'lm', 'wiki')
     */
    /*public function getParentType() : string
    {
        $parent_id = $this->getParentId();
        
        if ($parent_id > 0) {
            try {
                return ilObject::_lookupType($parent_id);
            } catch (Exception $e) {
                // Try with ref_id if obj_id didn't work
                if (isset($_GET['ref_id']) && is_numeric($_GET['ref_id'])) {
                    try {
                        return ilObject::_lookupType((int)$_GET['ref_id'], true);
                    } catch (Exception $e2) {
                        // Ignore
                    }
                }
            }
        }
        
        return '';
    }*/

    /**
     * Plugin activation - create database tables
     */
    protected function beforeActivation(): bool
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');
        
        try {
            $this->executeDatabaseUpdate();
            $logger->info("Plugin activated successfully - database tables created/updated");
            return true;
        } catch (Exception $e) {
            $logger->error("Plugin activation failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Plugin uninstallation - drop database tables
     */
    protected function beforeUninstall(): bool
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->comp('pcaic');
        
        try {
            $tables = ['pcaic_attachments', 'pcaic_messages', 'pcaic_sessions', 'pcaic_chats', 'pcaic_data'];
            $dropped_tables = [];
            
            foreach ($tables as $table) {
                if ($db->tableExists($table)) {
                    $db->dropTable($table);
                    $dropped_tables[] = $table;
                }
            }

            $logger->info("Plugin uninstalled successfully - database tables dropped", [
                'tables_dropped' => $dropped_tables
            ]);
            return true;
            
        } catch (Exception $e) {
            $logger->error("Plugin uninstallation failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Execute database update script
     */
    private function executeDatabaseUpdate(): void
    {
        $sql_file = $this->getPluginBaseDir() . '/sql/dbupdate.php';
        
        if (!file_exists($sql_file)) {
            throw new Exception('Database update script not found: ' . $sql_file);
        }
        
        global $DIC;
        $ilDB = $DIC->database();
        require_once $sql_file;
    }

    /**
     * Mark a cut/paste operation for a specific chat_id
     * This is called from onDelete() with move_operation=true
     */
    private function markCutPasteOperation(string $chat_id): void
    {
        // Store in session with timestamp for later detection
        if (!isset($_SESSION['pcaic_cut_paste_operations'])) {
            $_SESSION['pcaic_cut_paste_operations'] = [];
        }
        
        $_SESSION['pcaic_cut_paste_operations'][$chat_id] = time();
        
        // Clean up old entries (older than 60 seconds)
        $current_time = time();
        foreach ($_SESSION['pcaic_cut_paste_operations'] as $stored_chat_id => $timestamp) {
            if ($current_time - $timestamp > 60) {
                unset($_SESSION['pcaic_cut_paste_operations'][$stored_chat_id]);
            }
        }
    }
    
    /**
     * Check if this is a cut/paste operation for a specific chat_id
     * This is called from onClone() to detect if it's cut/paste vs real copy
     */
    private function isCutPasteOperation(string $chat_id): bool
    {
        if (!isset($_SESSION['pcaic_cut_paste_operations'])) {
            return false;
        }
        
        if (isset($_SESSION['pcaic_cut_paste_operations'][$chat_id])) {
            $timestamp = $_SESSION['pcaic_cut_paste_operations'][$chat_id];
            $current_time = time();
            
            // Consider it cut/paste if it happened within the last 30 seconds
            if ($current_time - $timestamp <= 30) {
                // Remove the marker after use
                unset($_SESSION['pcaic_cut_paste_operations'][$chat_id]);
                return true;
            } else {
                // Clean up old marker
                unset($_SESSION['pcaic_cut_paste_operations'][$chat_id]);
            }
        }
        
        return false;
    }
    
    /**
     * Clone background files in IRSS for real copy operations
     */
    private function cloneBackgroundFiles(array $file_ids): array
    {
        if (empty($file_ids)) {
            return [];
        }
        
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');
        $irss = $DIC->resourceStorage();
        $cloned_files = [];
        
        foreach ($file_ids as $file_id) {
            try {
                $original_identification = $irss->manage()->find($file_id);
                if (!$original_identification) {
                    $logger->warning("Background file not found during cloning", ['file_id' => $file_id]);
                    continue;
                }
                
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                $new_identification = $irss->manage()->clone($original_identification, $stakeholder);
                $cloned_files[] = $new_identification->serialize();
                
            } catch (\Exception $e) {
                $logger->warning("Background file cloning failed", [
                    'file_id' => $file_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $cloned_files;
    }
}