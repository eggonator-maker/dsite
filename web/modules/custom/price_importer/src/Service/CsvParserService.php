<?php

declare(strict_types=1);

namespace Drupal\price_importer\Service;

use Drupal\price_importer\DataTransfer\ImportData;
use Drupal\price_importer\DataTransfer\MainCategory;
use Drupal\price_importer\DataTransfer\PriceItem;
use Drupal\price_importer\DataTransfer\ServiceGroup;
use Drupal\price_importer\DataTransfer\SubCategory;
use Drupal\price_importer\Exception\PriceImportException;

/**
 * Parses CSV files into the internal price import data structure.
 *
 * Expected CSV columns:
 *   main_category, subcategory, service_group, display_name, price, appointment_url
 *
 * - If service_group is non-empty the subcategory uses 'service_groups' display mode.
 * - If service_group is empty the subcategory uses 'direct_items' display mode.
 */
class CsvParserService {

  /**
   * Required CSV column names.
   */
  const REQUIRED_COLUMNS = ['main_category', 'subcategory', 'display_name', 'price'];

  /**
   * Parses a CSV file at the given path.
   *
   * @param string $filePath
   *   Absolute path to the CSV file.
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  public function parseFile(string $filePath): ImportData {
    if (!file_exists($filePath)) {
      throw new PriceImportException("CSV file not found: {$filePath}");
    }

    $handle = fopen($filePath, 'r');
    if ($handle === FALSE) {
      throw new PriceImportException("Cannot open CSV file: {$filePath}");
    }

    try {
      return $this->parseHandle($handle);
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Parses a CSV string directly (useful for testing).
   *
   * @param string $csvContent
   *   Raw CSV content.
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  public function parseString(string $csvContent): ImportData {
    if (empty(trim($csvContent))) {
      throw new PriceImportException('CSV content is empty.');
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csvContent);
    rewind($handle);

    try {
      return $this->parseHandle($handle);
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Parses an open file handle into ImportData.
   *
   * @param resource $handle
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  protected function parseHandle($handle): ImportData {
    $header = fgetcsv($handle);
    if ($header === FALSE || empty($header)) {
      throw new PriceImportException('CSV file has no header row.');
    }

    // Normalise header: trim whitespace and lowercase.
    $header = array_map(static fn($col) => strtolower(trim((string) $col)), $header);

    $missing = array_diff(self::REQUIRED_COLUMNS, $header);
    if (!empty($missing)) {
      throw new PriceImportException(
        'CSV is missing required columns: ' . implode(', ', $missing)
      );
    }

    $colIndex = array_flip($header);
    $rows = [];
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== FALSE) {
      $lineNumber++;
      // Pad row with empty strings if fewer columns than header.
      $row = array_pad($row, count($header), '');
      $parsed = $this->parseRow($row, $colIndex);
      if ($parsed !== NULL) {
        $rows[] = $parsed;
      }
    }

    if (empty($rows)) {
      throw new PriceImportException('CSV file contains no valid data rows.');
    }

    return $this->buildImportData($rows);
  }

  /**
   * Parses a single CSV row into a normalised associative array.
   *
   * Returns NULL for rows that should be skipped (blank lines, etc.).
   *
   * @param array $row
   *   Raw CSV row values.
   * @param array $colIndex
   *   Map of column name => array index.
   *
   * @return array|null
   */
  protected function parseRow(array $row, array $colIndex): ?array {
    $get = static fn(string $col): string => trim($row[$colIndex[$col] ?? -1] ?? '');

    $mainCategory = $get('main_category');
    $subcategory = $get('subcategory');
    $displayName = $get('display_name');

    // Skip rows without the core required fields.
    if ($mainCategory === '' || $subcategory === '' || $displayName === '') {
      return NULL;
    }

    $price = $this->parsePrice($get('price'));

    return [
      'main_category' => $mainCategory,
      'subcategory' => $subcategory,
      'service_group' => $get('service_group'),
      'display_name' => $displayName,
      'price' => $price,
      'appointment_url' => $get('appointment_url'),
    ];
  }

