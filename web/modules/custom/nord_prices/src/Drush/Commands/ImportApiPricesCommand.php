<?php

namespace Drupal\nord_prices\Drush\Commands;

use Drupal\nord_prices\Service\PricesDataFetcher;
use Drupal\nord_prices\Service\PricesImporter;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
// 1. ADD THIS IMPORT
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import prices from MedAPI.
 */
#[AsCommand(
  name: 'nord_prices:import-api-prices',
  description: 'Import prices from MedAPI',
  aliases: ['npi-api-prices']
)]
final class ImportApiPricesCommand extends Command {

  use AutowireTrait;

  public const NAME = 'nord_prices:import-api-prices';

  public function __construct(
    private readonly PricesDataFetcher $dataFetcher,
    private readonly PricesImporter $importer,
    // 2. ADD THIS ATTRIBUTE
    #[Autowire(service: 'logger.channel.default')]
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->setHelp('Fetches price data from MedAPI and imports into Drupal.')
      ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Import mode: replace or update', 'replace')
      ->addUsage('nord_prices:import-api');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $mode = $input->getOption('mode');

    $io->title('Importing Prices from MedAPI');
    $io->text('Fetching data from MedAPI...');

    try {
      $data = $this->dataFetcher->fetchFromApi();

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