<?php

declare(strict_types=1);

namespace Drupal\price_importer\DataTransfer;

/**
 * Represents a service group within a subcategory (shown as accordion).
 */
final class ServiceGroup {

  /**
   * @var \Drupal\price_importer\DataTransfer\PriceItem[]
   */
  public array $items = [];

  /**
   * @param string $name
   *   The service/group display name (accordion header).
   */
  public function __construct(public readonly string $name) {}

}
