<?php

declare(strict_types=1);

namespace Drupal\Tests\price_importer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\price_importer\Service\ApiClientService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ApiClientService.
 *
 * @group price_importer
 * @coversDefaultClass \Drupal\price_importer\Service\ApiClientService
 */
class ApiClientServiceTest extends UnitTestCase {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ApiClientService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $logger = $this->createMock(LoggerInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $this->configFactory->method('get')->willReturn($config);

    $this->service = new ApiClientService(
      $this->httpClient,
      $this->configFactory,
      $this->loggerFactory,
    );

    // Ensure env var is not set during tests.
    putenv('MEDAPI_TOKEN');
  }

  // ── Token resolution ────────────────────────────────────────────────────────

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenFromEnvVar(): void {
    putenv('MEDAPI_TOKEN=env_token_xyz');
    $this->assertSame('env_token_xyz', $this->service->resolveToken());
    putenv('MEDAPI_TOKEN');
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenFromConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('api_token')->willReturn('config_token_abc');
    $this->configFactory->method('get')->willReturn($config);

    $service = new ApiClientService($this->httpClient, $this->configFactory, $this->loggerFactory);
    $this->assertSame('config_token_abc', $service->resolveToken());
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenReturnsEmptyWhenNoneConfigured(): void {
    $this->assertSame('', $this->service->resolveToken());
  }

  // ── Header building ─────────────────────────────────────────────────────────

  /**
   * @covers ::buildHeaders
   */
  public function testBuildHeadersWithToken(): void {
    $method = new \ReflectionMethod(ApiClientService::class, 'buildHeaders');
    $headers = $method->invoke($this->service, 'my_token');

    $this->assertSame('application/json', $headers['Accept']);
    $this->assertSame('Bearer my_token', $headers['Authorization']);
  }

  /**
   * @covers ::buildHeaders
   */
  public function testBuildHeadersWithoutToken(): void {
    $method = new \ReflectionMethod(ApiClientService::class, 'buildHeaders');
    $headers = $method->invoke($this->service, '');

    $this->assertSame('application/json', $headers['Accept']);
    $this->assertArrayNotHasKey('Authorization', $headers);
  }

  // ── buildFromRows ────────────────────────────────────────────────────────────

  /**
   * @covers ::buildFromRows
   */
  public function testBuildFromRowsGroupsCorrectly(): void {
    $rows = [
      ['main_category' => 'Laborator', 'subcategory' => 'Lab A', 'service_group' => 'G1', 'display_name' => 'Item 1', 'price' => 100.0, 'appointment_url' => ''],
      ['main_category' => 'Laborator', 'subcategory' => 'Lab A', 'service_group' => 'G1', 'display_name' => 'Item 2', 'price' => 200.0, 'appointment_url' => ''],
      ['main_category' => 'Ambulatoriu', 'subcategory' => 'Cardio', 'service_group' => 'Consult', 'display_name' => 'Dr X', 'price' => 300.0, 'appointment_url' => ''],
    ];

    $data = $this->service->buildFromRows($rows);

    $this->assertCount(2, $data->mainCategories);
    $this->assertSame('Laborator', $data->mainCategories[0]->name);
    $this->assertSame('service_groups', $data->mainCategories[0]->subcategories[0]->displayMode);
    $this->assertCount(2, $data->mainCategories[0]->subcategories[0]->serviceGroups[0]->items);
  }

  /**
   * @covers ::buildFromRows
   */
  public function testBuildFromRowsSkipsIncompleteRows(): void {
    $rows = [
      ['main_category' => '', 'subcategory' => 'Sub', 'service_group' => '', 'display_name' => 'Item', 'price' => 10.0, 'appointment_url' => ''],
      ['main_category' => 'Cat', 'subcategory' => '', 'service_group' => '', 'display_name' => 'Item', 'price' => 10.0, 'appointment_url' => ''],
      ['main_category' => 'Cat', 'subcategory' => 'Sub', 'service_group' => '', 'display_name' => '', 'price' => 10.0, 'appointment_url' => ''],
    ];

    $data = $this->service->buildFromRows($rows);
    $this->assertEmpty($data->mainCategories);
  }

  // ── toFloat helper ──────────────────────────────────────────────────────────

  /**
   * @covers ::toFloat
   * @dataProvider toFloatProvider
   */
  public function testToFloat(mixed $input, float $expected): void {
    $method = new \ReflectionMethod(ApiClientService::class, 'toFloat');
    $this->assertSame($expected, $method->invoke($this->service, $input));
  }

  public static function toFloatProvider(): array {
    return [
      'int'           => [100,     100.0],
      'float'         => [1.5,     1.5],
      'string int'    => ['200',   200.0],
      'string float'  => ['3.14',  3.14],
      'comma decimal' => ['1,5',   1.5],
      'invalid'       => ['abc',   0.0],
      'null-like 0'   => [0,       0.0],
    ];
  }

  // ── generateAnchorId ────────────────────────────────────────────────────────

  /**
   * @covers ::generateAnchorId
   */
  public function testGenerateAnchorIdMatchesThemeExtension(): void {
    $method = new \ReflectionMethod(ApiClientService::class, 'generateAnchorId');

    $this->assertSame('group-gastroenterologie', $method->invoke($this->service, 'Gastroenterologie'));
    $this->assertSame('group-lab-muntenia-medical-competences', $method->invoke($this->service, 'Lab Muntenia Medical Competences'));
  }

  // ── fetchAll with mocked HTTP ───────────────────────────────────────────────

  /**
   * @covers ::fetchAll
   * @covers ::fetchLabServices
   */
  public function testFetchAllParsesLabResponse(): void {
    $labPayload = json_encode([
      'data' => [
        [
          'LaboratoryId' => '58660',
          'LaboratoryName' => 'Test Lab',
          'Services' => [
            ['ServiceGroup' => 'Group A', 'ServiceName' => 'Analysis 1', 'Price' => 100.0],
          ],
        ],
      ],
    ]);

    // Minimal doctors response so fetchDoctorServices does not fail.
    $doctorsPayload = json_encode(['data' => []]);

    $this->mockHttpResponse($labPayload, $doctorsPayload);

    $data = $this->service->fetchAll();

    $this->assertCount(1, $data->mainCategories);
    $this->assertSame('Laborator', $data->mainCategories[0]->name);
    $this->assertTrue($data->mainCategories[0]->isExpanded);
    $this->assertSame('Test Lab', $data->mainCategories[0]->subcategories[0]->name);
  }

  /**
   * Configures the mocked HTTP client to return the given payloads in order.
   */
  protected function mockHttpResponse(string ...$payloads): void {
    $responses = array_map(function (string $body) {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($body);
      $stream->method('getContents')->willReturn($body);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }, $payloads);

    $this->httpClient->method('request')->willReturnOnConsecutiveCalls(...$responses);
  }

}
