<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Service;

use App\Entity\Archiver;
use App\Entity\ExceptionLogEntry;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class HearingOverviewHelper
{
    use LoggerAwareTrait;

    private $archiver;

    /** @var DeskproService */
    private $deskproService;

    /** @var ShareFileService */
    private $shareFileService;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    /** @var ParameterBagInterface */
    private $parameters;

    public function __construct(
        DeskproService $deskproService,
        ShareFileService $shareFileService,
        EntityManagerInterface $entityManager,
        \Swift_Mailer $mailer,
        ParameterBagInterface $parameters,
        LoggerInterface $logger = null
    ) {
        $this->deskproService = $deskproService;
        $this->shareFileService = $shareFileService;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->parameters = $parameters;
        $this->setLogger($logger ?? new NullLogger());
    }

    public function setArchiver(Archiver $archiver)
    {
        $this->archiver = $archiver;
        $this->shareFileService->setArchiver($archiver);

        return $this;
    }

    public function process(array $hearingsIds = null)
    {
        if (null === $this->archiver) {
            throw new \RuntimeException('No archiver');
        }

        try {
            $startTime = new \DateTime();
            $hearings = $this->getFinishedHearings($hearingsIds);
            foreach ($hearings as $hearing) {
                try {
                    $hearingId = (int) $hearing['hearing_id'];
                    $this->run($hearingId, $hearing);
                } catch (\Throwable $t) {
                    $this->logException($t, ['hearing' => $hearing]);
                }
            }
            $this->archiver->setLastRunAt($startTime);
            $this->entityManager->persist($this->archiver);
            $this->entityManager->flush();
        } catch (\Throwable $t) {
            $this->logException($t);
        }
    }

    public function run(int $hearingId, array $hearing)
    {
        if (null === $this->archiver) {
            throw new \RuntimeException('No archiver');
        }

        $this->logger->debug(sprintf('Getting ShareFile folder for hearing %d', $hearingId));
        $hearingFolder = $this->shareFileService->findHearing('H'.$hearingId);

        $this->logger->debug(sprintf('Getting Deskpro tickets for hearing %d', $hearingId));
        $tickets = $this->deskproService->getHearingTickets($hearingId);

        $this->logger->debug(sprintf('Generating overview spreadsheet hearing %d', $hearingId));
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Dato modtaget',
            'HøringID',
            'Høringssvar ID',
            'Emne',
            'Navn på afsender',
            'Udtaler sig som',
            'Evt. Navn på organisation',
            'Resume',
            'Vurdering',
        ];

        $row = 1;
        $addRow = function (array $values) use ($sheet, &$row) {
            foreach ($values as $index => $value) {
                if (\is_array($value)) {
                    // value, number format
                    $sheet->setCellValueByColumnAndRow($index + 1, $row, $value[0]);
                    $sheet->getStyleByColumnAndRow($index + 1, $row)
                        ->getNumberFormat()
                        ->setFormatCode($value[1]);
                } else {
                    $sheet->setCellValueByColumnAndRow($index + 1, $row, $value);
                }
            }
            ++$row;
        };
        $addRow($headers);

        // Resize columns to fit header content.
        $numberOfColumns = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($col = 1; $col <= $numberOfColumns; ++$col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->calculateColumnWidths();

        foreach ($tickets as $ticket) {
            $addRow([
                [
                    Date::dateTimeToExcel(new \DateTimeImmutable($ticket['date_created'])),
                    NumberFormat::FORMAT_DATE_DDMMYYYY,
                ],
                $ticket['fields']['hearing_id'],
                $ticket['ref'],
                $ticket['subject'],
                $ticket['person']['display_name'],
                reset($ticket['fields']['udtaler'])['title'] ?? '',
                $ticket['fields']['organization'],
                $ticket['fields']['resume'],
                $ticket['fields']['vurdering'],
            ]);
        }

        // Freeze first row.
        $sheet->freezePane('A2');

        // Add auto filter on all columns.
        $dimensions = 'A1'.':'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow();
        $sheet->setAutoFilter($dimensions);
        $sheet->getAutoFilter()->showHideRows();

        // Resize some columns to fit content.
        $autosizeColumns = [
            1, // date_created
            2, // hearing_id
            3, // ref
            5, // person display_name
            6, // udtaler sig som
            7, // organization
        ];
        for ($col = 1; $col <= $numberOfColumns; ++$col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(\in_array($col, $autosizeColumns, true));
        }
        $sheet->calculateColumnWidths();

        // Header styling.
        $headerBackgroundColor = '4472C4';
        $headerColor = Color::COLOR_WHITE;
        $sheet->getStyle('A1:'.$sheet->getHighestDataColumn().'1')
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($headerBackgroundColor);
        $sheet->getStyle('A1:'.$sheet->getHighestDataColumn().'1')
            ->getFont()->getColor()->setARGB($headerColor);

        // Zebra striping.
        $color = 'D9E1F2';
        $range = 'A2:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow();
        $conditional1 = new Conditional();
        $conditional1->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->setOperatorType(Conditional::OPERATOR_EQUAL)
            ->addCondition('MOD(ROW(),2)=0');
        $conditional1->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($color);
        $conditional1->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
            ->getEndColor()->setARGB($color);
        $sheet->getStyle($range)->setConditionalStyles([$conditional1]);

        $filename = sprintf(
            '%s/var/xlsx/overblik.xlsx',
            $this->parameters->get('kernel.project_dir'),
            $hearingId
        );
        if (!is_dir(\dirname($filename))) {
            mkdir(\dirname($filename));
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);
        $this->logger->info(sprintf('Overview written to file %s', $filename));

        $result = $this->shareFileService->uploadFile($filename, $hearingFolder->getId());

        $this->logger->info(sprintf(
            'File %s uploaded to ShareFile folder %s',
            basename($filename),
            $hearingFolder->getId()
        ));
    }

    private function getHearings(array $hearingIds = null)
    {
        $this->logger->info('Getting all hearings');
        $config = $this->archiver->getConfigurationValue('hearings');
        if (!isset($config['api_url'])) {
            throw new RuntimeException('Missing hearings api url');
        }

        $hearings = [];

        $client = new Client();

        $url = $config['api_url'];
        while (null !== $url) {
            $this->logger->debug(sprintf('api url: %s', $url));
            $response = $client->get($url);
            $data = json_decode((string) $response->getBody(), true);

            $hearings[] = array_map(function ($feature) {
                return $feature['properties'];
            }, $data['features']);

            // Parse link header (cf. https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Link).
            $next = null;
            $link = $response->getHeader('link');
            $rels = reset($link);
            if ($rels && preg_match_all('/<(?P<url>[^>]+)>; rel="(?P<rel>[^"]+)"/', $rels, $matches, PREG_SET_ORDER)) {
                $next = array_values(array_filter($matches, static function ($match) {
                    return 'next' === $match['rel'];
                }))[0] ?? null;
            }

            $url = $next['url'] ?? null;
        }

        // Flatten.
        $hearings = array_merge(...$hearings);

        if (!empty($hearingIds)) {
            $hearings = array_filter($hearings, static function ($properties) use ($hearingIds) {
                return \in_array($properties['hearing_id'], $hearingIds, true);
            });
        }

        return $hearings;
    }

    private function getFinishedHearings(array $hearingIds = null)
    {
        $hearings = $this->getHearings($hearingIds);

        $to = new \DateTime();
        $lastRunAt = $this->archiver->getLastRunAt() ?? new \DateTime('2001-01-01');
        $from = clone $lastRunAt;
        // Allow changes on hearings after reply deadline.
        try {
            $from->modify($this->parameters->get('hearing_reply_deadline_offset'));
        } catch (\Throwable $t) {
        }

        $this->logger->info(sprintf('Getting hearings finished after %s', $from->format(\DateTimeImmutable::ATOM)));

        // Get hearings finished since last run.
        $hearings = array_filter(
            $hearings,
            function ($hearing) use ($from, $to) {
                $deadline = new \DateTime($hearing['hearing_reply_deadline']);

                return $from <= $deadline && $deadline < $to;
            }
        );

        // Keep only hearings with new content in ShareFile.
        $hearings = array_filter(
            $hearings,
            function ($hearing) use ($lastRunAt) {
                try {
                    $hearingId = 'H'.$hearing['hearing_id'];
                    $hearing = $this->shareFileService->findHearing($hearingId);
                    $lastChangeAt = new \DateTime($hearing['ProgenyEditDate']);

                    return $lastChangeAt >= $lastRunAt;
                } catch (\Throwable $t) {
                    return false;
                }
            }
        );

        return $hearings;
    }

    private function logException(\Throwable $t, array $context = [])
    {
        $this->logger->emergency($t->getMessage());
        $logEntry = new ExceptionLogEntry($t, $context);
        $this->entityManager->persist($logEntry);
        $this->entityManager->flush();

        if (null !== $this->archiver) {
            $config = $this->archiver->getConfigurationValue('[notifications][email]');

            if ($config) {
                $message = (new \Swift_Message($t->getMessage()))
                    ->setFrom($config['from'])
                    ->setTo($config['to'])
                    ->setBody(
                        implode(
                            PHP_EOL.PHP_EOL,
                            [
                                json_encode($context, JSON_PRETTY_PRINT),
                                $t->getTraceAsString(),
                            ]
                        ),
                        'text/plain'
                    );

                $this->mailer->send($message);
            }
        }
    }
}
