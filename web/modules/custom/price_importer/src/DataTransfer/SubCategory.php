<?php

declare(strict_types=1);

namespace Drupal\price_importer\DataTransfer;

/**
 * Represents a price subcategory (e.g. a lab name or medical specialty).
 */
final class SubCategory {

  /**
   * @var \Drupal\price_importer\DataTransfer\ServiceGroup[]
   */
  public array $serviceGroups = [];

  /**
   * @var \Drupal\price_importer\DataTransfer\PriceItem[]
   */
  public array $directItems = [];

  /**
   * @param string $name
   *   The subcategory display name.
   * @param string $anchorId
   *   The HTML anchor ID used for sidebar navigation.
   * @param string $displayMode
   *   Either 'service_groups' (with accordion) or 'direct_items'.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $anchorId,
    public readonly string $displayMode = 'service_groups',
  ) {}

}
