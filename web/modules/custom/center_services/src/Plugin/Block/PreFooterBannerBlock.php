<?php

namespace Drupal\center_services\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Pre-Footer Banner' Block.
 *
 * @Block(
 *   id = "pre_footer_banner",
 *   admin_label = @Translation("Pre-Footer Banner"),
 *   category = @Translation("Center Services"),
 * )
 */
class PreFooterBannerBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'variant' => 'full-width',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['variant'] = [
      '#type' => 'select',
      '#title' => $this->t('Variant'),
      '#description' => $this->t('Choose the banner variant.'),
      '#options' => [
        'full-width' => $this->t('Full Width (Footer)'),
        'boxed' => $this->t('Boxed (Center Pages)'),
      ],
      '#default_value' => $config['variant'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $this->configuration['variant'] = $form_state->getValue('variant');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();

    return [
      '#theme' => 'pre_footer_banner',
      '#variant' => $config['variant'],
      '#cache' => [
        'max-age' => 3600,
      ],
    ];
  }

}