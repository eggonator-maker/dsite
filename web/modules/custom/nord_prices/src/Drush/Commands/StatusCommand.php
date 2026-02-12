<?php

namespace Drupal\nord_prices\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show price import statistics.
 */
#[AsCommand(
  name: 'nord_prices:status',
  description: 'Show import statistics',
  aliases: ['npi-status']
)]
final class StatusCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->setHelp('Displays statistics about imported price data.')
      ->addUsage('nord_prices:status');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');

    $types = [
      'Main Categories' => 'price_main_category',
      'Subcategories' => 'price_subcategory',
      'Service Groups' => 'price_service_group',
      'Price Items' => 'price_item',
    ];

    $io->title('Price Import Statistics');

    $rows = [];
    foreach ($types as $label => $type) {
      $query = $paragraph_storage->getQuery()
        ->condition('type', $type)
        ->accessCheck(FALSE);

      $count = $query->count()->execute();
      $rows[] = [$label, $count];
    }

    $io->table(['Type', 'Count'], $rows);

    // Get last import time
    $query = $paragraph_storage->getQuery()
      ->condition('type', 'price_main_category')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $ids = $query->execute();

    if ($ids) {
      $paragraph = $paragraph_storage->load(reset($ids));
      $date = date('Y-m-d H:i:s', $paragraph->getCreatedTime());
      $io->info("Last Import: $date");
    }
    else {
      $io->warning("Last Import: Never");
    }

    return Command::SUCCESS;
  }

}