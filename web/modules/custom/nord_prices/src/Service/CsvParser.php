<?php

namespace Drupal\nord_prices\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for parsing CSV files.
 */
class CsvParser {

  protected $logger;

  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('nord_prices');
  }

  /**
   * Parse CSV files and return structured data.
   * 
   * @param string $doctors_csv_path
   *   Path to doctors_services.csv
   * @param string $lab_csv_path
   *   Path to lab_services.csv
   */
  public function parseCsv($doctors_csv_path, $lab_csv_path) {
    $this->logger->info('Parsing CSV files');
    
    try {
      $doctors_services = $this->parseDoctorsCsv($doctors_csv_path);
      $lab_services = $this->parseLabCsv($lab_csv_path);
      
      return $this->restructureCsvData($doctors_services, $lab_services);
    }
    catch (\Exception $e) {
      $this->logger->error('CSV parsing failed: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to parse CSV files: ' . $e->getMessage());
    }
  }

  /**
   * Parse doctors services CSV.
   */
  protected function parseDoctorsCsv($file_path) {
    if (!file_exists($file_path)) {
      throw new \Exception("Doctors CSV file not found: $file_path");
    }
    
    $data = [];
    $handle = fopen($file_path, 'r');
    
    if ($handle === FALSE) {
      throw new \Exception("Could not open doctors CSV file: $file_path");
    }
    
    // Read header
    $header = fgetcsv($handle);
    
    // Map header to indices
    $header_map = array_flip($header);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
      if (empty($row[0])) {
        continue; // Skip empty rows
      }
      
      $data[] = [
        'medic' => $row[$header_map['medic']] ?? '',
        'medic_id' => (int) ($row[$header_map['medic_id']] ?? 0),
        'interventie' => $row[$header_map['interventie']] ?? '',
        'pret' => (float) ($row[$header_map['pret']] ?? 0),
        'unitate' => $row[$header_map['unitate']] ?? '',
        'specialitate' => $row[$header_map['specialitate']] ?? '',
        'service_id' => $row[$header_map['service_id']] ?? '',
      ];
    }
    
    fclose($handle);
    
    $this->logger->info('Parsed @count doctor services', ['@count' => count($data)]);
    
    return $data;
  }

  /**
   * Parse lab services CSV.
   */
  protected function parseLabCsv($file_path) {
    if (!file_exists($file_path)) {
      throw new \Exception("Lab CSV file not found: $file_path");
    }
    
    $data = [];
    $handle = fopen($file_path, 'r');
    
    if ($handle === FALSE) {
      throw new \Exception("Could not open lab CSV file: $file_path");
    }
    
    // Read header
    $header = fgetcsv($handle);
    
    // Map header to indices
    $header_map = array_flip($header);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
      if (empty($row[0])) {
        continue; // Skip empty rows
      }
      
      $data[] = [
        'LaboratoryId' => $row[$header_map['LaboratoryId']] ?? '',
        'LaboratoryName' => $row[$header_map['LaboratoryName']] ?? '',
        'ServiceGroup' => $row[$header_map['ServiceGroup']] ?? '',
        'ServiceId' => $row[$header_map['ServiceId']] ?? '',
        'ServiceName' => $row[$header_map['ServiceName']] ?? '',
        'Price' => (float) ($row[$header_map['Price']] ?? 0),
      ];
    }
    
    fclose($handle);
    
    $this->logger->info('Parsed @count lab services', ['@count' => count($data)]);
    
    return $data;
  }

  /**
   * Restructure CSV data to match API structure.
   */
  protected function restructureCsvData(array $doctors_services, array $lab_services) {
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

}