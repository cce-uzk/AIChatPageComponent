<?php declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;

/**
 * Background file upload handler for AIChatPageComponent
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_isCalledBy ilAIChatBackgroundFileUploadHandlerGUI: ilAIChatPageComponentPluginGUI
 */
class ilAIChatBackgroundFileUploadHandlerGUI extends AbstractCtrlAwareIRSSUploadHandler
{
    public function __construct()
    {
        parent::__construct();
        error_log("UPLOAD HANDLER CONSTRUCTOR CALLED: " . __CLASS__);
    }

    /**
     * @return ResourceStakeholder
     */
    protected function getStakeholder(): ResourceStakeholder
    {
        return new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
    }

    /**
     * @return array
     */
    protected function getClassPath(): array
    {
        return [ilAIChatPageComponentPluginGUI::class, self::class];
    }

    /**
     * @return bool
     */
    public function supportsChunkedUploads(): bool
    {
        return true;
    }

    /**
     * Override executeCommand to force synchronous behavior during CREATE mode
     */
    public function executeCommand(): void
    {
        global $DIC;
        $pc_id = $_GET['pc_id'] ?? '';
        
        error_log("UPLOAD HANDLER executeCommand() CALLED - pc_id: '$pc_id', cmd: " . $this->ctrl->getCmd());
        error_log("REQUEST METHOD: " . $_SERVER['REQUEST_METHOD']);
        error_log("ACCEPT HEADER: " . ($_SERVER['HTTP_ACCEPT'] ?? 'none'));
        
        if (empty($pc_id)) {
            // CREATE mode: Force synchronous execution like MainMenu
            error_log("CREATE mode: Forcing synchronous upload execution");
            
            switch ($this->ctrl->getCmd()) {
                case self::CMD_UPLOAD:
                    try {
                        $this->readChunkedInformation();
                        $result = $this->getUploadResult();
                        $content = json_encode($result);
                    } catch (\Throwable $t) {
                        $content = json_encode(
                            new BasicHandlerResult(
                                $this->getFileIdentifierParameterName(),
                                BasicHandlerResult::STATUS_FAILED,
                                '',
                                $t->getMessage()
                            )
                        );
                    }
                    
                    // Force synchronous response
                    $response = $this->http->response()->withBody(Streams::ofString($content));
                    $response = $response->withHeader('Content-Type', 'application/json');
                    $this->http->saveResponse($response);
                    $this->http->sendResponse();
                    $this->http->close();
                    break;
                    
                case self::CMD_REMOVE:
                    $file_identifier = $this->http->request()->getQueryParams()[$this->getFileIdentifierParameterName()];
                    $content = json_encode($this->getRemoveResult($file_identifier));
                    
                    $response = $this->http->response()->withBody(Streams::ofString($content));
                    $response = $response->withHeader('Content-Type', 'application/json');
                    $this->http->saveResponse($response);
                    $this->http->sendResponse();
                    $this->http->close();
                    break;
                    
                case self::CMD_INFO:
                    $file_identifier = $this->http->request()->getQueryParams()[$this->getFileIdentifierParameterName()];
                    $content = json_encode($this->getInfoResult($file_identifier));
                    
                    $response = $this->http->response()->withBody(Streams::ofString($content));
                    $response = $response->withHeader('Content-Type', 'application/json');
                    $this->http->saveResponse($response);
                    $this->http->sendResponse();
                    $this->http->close();
                    break;
            }
        } else {
            // EDIT mode: Use parent IRSS behavior
            error_log("EDIT mode: Using parent IRSS execution");
            parent::executeCommand();
        }
    }

    /**
     * Override URL generation to handle CREATE mode properly
     */
    public function getUploadURL(): string
    {
        // Check if we're in CREATE mode (no pc_id available) - use simpler routing
        global $DIC;
        $pc_id = $_GET['pc_id'] ?? '';
        
        if (empty($pc_id)) {
            // CREATE mode: Use simple, non-async routing like MainMenu
            $simple_url = $this->ctrl->getLinkTargetByClass([static::class], self::CMD_UPLOAD, null, false);
            error_log("CREATE mode: Simple URL = " . $simple_url);
            return $simple_url;
        } else {
            // EDIT mode: Use full IRSS routing
            $irss_url = $this->ctrl->getLinkTargetByClass($this->getClassPath(), self::CMD_UPLOAD, null, true);
            error_log("EDIT mode: IRSS URL = " . $irss_url);
            return $irss_url;
        }
    }

    public function getExistingFileInfoURL(): string
    {
        global $DIC;
        $pc_id = $_GET['pc_id'] ?? '';
        
        if (empty($pc_id)) {
            return $this->ctrl->getLinkTargetByClass([static::class], self::CMD_INFO, null, false);
        } else {
            return $this->ctrl->getLinkTargetByClass($this->getClassPath(), self::CMD_INFO, null, true);
        }
    }

    public function getFileRemovalURL(): string
    {
        global $DIC;
        $pc_id = $_GET['pc_id'] ?? '';
        
        if (empty($pc_id)) {
            return $this->ctrl->getLinkTargetByClass([static::class], self::CMD_REMOVE, null, false);
        } else {
            return $this->ctrl->getLinkTargetByClass($this->getClassPath(), self::CMD_REMOVE, null, true);
        }
    }
}