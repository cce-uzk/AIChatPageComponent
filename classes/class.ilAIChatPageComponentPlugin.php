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

    private static ?self $instance = null;

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
     * Check if parent type is valid and user has permissions to create AI Chat Page Components
     *
     * This method checks both the parent object type compatibility and user permissions.
     * Since PageComponent plugins cannot integrate into ILIAS's standard permission system
     * (they are not processed as CreatableSubObjects), we fall back to checking permissions
     * of the AIChat Repository Plugin if it exists, otherwise allow access for content editors.
     *
     * @param string $a_parent_type The parent object type (e.g., 'crs', 'lm', 'grp')
     * @return bool True if the parent type is supported and user has permissions
     */
    public function isValidParentType(string $a_parent_type) : bool
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');

        // First check if parent type is supported for AI Chat Page Components
        $supported_types = $this->getParentTypes();
        $logger->debug('parent_type: ' . $a_parent_type);
        if (!in_array($a_parent_type, $supported_types)) {
            $logger->debug("PageComponent access denied: Unsupported parent type", ['parent_type' => $a_parent_type]);
            return false;
        }

        // Get current parent object reference ID for permission checks
        $parent_ref_id = $this->getCurrentParentRefId();
        if (!$parent_ref_id) {
            $logger->warning("PageComponent access denied: No parent context found");
            return false;
        }

        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC['component.repository'];
        $access = $DIC->access();

        // Strategy 1: Check if AIChat Repository Plugin is available and use its permissions
        try {
            $ai_chat_plugin = $component_repository->getPluginById("xaic");

            if ($ai_chat_plugin && $ai_chat_plugin->isActive()) {
                // AIChat plugin is active - use its creation permission
                $has_create_access = $access->checkAccess('create_xaic', '', $parent_ref_id);

                if (!$has_create_access) {
                    $logger->info("PageComponent access denied: User lacks AIChat creation permission", [
                        'ref_id' => $parent_ref_id,
                        'parent_type' => $a_parent_type,
                        'required_permission' => 'create_xaic'
                    ]);
                    return false;
                }

                $logger->debug("PageComponent access granted via AIChat plugin permissions", [
                    'ref_id' => $parent_ref_id,
                    'parent_type' => $a_parent_type
                ]);
                return true;
            }
        } catch (Exception $e) {
            $logger->debug("AIChat plugin not found or inactive", ['error' => $e->getMessage()]);
        }

        // Strategy 2: Fallback - check if user can edit content (write permission)
        // This ensures content editors can add AI Chat components even without AIChat plugin
        $has_write_access = $access->checkAccess('write', '', $parent_ref_id);

        if (!$has_write_access) {
            $logger->info("PageComponent access denied: User lacks content editing permission", [
                'ref_id' => $parent_ref_id,
                'parent_type' => $a_parent_type,
                'required_permission' => 'write'
            ]);
            return false;
        }

        $logger->debug("PageComponent access granted via content editing permissions", [
            'ref_id' => $parent_ref_id,
            'parent_type' => $a_parent_type
        ]);
        return true;
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
     * Plugin activation - create database tables
     *
     * @return bool True if activation was successful, false otherwise
     */
    protected function beforeActivation(): bool
    {
        global $DIC;
        $logger = $DIC->logger()->comp('pcaic');

        try {
            // Create/update database tables
            $this->executeDatabaseUpdate();
            $logger->info("Database tables created/updated successfully");

            /**
             * Note: RBAC permission setup has been disabled because PageComponent plugins
             * cannot integrate into ILIAS's standard permission system. ILIAS currently only
             * processes Repository Object Plugins (robj) and OrgUnit Extension Plugins (orguext)
             * through parsePluginData(), but not PageComponent Plugins (pgcp).
             *
             * Technical explanation:
             * - ILIAS's permission UI (ilObjectRolePermissionTableGUI) only shows "create"
             * permissions for objects listed as CreatableSubObjects
             * - CreatableSubObjects are populated by parsePluginData() in ilObjectDefinition
             * - parsePluginData() only processes "robj" and "orguext" plugin slots
             * - PageComponent plugins ("pgcp") are not processed and thus never appear
             * in the permission interface
             *
             * Workaround: This plugin now uses the AIChat Repository Plugin's permissions
             * when available, if not it is always accessible.
             */
            // $this->setupRBACPermissions();

            $logger->info("Plugin activated successfully - database ready");
            return true;

        } catch (Exception $e) {
            $logger->error("Plugin activation failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Plugin uninstallation - cleanup database tables and RBAC permissions
     *
     * This method is called before plugin uninstall and handles:
     * 1. Cleanup of all plugin data and files
     * 2. Database table removal
     * 3. RBAC object type and permissions cleanup
     *
     * @return bool True if uninstallation cleanup was successful, false otherwise
     */
    protected function beforeUninstall(): bool
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->comp('pcaic');

        try {
            // Step 1: Cleanup all chat data and IRSS files
            $this->cleanupAllPluginData();
            $logger->info("All plugin data and files cleaned up successfully");

            // Step 2: Drop database tables
            $tables = ['pcaic_attachments', 'pcaic_messages', 'pcaic_sessions', 'pcaic_chats', 'pcaic_config', 'pcaic_data'];
            $dropped_tables = [];

            foreach ($tables as $table) {
                if ($db->tableExists($table)) {
                    $db->dropTable($table);
                    $dropped_tables[] = $table;
                }
            }

            /**
             * Note: RBAC permission cleanup has been disabled because PageComponent plugins
             * cannot integrate into ILIAS's standard permission system. The system only
             * processes Repository Object Plugins (robj) and OrgUnit Extension Plugins (orguext)
             * through parsePluginData(), but not PageComponent Plugins (pgcp).
             *
             * Technical explanation:
             * - parsePluginData() in ilObjectDefinition only handles 'robj' and 'orguext' plugins
             * - PageComponent plugins (pgcp) are never processed for CreatableSubObjects
             * - Without CreatableSubObjects, permissions don't appear in the permission UI
             * - Access control is handled via isValidParentType() using AIChat permissions or write permissions
             */
            // $this->cleanupRBACPermissions();
            // $logger->info("RBAC permissions cleaned up successfully");

            $logger->info("Plugin uninstalled successfully", [
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

    /**
     * Valid types for page component.
     * https://docu.ilias.de/ilias.php?baseClass=illmpresentationgui&obj_id=56942&ref_id=42
     * Blog Postings: "blp"
     * Data Collection Detailed Views: "dclf"
     * Glossary Definitions: "gdf"
     * ILIAS Learning Module Pages: "lm"
     * Media Pool Content Snippets: "mep"
     * Portfolio Pages: "prtf"
     * Portfolio Template Pages: "prtt"
     * SCORM Editor Pages: "sahs"
     * Test Question Hint Pages: "qht"
     * Test Question Pages: "qpl"
     * Test Generic Feedback Pages: "qfbg"
     * Test Specific Feedback Pages: "qfbs"
     * Wiki Pages: "wpg"
     * Login Pages: "auth"
     * Container (Course, Group, Folder, Category) Pages: "cont"
     * Imprint: "impr"
     * Shop Page: "shop"
     * Page Template Page: "stys"
     * @return string[]
     */
    public function getParentTypes(): array
    {
        $par_types = ["blp", "lm", "sahs", "qpl", "wpg", "auth", "cont", "impr" ];
        return $par_types;
    }

    /**
     * Valid parent types.
     * @return string[]
     */
    public function getParentObjectTypes(): array
    {
        $par_types = ["root", "cat", "crs", "grp", "fold"];
        return $par_types;
    }

    /**
     * Setup RBAC permissions for AI Chat Page Components
     *
     * This method creates the necessary RBAC object type and permissions for the plugin.
     * It follows the same pattern as ilRepositoryObjectPlugin but adapted for PageComponent plugins.
     *
     * Creates:
     * - Object type entry in object_data table
     * - Standard RBAC operations (read, write, delete, edit_permissions, visible)
     * - Creation permission (create_pcaic)
     * - Associates creation permission with supported parent types
     *
     * @throws Exception If RBAC setup fails
     */
    private function setupRBACPermissions(): void
    {
        global $DIC;
        $ilDB = $DIC->database();
        $logger = $DIC->logger()->comp('pcaic');

        $type = self::PLUGIN_ID; // 'pcaic'

        // Ensure plugin type starts with 'x' (not required for PageComponents, but good practice)
        // Note: PageComponent plugins don't require 'x' prefix like RepositoryObject plugins

        // Step 1: Create object type entry if it doesn't exist
        $set = $ilDB->query(
            "SELECT * FROM object_data " .
            " WHERE type = " . $ilDB->quote("typ", "text") .
            " AND title = " . $ilDB->quote($type, "text")
        );

        if ($rec = $ilDB->fetchAssoc($set)) {
            $t_id = (int)$rec["obj_id"];
            $logger->debug("Object type already exists", ['type' => $type, 'obj_id' => $t_id]);
        } else {
            $t_id = $ilDB->nextId("object_data");
            $ilDB->manipulate("INSERT INTO object_data " .
                "(obj_id, type, title, description, owner, create_date, last_update) VALUES (" .
                $ilDB->quote($t_id, "integer") . "," .
                $ilDB->quote("typ", "text") . "," .
                $ilDB->quote($type, "text") . "," .
                $ilDB->quote("AI Chat Page Component Plugin", "text") . "," .
                $ilDB->quote(-1, "integer") . "," .
                $ilDB->quote(ilUtil::now(), "timestamp") . "," .
                $ilDB->quote(ilUtil::now(), "timestamp") .
                ")");
            $logger->info("Object type created", ['type' => $type, 'obj_id' => $t_id]);
        }

        // Step 2: Add standard RBAC operations
        // Standard operations: 1=edit_permissions, 2=visible, 3=read, 4=write, 6=delete
        $ops = [1, 2, 3, 4, 6];

        foreach ($ops as $op) {
            $set = $ilDB->query(
                "SELECT * FROM rbac_ta " .
                " WHERE typ_id = " . $ilDB->quote($t_id, "integer") .
                " AND ops_id = " . $ilDB->quote($op, "integer")
            );

            if (!$ilDB->fetchAssoc($set)) {
                $ilDB->manipulate("INSERT INTO rbac_ta " .
                    "(typ_id, ops_id) VALUES (" .
                    $ilDB->quote($t_id, "integer") . "," .
                    $ilDB->quote($op, "integer") .
                    ")");
                $logger->debug("RBAC operation added", ['type' => $type, 'operation_id' => $op]);
            }
        }

        // Step 3: Create creation permission operation
        $create_operation = "create_" . $type;
        $set = $ilDB->query(
            "SELECT * FROM rbac_operations " .
            " WHERE class = " . $ilDB->quote("create", "text") .
            " AND operation = " . $ilDB->quote($create_operation, "text")
        );

        if ($rec = $ilDB->fetchAssoc($set)) {
            $create_ops_id = (int)$rec["ops_id"];
            $logger->debug("Creation operation already exists", ['operation' => $create_operation, 'ops_id' => $create_ops_id]);
        } else {
            $create_ops_id = $ilDB->nextId("rbac_operations");
            $ilDB->manipulate("INSERT INTO rbac_operations " .
                "(ops_id, operation, description, class) VALUES (" .
                $ilDB->quote($create_ops_id, "integer") . "," .
                $ilDB->quote($create_operation, "text") . "," .
                $ilDB->quote("Create AI Chat Page Component", "text") . "," .
                $ilDB->quote("create", "text") .
                ")");
            $logger->info("Creation operation created", ['operation' => $create_operation, 'ops_id' => $create_ops_id]);
        }

        // Step 4: Assign creation operation to supported parent types
        $parent_types = $this->getParentObjectTypes();

        foreach ($parent_types as $par_type) {
            // Get parent type object ID
            $set = $ilDB->query(
                "SELECT obj_id FROM object_data " .
                " WHERE type = " . $ilDB->quote("typ", "text") .
                " AND title = " . $ilDB->quote($par_type, "text")
            );

            if ($rec = $ilDB->fetchAssoc($set)) {
                $par_type_id = (int)$rec["obj_id"];

                // Check if association already exists
                $set = $ilDB->query(
                    "SELECT * FROM rbac_ta " .
                    " WHERE typ_id = " . $ilDB->quote($par_type_id, "integer") .
                    " AND ops_id = " . $ilDB->quote($create_ops_id, "integer")
                );

                if (!$ilDB->fetchAssoc($set)) {
                    $ilDB->manipulate("INSERT INTO rbac_ta " .
                        "(typ_id, ops_id) VALUES (" .
                        $ilDB->quote($par_type_id, "integer") . "," .
                        $ilDB->quote($create_ops_id, "integer") .
                        ")");
                    $logger->debug("Creation permission assigned to parent type", [
                        'parent_type' => $par_type,
                        'parent_type_id' => $par_type_id
                    ]);
                }
            } else {
                $logger->warning("Parent type not found in object_data", ['parent_type' => $par_type]);
            }
        }

        $logger->info("RBAC permissions setup completed", ['plugin_type' => $type]);
    }

    /**
     * Cleanup RBAC permissions for AI Chat Page Components
     *
     * This method removes all RBAC-related entries for the plugin during uninstallation.
     * It's the counterpart to setupRBACPermissions() and ensures clean removal.
     *
     * Removes:
     * - Creation permission associations from parent types
     * - Creation operation from rbac_operations
     * - Standard operation associations from rbac_ta
     * - Object type entry from object_data
     *
     * @throws Exception If RBAC cleanup fails
     */
    private function cleanupRBACPermissions(): void
    {
        global $DIC;
        $ilDB = $DIC->database();
        $logger = $DIC->logger()->comp('pcaic');

        $type = self::PLUGIN_ID; // 'pcaic'
        $create_operation = "create_" . $type;

        try {
            // Step 1: Get object type ID
            $set = $ilDB->query(
                "SELECT obj_id FROM object_data " .
                " WHERE type = " . $ilDB->quote("typ", "text") .
                " AND title = " . $ilDB->quote($type, "text")
            );

            if ($rec = $ilDB->fetchAssoc($set)) {
                $t_id = (int)$rec["obj_id"];

                // Step 2: Remove standard RBAC operation associations
                $ilDB->manipulate(
                    "DELETE FROM rbac_ta WHERE typ_id = " . $ilDB->quote($t_id, "integer")
                );
                $logger->debug("Removed RBAC operation associations", ['type_id' => $t_id]);

                // Step 3: Remove object type entry
                $ilDB->manipulate(
                    "DELETE FROM object_data WHERE obj_id = " . $ilDB->quote($t_id, "integer")
                );
                $logger->debug("Removed object type entry", ['type_id' => $t_id]);
            }

            // Step 4: Get and remove creation operation
            $set = $ilDB->query(
                "SELECT ops_id FROM rbac_operations " .
                " WHERE class = " . $ilDB->quote("create", "text") .
                " AND operation = " . $ilDB->quote($create_operation, "text")
            );

            if ($rec = $ilDB->fetchAssoc($set)) {
                $create_ops_id = (int)$rec["ops_id"];

                // Remove creation operation associations from all parent types
                $ilDB->manipulate(
                    "DELETE FROM rbac_ta WHERE ops_id = " . $ilDB->quote($create_ops_id, "integer")
                );
                $logger->debug("Removed creation operation associations", ['ops_id' => $create_ops_id]);

                // Remove creation operation itself
                $ilDB->manipulate(
                    "DELETE FROM rbac_operations WHERE ops_id = " . $ilDB->quote($create_ops_id, "integer")
                );
                $logger->debug("Removed creation operation", ['operation' => $create_operation]);
            }

            $logger->info("RBAC permissions cleanup completed", ['plugin_type' => $type]);

        } catch (Exception $e) {
            $logger->error("RBAC cleanup failed", [
                'plugin_type' => $type,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cleanup all plugin data during uninstallation
     *
     * This method removes all chat configurations, sessions, messages, attachments,
     * and associated files from IRSS before database tables are dropped.
     *
     * @throws Exception If data cleanup fails
     */
    private function cleanupAllPluginData(): void
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->comp('pcaic');
        $irss = $DIC->resourceStorage();

        try {
            $total_files_deleted = 0;
            $total_chats_deleted = 0;

            // Get all chat IDs for cleanup
            if ($db->tableExists('pcaic_chats')) {
                $result = $db->query("SELECT chat_id FROM pcaic_chats");

                while ($row = $db->fetchAssoc($result)) {
                    try {
                        $this->deleteCompleteChat($row['chat_id']);
                        $total_chats_deleted++;
                    } catch (Exception $e) {
                        $logger->warning("Failed to cleanup chat during uninstall", [
                            'chat_id' => $row['chat_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Cleanup any remaining IRSS resources associated with this plugin
            try {
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                // Note: IRSS doesn't have a direct "cleanup all by stakeholder" method,
                // but the individual chat cleanups above should handle all files
            } catch (Exception $e) {
                $logger->warning("IRSS cleanup warning", ['error' => $e->getMessage()]);
            }

            $logger->info("Plugin data cleanup completed", [
                'chats_deleted' => $total_chats_deleted
            ]);

        } catch (Exception $e) {
            $logger->error("Plugin data cleanup failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
