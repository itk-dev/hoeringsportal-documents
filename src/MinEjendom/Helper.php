<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\MinEjendom;

use App\Entity\Archiver;
use App\Entity\ExceptionLogEntry;
use App\Entity\MinEjendom\Document;
use App\Repository\MinEjendom\DocumentRepository;
use App\Service\AbstractArchiveHelper;
use App\Service\EdocService;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\Edoc\Entity\CaseFile;
use ItkDev\Edoc\Entity\Document as EDocDocument;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\HttpFoundation\Response;

class Helper extends AbstractArchiveHelper
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /**
     * {@inheritdoc}
     */
    protected $archiverType = Archiver::TYPE_MIN_EJENDOM;

    /** @var SagerApiHelper */
    private $sager;

    /** @var EdocService */
    private $edoc;

    /** @var MinEjendomApiHelper */
    private $minEjendom;

    /** @var DocumentRepository */
    private $documentRepository;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    /** @var array */
    private $archiveFormats;

    /** @var Archiver */
    private $archiver;

    public function __construct(SagerApiHelper $sager, EdocService $edoc, MinEjendomApiHelper $minEjendom, DocumentRepository $documentRepository, EntityManagerInterface $entityManager, \Swift_Mailer $mailer)
    {
        $this->sager = $sager;
        $this->edoc = $edoc;
        $this->minEjendom = $minEjendom;
        $this->documentRepository = $documentRepository;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    public function updateDocuments(Archiver $archiver, array $options = [])
    {
        $this->archiver = $archiver;

        try {
            $this->sager->setArchiver($archiver);
            $this->edoc->setArchiver($archiver);
            $this->minEjendom->setArchiver($archiver);

            // Archive formats indexed by Code.
            $this->archiveFormats = array_column($this->edoc->getArchiveFormats(), null, 'Code');
            $sager = $this->sager->getSager();
            foreach ($sager as $index => $sag) {
                try {
                    $eDocCaseSequenceNumber = $sag['esdh'];
                    $byggesagGuid = $sag['minEjendomGuid'];

                    if (isset($options['eDocCaseSequenceNumber']) && $options['eDocCaseSequenceNumber'] !== $eDocCaseSequenceNumber) {
                        continue;
                    }

                    $this->info(sprintf('% 4d: %s -> %s', $index + 1, $eDocCaseSequenceNumber, $byggesagGuid));

                    $case = $this->edoc->getCaseBySequenceNumber($eDocCaseSequenceNumber);
                    $documents = $this->edoc->getDocumentList($case);
                    foreach ($documents as $document) {
                        try {
                            if (isset($options['eDocDocumentNumber']) && $options['eDocDocumentNumber'] !== $document->DocumentNumber) {
                                continue;
                            }

                            // Some documents don't have a version identifier,
                            // i.e. they have no files.
                            if (empty($document->DocumentVersionIdentifier)) {
                                return;
                            }

                            $this->createDocument($document, $sag, $case);

                            $attachments = $this->edoc->getAttachments($document);
                            foreach ($attachments as $attachment) {
                                $this->createDocument($attachment, $sag, $case, $document);
                            }
                        } catch (\Throwable $t) {
                            $this->logException($t, [
                                'sag' => $sag,
                                'case' => $case->getData(),
                                'document' => $document->getData(),
                            ]);
                        }
                    }
                } catch (\Throwable $t) {
                    $this->logException($t, [
                        'sag' => $sag,
                    ]);
                }
            }
        } catch (\Throwable $t) {
            $this->logException($t);
        }
    }

    public function log($level, $message, array $context = [])
    {
        if (null !== $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    private function createDocument(EDocDocument $document, array $sag, CaseFile $case, EDocDocument $mainDocument = null)
    {
        try {
            $eDocCaseSequenceNumber = $sag['esdh'];
            $byggesagGuid = $sag['minEjendomGuid'];

            $documentDocumentIdentifier = $document->DocumentIdentifier;
            $documentVersionIdentifier = $document->DocumentVersionIdentifier;

            $minEjendomDocument = $this->documentRepository->findOneBy([
                'archiver' => $this->archiver,
                'eDocCaseSequenceNumber' => $eDocCaseSequenceNumber,
                'documentIdentifier' => $documentDocumentIdentifier,
                'documentVersionIdentifier' => $documentVersionIdentifier,
            ]);

            if (null !== $minEjendomDocument) {
                $this->info(sprintf('Document %s (version: %s) already handled.', $document->DocumentNumber, $document->DocumentVersionIdentifier));

                return;
            }

            $version = $this->edoc->getDocumentVersion($document->DocumentVersionIdentifier);
            $imageFormat = '.'.strtolower($this->archiveFormats[$version->ArchiveFormatCode]->FileExtension ?? '');
            // Remove file extension.
            $filename = preg_replace('/'.preg_quote($imageFormat, '/').'/i', '', $document->TitleText);

            $minEjendomDocument = (new Document())
                ->setArchiver($this->archiver)
                ->setEDocCaseSequenceNumber($eDocCaseSequenceNumber)
                ->setDocumentIdentifier($documentDocumentIdentifier)
                ->setDocumentVersionIdentifier($documentVersionIdentifier)
                ->setFilename($filename.$imageFormat);

            $minEjendomDocument->addData('[sag]', $sag);

            $baseUrl = rtrim($this->archiver->getConfigurationValue('[minejendom][url]'), '/');
            $data = [
                'base_url' => $baseUrl,
            ];
            if (isset($sag['minEjendomId'])) {
                $data['case_url'] = $baseUrl.'/Byggesag/Vis/'.$sag['minEjendomId'];
            }

            $minEjendomDocument->addData('[minejendom]', $data);

            $minEjendomDocument->addData('[edoc][case]', $case->getData());
            $minEjendomDocument->addData('[edoc][document]', $document->getData());
            if (null !== $mainDocument) {
                $minEjendomDocument->addData('[edoc][main-document]', $mainDocument->getData());
            }
            $this->info(sprintf('Document: %s (%s)', $document->DocumentNumber, $document->DocumentIdentifier));

            $minEjendomDocument->addData('[edoc][version]', $version->getData());

            $this->info(sprintf('Version: %s', $version->DocumentVersionNumber));

            $documentNumber = 1;
            if (preg_match('/-(?<number>\d+)$/', $document->DocumentNumber, $matches)) {
                $documentNumber = (int) $matches['number'];
            }
            $aktNumber = sprintf('%d-%d', $documentNumber, $version->DocumentVersionNumber);

            $this->info(sprintf('aktNumber: %s', $aktNumber));

            $data = [
                'EksternID' => $document->DocumentNumber,
                'aktNummer' => $aktNumber,
                'beskrivelse' => $mainDocument->TitleText ?? $document->TitleText,
                'byggesagGuid' => $byggesagGuid,
                'filename' => $filename,
                'imageFormat' => $imageFormat,
                'originalCreatedDate' => $document->DocumentDate,
            ];

            $minEjendomDocument->addData('[document][data]', $data);

            $binaryContents = $version->getBinaryContents();

            $response = $this->minEjendom->createDocument($data, $binaryContents);

            $minEjendomDocument->addData('[document][response][create]', [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                'status_code' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ]);

            if (Response::HTTP_OK === $response->getStatusCode()) {
                try {
                    $documentGuid = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (\is_string($documentGuid)) {
                        $minEjendomDocument->setDocumentGuid($documentGuid);
                    }
                } catch (\JsonException $exception) {
                }

                // Delete old versions of the document.
                $existingDocuments = $this->documentRepository->findBy([
                    'archiver' => $this->archiver,
                    'eDocCaseSequenceNumber' => $eDocCaseSequenceNumber,
                    'documentIdentifier' => $documentDocumentIdentifier,
                ]);

                foreach ($existingDocuments as $existingDocument) {
                    $this->info(sprintf('Deleting document %s', $existingDocument->getDocumentGuid()));
                    $deleteResponse = $this->sager->deleteDocument($existingDocument->getDocumentGuid());
                    $this->log(Response::HTTP_NO_CONTENT === $deleteResponse->getStatusCode() ? 'info' : 'error', sprintf('Response status code: %d', $deleteResponse->getStatusCode()));
                    $this->entityManager->remove($existingDocument);
                }

                $this->entityManager->persist($minEjendomDocument);
                $this->entityManager->flush();
            }

            $this->log(Response::HTTP_OK === $response->getStatusCode() ? 'info' : 'error', sprintf('Response status code: %d', $response->getStatusCode()));
            $this->debug(sprintf('Response body: %s', (string) $response->getBody()));
        } catch (\Throwable $t) {
            $this->logException($t, [
                'sag' => $sag,
                'case' => $case->getData(),
                'document' => $document->getData(),
            ]);
        }
    }

    private function logException(\Throwable $t, array $context = [])
    {
        $this->emergency($t->getMessage(), $context);
        $logEntry = new ExceptionLogEntry($t, $context);
        $this->entityManager->persist($logEntry);
        $this->entityManager->flush();

        if (null !== $this->archiver) {
            $config = $this->archiver->getConfigurationValue('[notifications][email]');

            $message = (new \Swift_Message($t->getMessage()))
                ->setFrom($config['from'])
                ->setTo($config['to'])
                ->setBody(
                    implode(PHP_EOL, [
                        json_encode($context, JSON_PRETTY_PRINT),
                        $t->getTraceAsString(),
                    ]),
                    'text/plain'
                );

            $this->mailer->send($message);
        }
    }
}
