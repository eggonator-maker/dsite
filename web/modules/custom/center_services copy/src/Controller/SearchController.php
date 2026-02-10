<?php

namespace Drupal\center_services\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the site search page.
 */
class SearchController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a SearchController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Search results page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function search(Request $request) {
    $keys = trim($request->query->get('keys', ''));
    $results = [];

    if (!empty($keys)) {
      $like = '%' . $this->database->escapeLike($keys) . '%';

      // Search nodes by type (title + body where available).
      // Just uses the first element of the type group for naming of the type group in the result
      foreach ([['article'], ['doctor'], ['centers', 'centers_service', 'centers_sub_page']] as $type_group) {
        foreach ($type_group as $type) {
        $query = $this->database->select('node_field_data', 'n')
          ->fields('n', ['nid', 'title'])
          ->condition('n.status', 1)
          ->condition('n.type', $type)
          ->distinct();
        $query->leftJoin('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
        $or = $query->orConditionGroup()
          ->condition('n.title', $like, 'LIKE')
          ->condition('b.body_value', $like, 'LIKE');
        $query->condition($or);
        $query->orderBy('n.title', 'ASC');

        foreach ($query->execute() as $row) {
          $results[$type_group[0]][] = [
            'title' => $row->title,
            'url' => Url::fromRoute('entity.node.canonical', ['node' => $row->nid])->toString(),
          ];
        }
      }
      }

      
      // Search taxonomy terms (specialities by name).
      $term_query = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid', 'name'])
        ->condition('t.vid', 'specialities')
        ->condition('t.status', 1)
        ->condition('t.name', $like, 'LIKE')
        ->orderBy('t.name', 'ASC');

      foreach ($term_query->execute() as $row) {
        $results['specialities'][] = [
          'title' => $row->name,
          'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $row->tid])->toString(),
        ];
      }
    }

    return [
      '#theme' => 'search_page',
      '#keys' => $keys,
      '#results' => $results,
    ];
  }

}
