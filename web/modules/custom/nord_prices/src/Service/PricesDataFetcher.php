<?php

namespace Drupal\nord_prices\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for fetching price data from MedAPI.
 */
class PricesDataFetcher {

  protected $httpClient;
  protected $fileSystem;
  protected $logger;
  protected $config;

  const BASE_URL = 'https://medapi.ro';
  const DOCTORS_URL = '/sync/doctor-muntenia';
  const DETAILS_URL = '/sync/doctor-muntenia/details';
  const LAB_URL = '/sync/lab-muntenia';

  public function __construct(
    ClientInterface $http_client,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('nord_prices');
    $this->config = $config_factory->get('nord_prices.settings');
  }

  /**
   * Fetch all data from MedAPI.
   */
  public function fetchFromApi() {
    $this->logger->info('Starting API data fetch');
    
    try {
      $doctors = $this->fetchDoctors();
      $this->logger->info('Fetched @count doctors', ['@count' => count($doctors)]);
      
      $doctors_services = $this->fetchDoctorServices($doctors);
      $this->logger->info('Fetched @count doctor services', ['@count' => count($doctors_services)]);
      
      $lab_services = $this->fetchLabServices();
      $this->logger->info('Fetched @count lab services', ['@count' => count($lab_services)]);
      
      // Restructure data
      $structured_data = $this->restructureData($doctors_services, $lab_services);
      
      return $structured_data;
    }
    catch (\Exception $e) {
      $this->logger->error('API fetch failed: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Fetch doctors list.
   */
  protected function fetchDoctors() {
    $url = self::BASE_URL . self::DOCTORS_URL;
    $headers = $this->getHeaders();
    
    $response = $this->httpClient->get($url, ['headers' => $headers]);
    $data = json_decode($response->getBody(), TRUE);
    
    $doctors = [];
    foreach ($data['data'] ?? [] as $row) {
      if (!$row || count($row) < 2) {
        continue;
      }
      $doctors[] = [
        'DoctorId' => (int) $row[0],
        'DoctorName' => $row[1],
        'DoctorTitle' => $row[2] ?? NULL,
      ];
    }
    
    return $doctors;
  }

  /**
   * Fetch services for all doctors.
   */
  protected function fetchDoctorServices(array $doctors) {
    $url = self::BASE_URL . self::DETAILS_URL;
    $headers = array_merge($this->getHeaders(), ['Content-Type' => 'application/json']);
    
    $all_services = [];
    $total = count($doctors);
    
    foreach ($doctors as $index => $doctor) {
      if (($index + 1) % 10 === 0) {
        $this->logger->info('Processing doctor @current/@total', [
          '@current' => $index + 1,
          '@total' => $total,
        ]);
      }
      
      try {
        $response = $this->httpClient->post($url, [
          'headers' => $headers,
          'json' => ['DoctorId' => $doctor['DoctorId']],
        ]);
        
        $data = json_decode($response->getBody(), TRUE);
        
        if (!($data['isValid'] ?? FALSE)) {
          continue;
        }
        
        foreach ($data['data'] ?? [] as $entry) {
          foreach ($entry['Services'] ?? [] as $service) {
            $all_services[] = [
              'medic' => $doctor['DoctorName'],
              'medic_id' => $doctor['DoctorId'],
              'interventie' => $service['ServiceName'],
              'pret' => $this->parsePrice($service['Price']),
              'unitate' => $entry['MedicalUnitName'],
              'specialitate' => $entry['SpecialtyName'],
              'service_id' => $service['ServiceId'],
            ];
          }
        }
        
        usleep(100000); // 100ms delay
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to fetch services for doctor @id: @message', [
          '@id' => $doctor['DoctorId'],
          '@message' => $e->getMessage(),
        ]);
      }
    }
    
    return $all_services;
  }

  /**
   * Fetch lab services.
   */
  protected function fetchLabServices() {
    $url = self::BASE_URL . self::LAB_URL;
    $headers = $this->getHeaders();
    
    $response = $this->httpClient->get($url, ['headers' => $headers]);
    $data = json_decode($response->getBody(), TRUE);
    
    $services = [];
    foreach ($data['data'] ?? [] as $lab) {
      foreach ($lab['Services'] ?? [] as $service) {
        $services[] = [
          'LaboratoryId' => $lab['LaboratoryId'],
          'LaboratoryName' => $lab['LaboratoryName'],
          'ServiceGroup' => $service['ServiceGroup'],
          'ServiceId' => $service['ServiceId'],
          'ServiceName' => $service['ServiceName'],
          'Price' => $this->parsePrice($service['Price']),
        ];
      }
    }
    
    return $services;
  }

  /**
   * Restructure data to match Drupal structure.
   */
  protected function restructureData(array $doctors_services, array $lab_services) {
    $main_categories = [];
    
    // Process lab services
    $lab_structure = [];
    foreach ($lab_services as $service) {
      $lab_name = $service['LaboratoryName'] ?: 'Unknown Laboratory';
      $group_name = $service['ServiceGroup'] ?: 'Unknown Group';
      
      $lab_structure[$lab_name][$group_name][] = [
        'item_name' => $service['ServiceName'],
        'service_id' => $service['ServiceId'],
        'laboratory_id' => $service['LaboratoryId'],
        'price' => $service['Price'],
      ];
    }
    
    foreach ($lab_structure as $lab_name => $groups) {
      $service_groups = [];
      foreach ($groups as $group_name => $items) {
        $service_groups[] = [
          'service_name' => $group_name,
          'items' => $items,
        ];
      }
      
      $main_categories[] = [
        'main_category' => 'Laborator',
        'subcategory' => $lab_name,
        'service_groups' => $service_groups,
      ];
    }
    
    // Process doctor services
    $doctor_structure = [];
    foreach ($doctors_services as $service) {
      $specialty = $service['specialitate'] ?: 'Unknown Specialty';
      $service_name = $service['interventie'] ?: 'Unknown Service';
      
      $doctor_structure[$specialty][$service_name][] = [
        'item_name' => $service['medic'],
        'doctor_id' => $service['medic_id'],
        'service_id' => $service['service_id'],
        'price' => $service['pret'],
        'unit_name' => $service['unitate'],
      ];
    }
    
    foreach ($doctor_structure as $specialty => $services) {
      $service_groups = [];
      foreach ($services as $service_name => $items) {
        $service_groups[] = [
          'service_name' => $service_name,
          'items' => $items,
        ];
      }
      
      $main_categories[] = [
        'main_category' => 'Ambulatoriu',
        'subcategory' => $specialty,
        'service_groups' => $service_groups,
      ];
    }
    
    return ['main_categories' => $main_categories];
  }

  /**
   * Get request headers (token is optional).
   */
  protected function getHeaders() {
    $headers = ['Accept' => 'application/json'];
    
    // Token is optional - only add if present
    $token = getenv('MEDAPI_TOKEN') ?: $this->config->get('api_token');
    if ($token) {
      $headers['Authorization'] = 'Bearer ' . $token;
      $this->logger->info('Using API token for authentication');
    }
    else {
      $this->logger->info('No API token provided - using public access');
    }
    
    return $headers;
  }

  /**
   * Parse price value.
   */
  protected function parsePrice($value) {
    try {
      return (float) $value;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}