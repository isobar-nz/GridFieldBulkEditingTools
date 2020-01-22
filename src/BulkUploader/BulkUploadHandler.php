<?php

namespace Colymba\BulkUpload;

use Colymba\BulkTools\HTTPBulkToolsResponse;
use SilverStripe\Control\Controller;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Control\HTTPRequest;

//use SilverStripe\Core\Injector\Injector;
//use SilverStripe\ORM\DataObject;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\ORM\DataObject;

/**
 * Handles request from the GridFieldBulkUpload component.
 *
 * @author colymba
 */
class BulkUploadHandler extends RequestHandler
{
    /**
     * Gridfield instance.
     *
     * @var GridField
     */
    protected $gridField;

    /**
     * Bulk upload component.
     *
     * @var BulkUploader
     */
    protected $component;

    /**
     * RequestHandler allowed actions.
     *
     * @var array
     */
    private static $allowed_actions = array(
        'upload', 'attach'
    );

    /**
     * RequestHandler url => action map.
     *
     * @var array
     */
    private static $url_handlers = array(
        '$Action!' => '$Action'
    );

    /**
     * Handler's constructor.
     *
     * @param GridField $gridField
     * @param GridField_URLHandler $component
     */
    public function __construct($gridField, $component)
    {
        $this->gridField = $gridField;
        $this->component = $component;
        parent::__construct();
    }

    /**
     * Creates a new DataObject
     * Add file ID to the Dataobject
     * Add DataObject to Gridfield list
     * Publish DataObject if enabled
     *
     * @param integer $fileID The newly uploaded/attached file ID
     *
     * @return  DataObject The new DataObject
     * @throws \SilverStripe\ORM\ValidationException
     */
    protected function createDataObject($fileID)
    {
        $recordClass = $this->component->getRecordClassName($this->gridField);

        /** @var DataObject $record */
        $record = $recordClass::create();
        $record->write();

        $record->extend('onBulkUpload', $this->gridField);

        $fileRelationName = $this->component->getFileRelationName($this->gridField);
        $record->{"{$fileRelationName}ID"} = $fileID;
        $record->write(); //HasManyList call write on record but not ManyManyList, so we call it here again

        $this->gridField->getList()->add($record);

        if ($this->component->getAutoPublishDataObject() && $record->hasExtension('Versioned'))
        {
            $record->publishRecursive();
        }

        return $record;
    }

    /**
     * Process upload through AssetAdmin::apiCreateFile,
     * uses result file ID to create the DataObject.
     *
     * @param HTTPRequest $request
     *
     * @return string json
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function upload(HTTPRequest $request)
    {
        $assetAdmin = AssetAdmin::singleton();
        $uploadResponse = $assetAdmin->apiCreateFile($request);

        if ($uploadResponse->getStatusCode() == 200)
        {
            $responseData = json_decode($uploadResponse->getBody(), true);
            $responseData = array_shift($responseData);

            $record = $this->createDataObject($responseData['id']);

            $bulkToolsResponse = new HTTPBulkToolsResponse(false, $this->gridField);
            $bulkToolsResponse->addSuccessRecord($record);

            $responseData['bulkTools'] = json_decode($bulkToolsResponse->getBody());
            $uploadResponse->setBody(json_encode(array($responseData)));
        }

        return $uploadResponse;
    }

    /**
     * Retrieve File to be attached
     * and generated DataObject.
     *
     * @param HTTPRequest $request
     *
     * @return HTTPBulkToolsResponse
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function attach(HTTPRequest $request)
    {
        $fileID = $request->requestVar('fileID'); //why is this not POST?
        $dataObject = $this->createDataObject($fileID);

        $response = new HTTPBulkToolsResponse(false, $this->gridField);
        $response->addSuccessRecord($dataObject);
        return $response;
    }

    public function getRecordRow(HTTPRequest $request)
    {
        $recordID = $request->requestVar('recordID');
        print_r($recordID);
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function Link($action = null)
    {
        return Controller::join_links($this->gridField->Link(), '/bulkupload/', $action);
    }
}
