<?php

namespace Drupal\center_services\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Provides a flexible 'Taxonomy Filter' Block.
 *
 * @Block(
 * id = "taxonomy_filter_block",
 * admin_label = @Translation("Taxonomy Filter Block"),
 * category = @Translation("Center Services"),
 * )
 */
class TaxonomyFilterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $entityTypeManager;
  protected $entityFieldManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  public function defaultConfiguration() {
    return [
      'block_title' => '',
      'description' => '',
      'content_type' => '',
      'selection_mode' => 'taxonomy',
      'taxonomy_vocabulary' => '',
      'taxonomy_field' => '',
      'taxonomy_terms' => [],
      'direct_entities' => [],
      'display_mode' => 'teaser',
      'items_to_show' => 10,
      'sort_field' => 'created',
      'sort_direction' => 'DESC',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;
  
    $form['block_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block Title'),
      '#default_value' => $config['block_title'],
      '#description' => $this->t('The title to display above the filtered content.'),
    ];
  
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config['description'],
      '#rows' => 6,
      '#description' => $this->t('Optional description text.'),
    ];
  
    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => ['' => $this->t('- Select -')] + $this->getContentTypeOptions(),
      '#default_value' => $config['content_type'],
      '#required' => TRUE,
      '#description' => $this->t('Note: After selecting, save and re-edit to populate field options.'),
    ];

    $form['selection_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Selection Mode'),
      '#options' => [
        'taxonomy' => $this->t('Filter by taxonomy terms'),
        'direct' => $this->t('Select specific items'),
      ],
      '#default_value' => $config['selection_mode'],
      '#required' => TRUE,
    ];

    $form['taxonomy_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="settings[selection_mode]"]' => ['value' => 'taxonomy'],
        ],
      ],
    ];
  
    $form['taxonomy_container']['taxonomy_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Taxonomy Vocabulary'),
      '#options' => ['' => $this->t('- Select -')] + $this->getVocabularyOptions(),
      '#default_value' => $config['taxonomy_vocabulary'],
      '#description' => $this->t('Note: After selecting, save and re-edit to enable term selection.'),
    ];
  
    $content_type = $config['content_type'];
    $vocabulary = $config['taxonomy_vocabulary'];
  
    $field_options = ['' => $this->t('- Select -')];
    if (!empty($content_type)) {
      $field_options += $this->getTaxonomyFieldOptions($content_type, $vocabulary);
    }
  
    $form['taxonomy_container']['taxonomy_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Taxonomy Field'),
      '#options' => $field_options,
      '#default_value' => $config['taxonomy_field'],
      '#description' => $this->t('Select the field on the content type that references the taxonomy vocabulary.'),
    ];
  
    $selection_settings = [];
    if (!empty($vocabulary)) {
      $selection_settings['target_bundles'] = [$vocabulary];
    }
  
    $form['taxonomy_container']['taxonomy_terms'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#selection_settings' => $selection_settings,
      '#tags' => TRUE,
      '#title' => $this->t('Taxonomy Terms'),
      '#default_value' => $this->loadTerms($config['taxonomy_terms']),
      '#description' => !empty($vocabulary)
        ? $this->t('Start typing to search for terms. You can add multiple terms separated by commas.')
        : $this->t('Please select a taxonomy vocabulary first to enable term selection.'),
      '#disabled' => empty($vocabulary),
    ];

    $form['direct_entities'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => !empty($content_type) ? [$content_type] : [],
      ],
      '#tags' => TRUE,
      '#title' => $this->t('Select Items'),
      '#default_value' => $this->loadEntities($config['direct_entities']),
      '#description' => $this->t('Start typing to search for items.'),
      '#states' => [
        'visible' => [
          ':input[name="settings[selection_mode]"]' => ['value' => 'direct'],
        ],
      ],
      '#disabled' => empty($content_type),
    ];
  
    $form['display_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Settings'),
      '#open' => FALSE,
    ];
  
    $form['display_settings']['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Display Mode'),
      '#options' => $this->getViewModeOptions($content_type),
      '#default_value' => $config['display_mode'],
    ];
  
    $form['display_settings']['items_to_show'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items to show'),
      '#default_value' => $config['items_to_show'],
      '#min' => 1,
      '#max' => 100,
    ];
  
    $form['display_settings']['sort_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by'),
      '#options' => [
        'created' => $this->t('Creation date'),
        'changed' => $this->t('Last updated'),
        'title' => $this->t('Title'),
        'sticky' => $this->t('Sticky first'),
      ],
      '#default_value' => $config['sort_field'],
    ];
  
    $form['display_settings']['sort_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort direction'),
      '#options' => [
        'ASC' => $this->t('Ascending'),
        'DESC' => $this->t('Descending'),
      ],
      '#default_value' => $config['sort_direction'],
    ];
  
    return $form;
  }

  /**
   * Ajax callback to update taxonomy settings.
   */
  public function updateTaxonomySettings(array &$form, FormStateInterface $form_state) {
    if (isset($form['settings']['block_form']['taxonomy_settings_wrapper'])) {
        return $form['settings']['block_form']['taxonomy_settings_wrapper'];
    }
    if (isset($form['settings']['taxonomy_settings_wrapper'])) {
        return $form['settings']['taxonomy_settings_wrapper'];
    }
    if (isset($form['taxonomy_settings_wrapper'])) {
        return $form['taxonomy_settings_wrapper'];
    }
    return ['#markup' => 'Error: Wrapper not found'];
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['block_title'] = $form_state->getValue('block_title');
    $this->configuration['description'] = $form_state->getValue('description');
    $this->configuration['content_type'] = $form_state->getValue('content_type');
    $this->configuration['selection_mode'] = $form_state->getValue('selection_mode');
    
    // Handle taxonomy container values
    $taxonomy_container = $form_state->getValue('taxonomy_container');
    $this->configuration['taxonomy_vocabulary'] = $taxonomy_container['taxonomy_vocabulary'] ?? '';
    $this->configuration['taxonomy_field'] = $taxonomy_container['taxonomy_field'] ?? '';
    
    $taxonomy_values = $taxonomy_container['taxonomy_terms'] ?? [];
    $term_ids = [];
    if (!empty($taxonomy_values)) {
      foreach ($taxonomy_values as $term) {
        if (isset($term['target_id'])) {
          $term_ids[] = $term['target_id'];
        }
      }
    }
    $this->configuration['taxonomy_terms'] = $term_ids;

    // Handle direct entities
    $direct_values = $form_state->getValue('direct_entities');
    $entity_ids = [];
    if (!empty($direct_values)) {
      foreach ($direct_values as $entity) {
        if (isset($entity['target_id'])) {
          $entity_ids[] = $entity['target_id'];
        }
      }
    }
    $this->configuration['direct_entities'] = $entity_ids;
  
    // Display settings
    $display_settings = $form_state->getValue('display_settings');
    $this->configuration['display_mode'] = $display_settings['display_mode'] ?? 'teaser';
    $this->configuration['items_to_show'] = $display_settings['items_to_show'] ?? 10;
    $this->configuration['sort_field'] = $display_settings['sort_field'] ?? 'created';
    $this->configuration['sort_direction'] = $display_settings['sort_direction'] ?? 'DESC';
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configuration;
    
    if (empty($config['content_type'])) {
      return [];
    }

    $selection_mode = $config['selection_mode'] ?? 'taxonomy';
    $content_type = $config['content_type'];
    
    $cache_tags = ['node_list', 'node_list:' . $content_type];

    if ($selection_mode === 'direct') {
      $nids = $config['direct_entities'] ?? [];
      if (empty($nids)) {
        return [];
      }
      
      foreach ($nids as $nid) {
        $cache_tags[] = 'node:' . $nid;
      }
    } else {
      if (empty($config['taxonomy_field'])) {
        return [];
      }
      
      $term_ids = $config['taxonomy_terms'];
      if (empty($term_ids)) {
        return [];
      }
      
      foreach ($term_ids as $term_id) {
        $cache_tags[] = 'taxonomy_term:' . $term_id;
      }
      
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->condition('status', 1)
        ->condition('type', $content_type)
        ->accessCheck(TRUE);

      $taxonomy_field = $config['taxonomy_field'];
      if (count($term_ids) > 1) {
        $or_group = $query->orConditionGroup();
        foreach ($term_ids as $term_id) {
          $or_group->condition($taxonomy_field, $term_id);
        }
        $query->condition($or_group);
      } else {
        $query->condition($taxonomy_field, $term_ids[0]);
      }

      $query->range(0, $config['items_to_show'])
        ->sort($config['sort_field'], $config['sort_direction']);

      $nids = $query->execute();
    }

    $build = [
      '#theme' => 'taxonomy_filter_block',
      '#block_title' => $config['block_title'],
      '#description' => $config['description'],
      '#nodes' => [],
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['url.path', 'user.permissions'],
        'max-age' => 3600,
      ],
    ];

    if (!empty($nids)) {
      $storage = $this->entityTypeManager->getStorage('node');
      $nodes = $storage->loadMultiple($nids);
      $view_builder = $this->entityTypeManager->getViewBuilder('node');
      
      foreach ($nodes as $node) {
        $build['#nodes'][] = $view_builder->view($node, $config['display_mode']);
      }
    }

    return $build;
  }

  protected function getContentTypeOptions() {
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }
    return $options;
  }

  protected function getVocabularyOptions() {
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $options = [];
    foreach ($vocabularies as $vocabulary) {
      $options[$vocabulary->id()] = $vocabulary->label();
    }
    return $options;
  }

  protected function getTaxonomyFieldOptions($content_type, $vocabulary) {
    if (empty($content_type)) return [];
  
    $options = [];
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $content_type);
  
    foreach ($fields as $field_name => $field_definition) {
      if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }
      
      if ($field_definition->getType() === 'entity_reference') {
        $settings = $field_definition->getSettings();
        
        if (isset($settings['target_type']) && $settings['target_type'] === 'taxonomy_term') {
          $handler_settings = $settings['handler_settings'] ?? [];
          $target_bundles = $handler_settings['target_bundles'] ?? [];
          
          if (empty($vocabulary) || empty($target_bundles) || isset($target_bundles[$vocabulary])) {
            $label = $field_definition->getLabel() . ' (' . $field_name . ')';
            $options[$field_name] = $label;
          }
        }
      }
    }
  
    return $options;
  }

  protected function getViewModeOptions($content_type) {
    if (empty($content_type)) return ['default' => $this->t('Default')];

    $view_modes = $this->entityTypeManager->getStorage('entity_view_mode')->loadMultiple();
    $options = ['default' => $this->t('Default')];
    
    foreach ($view_modes as $view_mode) {
      if (strpos($view_mode->id(), 'node.') === 0) {
        $mode_id = str_replace('node.', '', $view_mode->id());
        $options[$mode_id] = $view_mode->label();
      }
    }
    return $options;
  }

  protected function loadTerms($term_ids) {
    if (empty($term_ids)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids);
  }

  protected function loadEntities($entity_ids) {
    if (empty($entity_ids)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
  }

}