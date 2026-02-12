<?php

namespace Drupal\nord_prices\Drush\Commands;

use Drupal\nord_prices\Service\CsvParser;
use Drupal\nord_prices\Service\PricesImporter;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import prices from CSV files.
 */
#[AsCommand(
  name: 'nord_prices:import-csv',
  description: 'Import prices from CSV files',
  aliases: ['npi-csv']
)]
final class ImportCsvCommand extends Command {

  use AutowireTrait;
  public const NAME = 'nord_prices:import-csv';   

  public function __construct(
    private readonly CsvParser $csvParser,
    private readonly PricesImporter $importer,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->setHelp('Imports price data from CSV files.')
      ->addArgument('doctors_csv', InputArgument::REQUIRED, 'Path to doctors_services.csv')
      ->addArgument('lab_csv', InputArgument::REQUIRED, 'Path to lab_services.csv')
      ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Import mode: replace or update', 'replace')
      ->addUsage('nord_prices:import-csv /path/to/doctors.csv /path/to/lab.csv')
      ->addUsage('nord_prices:import-csv /path/to/doctors.csv /path/to/lab.csv --mode=replace');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $doctors_csv = $input->getArgument('doctors_csv');
    $lab_csv = $input->getArgument('lab_csv');
    $mode = $input->getOption('mode');

    if (!file_exists($doctors_csv)) {
      $io->error("Doctors CSV file not found: $doctors_csv");
      return Command::FAILURE;
    }

    if (!file_exists($lab_csv)) {
      $io->error("Lab CSV file not found: $lab_csv");
      return Command::FAILURE;
    }

    $io->title('Importing Prices from CSV');
    $io->text('Parsing CSV files...');

    try {
      $data = $this->csvParser->parseCsv($doctors_csv, $lab_csv);

      $io->text('Importing prices...');
      $result = $this->importer->import($data, $mode);

      if ($result['success']) {
        $io->success("Successfully imported {$result['count']} price categories");
        return Command::SUCCESS;
      }
      else {
        $io->error('Import failed');
        return Command::FAILURE;
      }
    }
    catch (\Exception $e) {
      $io->error('Import error: ' . $e->getMessage());
      $this->logger->error('Import error: ' . $e->getMessage());
      return Command::FAILURE;
    }
  }

}