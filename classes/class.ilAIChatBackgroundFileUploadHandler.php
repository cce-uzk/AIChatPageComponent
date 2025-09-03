<?php declare(strict_types=1);

use ILIAS\FileUpload\Handler\AbstractCtrlAwareUploadHandler;
use ILIAS\FileUpload\Handler\BasicFileInfoResult;
use ILIAS\FileUpload\Handler\BasicHandlerResult;
use ILIAS\FileUpload\Handler\FileInfoResult;
use ILIAS\FileUpload\Handler\HandlerResult;
use ILIAS\Plugin\pcaic\Validation\FileUploadValidator;

/**
 * File Upload Handler for AI Chat Background Files
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * 
 * @ilCtrl_isCalledBy ilAIChatBackgroundFileUploadHandler: ilAIChatPageComponentPluginGUI
 */
class ilAIChatBackgroundFileUploadHandler extends AbstractCtrlAwareUploadHandler
{
    private $chat_id;
    private \ilLogger $logger;
    
    public function __construct(string $chat_id = '')
    {
        global $DIC;
        parent::__construct();
        $this->ctrl = $DIC->ctrl();
        $this->logger = $DIC->logger()->comp('pcaic');
        $this->chat_id = $chat_id;
    }

    /**
     * @inheritDoc
     */
    public function getUploadURL(): string
    {
        return $this->ctrl->getLinkTarget($this, self::CMD_UPLOAD);
    }

    /**
     * @inheritDoc
     */
    public function getExistingFileInfoURL(): string
    {
        return $this->ctrl->getLinkTarget($this, self::CMD_INFO);
    }

    /**
     * @inheritDoc
     */
    public function getFileRemovalURL(): string
    {
        return $this->ctrl->getLinkTarget($this, self::CMD_REMOVE);
    }

    /**
     * @inheritDoc
     */
    public function getUploadResult(): HandlerResult
    {
        global $DIC;

        try {
            $upload_service = $DIC->upload();
            $resource_storage = $DIC->resourceStorage();
            $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
            
            if (!$upload_service->hasUploads()) {
                return new BasicHandlerResult($this->getFileIdentifierParameterName(), BasicHandlerResult::STATUS_FAILED, 'No uploads found', '');
            }

            $upload_results = $upload_service->getResults();
            $uploaded_files = [];
            
            foreach ($upload_results as $result) {
                if (!$result->isOK()) {
                    continue;
                }
                
                // Check if background file uploads are enabled first
                if (!FileUploadValidator::isUploadEnabled('background')) {
                    $this->logger->info("Background file uploads disabled by admin");
                    continue;
                }
                
                // Create temporary file info for validation
                $temp_upload_info = [
                    'name' => $result->getName(),
                    'size' => $result->getSize(),
                    'tmp_name' => $result->getPath()  // ILIAS UploadResult path
                ];
                
                // Use FileUploadValidator for comprehensive validation
                $validation_result = FileUploadValidator::validateUpload($temp_upload_info, 'background', $this->chat_id);
                if (!$validation_result['success']) {
                    $this->logger->info("Background file validation failed", [
                        'filename' => $result->getName(),
                        'size' => $result->getSize(),
                        'error' => $validation_result['error']
                    ]);
                    continue;
                }

                // Store in IRSS
                $resource_id = $resource_storage->manage()->upload($result, $stakeholder);
                $resource_info = $resource_storage->manage()->getCurrentRevision($resource_id);
                
                if ($resource_info !== null) {
                    $file_info = [
                        'resource_id' => $resource_id->serialize(),
                        'title' => $resource_info->getTitle(),
                        'size' => $resource_info->getInformation()->getSize(),
                        'mime_type' => $resource_info->getInformation()->getMimeType(),
                        'suffix' => $resource_info->getInformation()->getSuffix(),
                        'uploaded_at' => time(),
                        'content' => '[File content - ' . $resource_info->getInformation()->getSuffix() . ' file]'
                    ];
                    
                    $uploaded_files[] = $file_info;
                }
            }

            if (!empty($uploaded_files)) {
                // Return success with file info
                return new BasicHandlerResult(
                    $this->getFileIdentifierParameterName(), 
                    BasicHandlerResult::STATUS_OK, 
                    json_encode($uploaded_files), 
                    $uploaded_files[0]['resource_id'] ?? ''
                );
            }

        } catch (Exception $e) {
            $this->logger->warning("Upload handler error", ['error' => $e->getMessage()]);
        }

        return new BasicHandlerResult($this->getFileIdentifierParameterName(), BasicHandlerResult::STATUS_FAILED, 'Upload failed', '');
    }

    /**
     * @inheritDoc
     */
    public function getRemoveResult(string $identifier): HandlerResult
    {
        global $DIC;
        
        try {
            $resource_storage = $DIC->resourceStorage();
            $resource_id = $resource_storage->manage()->find($identifier);
            
            if ($resource_id !== null) {
                $resource_storage->manage()->remove($resource_id, new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder());
            }
            
            return new BasicHandlerResult($this->getFileIdentifierParameterName(), BasicHandlerResult::STATUS_OK, 'File removed', '');
            
        } catch (Exception $e) {
            $this->logger->warning("Remove handler error", ['error' => $e->getMessage()]);
            return new BasicHandlerResult($this->getFileIdentifierParameterName(), BasicHandlerResult::STATUS_FAILED, 'Remove failed', '');
        }
    }

    /**
     * @inheritDoc
     */
    public function getInfoResult(string $identifier): ?FileInfoResult
    {
        global $DIC;
        
        try {
            $resource_storage = $DIC->resourceStorage();
            $resource_id = $resource_storage->manage()->find($identifier);
            
            if ($resource_id !== null) {
                $resource_info = $resource_storage->manage()->getCurrentRevision($resource_id);
                if ($resource_info !== null) {
                    return new BasicFileInfoResult(
                        $identifier,
                        $identifier,
                        $resource_info->getTitle(),
                        (int)$resource_info->getInformation()->getSize(),
                        $resource_info->getInformation()->getMimeType()
                    );
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Info handler error", ['error' => $e->getMessage()]);
        }
        
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getInfoForExistingFiles(array $file_ids): array
    {
        $results = [];
        foreach ($file_ids as $file_id) {
            $info = $this->getInfoResult($file_id);
            if ($info !== null) {
                $results[] = $info;
            }
        }
        return $results;
    }
}