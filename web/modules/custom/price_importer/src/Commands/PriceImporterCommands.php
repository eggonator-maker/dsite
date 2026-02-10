<?php

declare(strict_types=1);

namespace Drupal\price_importer\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\price_importer\DataTransfer\ImportData;
use Drupal\price_importer\Exception\PriceImportException;
use Drupal\price_importer\Service\ApiClientService;
use Drupal\price_importer\Service\CsvParserService;
use Drupal\price_importer\Service\PriceImporterService;

/**
 * Drush commands for managing price imports.
 */
class PriceImporterCommands extends DrushCommands {

  public function __construct(
    protected readonly CsvParserService $csvParser,
    protected readonly ApiClientService $apiClient,
    protected readonly PriceImporterService $priceImporter,
  ) {
    parent::__construct();
  }

  /**
   * Import prices from a CSV file.
   */
  #[CLI\Command(name: 'price-import:csv', aliases: ['pi-csv'])]
  #[CLI\Argument(name: 'file', description: 'Absolute or relative path to the CSV file.')]
  #[CLI\Usage(name: 'drush price-import:csv /path/to/drupal_prices_import.csv', description: 'Import from a specific CSV file.')]
  public function importCsv(string $file): void {
    $path = realpath($file);
    if ($path === FALSE || !file_exists($path)) {
      throw new \InvalidArgumentException("File not found: {$file}");
    }

    $this->logger()->info("Parsing CSV: {$path}");

    try {
      $data = $this->csvParser->parseFile($path);
      $this->logDataSummary($data);

      $this->logger()->info('Importing…');
      $this->priceImporter->import($data);
      $this->logger()->success('Price import from CSV completed successfully.');
    }
    catch (PriceImportException $e) {
      $this->logger()->error("Import failed: {$e->getMessage()}");
      throw $e;
    }
  }

  /**
   * Import prices directly from the MedAPI.
   */
  #[CLI\Command(name: 'price-import:api', aliases: ['pi-api'])]
  #[CLI\Option(name: 'token', description: 'Override the API bearer token for this run.')]
  #[CLI\Usage(name: 'drush price-import:api', description: 'Import using the configured token or MEDAPI_TOKEN env var.')]
  #[CLI\Usage(name: 'drush price-import:api --token=abc123', description: 'Import using an explicit token.')]
  public function importApi(array $options = ['token' => '']): void {
    // Allow overriding the token on the command line without storing it.
    if (!empty($options['token'])) {
      putenv('MEDAPI_TOKEN=' . $options['token']);
    }

    $this->logger()->info('Fetching prices from MedAPI…');

    try {
      $data = $this->apiClient->fetchAll();
      $this->logDataSummary($data);

      $this->logger()->info('Importing…');
      $this->priceImporter->import($data);
      $this->logger()->success('Price import from API completed successfully.');
    }
    catch (PriceImportException $e) {
      $this->logger()->error("Import failed: {$e->getMessage()}");
      throw $e;
    }
  }

  /**
   * Logs a human-readable summary of the data that will be imported.
   */
  protected function logDataSummary(ImportData $data): void {
    $totalItems = 0;
    $totalGroups = 0;
    $totalSubs = 0;

    foreach ($data->mainCategories as $cat) {
      $totalSubs += count($cat->subcategories);
      foreach ($cat->subcategories as $sub) {
        $totalItems += count($sub->directItems);
        $totalGroups += count($sub->serviceGroups);
        foreach ($sub->serviceGroups as $group) {
          $totalItems += count($group->items);
        }
      }
    }

    $this->logger()->info(
      sprintf(
        'Data summary: %d main categories, %d subcategories, %d service groups, %d price items.',
        count($data->mainCategories),
        $totalSubs,
        $totalGroups,
        $totalItems,
      )
    );
  }

}
