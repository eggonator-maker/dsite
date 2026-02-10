<?php

declare(strict_types=1);

namespace Drupal\price_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\price_importer\DataTransfer\ImportData;
use Drupal\price_importer\DataTransfer\MainCategory;
use Drupal\price_importer\DataTransfer\PriceItem;
use Drupal\price_importer\DataTransfer\ServiceGroup;
use Drupal\price_importer\DataTransfer\SubCategory;
use Drupal\price_importer\Exception\PriceImportException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Fetches medical price data directly from the MedAPI.
 *
 * Mirrors the logic of the Python price_scrape.py script.
 */
class ApiClientService {

  const API_BASE_URL = 'https://medapi.ro';
  const DOCTORS_PATH = '/sync/doctor-muntenia';
  const DETAILS_PATH = '/sync/doctor-muntenia/details';
  const LAB_PATH = '/sync/lab-muntenia';

  /**
   * Delay between doctor detail requests (microseconds).
   */
  const REQUEST_DELAY_US = 100000;

  protected LoggerInterface $logger;

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('price_importer');
  }

  /**
   * Fetches all price data from the API and returns an ImportData object.
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  public function fetchAll(): ImportData {
    $token = $this->resolveToken();

    if ($token === '') {
      $this->logger->notice('No API token configured; requests will be sent without authentication.');
    }

    $data = new ImportData();

    $labData = $this->fetchLabServices($token);
    $data->mainCategories = array_merge($data->mainCategories, $labData->mainCategories);

    $doctorData = $this->fetchDoctorServices($token);
    $data->mainCategories = array_merge($data->mainCategories, $doctorData->mainCategories);

    // Mark first category as expanded for the sidebar accordion.
    if (!empty($data->mainCategories)) {
      $data->mainCategories[0]->isExpanded = TRUE;
    }

    return $data;
  }

  /**
   * Resolves the API token from environment variable or module config.
   *
   * Returns an empty string if no token is available; the API will then be
   * called without an Authorization header (useful when the endpoint is
   * publicly accessible).
   *
   * @return string
   */
  public function resolveToken(): string {
    $token = (string) getenv('MEDAPI_TOKEN');
    if ($token !== '') {
      return trim($token);
    }

    return trim((string) ($this->configFactory->get('price_importer.settings')->get('api_token') ?? ''));
  }

  /**
   * Builds request headers, adding Authorization only when a token is present.
   *
   * @param string $token
   *
   * @return array
   */
  protected function buildHeaders(string $token): array {
    $headers = ['Accept' => 'application/json'];
    if ($token !== '') {
      $headers['Authorization'] = "Bearer {$token}";
    }
    return $headers;
  }

  /**
   * Fetches and structures laboratory services.
   *
   * @param string $token
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  protected function fetchLabServices(string $token): ImportData {
    $this->logger->info('Fetching laboratory services from API.');

    try {
      $response = $this->httpClient->request('GET', self::API_BASE_URL . self::LAB_PATH, [
        'headers' => $this->buildHeaders($token),
        'timeout' => 60,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      throw new PriceImportException("Failed to fetch lab services: {$e->getMessage()}", 0, $e);
    }

    $rows = [];
    foreach ($body['data'] ?? [] as $lab) {
      $labName = (string) ($lab['LaboratoryName'] ?? 'Unknown Laboratory');
      foreach ($lab['Services'] ?? [] as $service) {
        $rows[] = [
          'main_category' => 'Laborator',
          'subcategory' => $labName,
          'service_group' => (string) ($service['ServiceGroup'] ?? ''),
          'display_name' => (string) ($service['ServiceName'] ?? ''),
          'price' => $this->toFloat($service['Price'] ?? 0),
          'appointment_url' => '',
        ];
      }
    }

    $this->logger->info('Fetched @count laboratory service rows.', ['@count' => count($rows)]);
    return $this->buildFromRows($rows);
  }

  /**
   * Fetches all doctors then their individual service details.
   *
   * @param string $token
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   */
  protected function fetchDoctorServices(string $token): ImportData {
    $this->logger->info('Fetching doctor list from API.');

    try {
      $response = $this->httpClient->request('GET', self::API_BASE_URL . self::DOCTORS_PATH, [
        'headers' => $this->buildHeaders($token),
        'timeout' => 60,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      throw new PriceImportException("Failed to fetch doctor list: {$e->getMessage()}", 0, $e);
    }

    $doctors = [];
    foreach ($body['data'] ?? [] as $row) {
      if (empty($row) || count($row) < 2) {
        continue;
      }
      $doctors[] = [
        'id' => (int) $row[0],
        'name' => (string) ($row[1] ?? ''),
      ];
    }

    $this->logger->info('Found @count doctors. Fetching service details…', ['@count' => count($doctors)]);

    $rows = [];
    $total = count($doctors);
    foreach ($doctors as $i => $doctor) {
      if ($i > 0 && $i % 10 === 0) {
        $this->logger->info('Doctor progress: @current/@total.', [
          '@current' => $i,
          '@total' => $total,
        ]);
      }

      try {
        $services = $this->fetchDoctorDetails($doctor['id'], $token);
        foreach ($services as $service) {
          $rows[] = [
            'main_category' => 'Ambulatoriu',
            'subcategory' => $service['specialty'] !== '' ? $service['specialty'] : 'General',
            'service_group' => $service['service_name'],
            'display_name' => $doctor['name'],
            'price' => $this->toFloat($service['price']),
            'appointment_url' => '',
          ];
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not fetch services for doctor @id: @msg', [
          '@id' => $doctor['id'],
          '@msg' => $e->getMessage(),
        ]);
      }

      // Respect rate limit.
      usleep(self::REQUEST_DELAY_US);
    }

    $this->logger->info('Fetched @count doctor service rows.', ['@count' => count($rows)]);
    return $this->buildFromRows($rows);
  }

  /**
   * Fetches service details for a single doctor.
   *
   * @param int $doctorId
   * @param string $token
   *
   * @return array
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function fetchDoctorDetails(int $doctorId, string $token): array {
    $response = $this->httpClient->request('POST', self::API_BASE_URL . self::DETAILS_PATH, [
      'headers' => array_merge($this->buildHeaders($token), ['Content-Type' => 'application/json']),
      'body' => json_encode(['DoctorId' => $doctorId]),
      'timeout' => 60,
    ]);

    $body = json_decode((string) $response->getBody(), TRUE);

    if (!($body['isValid'] ?? FALSE)) {
      return [];
    }

    $services = [];
    foreach ($body['data'] ?? [] as $entry) {
      $specialty = (string) ($entry['SpecialtyName'] ?? '');
      foreach ($entry['Services'] ?? [] as $s) {
        $services[] = [
          'specialty' => $specialty,
          'service_name' => (string) ($s['ServiceName'] ?? ''),
          'price' => $s['Price'] ?? 0,
        ];
      }
    }

    return $services;
  }

  /**
   * Converts flat rows into an ImportData object.
   *
   * @param array $rows
   *
   * @return \Drupal\price_importer\DataTransfer\ImportData
   */
  public function buildFromRows(array $rows): ImportData {
    $data = new ImportData();
    $structure = [];

    foreach ($rows as $row) {
      if ($row['main_category'] === '' || $row['subcategory'] === '' || $row['display_name'] === '') {
        continue;
      }
      $structure[$row['main_category']][$row['subcategory']][] = $row;
    }

    foreach ($structure as $catName => $subcategories) {
      $mainCategory = new MainCategory($catName, FALSE);

      foreach ($subcategories as $subName => $items) {
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
              $this->toFloat($item['price']),
              $item['appointment_url'],
            );
          }
          $subCategory->serviceGroups = array_values($groups);
        }
        else {
          foreach ($items as $item) {
            $subCategory->directItems[] = new PriceItem(
              $item['display_name'],
              $this->toFloat($item['price']),
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
   * Safely converts a value to float.
   *
   * @param mixed $value
   *
   * @return float
   */
  protected function toFloat(mixed $value): float {
    if (is_float($value) || is_int($value)) {
      return (float) $value;
    }
    if (is_string($value)) {
      $cleaned = str_replace(',', '.', $value);
      if (is_numeric($cleaned)) {
        return (float) $cleaned;
      }
    }
    return 0.0;
  }

  /**
   * Generates a URL-safe anchor ID matching the theme's clean_id Twig filter.
   *
   * @param string $name
   *
   * @return string
   */
  protected function generateAnchorId(string $name): string {
    $replacements = [
      'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
      'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't',
    ];
    $name = strtr($name, $replacements);
    $name = mb_strtolower($name, 'UTF-8');
    $name = (string) preg_replace('/[^a-z0-9]+/', '-', $name);
    return 'group-' . trim($name, '-');
  }

}
