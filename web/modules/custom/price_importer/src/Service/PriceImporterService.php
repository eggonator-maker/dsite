<?php

declare(strict_types=1);

namespace Drupal\price_importer\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\price_importer\DataTransfer\ImportData;
use Drupal\price_importer\Exception\PriceImportException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the import of price data.
 *
 * Stores the entire price tree as a single JSON blob in the state API,
 * avoiding the overhead of 3 000+ paragraph entity loads on each page render.
 * The block content entity is saved (with an empty field_main_categories) only
 * to invalidate the Drupal block/page cache via its standard cache tags.
 */
class PriceImporterService {

  /**
   * Block content bundle that holds the price hierarchy.
   */
  const PRICES_BLOCK_BUNDLE = 'prices_block';

  /**
   * State key used to persist the serialised price tree.
   */
  const STATE_KEY = 'prices_block.json';

  /**
   * Maximum paragraphs per deletion batch to avoid memory exhaustion.
   */
  const DELETE_CHUNK_SIZE = 100;

  protected LoggerInterface $logger;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly Connection $database,
    protected readonly StateInterface $state,
  ) {
    $this->logger = $loggerFactory->get('price_importer');
  }

  /**
   * Imports price data, replacing the previous price tree.
   *
   * @param \Drupal\price_importer\DataTransfer\ImportData $data
   *
   * @throws \Drupal\price_importer\Exception\PriceImportException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function import(ImportData $data): void {
    if (empty($data->mainCategories)) {
      throw new PriceImportException('No data to import: the import data set is empty.');
    }

    $block = $this->findPricesBlock();
    if ($block === NULL) {
      throw new PriceImportException(
        'No block_content of type "' . self::PRICES_BLOCK_BUNDLE . '" was found. '
        . 'Please create a Prices block in the block layout before importing.'
      );
    }

    $this->logger->info('Starting price import into block @id (@count top-level categories).', [
      '@id' => $block->id(),
      '@count' => count($data->mainCategories),
    ]);

    // Clean up any legacy paragraph entities left from the previous approach.
    $this->deleteOldParagraphs((int) $block->id());

    // Persist the entire tree as a single JSON blob.
    $this->state->set(self::STATE_KEY, json_encode($this->toArray($data), JSON_UNESCAPED_UNICODE));

    // Save the block (emptying the paragraph field) so its cache tags are
    // invalidated and the page cache entry for /preturi is cleared.
    $block->set('field_main_categories', []);
    $block->save();
    Cache::invalidateTags($block->getCacheTagsToInvalidate());

    $this->logger->info('Price import complete: @count main categories stored as JSON.', [
      '@count' => count($data->mainCategories),
    ]);
  }

  /**
   * Loads the first prices_block block content entity.
   *
   * @return \Drupal\block_content\Entity\BlockContent|null
   */
  public function findPricesBlock() {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $ids = $storage->getQuery()
      ->condition('type', self::PRICES_BLOCK_BUNDLE)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Deletes all paragraph entities that are descendants of the given block.
   *
   * @param int $blockId
   */
  protected function deleteOldParagraphs(int $blockId): void {
    $allIds = $this->collectDescendantParagraphIds($blockId, 'block_content');

    if (empty($allIds)) {
      return;
    }

    $this->logger->info('Deleting @count legacy price paragraphs.', ['@count' => count($allIds)]);

    $storage = $this->entityTypeManager->getStorage('paragraph');
    foreach (array_chunk($allIds, self::DELETE_CHUNK_SIZE) as $chunk) {
      $paragraphs = $storage->loadMultiple($chunk);
      if (!empty($paragraphs)) {
        $storage->delete($paragraphs);
      }
    }
  }

  /**
   * Collects all paragraph IDs that are children of a given entity (BFS).
   *
   * @param int $parentId
   * @param string $parentType
   *
   * @return array
   */
  protected function collectDescendantParagraphIds(int $parentId, string $parentType): array {
    $allIds = [];
    $currentParentIds = [$parentId];
    $currentParentType = $parentType;

    while (!empty($currentParentIds)) {
      $query = $this->database->select('paragraphs_item_field_data', 'p')
        ->fields('p', ['id'])
        ->condition('parent_type', $currentParentType)
        ->condition('parent_id', $currentParentIds, 'IN');
      $childIds = $query->execute()->fetchCol();

      if (empty($childIds)) {
        break;
      }

      $allIds = array_merge($allIds, $childIds);
      $currentParentIds = $childIds;
      $currentParentType = 'paragraph';
    }

    return array_unique($allIds);
  }

  /**
   * Converts ImportData to a plain array suitable for JSON serialisation.
   *
   * Keys are snake_case so Twig templates can access them naturally.
   *
   * @param \Drupal\price_importer\DataTransfer\ImportData $data
   *
   * @return array
   */
  private function toArray(ImportData $data): array {
    $categories = [];
    foreach ($data->mainCategories as $cat) {
      $subs = [];
      foreach ($cat->subcategories as $sub) {
        $groups = [];
        foreach ($sub->serviceGroups as $grp) {
          $items = [];
          foreach ($grp->items as $item) {
            $items[] = [
              'display_name' => $item->displayName,
              'price' => $item->price,
              'appointment_url' => $item->appointmentUrl,
            ];
          }
          $groups[] = ['name' => $grp->name, 'items' => $items];
        }

        $direct = [];
        foreach ($sub->directItems as $item) {
          $direct[] = [
            'display_name' => $item->displayName,
            'price' => $item->price,
            'appointment_url' => $item->appointmentUrl,
          ];
        }

        $subs[] = [
          'name' => $sub->name,
          'anchor_id' => $sub->anchorId,
          'display_mode' => $sub->displayMode,
          'service_groups' => $groups,
          'direct_items' => $direct,
        ];
      }

      $categories[] = [
        'name' => $cat->name,
        'is_expanded' => $cat->isExpanded,
        'subcategories' => $subs,
      ];
    }

    return $categories;
  }

}
