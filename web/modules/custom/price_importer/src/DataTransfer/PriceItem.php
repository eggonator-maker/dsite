<?php

declare(strict_types=1);

namespace Drupal\price_importer\DataTransfer;

/**
 * Represents a single price item (a doctor, test, or service with price).
 */
final class PriceItem {

  /**
   * @param string $displayName
   *   The item display name (doctor name, test name, etc.).
   * @param float $price
   *   The price in RON (lei).
   * @param string $appointmentUrl
   *   Optional appointment booking URL.
   */
  public function __construct(
    public readonly string $displayName,
    public readonly float $price,
    public readonly string $appointmentUrl = '',
  ) {}

}
