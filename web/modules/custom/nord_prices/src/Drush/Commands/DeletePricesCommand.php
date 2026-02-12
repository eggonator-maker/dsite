<?php

namespace Drupal\nord_prices\Drush\Commands;

use Drupal\nord_prices\Service\PricesImporter;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Delete all price data.
 */
#[AsCommand(
  name: 'nord_prices:delete',
  description: 'Delete all price data',
  aliases: ['npi-delete']
)]
final class DeletePricesCommand extends Command {

  use AutowireTrait;
  public const NAME = 'nord_prices:delete';   


  public function __construct(
    private readonly PricesImporter $importer,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->setHelp('Deletes all price paragraphs and blocks.')
      ->addUsage('nord_prices:delete');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    if (!$io->confirm('Are you sure you want to delete all price data?', false)) {
      $io->note('Delete operation cancelled.');
      return Command::SUCCESS;
    }

    $io->title('Deleting Price Data');

    try {
      $this->importer->import(['main_categories' => []], 'replace');
      $io->success('All price data deleted');
      return Command::SUCCESS;
    }
    catch (\Exception $e) {
      $io->error('Delete error: ' . $e->getMessage());
      $this->logger->error('Delete error: ' . $e->getMessage());
      return Command::FAILURE;
    }
  }

}