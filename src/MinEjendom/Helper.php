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
use App\Service\AbstractArchiveHelper;
use App\Service\EdocService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;

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

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    /** @var Archiver */
    private $archiver;

    public function __construct(SagerApiHelper $sager, EdocService $edoc, MinEjendomApiHelper $minEjendom, EntityManagerInterface $entityManager, \Swift_Mailer $mailer)
    {
        $this->sager = $sager;
        $this->edoc = $edoc;
        $this->minEjendom = $minEjendom;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    public function updateDocuments(Archiver $archiver, string $eDocCaseSequenceNumber = null)
    {
        $this->sager->setArchiver($archiver);
        $this->edoc->setArchiver($archiver);
        $this->minEjendom->setArchiver($archiver);

        $sager = $this->sager->getSager();

        foreach ($sager as $index => $sag) {
            $eDocCaseSequenceNumber = $sag['esdh'];
            $byggesagGuid = $sag['minEjendomGuid'];

            $this->info(sprintf('% 4d: %s -> %s', $index + 1, $eDocCaseSequenceNumber, $byggesagGuid));

            $case = $this->edoc->getCaseBySequenceNumber($eDocCaseSequenceNumber);
            $documents = $this->edoc->getDocumentList($case);
            // $types = $this->edoc->getArchiveFormats();
            foreach ($documents as $document) {
                $this->info(sprintf('Document: %s', $document->DocumentIdentifier));

                $version = $this->edoc->getDocumentVersion($document);

                $this->info(sprintf('Version: %s', $version->DocumentVersionNumber));

                $data = [
                    'byggesagGuid' => $byggesagGuid,
                    'originalCreatedDate' => $document->DocumentDate,
                    'EksternID' => $document->DocumentNumber,
                    'aktNummer' => 1, // @todo
                    'beskrivelse' => $document->TitleText,
                    'filename' => $document->DocumentVersionIdentifier,
                    'imageFormat' => '.'.strtolower($document->ArchiveFormatFileExtension),
                ];

                $response = $this->minEjendom->createDocument($data, $version->getBinaryContents());

                $this->info(sprintf('Response status code: %d', $response->getStatusCode()));
            }
        }
    }

    public function log($level, $message, array $context = [])
    {
        if (null !== $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
