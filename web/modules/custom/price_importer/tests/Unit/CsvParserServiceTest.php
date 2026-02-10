<?php

declare(strict_types=1);

namespace Drupal\Tests\price_importer\Unit;

use Drupal\price_importer\Exception\PriceImportException;
use Drupal\price_importer\Service\CsvParserService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for CsvParserService.
 *
 * @group price_importer
 * @coversDefaultClass \Drupal\price_importer\Service\CsvParserService
 */
class CsvParserServiceTest extends UnitTestCase {

  protected CsvParserService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->service = new CsvParserService();
  }

  // ── Happy-path tests ─────────────────────────────────────────────────────────

  /**
   * @covers ::parseString
   * @covers ::buildImportData
   */
  public function testParseBasicServiceGroupsCsv(): void {
    $csv = implode("\n", [
      'main_category,subcategory,service_group,display_name,price,appointment_url',
      'Laborator,Lab Alpha,Group 1,Test X,100,',
      'Laborator,Lab Alpha,Group 1,Test Y,200,',
      'Laborator,Lab Alpha,Group 2,Test Z,50,',
    ]);

    $data = $this->service->parseString($csv);

    $this->assertCount(1, $data->mainCategories);
    $cat = $data->mainCategories[0];
    $this->assertSame('Laborator', $cat->name);
    $this->assertTrue($cat->isExpanded, 'First category must be expanded by default.');
    $this->assertCount(1, $cat->subcategories);

    $sub = $cat->subcategories[0];
    $this->assertSame('Lab Alpha', $sub->name);
    $this->assertSame('service_groups', $sub->displayMode);
    $this->assertCount(2, $sub->serviceGroups);
    $this->assertCount(2, $sub->serviceGroups[0]->items);
    $this->assertCount(1, $sub->serviceGroups[1]->items);
    $this->assertSame(100.0, $sub->serviceGroups[0]->items[0]->price);
  }

  /**
   * @covers ::parseString
   * @covers ::buildImportData
   */
  public function testParseDirectItemsCsv(): void {
    $csv = implode("\n", [
      'main_category,subcategory,service_group,display_name,price,appointment_url',
      'Imagistica,Radiologie,,Radiografie torace,150,https://book.example.com',
      'Imagistica,Radiologie,,CT Abdomen,350,',
    ]);

    $data = $this->service->parseString($csv);

    $sub = $data->mainCategories[0]->subcategories[0];
    $this->assertSame('direct_items', $sub->displayMode);
    $this->assertEmpty($sub->serviceGroups);
    $this->assertCount(2, $sub->directItems);
    $this->assertSame(150.0, $sub->directItems[0]->price);
    $this->assertSame('https://book.example.com', $sub->directItems[0]->appointmentUrl);
  }

  /**
   * @covers ::parseString
   * @covers ::buildImportData
   */
  public function testMultipleCategoriesFirstIsExpanded(): void {
    $csv = implode("\n", [
      'main_category,subcategory,service_group,display_name,price,appointment_url',
      'Laborator,Lab A,G1,Item,100,',
      'Ambulatoriu,Cardio,Consult,Dr A,200,',
    ]);

    $data = $this->service->parseString($csv);

    $this->assertCount(2, $data->mainCategories);
    $this->assertTrue($data->mainCategories[0]->isExpanded);
    $this->assertFalse($data->mainCategories[1]->isExpanded);
  }

  /**
   * @covers ::parseString
   */
  public function testAppointmentUrlIsPreserved(): void {
    $csv = implode("\n", [
      'main_category,subcategory,service_group,display_name,price,appointment_url',
      'Laborator,Lab A,G1,Item,100,https://schedule.hospital.ro/book',
    ]);

    $data = $this->service->parseString($csv);
    $item = $data->mainCategories[0]->subcategories[0]->serviceGroups[0]->items[0];
    $this->assertSame('https://schedule.hospital.ro/book', $item->appointmentUrl);
  }

  /**
   * @covers ::parseString
   */
  public function testColumnOrderIndependence(): void {
    // Columns in a different order.
    $csv = implode("\n", [
      'price,display_name,main_category,appointment_url,subcategory,service_group',
      '99,Widget,Laborator,,Sub A,Grp 1',
    ]);

    $data = $this->service->parseString($csv);
    $item = $data->mainCategories[0]->subcategories[0]->serviceGroups[0]->items[0];
    $this->assertSame('Widget', $item->displayName);
    $this->assertSame(99.0, $item->price);
  }

  // ── Price parsing tests ──────────────────────────────────────────────────────

  /**
   * @covers ::parsePrice
   * @dataProvider priceProvider
   */
  public function testPriceParsing(string $input, float $expected): void {
    $this->assertSame($expected, $this->service->parsePrice($input));
  }

  public static function priceProvider(): array {
    return [
      'integer'                  => ['100',     100.0],
      'float dot'                => ['1234.56', 1234.56],
      'float comma'              => ['1234,56', 1234.56],
      'Romanian thousand+comma'  => ['1.234,56', 1234.56],
      'English thousand+dot'     => ['1,234.56', 1234.56],
      'empty'                    => ['',        0.0],
      'non-numeric'              => ['N/A',     0.0],
      'whitespace'               => ['  150  ', 150.0],
    ];
  }

  // ── Anchor ID tests ─────────────────────────────────────────────────────────

  /**
   * @covers ::generateAnchorId
   * @dataProvider anchorIdProvider
   */
  public function testGenerateAnchorId(string $input, string $expected): void {
    $this->assertSame($expected, $this->service->generateAnchorId($input));
  }

  public static function anchorIdProvider(): array {
    return [
      'simple'                     => ['Laborator',        'group-laborator'],
      'spaces become hyphens'      => ['Lab Muntenia',     'group-lab-muntenia'],
      'Romanian ă â î ș ț'        => ['Anestezie și ATI', 'group-anestezie-si-ati'],
      'special characters removed' => ['Test (v2)!',       'group-test-v2'],
    ];
  }

  // ── Error cases ─────────────────────────────────────────────────────────────

  /**
   * @covers ::parseString
   */
  public function testEmptyStringThrows(): void {
    $this->expectException(PriceImportException::class);
    $this->service->parseString('');
  }

  /**
   * @covers ::parseString
   */
  public function testWhitespaceOnlyThrows(): void {
    $this->expectException(PriceImportException::class);
    $this->service->parseString("   \n\t\n  ");
  }

  /**
   * @covers ::parseString
   */
  public function testMissingRequiredColumnThrows(): void {
    $this->expectException(PriceImportException::class);
    $this->expectExceptionMessageMatches('/missing required columns/i');
    $this->service->parseString("main_category,subcategory\nLab,Sub\n");
  }

  /**
   * @covers ::parseString
   */
  public function testHeaderOnlyThrows(): void {
    $this->expectException(PriceImportException::class);
    $this->expectExceptionMessageMatches('/no valid data rows/i');
    $this->service->parseString("main_category,subcategory,service_group,display_name,price,appointment_url\n");
  }

  /**
   * @covers ::parseString
   */
  public function testRowsWithMissingCoreFieldsAreSkipped(): void {
    $csv = implode("\n", [
      'main_category,subcategory,service_group,display_name,price,appointment_url',
      ',,, ,0,',        // All blank – skipped.
      'Lab,Sub,,Item,50,', // Valid.
    ]);

    $data = $this->service->parseString($csv);
    $this->assertCount(1, $data->mainCategories);
  }

  /**
   * @covers ::parseFile
   */
  public function testMissingFileThrows(): void {
    $this->expectException(PriceImportException::class);
    $this->expectExceptionMessageMatches('/not found/i');
    $this->service->parseFile('/non/existent/path.csv');
  }

}
