<?php

declare(strict_types=1);

namespace Drupal\Tests\price_importer\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\price_importer\DataTransfer\ImportData;
use Drupal\price_importer\DataTransfer\MainCategory;
use Drupal\price_importer\Exception\PriceImportException;
use Drupal\price_importer\Service\PriceImporterService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PriceImporterService.
 *
 * Note: Paragraph entity creation (createMainCategory, etc.) requires the
 * Drupal entity API and is covered by functional/kernel tests. These unit
 * tests focus on the orchestration logic, guard clauses, and DB queries.
 *
 * @group price_importer
 * @coversDefaultClass \Drupal\price_importer\Service\PriceImporterService
 */
class PriceImporterServiceTest extends UnitTestCase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected Connection $database;
  protected StateInterface $state;
  protected PriceImporterService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->state = $this->createMock(StateInterface::class);

    $logger = $this->createMock(LoggerInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $this->service = new PriceImporterService(
      $this->entityTypeManager,
      $this->loggerFactory,
      $this->database,
      $this->state,
    );
  }

  // ── Guard clauses ────────────────────────────────────────────────────────────

  /**
   * @covers ::import
   */
  public function testImportThrowsWhenDataIsEmpty(): void {
    $this->expectException(PriceImportException::class);
    $this->expectExceptionMessageMatches('/empty/i');
    $this->service->import(new ImportData());
  }

  /**
   * @covers ::import
   * @covers ::findPricesBlock
   */
  public function testImportThrowsWhenNoPricesBlockExists(): void {
    $this->stubBlockStorageReturning([]);

    $this->expectException(PriceImportException::class);
    $this->expectExceptionMessageMatches('/prices_block/');

    $data = new ImportData();
    $data->mainCategories[] = new MainCategory('Cat', TRUE);
    $this->service->import($data);
  }

  // ── findPricesBlock ─────────────────────────────────────────────────────────

  /**
   * @covers ::findPricesBlock
   */
  public function testFindPricesBlockReturnsNullWhenNoneExist(): void {
    $storage = $this->stubBlockStorageReturning([]);
    $storage->expects($this->never())->method('load');

    $this->assertNull($this->service->findPricesBlock());
  }

  /**
   * @covers ::findPricesBlock
   */
  public function testFindPricesBlockLoadsFirstResult(): void {
    $blockEntity = $this->createMock(\Drupal\Core\Entity\EntityInterface::class);
    $storage = $this->stubBlockStorageReturning([228 => 228]);
    $storage->expects($this->once())->method('load')->with(228)->willReturn($blockEntity);

    $this->assertSame($blockEntity, $this->service->findPricesBlock());
  }

  // ── collectDescendantParagraphIds ────────────────────────────────────────────

  /**
   * @covers ::collectDescendantParagraphIds
   */
  public function testCollectDescendantParagraphIdsReturnsEmptyWhenNoChildren(): void {
    $this->stubDatabaseSelectReturning([]);

    $method = new \ReflectionMethod(PriceImporterService::class, 'collectDescendantParagraphIds');
    $result = $method->invoke($this->service, 1, 'block_content');

    $this->assertSame([], $result);
  }

  /**
   * @covers ::collectDescendantParagraphIds
   */
  public function testCollectDescendantParagraphIdsTraversesLevels(): void {
    // Simulate: block(1) → paragraphs(10, 11) → paragraphs(20, 21) → []
    $callCount = 0;
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchCol')->willReturnCallback(function () use (&$callCount) {
      return match ($callCount++) {
        0 => [10, 11],
        1 => [20, 21],
        default => [],
      };
    });

    $select = $this->createSelectMock($stmt);
    $this->database->method('select')->willReturn($select);

    $method = new \ReflectionMethod(PriceImporterService::class, 'collectDescendantParagraphIds');
    $result = $method->invoke($this->service, 1, 'block_content');

    $this->assertEqualsCanonicalizing([10, 11, 20, 21], $result);
  }

  // ── deleteOldParagraphs ─────────────────────────────────────────────────────

  /**
   * @covers ::deleteOldParagraphs
   */
  public function testDeleteOldParagraphsDeletesFoundEntities(): void {
    $paragraphA = $this->createMock(\Drupal\Core\Entity\EntityInterface::class);
    $paragraphB = $this->createMock(\Drupal\Core\Entity\EntityInterface::class);

    // Database returns one set of child IDs.
    $callCount = 0;
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchCol')->willReturnCallback(function () use (&$callCount) {
      return $callCount++ === 0 ? [1, 2] : [];
    });
    $select = $this->createSelectMock($stmt);
    $this->database->method('select')->willReturn($select);

    $paragraphStorage = $this->createMock(EntityStorageInterface::class);
    $paragraphStorage->expects($this->once())
      ->method('loadMultiple')
      ->with([1, 2])
      ->willReturn([$paragraphA, $paragraphB]);
    $paragraphStorage->expects($this->once())
      ->method('delete')
      ->with([$paragraphA, $paragraphB]);

    $this->entityTypeManager->method('getStorage')
      ->with('paragraph')
      ->willReturn($paragraphStorage);

    $method = new \ReflectionMethod(PriceImporterService::class, 'deleteOldParagraphs');
    $method->invoke($this->service, 42);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  /**
   * Sets up the block_content storage mock to return the given ID list.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject&\Drupal\Core\Entity\EntityStorageInterface
   */
  protected function stubBlockStorageReturning(array $ids) {
    $storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('block_content')
      ->willReturn($storage);

    $storage->method('getQuery')->willReturn($query);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    return $storage;
  }

  /**
   * Stubs the database SELECT to always return the given row list.
   */
  protected function stubDatabaseSelectReturning(array $rows): void {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchCol')->willReturn($rows);
    $select = $this->createSelectMock($stmt);
    $this->database->method('select')->willReturn($select);
  }

  /**
   * Creates a fluent SELECT mock that returns the provided statement.
   */
  protected function createSelectMock(StatementInterface $stmt) {
    $select = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fields', 'condition', 'execute'])
      ->getMock();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);
    return $select;
  }

}
