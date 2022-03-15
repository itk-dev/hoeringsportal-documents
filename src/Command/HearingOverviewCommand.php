<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Command;

use App\Entity\Archiver;
use App\Service\HearingOverviewHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class HearingOverviewCommand extends Command
{
    protected static $defaultName = 'app:hearing:overview';
    protected $archiverType = Archiver::TYPE_HEARING_OVERVIEW;

    /** @var HearingOverviewHelper */
    private $helper;

    public function __construct(HearingOverviewHelper $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    public function configure()
    {
        parent::configure();
        $this->addArgument('hearing-id', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The hearing id');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $this->helper->setArchiver($this->archiver);
        $this->helper->setLogger(new ConsoleLogger($output));

        $hearingIds = $input->getArgument('hearing-id');

        $this->helper->process(array_map('intval', $hearingIds));
    }
}