  /**
   * Parses a price string into a float value.
   *
   * Handles formats like "1.234,56" (Romanian) or "1,234.56" (English).
   *
   * @param string $value
   *
   * @return float
   */
  public function parsePrice(string $value): float {
    if ($value === '') {
      return 0.0;
    }

    // Remove whitespace and common thousand-separator characters.
    $cleaned = preg_replace('/\s/', '', $value);

    // If the string has both comma and dot, the last one is the decimal separator.
    if (str_contains($cleaned, ',') && str_contains($cleaned, '.')) {
      // Remove thousand separators (the first type) and keep decimal (the last).
      if (strrpos($cleaned, ',') > strrpos($cleaned, '.')) {
        // Comma is decimal separator: 1.234,56 → 1234.56
        $cleaned = str_replace('.', '', $cleaned);
        $cleaned = str_replace(',', '.', $cleaned);
      }
      else {
        // Dot is decimal separator: 1,234.56 → 1234.56
        $cleaned = str_replace(',', '', $cleaned);
      }
    }
    elseif (str_contains($cleaned, ',')) {
      // Only comma: treat as decimal separator.
      $cleaned = str_replace(',', '.', $cleaned);
    }

    // Remove anything that is not a digit, dot, or minus sign.
    $cleaned = preg_replace('/[^0-9.\-]/', '', $cleaned);

    return is_numeric($cleaned) ? (float) $cleaned : 0.0;
  }

  /**
   * Builds an ImportData object from a flat array of normalised rows.
   *
   * @param array $rows
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   */
  public function buildImportData(array $rows): ImportData {
    $data = new ImportData();

    // Group rows: main_category → subcategory → [rows].
    $structure = [];
    foreach ($rows as $row) {
      $structure[$row['main_category']][$row['subcategory']][] = $row;
    }

    $isFirstCategory = TRUE;
    foreach ($structure as $catName => $subcategories) {
      $mainCategory = new MainCategory($catName, $isFirstCategory);
      $isFirstCategory = FALSE;

      foreach ($subcategories as $subName => $items) {
        // Determine display mode: any non-empty service_group → service_groups mode.
        $hasGroups = !empty(array_filter(array_column($items, 'service_group')));
        $displayMode = $hasGroups ? 'service_groups' : 'direct_items';
        $anchorId = $this->generateAnchorId($subName);

        $subCategory = new SubCategory($subName, $anchorId, $displayMode);

        if ($displayMode === 'service_groups') {
          $groups = [];
          foreach ($items as $item) {
            $groupName = $item['service_group'] !== '' ? $item['service_group'] : 'General';
            if (!isset($groups[$groupName])) {
              $groups[$groupName] = new ServiceGroup($groupName);
            }
            $groups[$groupName]->items[] = new PriceItem(
              $item['display_name'],
              $item['price'],
              $item['appointment_url'],
            );
          }
          $subCategory->serviceGroups = array_values($groups);
        }
        else {
          foreach ($items as $item) {
            $subCategory->directItems[] = new PriceItem(
              $item['display_name'],
              $item['price'],
              $item['appointment_url'],
            );
          }
        }

        $mainCategory->subcategories[] = $subCategory;
      }

      $data->mainCategories[] = $mainCategory;
    }

    return $data;
  }

  /**
   * Generates a URL-safe anchor ID from a string.
   *
   * Mirrors the `clean_id` Twig filter in PricesTwigExtension and prepends
   * 'group-' to match the sidebar navigation links.
   *
   * @param string $name
   *
   * @return string
   */
  public function generateAnchorId(string $name): string {
    $replacements = [
      'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
      'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't',
    ];
    $name = strtr($name, $replacements);
    $name = mb_strtolower($name, 'UTF-8');
    $name = (string) preg_replace('/[^a-z0-9]+/', '-', $name);
    $name = trim($name, '-');
    return 'group-' . $name;
  }

}
