<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

/** @noinspection DuplicatedCode */

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Service;

use App\Entity\Archiver;
use App\Repository\EDoc\CaseFileRepository;
use App\Repository\EDoc\DocumentRepository;
use App\ShareFile\Item;
use App\Util\TemplateHelper;
use ItkDev\Edoc\Entity\ArchiveFormat;
use ItkDev\Edoc\Entity\CaseFile;
use ItkDev\Edoc\Entity\Document;
use ItkDev\Edoc\Entity\Entity;
use ItkDev\Edoc\Util\Edoc;
use ItkDev\Edoc\Util\EdocClient;
use ItkDev\Edoc\Util\ItemListType;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class EdocService
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';

    /** @var CaseFileRepository */
    private $caseFileRepository;

    /** @var DocumentRepository */
    private $documentRepository;

    /** @var TemplateHelper */
    private $template;

    /** @var Archiver */
    private $archiver;

    /** @var array */
    private $configuration;

    /** @var EdocClient */
    private $client;

    /** @var Edoc */
    private $edoc;

    /** @var array */
    private $documentTypes;

    /** @var array */
    private $documentStatuses;

    /** @var array */
    private $handlingCodeTree;

    /** @var array */
    private $primaryCodeTree;

    public function __construct(CaseFileRepository $caseFileRepository, DocumentRepository $documentRepository, TemplateHelper $template)
    {
        $this->caseFileRepository = $caseFileRepository;
        $this->documentRepository = $documentRepository;
        $this->template = $template;
    }

    public function __call($name, array $arguments)
    {
        return $this->edoc()->{$name}(...$arguments);
    }

    public function setArchiver(Archiver $archiver)
    {
        $this->archiver = $archiver;
        $this->configuration = $archiver->getConfigurationValue('edoc', []);
        $this->validateConfiguration();
    }

    /**
     * Check that we can connect to ShareFile.
     */
    public function connect()
    {
//        $this->getArchiveFormats();
    }

    public function getDocument(CaseFile $case, Item $item)
    {
        $document = $this->documentRepository->findOneByItemAndArchiver($item, $this->archiver);

        return $document ? $this->getDocumentById($document->getDocumentIdentifier()) : null;
    }

    public function createDocument(CaseFile $case, Item $item, array $data = [])
    {
        $name = $item->getName();
        $data += [
            'TitleText' => $name,
        ];

        if (isset($this->configuration['document']['defaults'])) {
            $data += $this->configuration['document']['defaults'];
        }

        $document = $this->edoc()->createDocumentAndDocumentVersion($case, $data);

        $this->documentRepository->created($document, $item, $this->archiver);

        return $document;
    }

    public function updateDocument(Document $document, Item $item, array $data)
    {
        $this->unlockDocument($document);

        $result = $this->edoc()->createDocumentVersion($document, $data);

        $this->lockDocument($document);

        $this->documentRepository->updated($document, $item, $this->archiver);

        return $result;
    }

    public function updateDocumentSettings(Document $document, array $data)
    {
        $return = $this->edoc()->updateDocument($document, $data);
    }

    public function getHearings()
    {
        return $this->getCases();
    }

    /**
     * Ensure that a case file exists.
     *
     * @param array $data additional data for new case file
     *
     * @throws \ItkDev\Edoc\Util\EdocException
     *
     * @return CaseFile
     */
    public function ensureCaseFile(Item $item, array $data = [], array $config = [])
    {
        $caseFile = $this->caseFileRepository->findOneByItemAndArchiver($item, $this->archiver);

        $edocCaseFile = $caseFile ? $this->getCaseById($caseFile->getCaseFileIdentifier()) : null;

        if (null !== $edocCaseFile) {
            if (\is_callable($config['callback'] ?? null)) {
                $config['callback']([
                    'status' => static::UPDATED,
                    'item' => $item,
                    'data' => $data,
                    'case_file' => $edocCaseFile,
                ]);
            }
            // @TODO Update case file?
            return $edocCaseFile;
        }

        return $this->createCaseFile($item, $data, $config);
    }

    /**
     * Create a case file.
     *
     * @param array $data additional data for new case file
     *
     * @throws \ItkDev\Edoc\Util\EdocException
     *
     * @return CaseFile
     */
    public function createCaseFile(Item $item, array $data = [], array $config = [])
    {
        $name = $this->getCaseFileName($item);
        $data += [
            'TitleText' => $name,
        ];

        if (isset($this->configuration['project_id'])) {
            $data += ['Project' => $this->configuration['project_id']];
        }

        if (isset($this->configuration['case_file']['defaults'])) {
            $data += $this->configuration['case_file']['defaults'];
        }

        $caseFile = $this->edoc()->createCaseFile($data);

        $this->caseFileRepository->created($caseFile, $item, $this->archiver);

        if (\is_callable($config['callback'] ?? null)) {
            $config['callback']([
                'status' => self::CREATED,
                'item' => $item,
                'data' => $data,
                'case_file' => $caseFile,
            ]);
        }

        return $caseFile;
    }

    public function updateCaseFile(CaseFile $caseFile, Item $item, array $data)
    {
        if ($this->edoc()->updateCaseFile($caseFile, $data)) {
            $this->caseFileRepository->updated($caseFile, $item, $this->archiver);

            return $this->getCaseById($caseFile->CaseFileIdentifier);
        }

        return null;
    }

    /**
     * Get or create a hearing.
     *
     * @param string $name   the hearing name
     * @param bool   $create if true, a new hearing will be created
     * @param array  $data   additional data for new hearing
     *
     * @return CaseFile
     */
    public function getHearing(Item $item, bool $create = false, array $data = [])
    {
        $caseFile = $this->caseFileRepository->findOneByItemAndArchiver($item, $this->archiver);

        $hearing = $caseFile ? $this->getCaseById($caseFile->getCaseFileIdentifier()) : null;
        if (null !== $hearing || !$create) {
            // @TODO Update hearing?
            return $hearing;
        }

        return $this->createHearing($item, $data);
    }

    /**
     * Create a hearing.
     *
     * @param array $data additional data for new hearing
     *
     * @throws \ItkDev\Edoc\Util\EdocException
     *
     * @return CaseFile
     */
    public function createHearing(Item $item, array $data = [])
    {
        $name = $this->getCaseFileName($item);
        $data += [
            'TitleText' => $name,
        ];

        if (isset($this->configuration['project_id'])) {
            $data += ['Project' => $this->configuration['project_id']];
        }

        if (isset($this->configuration['case_file']['defaults'])) {
            $data += $this->configuration['case_file']['defaults'];
        }

        $caseFile = $this->edoc()->createCaseFile($data);

        $this->caseFileRepository->created($caseFile, $item, $this->archiver);

        return $caseFile;
    }

    /**
     * Get a hearing reponse.
     *
     * @param string $item
     * @param bool   $create if true, a new response will be created
     * @param array  $data   additional data for new response
     *
     * @return Document
     */
    public function getResponse(CaseFile $hearing, Item $item, bool $create = false, array $data = [])
    {
//        $document = $this->getDocumentByName($hearing, $item->name);
//        if (null !== $document || !$create) {
//            return $document;
//        }

        $document = $this->documentRepository->findOneByItemAndArchiver($item, $this->archiver);

        $response = $document ? $this->getDocumentById($document->getDocumentIdentifier()) : null;
        if (null !== $response || !$create) {
            // @TODO Update response
            return $response;
        }

        return $this->createResponse($hearing, $item, $data);
    }

    /**
     * Ensure that a document exists in eDoc.
     *
     * @param bool $create
     *
     * @throws \ItkDev\Edoc\Util\EdocException
     *
     * @return null|Document|mixed
     */
    public function ensureDocument(CaseFile $hearing, Item $item, array $data = [])
    {
        $document = $this->documentRepository->findOneByItemAndArchiver($item, $this->archiver);

        $edocDocument = $document ? $this->getDocumentById($document->getDocumentIdentifier()) : null;
        if (null !== $edocDocument) {
            // @TODO Update document.
            return $edocDocument;
        }

        return $this->createDocument($hearing, $item, $data);
    }

    public function getDocumentUpdatedAt(Document $document)
    {
        $document = $this->documentRepository->findOneByDocumentAndArchiver($document, $this->archiver);

        return $document ? $document->getUpdatedAt() : null;
    }

    /**
     * Create a hearing response.
     *
     * @param Item  $item the response name
     * @param array $data data for new response
     *
     * @throws \ItkDev\Edoc\Util\EdocException
     *
     * @return Document
     */
    public function createResponse(CaseFile $hearing, Item $item, array $data)
    {
        $name = $this->getResponseName($item);
        $data += [
            'TitleText' => $name,
        ];

        if (isset($this->configuration['document']['defaults'])) {
            $data += $this->configuration['document']['defaults'];
        }

        $response = $this->edoc()->createDocumentAndDocumentVersion($hearing, $data);

        $this->documentRepository->created($response, $item, $this->archiver);

        return $response;
    }

    public function updateResponse(Document $response, Item $item, array $data)
    {
        $this->unlockDocument($response);

        $result = $this->edoc()->createDocumentVersion($response, $data);

        $this->lockDocument($response);

        $this->documentRepository->updated($response, $item, $this->archiver);

        return $result;
    }

    /**
     * Attach a file to a document.
     *
     * @param $contents
     */
    public function attachFile(Document $document, string $name, $contents)
    {
        $this->edoc()->attachFile($document, $name, $contents);
    }

    public function getAttachments(Document $document)
    {
        return $this->edoc()->getDocumentAttachmentList($document);
    }

    /**
     * @return ArchiveFormat[]
     */
    public function getArchiveFormats()
    {
        return $this->edoc()->getArchiveFormats();
    }

    /**
     * @param string $type mimetype or filename extension
     *
     * @return null|ArchiveFormat
     */
    public function getArchiveFormat(string $type)
    {
        $formats = $this->getArchiveFormats();

        foreach ($formats as $format) {
            if ($format->Mimetype === $type || 0 === strcasecmp($format->FileExtension, $type)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return Entity[]
     */
    public function getCaseTypes()
    {
        return $this->edoc()->getItemList(ItemListType::CASE_TYPE);
    }

    /**
     * @return array|CaseFile[]
     */
    public function getCases(array $criteria = [])
    {
        if (isset($this->configuration['project_id'])) {
            $criteria += [
                'Project' => $this->configuration['project_id'],
            ];
        }

        return $this->edoc()->searchCaseFile($criteria);
    }

    public function getCaseById(string $id)
    {
        $result = $this->getCases(['CaseFileIdentifier' => $id]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getCaseByName(string $name)
    {
        $result = $this->getCases(['TitleText' => $name]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getCaseBySequenceNumber(string $number)
    {
        $result = $this->getCases(['SequenceNumber' => $number]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getDocumentList(CaseFile $case)
    {
        return $this->edoc()->getDocumentList($case);
    }

    public function getDocuments(CaseFile $case)
    {
        return $this->edoc()->searchDocument([
            'CaseFileIdentifier' => '200031',
        ]);
    }

    public function getDocumentsBy(array $criteria)
    {
        return $this->edoc()->searchDocument($criteria);
    }

    public function getDocumentById(string $id)
    {
        $result = $this->edoc()->searchDocument(['DocumentIdentifier' => $id]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getDocumentByNumber(string $number)
    {
        $result = $this->edoc()->searchDocument(['DocumentNumber' => $number]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getDocumentByName(CaseFile $case, string $name)
    {
        $result = $this->edoc()->searchDocument([
            'CaseFileReference' => $case->CaseFileIdentifier,
            'TitleText' => $name,
        ]);

        return 1 === \count($result) ? reset($result) : null;
    }

    public function getDocumentVersion(string $documentVersionIdentifier)
    {
        return $this->edoc()->getDocumentVersion($documentVersionIdentifier);
    }

    public function getCaseWorkerByAz($az)
    {
        $az = 'adm\\'.$az;
        $result = $this->edoc()->getItemList(
            ItemListType::CASE_WORKER,
            [
                'CaseWorkerAccountName' => $az,
            ]
        );

        return 1 === \count($result) ? reset($result) : null;
    }

    /**
     * Make eDoc document updatable.
     */
    public function unlockDocument(Document $document)
    {
        try {
            // Apparently, settings these properties works as the wind blows …
            $this->updateDocumentSettings($document, [
                'DocumentStatusCode' => 1, // "Kladde"
                'DocumentTypeReference' => 60005, // "Notat"
            ]);
        } catch (\Exception $exception) {
        }
    }

    public function lockDocument(Document $document)
    {
        try {
            $defaults = $this->configuration['document']['defaults'];
            // Apparently, settings these properties works as the wind blows …
            $this->updateDocumentSettings($document, [
                'DocumentStatusCode' => $defaults['DocumentStatusCode'] ?? 6, // "Endelig"
                'DocumentTypeReference' => $defaults['DocumentTypeReference'] ?? 110, // "Indgående dokument"
            ]);
        } catch (\Exception $exception) {
        }
    }

    public function getDocumentTypeByName(string $name)
    {
        if (null === $this->documentTypes) {
            $this->documentTypes = $this->edoc()->getItemList(ItemListType::DOCUMENT_TYPE);
        }

        if (\is_array($this->documentTypes)) {
            foreach ($this->documentTypes as $item) {
                if (0 === strcasecmp($name, $item['DocumentTypeName'])) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function getDocumentStatusByName(string $name)
    {
        if (null === $this->documentStatuses) {
            $this->documentStatuses = $this->edoc()->getItemList(ItemListType::DOCUMENT_STATUS_CODE);
        }

        if (\is_array($this->documentStatuses)) {
            foreach ($this->documentStatuses as $item) {
                if (0 === strcasecmp($name, $item['DocumentStatusCodeName'])) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function getHandlingCodeByName(string $name)
    {
        if (null === $this->handlingCodeTree) {
            $this->handlingCodeTree = $this->edoc()->getItemList(ItemListType::HANDLING_CODE_TREE);
        }

        if (\is_array($this->handlingCodeTree)) {
            foreach ($this->handlingCodeTree as $item) {
                if (0 === strcasecmp($name, $item['HandlingCodeName'])) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function getPrimaryCodeByCode(string $code)
    {
        if (null === $this->primaryCodeTree) {
            $this->primaryCodeTree = $this->edoc()->getItemList(ItemListType::PRIMARY_CODE_TREE);
        }

        $primaryCode = null;
        if (\is_array($this->primaryCodeTree)) {
            foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($this->primaryCodeTree), RecursiveIteratorIterator::CHILD_FIRST) as $key => $value) {
                if (isset($value['PrimaryCodeCode']) && $code === $value['PrimaryCodeCode']) {
                    $primaryCode = $value;

                    break;
                }
            }
        }

        return $primaryCode;
    }

    private function getCaseFileName(Item $item)
    {
        $template = $this->configuration['case_file']['name'] ?? '{{ item.name }}';

        return $this->template->render($template, ['item' => ['name' => $item->name] + $item->metadata]);
    }

    private function getResponseName(Item $item)
    {
        $template = $this->configuration['document']['name'] ?? '{{ item.name }}';

        return $this->template->render($template, ['item' => ['name' => $item->name] + $item->metadata]);
    }

    private function validateConfiguration()
    {
        // @HACK
        if (null === $this->configuration) {
            return;
        }

        $requiredFields = ['ws_url', 'ws_username', 'ws_password', 'user_identifier'];

        foreach ($requiredFields as $field) {
            if (!isset($this->configuration[$field])) {
                throw new \RuntimeException('Configuration value "'.$field.'" missing.');
            }
        }
    }

    private function edoc()
    {
        if (null === $this->edoc) {
            $this->edoc = new Edoc($this->client(), $this->configuration['user_identifier']);
        }

        return $this->edoc;
    }

    private function client()
    {
        if (null === $this->client) {
            $this->client = new EdocClient(null, [
                'location' => $this->configuration['ws_url'],
                'username' => $this->configuration['ws_username'],
                'password' => $this->configuration['ws_password'],
                //            'trace' => true,
            ]);
        }

        return $this->client;
    }
}
