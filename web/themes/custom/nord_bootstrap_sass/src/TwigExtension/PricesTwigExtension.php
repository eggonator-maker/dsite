<?php

namespace Drupal\nord_bootstrap_sass\TwigExtension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Custom Twig extension for prices page.
 */
class PricesTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('clean_id', [$this, 'cleanId']),
    ];
  }

  /**
   * Converts a string to a clean HTML ID.
   *
   * @param string $string
   *   The string to convert.
   *
   * @return string
   *   The cleaned ID string.
   */
  public function cleanId($string) {
    // Convert to lowercase
    $string = mb_strtolower($string, 'UTF-8');
    
    // Replace Romanian characters
    $replacements = [
      'ă' => 'a',
      'â' => 'a',
      'î' => 'i',
      'ș' => 's',
      'ț' => 't',
      'Ă' => 'a',
      'Â' => 'a',
      'Î' => 'i',
      'Ș' => 's',
      'Ț' => 't',
    ];
    $string = strtr($string, $replacements);
    
    // Replace non-alphanumeric characters with hyphens
    $string = preg_replace('/[^a-z0-9]+/', '-', $string);
    
    // Remove leading/trailing hyphens
    $string = trim($string, '-');
    
    return $string;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'prices_twig_extension';
  }

}
