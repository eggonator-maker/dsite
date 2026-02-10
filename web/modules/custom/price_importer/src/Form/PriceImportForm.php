<?php

declare(strict_types=1);

namespace Drupal\price_importer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\price_importer\Exception\PriceImportException;
use Drupal\price_importer\Service\ApiClientService;
use Drupal\price_importer\Service\CsvParserService;
use Drupal\price_importer\Service\PriceImporterService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administration form for importing medical prices.
 *
 * Provides two import methods:
 *  1. CSV file upload.
 *  2. Direct API fetch from MedAPI.
 *
 * Also exposes API token configuration.
 */
class PriceImportForm extends FormBase {

  public function __construct(
    protected readonly CsvParserService $csvParser,
    protected readonly ApiClientService $apiClient,
    protected readonly PriceImporterService $priceImporter,
    ConfigFactoryInterface $configFactory,
  ) {
    // Populate the protected $configFactory property declared by FormBase.
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('price_importer.csv_parser'),
      $container->get('price_importer.api_client'),
      $container->get('price_importer.importer'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'price_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory()->get('price_importer.settings');

    // ── CSV import ─────────────────────────────────────────────────────────────
    $form['csv'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import from CSV file'),
    ];

    $form['csv']['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t(
        'Upload a UTF-8 encoded CSV file with the following columns: '
        . '<code>main_category, subcategory, service_group, display_name, price, appointment_url</code>. '
        . 'Leave <em>service_group</em> empty for direct-items display mode.'
      ),
      '#accept' => '.csv',
    ];

    $form['csv']['submit_csv'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import from CSV'),
      '#submit' => ['::submitCsv'],
      '#validate' => ['::validateCsvUpload'],
    ];

    // ── API import ─────────────────────────────────────────────────────────────
    $form['api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import from MedAPI'),
    ];

    $form['api']['description'] = [
      '#markup' => '<p>' . $this->t(
        'Fetches the latest prices directly from the MedAPI endpoints '
        . '(<code>medapi.ro/sync/doctor-muntenia</code> and <code>medapi.ro/sync/lab-muntenia</code>). '
        . 'This may take several minutes for large datasets; consider using Drush for bulk imports.'
      ) . '</p>',
    ];

    $form['api']['submit_api'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import from API'),
      '#submit' => ['::submitApi'],
      '#limit_validation_errors' => [],
    ];

    // ── Settings ───────────────────────────────────────────────────────────────
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API settings'),
      '#open' => $config->get('api_token') === '',
    ];

    $form['settings']['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API token'),
      '#default_value' => $config->get('api_token') ?? '',
      '#description' => $this->t(
        'Bearer token for MedAPI authentication. '
        . 'Leave blank to use the <code>MEDAPI_TOKEN</code> environment variable, '
        . 'or if the API is publicly accessible.'
      ),
    ];

    $form['settings']['submit_settings'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#submit' => ['::submitSettings'],
      '#limit_validation_errors' => [['api_token']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Individual submit handlers carry their own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intentionally empty; each action has its own submit handler.
  }

  /**
   * Validates that a CSV file was actually uploaded.
   */
  public function validateCsvUpload(array &$form, FormStateInterface $form_state): void {
    $tmpName = $_FILES['files']['tmp_name']['csv_file'] ?? '';
    if (empty($tmpName) || !is_uploaded_file($tmpName)) {
      $form_state->setErrorByName('csv_file', $this->t('Please select a CSV file to upload.'));
    }
  }

  /**
   * Processes a CSV file upload and runs the import.
   */
  public function submitCsv(array &$form, FormStateInterface $form_state): void {
    $tmpName = $_FILES['files']['tmp_name']['csv_file'] ?? '';

    try {
      $data = $this->csvParser->parseFile($tmpName);
      $this->priceImporter->import($data);
      $this->messenger()->addStatus($this->t('Prices imported successfully from CSV.'));
    }
    catch (PriceImportException $e) {
      $this->messenger()->addError($this->t('Import failed: @msg', ['@msg' => $e->getMessage()]));
      $this->getLogger('price_importer')->error('CSV import error: @msg', ['@msg' => $e->getMessage()]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('An unexpected error occurred during import.'));
      $this->getLogger('price_importer')->error('Unexpected CSV import error: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Triggers an API-based import.
   */
  public function submitApi(array &$form, FormStateInterface $form_state): void {
    try {
      $data = $this->apiClient->fetchAll();
      $this->priceImporter->import($data);
      $this->messenger()->addStatus($this->t('Prices imported successfully from API.'));
    }
    catch (PriceImportException $e) {
      $this->messenger()->addError($this->t('API import failed: @msg', ['@msg' => $e->getMessage()]));
      $this->getLogger('price_importer')->error('API import error: @msg', ['@msg' => $e->getMessage()]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('An unexpected error occurred during API import.'));
      $this->getLogger('price_importer')->error('Unexpected API import error: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Saves the API token configuration.
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('price_importer.settings')
      ->set('api_token', trim((string) $form_state->getValue('api_token')))
      ->save();

    $this->messenger()->addStatus($this->t('Settings saved.'));
  }

}
