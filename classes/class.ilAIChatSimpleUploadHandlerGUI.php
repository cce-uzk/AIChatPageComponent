<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ILIAS\FileUpload\Handler\AbstractCtrlAwareUploadHandler;
use ILIAS\FileUpload\Handler\HandlerResult;
use ILIAS\FileUpload\Handler\BasicHandlerResult;
use ILIAS\FileUpload\Handler\FileInfoResult;
use ILIAS\FileUpload\Handler\BasicFileInfoResult;
use ILIAS\FileUpload\DTO\UploadResult;

/**
 * Simple file upload handler for AIChatPageComponent (mimicking MainMenu approach)
 * 
 * @author Based on ilMMUploadHandlerGUI pattern
 * 
 * @ilCtrl_isCalledBy ilAIChatSimpleUploadHandlerGUI: ilAIChatPageComponentPluginGUI
 */
class ilAIChatSimpleUploadHandlerGUI extends AbstractCtrlAwareUploadHandler
{
    private $storage;
    private $stakeholder;

    public function __construct()
    {
        global $DIC;
        parent::__construct();
        $this->storage = $DIC['resource_storage'];
        $this->stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
        error_log("SIMPLE UPLOAD HANDLER CONSTRUCTOR CALLED: " . __CLASS__);
    }

    protected function getUploadResult(): HandlerResult
    {
        $this->upload->process();
        $array = $this->upload->getResults();
        $result = end($array);
        
        if ($result instanceof UploadResult && $result->isOK()) {
            $i = $this->storage->manage()->upload($result, $this->stakeholder);
            $status = HandlerResult::STATUS_OK;
            $identifier = $i->serialize();
            $message = 'Upload ok';
        } else {
            $status = HandlerResult::STATUS_FAILED;
            $identifier = '';
            $message = $result->getStatus()->getMessage();
        }

        return new BasicHandlerResult($this->getFileIdentifierParameterName(), $status, $identifier, $message);
    }

    protected function getRemoveResult(string $identifier): HandlerResult
    {
        $id = $this->storage->manage()->find($identifier);
        if ($id !== null) {
            $this->storage->manage()->remove($id, $this->stakeholder);
            return new BasicHandlerResult($this->getFileIdentifierParameterName(), HandlerResult::STATUS_OK, $identifier, 'file deleted');
        } else {
            return new BasicHandlerResult($this->getFileIdentifierParameterName(), HandlerResult::STATUS_FAILED, $identifier, 'file not found');
        }
    }

    public function getInfoResult(string $identifier): ?FileInfoResult
    {
        $id = $this->storage->manage()->find($identifier);
        if ($id === null) {
            return new BasicFileInfoResult($this->getFileIdentifierParameterName(), 'unknown', 'unknown', 0, 'unknown');
        }
        $r = $this->storage->manage()->getCurrentRevision($id)->getInformation();

        return new BasicFileInfoResult($this->getFileIdentifierParameterName(), $identifier, $r->getTitle(), $r->getSize(), $r->getMimeType());
    }

    public function getInfoForExistingFiles(array $file_ids): array
    {
        $infos = [];
        foreach ($file_ids as $file_id) {
            $id = $this->storage->manage()->find($file_id);
            if ($id === null) {
                continue;
            }
            $r = $this->storage->manage()->getCurrentRevision($id)->getInformation();

            $infos[] = new BasicFileInfoResult($this->getFileIdentifierParameterName(), $file_id, $r->getTitle(), $r->getSize(), $r->getMimeType());
        }

        return $infos;
    }

    public function supportsChunkedUploads(): bool
    {
        return true;
    }

    public function executeCommand(): void
    {
        error_log("SIMPLE HANDLER executeCommand() CALLED - cmd: " . $this->ctrl->getCmd());
        error_log("REQUEST METHOD: " . $_SERVER['REQUEST_METHOD']);
        error_log("ACCEPT HEADER: " . ($_SERVER['HTTP_ACCEPT'] ?? 'none'));
        
        // Override the command execution to handle uploads directly without redirects
        $cmd = $this->ctrl->getCmd();
        switch ($cmd) {
            case self::CMD_UPLOAD:
                error_log("SIMPLE HANDLER: Processing upload command directly");
                try {
                    $result = $this->getUploadResult();
                    $content = json_encode($result);
                    error_log("SIMPLE HANDLER: Upload result: " . $content);
                    
                    // Send JSON response directly
                    header('Content-Type: application/json');
                    echo $content;
                    exit;
                } catch (\Throwable $t) {
                    error_log("SIMPLE HANDLER: Upload error: " . $t->getMessage());
                    $content = json_encode([
                        'status' => 0,
                        'message' => $t->getMessage()
                    ]);
                    header('Content-Type: application/json');
                    echo $content;
                    exit;
                }
                break;
                
            case self::CMD_REMOVE:
                error_log("SIMPLE HANDLER: Processing remove command directly");
                $file_identifier = $_GET[$this->getFileIdentifierParameterName()] ?? '';
                $result = $this->getRemoveResult($file_identifier);
                $content = json_encode($result);
                header('Content-Type: application/json');
                echo $content;
                exit;
                break;
                
            case self::CMD_INFO:
                error_log("SIMPLE HANDLER: Processing info command directly");
                $file_identifier = $_GET[$this->getFileIdentifierParameterName()] ?? '';
                $result = $this->getInfoResult($file_identifier);
                $content = json_encode($result);
                header('Content-Type: application/json');
                echo $content;
                exit;
                break;
                
            default:
                error_log("SIMPLE HANDLER: Unknown command, calling parent");
                parent::executeCommand();
        }
    }

    public function getUploadURL(): string
    {
        error_log("SIMPLE HANDLER getUploadURL() called");
        
        // Generate a direct URL that bypasses ILIAS controller
        global $DIC;
        $plugin_dir = dirname(__DIR__);
        $direct_url = 'Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent/upload_endpoint.php';
        
        error_log("SIMPLE HANDLER: Using direct upload endpoint: " . $direct_url);
        return $direct_url;
    }
}