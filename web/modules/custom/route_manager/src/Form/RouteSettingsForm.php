<?php

namespace Drupal\route_manager\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-route SEO and access settings form.
 *
 * Route: /admin/route-manager/{id}/edit
 * If id=0, reads ?path= from query to create a new record.
 */
class RouteSettingsForm extends FormBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
    );
  }

  public function getFormId(): string {
    return 'route_manager_route_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, int $id = 0): array {
    $record = [];
    $meta = [];

    if ($id > 0) {
      $record = $this->database->select('route_manager_settings', 'rms')
        ->fields('rms')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc() ?: [];
    }

    if (empty($record) && $id === 0) {
      $path = $this->getRequest()->query->get('path', '');
      $record['path'] = $path;
    }

    if (!empty($record['metatag_overrides'])) {
      $meta = unserialize($record['metatag_overrides'], ['allowed_classes' => FALSE]);
    }

    $form['#record_id'] = $id;

    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#default_value' => $record['path'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('URL path, e.g. /about'),
    ];

    $form['route_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route name'),
      '#default_value' => $record['route_name'] ?? '',
      '#description' => $this->t('Optional Drupal route machine name.'),
    ];

    $form['access'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Access Control'),
    ];

    $form['access']['is_public'] = [
      '#type' => 'radios',
      '#title' => $this->t('Visibility'),
      '#options' => [
        '' => $this->t('Inherit global default'),
        '1' => $this->t('Public (accessible to everyone)'),
        '0' => $this->t('Hidden (returns 403 for non-admins)'),
      ],
      '#default_value' => isset($record['is_public']) ? (string) $record['is_public'] : '',
    ];

    $form['seo'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SEO Overrides'),
    ];

    $form['seo']['page_title_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page title override'),
      '#default_value' => $record['page_title_override'] ?? '',
      '#description' => $this->t('Overrides the HTML &lt;title&gt; tag.'),
    ];

    $form['seo']['meta_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Meta title'),
      '#default_value' => $meta['title'] ?? '',
      '#maxlength' => 255,
    ];

    $form['seo']['meta_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Meta description'),
      '#default_value' => $meta['description'] ?? '',
      '#rows' => 3,
    ];

    $form['seo']['canonical'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Canonical URL'),
      '#default_value' => $meta['canonical'] ?? '',
    ];

    $form['seo']['robots'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Robots meta'),
      '#default_value' => $meta['robots'] ?? '',
      '#description' => $this->t('e.g. noindex, nofollow'),
    ];

    $form['og'] = [
      '#type' => 'details',
      '#title' => $this->t('Open Graph'),
      '#open' => FALSE,
    ];

    $form['og']['og_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OG Title'),
      '#default_value' => $meta['og:title'] ?? '',
    ];

    $form['og']['og_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OG Description'),
      '#default_value' => $meta['og:description'] ?? '',
      '#rows' => 3,
    ];

    $form['og']['og_image'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OG Image URL'),
      '#default_value' => $meta['og:image'] ?? '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#ajax' => [
        'callback' => '::ajaxSave',
        'event'    => 'click',
      ],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('route_manager.admin_listing'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * AJAX callback: closes the modal and redirects the parent to the listing.
   *
   * Called when the form is submitted from inside a dialog. If there are
   * validation errors the form is rebuilt normally (Drupal re-renders it in
   * the modal with the error messages).
   */
  public function ajaxSave(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      // Validation failed — nothing to do here; Drupal re-renders the form.
      return $response;
    }

    $listing_url = Url::fromRoute('route_manager.admin_listing')->toString();
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new MessageCommand(
      (string) $this->t('Route settings saved.'),
      NULL,
      ['type' => 'status'],
      TRUE,
    ));
    $response->addCommand(new RedirectCommand($listing_url));

    return $response;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = $form['#record_id'];
    $is_public_raw = $form_state->getValue('is_public');
    $is_public = ($is_public_raw === '') ? NULL : (int) $is_public_raw;

    $meta = array_filter([
      'title' => $form_state->getValue('meta_title'),
      'description' => $form_state->getValue('meta_description'),
      'canonical' => $form_state->getValue('canonical'),
      'robots' => $form_state->getValue('robots'),
      'og:title' => $form_state->getValue('og_title'),
      'og:description' => $form_state->getValue('og_description'),
      'og:image' => $form_state->getValue('og_image'),
    ]);

    $fields = [
      'path' => $form_state->getValue('path'),
      'route_name' => $form_state->getValue('route_name'),
      'is_public' => $is_public,
      'page_title_override' => $form_state->getValue('page_title_override') ?: NULL,
      'metatag_overrides' => $meta ? serialize($meta) : NULL,
      'updated' => time(),
    ];

    if ($id > 0) {
      $this->database->update('route_manager_settings')
        ->fields($fields)
        ->condition('id', $id)
        ->execute();
    }
    else {
      // Upsert by path.
      $existing = $this->database->select('route_manager_settings', 'rms')
        ->fields('rms', ['id'])
        ->condition('path', $fields['path'])
        ->execute()
        ->fetchField();

      if ($existing) {
        $this->database->update('route_manager_settings')
          ->fields($fields)
          ->condition('id', $existing)
          ->execute();
      }
      else {
        $this->database->insert('route_manager_settings')
          ->fields($fields)
          ->execute();
      }
    }

    $this->messenger()->addStatus($this->t('Route settings saved.'));
    $form_state->setRedirectUrl(Url::fromRoute('route_manager.admin_listing'));
  }

}
