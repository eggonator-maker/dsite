<?php

declare(strict_types=1);

namespace Drupal\price_importer\DataTransfer;

/**
 * Represents a top-level price category (e.g. Laborator, Ambulatoriu).
 */
final class MainCategory {

  /**
   * @var \Drupal\price_importer\DataTransfer\SubCategory[]
   */
  public array $subcategories = [];

  /**
   * @param string $name
   *   The category display name.
   * @param bool $isExpanded
   *   Whether the sidebar accordion is expanded by default.
   */
  public function __construct(
    public readonly string $name,
    public bool $isExpanded = FALSE,
  ) {}

}
