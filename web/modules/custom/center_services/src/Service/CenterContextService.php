<?php
// center_services/src/Service/CenterContextService.php

namespace Drupal\center_services\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class CenterContextService {

  protected $routeMatch;
  protected $entityTypeManager;

  public function __construct(RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager) {
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get the current center node.
   */
  public function getCurrentCenter() {
    $center_node = NULL;
    $current_node = $this->routeMatch->getParameter('node');

    if ($this->routeMatch->getParameter('centers')) {
      $center_node = $this->routeMatch->getParameter('centers');
    }
    elseif ($current_node instanceof NodeInterface) {
      if ($current_node->bundle() === 'centers') {
        $center_node = $current_node;
      }
      elseif ($current_node->hasField('field_center') && !$current_node->get('field_center')->isEmpty()) {
        $center_node = $current_node->get('field_center')->entity;
      }
    }
    elseif ($term = $this->routeMatch->getParameter('taxonomy_term')) {
      if ($term->hasField('field_center') && !$term->get('field_center')->isEmpty()) {
        $center_node = $term->get('field_center')->entity;
      }
    }

    return $center_node instanceof NodeInterface ? $center_node : NULL;
  }
}