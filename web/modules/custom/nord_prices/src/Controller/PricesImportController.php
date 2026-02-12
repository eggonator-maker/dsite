<?php

namespace Drupal\nord_prices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for prices import status and management.
 */
class PricesImportController extends ControllerBase {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Display import status page.
   */
  public function status() {
    $stats = $this->getImportStats();
    
    $build = [
      '#theme' => 'prices_import_status',
      '#stats' => $stats,
      '#attached' => [
        'library' => [
          'nord_prices/admin',
        ],
      ],
    ];
    
    return $build;
  }

  /**
   * Get statistics about imported prices.
   */
  protected function getImportStats() {
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    
    $stats = [
      'main_categories' => 0,
      'subcategories' => 0,
      'service_groups' => 0,
      'price_items' => 0,
      'blocks' => 0,
      'last_import' => NULL,
    ];
    
    // Count paragraphs by type
    $types = [
      'main_categories' => 'price_main_category',
      'subcategories' => 'price_subcategory',
      'service_groups' => 'price_service_group',
      'price_items' => 'price_item',
    ];
    
    foreach ($types as $key => $type) {
      $query = $paragraph_storage->getQuery()
        ->condition('type', $type)
        ->accessCheck(FALSE);
      
      $stats[$key] = $query->count()->execute();
    }
    
    // Count prices blocks
    $query = $block_storage->getQuery()
      ->condition('type', 'prices_block')
      ->accessCheck(FALSE);
    
    $stats['blocks'] = $query->count()->execute();
    
    // Get last import time from most recent paragraph
    $query = $paragraph_storage->getQuery()
      ->condition('type', 'price_main_category')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);
    
    $ids = $query->execute();
    
    if ($ids) {
      $paragraph = $paragraph_storage->load(reset($ids));
      $stats['last_import'] = $paragraph->getCreatedTime();
    }
    
    return $stats;
  }

}