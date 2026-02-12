<?php

namespace Drupal\nord_prices\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\block_content\Entity\BlockContent;

/**
 * Service for importing price data into Drupal.
 */
class PricesImporter {

  protected $entityTypeManager;
  protected $logger;
  protected $messenger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('nord_prices');
    $this->messenger = $messenger;
  }

  /**
   * Import prices data.
   */
  public function import(array $data, $mode = 'replace') {
    $this->logger->info('Starting import with mode: @mode', ['@mode' => $mode]);
    
    if ($mode === 'replace') {
      $this->deleteExistingPrices();
    }
    
    $main_categories = [];
    $total = count($data['main_categories'] ?? []);
    
    foreach ($data['main_categories'] ?? [] as $index => $category_data) {
      try {
        $main_category = $this->createMainCategory($category_data);
        $main_categories[] = $main_category;
        
        if (($index + 1) % 5 === 0) {
          $this->logger->info('Created @current/@total main categories', [
            '@current' => $index + 1,
            '@total' => $total,
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to create category @name: @message', [
          '@name' => $category_data['main_category'] ?? 'Unknown',
          '@message' => $e->getMessage(),
        ]);
      }
    }
    
    // Update or create the prices block
    $block = $this->updatePricesBlock($main_categories);
    
    $this->logger->info('Import completed. Created @count main categories', [
      '@count' => count($main_categories),
    ]);
    
    return [
      'success' => TRUE,
      'count' => count($main_categories),
      'block_id' => $block->id(),
    ];
  }

  /**
   * Create main category paragraph with subcategories.
   */
  protected function createMainCategory(array $data) {
    $subcategory = $this->createSubcategory($data);
    
    $main_category = Paragraph::create([
      'type' => 'price_main_category',
      'field_category_name' => $data['main_category'] ?? 'Unknown Category',
      'field_is_expanded' => TRUE,
      'field_subcategories' => [$subcategory],
    ]);
    
    $main_category->save();
    
    return $main_category;
  }

  /**
   * Create subcategory paragraph.
   */
  protected function createSubcategory(array $data) {
    $subcategory_data = [
      'type' => 'price_subcategory',
      'field_subcategory_name' => $data['subcategory'] ?? 'Unknown Subcategory',
      'field_anchor_id' => $this->sanitizeId($data['subcategory'] ?? 'unknown'),
      'field_display_mode' => 'accordion', // or 'direct' - adjust as needed
    ];
    
    // Add service groups if they exist
    if (!empty($data['service_groups'])) {
      $service_groups = [];
      
      foreach ($data['service_groups'] as $group_data) {
        $service_group = $this->createServiceGroup($group_data);
        $service_groups[] = $service_group;
      }
      
      $subcategory_data['field_service_groups'] = $service_groups;
      $subcategory_data['field_display_mode'] = 'accordion';
    }
    
    // Add direct price items if they exist (for items without groups)
    if (!empty($data['direct_items'])) {
      $direct_items = [];
      
      foreach ($data['direct_items'] as $item_data) {
        $item = $this->createPriceItem($item_data);
        $direct_items[] = $item;
      }
      
      $subcategory_data['field_direct_price_items'] = $direct_items;
      $subcategory_data['field_display_mode'] = 'direct';
    }
    
    $subcategory = Paragraph::create($subcategory_data);
    $subcategory->save();
    
    return $subcategory;
  }

  /**
   * Create service group paragraph.
   */
  protected function createServiceGroup(array $data) {
    $items = [];
    
    foreach ($data['items'] as $item_data) {
      $item = $this->createPriceItem($item_data);
      $items[] = $item;
    }
    
    $service_group = Paragraph::create([
      'type' => 'price_service_group',
      'field_service_name' => $data['service_name'] ?? 'Unknown Service',
      'field_price_items' => $items,
    ]);
    
    $service_group->save();
    
    return $service_group;
  }

  /**
   * Create price item paragraph.
   */
  protected function createPriceItem(array $data) {
    $item_data = [
      'type' => 'price_item',
      'field_display_name' => $data['item_name'] ?? 'Unknown Item',
      'field_price' => $data['price'] ?? 0,
    ];
    
    // Add optional fields
    if (!empty($data['service_description'])) {
      $item_data['field_service_description'] = $data['service_description'];
    }
    
    // Store service_id, doctor_id, laboratory_id in service_description for now
    // You may want to add custom fields for these later
    $metadata = [];
    if (!empty($data['service_id'])) {
      $metadata[] = 'Service ID: ' . $data['service_id'];
    }
    if (!empty($data['doctor_id'])) {
      $metadata[] = 'Doctor ID: ' . $data['doctor_id'];
    }
    if (!empty($data['laboratory_id'])) {
      $metadata[] = 'Lab ID: ' . $data['laboratory_id'];
    }
    if (!empty($data['unit_name'])) {
      $metadata[] = 'Unit: ' . $data['unit_name'];
    }
    
    if (!empty($metadata)) {
      $item_data['field_service_description'] = implode(' | ', $metadata);
    }
    
    // Add appointment URL if you want to generate one
    // $item_data['field_appointment_url'] = ['uri' => 'https://example.com/book'];
    
    $item = Paragraph::create($item_data);
    $item->save();
    
    return $item;
  }

  /**
   * Update or create prices block.
   */
  protected function updatePricesBlock(array $main_categories) {
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    
    // Find existing prices block
    $query = $block_storage->getQuery()
      ->condition('type', 'prices_block')
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $ids = $query->execute();
    
    if ($ids) {
      $block = $block_storage->load(reset($ids));
      $this->logger->info('Updating existing block @id', ['@id' => $block->id()]);
    }
    else {
      $block = BlockContent::create([
        'type' => 'prices_block',
        'info' => 'Prices Block (Auto-generated)',
      ]);
      $this->logger->info('Creating new prices block');
    }
    
    $block->set('field_main_categories', $main_categories);
    $block->save();
    
    return $block;
  }

  /**
   * Delete all existing price paragraphs.
   */
  protected function deleteExistingPrices() {
    $this->logger->info('Deleting existing price data');
    
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    
    $types = [
      'price_main_category',
      'price_subcategory',
      'price_service_group',
      'price_item',
    ];
    
    foreach ($types as $type) {
      $query = $paragraph_storage->getQuery()
        ->condition('type', $type)
        ->accessCheck(FALSE);
      
      $ids = $query->execute();
      
      if ($ids) {
        $paragraphs = $paragraph_storage->loadMultiple($ids);
        $paragraph_storage->delete($paragraphs);
        $this->logger->info('Deleted @count paragraphs of type @type', [
          '@count' => count($ids),
          '@type' => $type,
        ]);
      }
    }
  }

  /**
   * Sanitize string for use as HTML ID.
   */
  protected function sanitizeId($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]+/', '-', $string);
    $string = trim($string, '-');
    return $string;
  }

}