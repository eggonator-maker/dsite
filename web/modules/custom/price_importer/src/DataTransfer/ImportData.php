<?php

declare(strict_types=1);

namespace Drupal\price_importer\DataTransfer;

/**
 * Represents the full set of price data to be imported.
 */
final class ImportData {

  /**
   * @var \Drupal\price_importer\DataTransfer\MainCategory[]
   */
  public array $mainCategories = [];

}
