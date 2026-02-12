<?php

namespace Drupal\nord_prices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nord_prices\Service\PricesDataFetcher;
use Drupal\nord_prices\Service\PricesImporter;
use Drupal\nord_prices\Service\CsvParser;

/**
 * Form for importing prices.
 */
class PricesImportForm extends FormBase {

  protected $dataFetcher;
  protected $importer;
  protected $csvParser;

  public function __construct(
    PricesDataFetcher $data_fetcher,
    PricesImporter $importer,
    CsvParser $csv_parser
  ) {
    $this->dataFetcher = $data_fetcher;
    $this->importer = $importer;
    $this->csvParser = $csv_parser;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('nord_prices.data_fetcher'),
      $container->get('nord_prices.importer'),
      $container->get('nord_prices.csv_parser')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'nord_prices_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Import prices from MedAPI or upload CSV files. The MedAPI does not require authentication for public data.') . '</p>',
    ];

    $form['import_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import Method'),
      '#options' => [
        'api' => $this->t('Fetch from MedAPI (no authentication required)'),
        'csv' => $this->t('Upload CSV files'),
      ],
      '#default_value' => 'csv',
      '#required' => TRUE,
      '#description' => $this->t('Choose whether to fetch data directly from the API or upload pre-exported CSV files.'),
    ];

    // CSV upload section
    $form['csv_files'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CSV Files'),
      '#description' => $this->t('Upload both CSV files exported from the Python scraper.'),
      '#states' => [
        'visible' => [
          ':input[name="import_method"]' => ['value' => 'csv'],
        ],
      ],
    ];

    $form['csv_files']['doctors_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Doctors Services CSV'),
      '#description' => $this->t('Upload doctors_services.csv file.'),
      '#upload_location' => 'private://nord_prices/',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
        'file_validate_size' => [50 * 1024 * 1024], // 50MB
      ],
      '#states' => [
        'required' => [
          ':input[name="import_method"]' => ['value' => 'csv'],
        ],
      ],
    ];

    $form['csv_files']['lab_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Lab Services CSV'),
      '#description' => $this->t('Upload lab_services.csv file.'),
      '#upload_location' => 'private://nord_prices/',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
        'file_validate_size' => [50 * 1024 * 1024], // 50MB
      ],
      '#states' => [
        'required' => [
          ':input[name="import_method"]' => ['value' => 'csv'],
        ],
      ],
    ];

    // API section
    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#description' => $this->t('The MedAPI allows public access without authentication. An API token is optional.'),
      '#states' => [
        'visible' => [
          ':input[name="import_method"]' => ['value' => 'api'],
        ],
      ],
    ];

    $form['api_settings']['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Token (Optional)'),
      '#description' => $this->t('If you have a MedAPI token, enter it here. Otherwise, leave blank to use public access. You can also set the MEDAPI_TOKEN environment variable.'),
      '#placeholder' => $this->t('Leave blank for public access'),
    ];

    $form['api_settings']['api_info'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--status">' . 
        $this->t('Note: The import process may take several minutes as it fetches data for all doctors and services.') . 
        '</div>',
    ];

    // Import mode section
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import Mode'),
      '#options' => [
        'replace' => $this->t('Replace all existing prices'),
        'update' => $this->t('Update/merge with existing prices (experimental)'),
      ],
      '#default_value' => 'replace',
      '#required' => TRUE,
      '#description' => $this->t('Replace mode will delete all existing price data before importing. Update mode will attempt to merge new data with existing data.'),
    ];

    // Warning for replace mode
    $form['replace_warning'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--warning" id="replace-warning" style="display:none;">' . 
        $this->t('<strong>Warning:</strong> This will permanently delete all existing price data including main categories, subcategories, service groups, and price items.') . 
        '</div>',
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => 'replace'],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Prices'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('nord_prices.status'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $method = $form_state->getValue('import_method');

    if ($method === 'csv') {
      $doctors_file_id = $form_state->getValue(['doctors_csv', 0]);
      $lab_file_id = $form_state->getValue(['lab_csv', 0]);
      
      if (empty($doctors_file_id)) {
        $form_state->setErrorByName('doctors_csv', $this->t('Please upload the doctors services CSV file.'));
      }
      
      if (empty($lab_file_id)) {
        $form_state->setErrorByName('lab_csv', $this->t('Please upload the lab services CSV file.'));
      }
    }
    // No validation needed for API method - token is optional
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $method = $form_state->getValue('import_method');
    $mode = $form_state->getValue('mode');

    // Show initial message
    $this->messenger()->addStatus($this->t('Starting import process...'));

    try {
      if ($method === 'api') {
        $token = $form_state->getValue('api_token');
        
        // Temporarily set token if provided
        if ($token) {
          putenv("MEDAPI_TOKEN=$token");
        }
        
        $this->messenger()->addStatus($this->t('Fetching data from MedAPI. This may take several minutes...'));
        $data = $this->dataFetcher->fetchFromApi();
        
        if (empty($data['main_categories'])) {
          $this->messenger()->addWarning($this->t('No data was fetched from the API. Please check the API endpoint or try again later.'));
          return;
        }
        
        $this->messenger()->addStatus($this->t('Successfully fetched @count categories from API.', [
          '@count' => count($data['main_categories']),
        ]));
      }
      else {
        // Get file paths
        $doctors_file_id = $form_state->getValue(['doctors_csv', 0]);
        $lab_file_id = $form_state->getValue(['lab_csv', 0]);
        
        $doctors_file = File::load($doctors_file_id);
        $lab_file = File::load($lab_file_id);
        
        $file_system = \Drupal::service('file_system');
        $doctors_path = $file_system->realpath($doctors_file->getFileUri());
        $lab_path = $file_system->realpath($lab_file->getFileUri());
        
        $this->messenger()->addStatus($this->t('Parsing CSV files...'));
        $data = $this->csvParser->parseCsv($doctors_path, $lab_path);
        
        if (empty($data['main_categories'])) {
          $this->messenger()->addWarning($this->t('No data was found in the CSV files. Please check the file format.'));
          return;
        }
        
        // Make files permanent
        $doctors_file->setPermanent();
        $doctors_file->save();
        $lab_file->setPermanent();
        $lab_file->save();
        
        $this->messenger()->addStatus($this->t('Successfully parsed @count categories from CSV files.', [
          '@count' => count($data['main_categories']),
        ]));
      }

      // Import the data
      $this->messenger()->addStatus($this->t('Importing prices into Drupal...'));
      $result = $this->importer->import($data, $mode);

      if ($result['success']) {
        $this->messenger()->addStatus($this->t('Successfully imported @count price categories. Block ID: @block_id', [
          '@count' => $result['count'],
          '@block_id' => $result['block_id'],
        ]));
        
        // Redirect to status page
        $form_state->setRedirect('nord_prices.status');
      }
      else {
        $this->messenger()->addError($this->t('Import completed but with errors. Please check the logs.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Import failed: @message', [
        '@message' => $e->getMessage(),
      ]));
      
      $this->getLogger('nord_prices')->error('Import error: @message<br>Trace: @trace', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
    }
  }

}