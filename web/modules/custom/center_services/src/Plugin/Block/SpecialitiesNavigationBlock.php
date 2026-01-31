<?php

namespace Drupal\center_services\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
// We no longer need NodeInterface, so it's removed.
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Speciality Navigation' block.
 *
 * @Block(
 * id = "specialities_navigation_block",
 * admin_label = @Translation("Speciality Navigation"),
 * category = @Translation("Center Services")
 * )
 */
class SpecialitiesNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new SpecialityNavigationBlock instance.
   *
   * @param array $configuration
   * A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   * The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   * The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $menu_items = [];
    $active_speciality_tid = NULL;
    $is_overview_page = FALSE;

    // --- Part 1: Find the active Speciality Term ID ---
    // --- CHANGE: Simplified active state logic ---
    // We only check if we are on a taxonomy term page.
    $current_term = $this->routeMatch->getParameter('taxonomy_term');
    if ($current_term instanceof TermInterface && $current_term->bundle() === 'specialities') {
      // ASSUMPTION: Your vocabulary is named 'specialities'.
      $active_speciality_tid = $current_term->id();
    }

    // Check if we're on the specialities overview page.
    $current_route_name = $this->routeMatch->getRouteName();
    if ($current_route_name === 'center_services.specialities_overview') {
      $is_overview_page = TRUE;
    }
    // --- End of simplified logic ---
    

    // --- Part 2: Load all terms and build the flat menu ---
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_query = $term_storage->getQuery()
      ->condition('vid', 'specialities') // ASSUMPTION: Your vocabulary is named 'specialities'.
      ->sort('name', 'ASC') // As requested.
      ->accessCheck(TRUE);
    
    $term_ids = $term_query->execute();

    if (empty($term_ids)) {
      return []; // No terms found, return empty build.
    }

    $terms = $term_storage->loadMultiple($term_ids);

    $first_term_id = NULL;
    foreach ($terms as $term) {
      $term_id = $term->id();

      // Track the first term ID.
      if ($first_term_id === NULL) {
        $first_term_id = $term_id;
      }

      $is_active = ($term_id == $active_speciality_tid);

      // If on overview page and this is the first term, mark it as active.
      if ($is_overview_page && $term_id == $first_term_id) {
        $is_active = TRUE;
      }

      // *** MODIFICATION: Optional Menu Title ***
      // Default to the term's main label (name).
      $menu_title = $term->label();
      // Check if a custom 'field_menu_title' exists and is not empty.
      if ($term->hasField('field_menu_title') && !$term->get('field_menu_title')->isEmpty()) {
        // If it does, use its value instead.
        $menu_title = $term->get('field_menu_title')->value;
      }
      // *** END MODIFICATION ***

      $menu_items[] = [
        'title' => $menu_title, // Use the new dynamic title
        'url' => $term->toUrl()->toString(),
        'is_active' => $is_active,
        'in_active_trail' => $is_active, // In a flat menu, 'is_active' and 'in_active_trail' are the same.
        'is_collapsible' => FALSE, // This is a flat menu, so nothing is collapsible.
        'children' => [], // No children.
      ];
    }

    // --- Part 3: Return the render array ---
    return [
      '#theme' => 'specialities_navigation', // Uses your theme hook
      '#items' => $menu_items,
      '#cache' => [
        // Cache varies by URL, and is invalidated when terms change.
        'contexts' => ['url.path'],
        'tags' => ['taxonomy_term_list'],
      ],
    ];
  }
}