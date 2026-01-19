<?php

namespace Drupal\center_services\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\center_services\Service\CenterContextService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Center Navigation' block.
 *
 * @Block(
 *   id = "center_navigation_block",
 *   admin_label = @Translation("Center Navigation"),
 *   category = @Translation("Center Services")
 * )
 */
class CenterNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The center context service.
   *
   * @var \Drupal\center_services\Service\CenterContextService
   */
  protected $centerContext;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new CenterNavigationBlock instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    CenterContextService $center_context,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->centerContext = $center_context;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('center_services.context'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get the current center node using the service.
    $center_node = $this->centerContext->getCurrentCenter();
    
    if (!$center_node instanceof NodeInterface) {
      return [];
    }

    $center_id = $center_node->id();
    
    // Get current route information.
    $current_route_name = $this->routeMatch->getRouteName();
    $current_parameters = $this->routeMatch->getRawParameters()->all();
    $current_node_id = $current_parameters['node'] ?? NULL;
    $current_term_id = $current_parameters['taxonomy_term'] ?? NULL;
    
    // Determine if we're on the main center page.
    $current_node = $this->routeMatch->getParameter('node');
    $is_main_center_page = $current_node instanceof NodeInterface && $current_node->bundle() === 'centers';
    
    // Fetch all data needed for the menu.
    $categories = $this->loadServiceCategories($center_id);
    $services = $this->loadServices($center_id);
    $services_by_category = $this->groupServicesByCategory($services);
    $top_level_pages = $this->loadTopLevelPages($center_id);
    
    // Build the menu structure.
    $menu_items = $this->buildMenuItems(
      $top_level_pages,
      $categories,
      $services_by_category['categorized'],
      $services_by_category['uncategorized'],
      $current_route_name,
      $current_node_id,
      $current_term_id,
      $is_main_center_page
    );

    return [
      '#theme' => 'center_navigation',
      '#items' => $menu_items,
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => ['node_list', 'taxonomy_term_list', 'node:' . $center_id],
      ],
    ];
  }

  /**
   * Load service categories for a center.
   */
  protected function loadServiceCategories($center_id) {
    $category_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $category_ids = $category_storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('field_center', $center_id)
      ->sort('field_menu_order', 'ASC')
      ->accessCheck(TRUE)
      ->execute();
    
    return $category_storage->loadMultiple($category_ids);
  }

  /**
   * Load services for a center.
   */
  protected function loadServices($center_id) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $service_ids = $node_storage->getQuery()
      ->condition('type', 'centers_service')
      ->condition('field_center', $center_id)
      ->sort('field_menu_order', 'ASC')
      ->accessCheck(TRUE)
      ->execute();
    
    return $node_storage->loadMultiple($service_ids);
  }

  /**
   * Group services by their parent category.
   */
  protected function groupServicesByCategory($services) {
    $categorized = [];
    $uncategorized = [];
    
    foreach ($services as $service) {
      if (!$service->get('field_service_category')->isEmpty()) {
        $parent_id = $service->get('field_service_category')->target_id;
        $categorized[$parent_id][] = $service;
      }
      else {
        $uncategorized[] = $service;
      }
    }
    
    return [
      'categorized' => $categorized,
      'uncategorized' => $uncategorized,
    ];
  }

  /**
   * Load top-level pages for a center.
   */
  protected function loadTopLevelPages($center_id) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $top_level_nids = $node_storage->getQuery()
      ->condition('type', 'centers_sub_page')
      ->condition('status', 1)
      ->condition('field_center', $center_id)
      ->sort('field_menu_order', 'ASC')
      ->accessCheck(TRUE)
      ->execute();
    
    return $node_storage->loadMultiple($top_level_nids);
  }

  /**
   * Build the complete menu structure.
   */
  protected function buildMenuItems(
    $top_level_pages,
    $categories,
    $services_by_category,
    $uncategorized_services,
    $current_route_name,
    $current_node_id,
    $current_term_id,
    $is_main_center_page
  ) {
    $menu_items = [];
    $top_level_nids = array_keys($top_level_pages);
    
    foreach ($top_level_pages as $page) {
      $page_type = $page->get('field_page_type')->value;
      $page_menu_title = $this->getMenuTitle($page);
      $is_page_active = $current_route_name == 'entity.node.canonical' && $current_node_id == $page->id();
      
      if ($page_type === 'placeholder') {
        $menu_items[] = $this->buildServicesMenuItem(
          $page,
          $page_menu_title,
          $is_page_active,
          $categories,
          $services_by_category,
          $uncategorized_services,
          $current_route_name,
          $current_node_id,
          $current_term_id
        );
      }
      else {
        $menu_items[] = $this->buildRegularMenuItem(
          $page,
          $page_menu_title,
          $is_page_active,
          $is_main_center_page,
          $top_level_nids
        );
      }
    }
    
    return $menu_items;
  }

  /**
   * Build a services placeholder menu item with children.
   */
  protected function buildServicesMenuItem(
    $page,
    $page_menu_title,
    $is_page_active,
    $categories,
    $services_by_category,
    $uncategorized_services,
    $current_route_name,
    $current_node_id,
    $current_term_id
  ) {
    $services_item_children = [];
    $services_item_in_active_trail = FALSE;
    
    // Build category items with their service children.
    foreach ($categories as $category) {
      $category_item = $this->buildCategoryMenuItem(
        $category,
        $services_by_category[$category->id()] ?? [],
        $current_route_name,
        $current_node_id,
        $current_term_id
      );
      
      if ($category_item['in_active_trail']) {
        $services_item_in_active_trail = TRUE;
      }
      
      $services_item_children[] = $category_item;
    }
    
    // Add uncategorized services.
    foreach ($uncategorized_services as $service) {
      $service_menu_title = $this->getMenuTitle($service);
      $is_service_active = $current_route_name == 'entity.node.canonical' && $current_node_id == $service->id();
      
      if ($is_service_active) {
        $services_item_in_active_trail = TRUE;
      }
      
      $services_item_children[] = [
        'title' => $service_menu_title,
        'url' => $service->toUrl()->toString(),
        'is_active' => $is_service_active,
        'in_active_trail' => $is_service_active,
        'children' => [],
      ];
    }
    
    $services_item_in_active_trail = $is_page_active || $services_item_in_active_trail;
    
    return [
      'title' => $page_menu_title,
      'url' => $page->toUrl()->toString(),
      'is_active' => $is_page_active,
      'in_active_trail' => $services_item_in_active_trail,
      'is_collapsible' => TRUE,
      'children' => $services_item_children,
    ];
  }

  /**
   * Build a category menu item with service children.
   */
  protected function buildCategoryMenuItem(
    $category,
    $category_services,
    $current_route_name,
    $current_node_id,
    $current_term_id
  ) {
    $category_menu_title = $this->getMenuTitle($category);
    $is_category_active = $current_route_name == 'entity.taxonomy_term.canonical' && $current_term_id == $category->id();
    $category_has_active_child = FALSE;
    $category_children = [];
    
    foreach ($category_services as $service) {
      $service_menu_title = $this->getMenuTitle($service);
      $is_service_active = $current_route_name == 'entity.node.canonical' && $current_node_id == $service->id();
      
      if ($is_service_active) {
        $category_has_active_child = TRUE;
      }
      
      $category_children[] = [
        'title' => $service_menu_title,
        'url' => $service->toUrl()->toString(),
        'is_active' => $is_service_active,
        'in_active_trail' => $is_service_active,
      ];
    }
    
    $category_in_active_trail = $is_category_active || $category_has_active_child;
    
    return [
      'title' => $category_menu_title,
      'url' => $category->toUrl()->toString(),
      'is_active' => $is_category_active,
      'in_active_trail' => $category_in_active_trail,
      'is_collapsible' => TRUE,
      'children' => $category_children,
    ];
  }

  /**
   * Build a regular (non-placeholder) menu item.
   */
  protected function buildRegularMenuItem(
    $page,
    $page_menu_title,
    $is_page_active,
    $is_main_center_page,
    $top_level_nids
  ) {
    $is_first_item = $page->id() == reset($top_level_nids);
    $is_active = ($is_main_center_page && $is_first_item) || $is_page_active;
    
    return [
      'title' => $page_menu_title,
      'url' => $page->toUrl()->toString(),
      'is_active' => $is_active,
      'in_active_trail' => $is_active,
      'is_collapsible' => FALSE,
    ];
  }

  /**
   * Get the menu title for an entity.
   */
  protected function getMenuTitle($entity) {
    if ($entity->hasField('field_menu_title') && !$entity->get('field_menu_title')->isEmpty()) {
      return $entity->get('field_menu_title')->value;
    }
    return $entity->label();
  }

}