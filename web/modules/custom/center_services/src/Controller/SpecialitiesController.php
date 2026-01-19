<?php

namespace Drupal\center_services\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for the Specialities overview page.
 */
class SpecialitiesController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SpecialitiesController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Overview page for specialities.
   *
   * This will redirect to the first speciality term based on the menu order,
   * or display a message if no specialities exist.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect response.
   */
  public function overview() {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load the first speciality term based on menu order.
    $term_query = $term_storage->getQuery()
      ->condition('vid', 'specialities')
      ->sort('field_menu_order', 'ASC')
      ->range(0, 1)
      ->accessCheck(TRUE);

    $term_ids = $term_query->execute();

    if (!empty($term_ids)) {
      // Redirect to the first speciality term.
      $term_id = reset($term_ids);
      $term = $term_storage->load($term_id);

      if ($term) {
        $url = $term->toUrl()->toString();
        return new RedirectResponse($url);
      }
    }

    // If no terms found, show a message.
    return [
      '#markup' => $this->t('No specialities available at this time.'),
    ];
  }
}
