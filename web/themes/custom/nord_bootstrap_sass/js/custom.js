/**
 * @file
 * Global utilities.
 *
 */
(function($, Drupal) {

  'use strict';

  Drupal.behaviors.nord_bootstrap_sass = {
    attach: function(context, settings) {

      // Set active navigation item based on current URL
      var currentPath = window.location.pathname;

      // Remove active class from all nav links
      $('.main-nav .nav-link', context).removeClass('active');

      // Determine which nav item should be active
      if (currentPath === '/' || currentPath === '') {
        $('.main-nav .nav-link[data-nav-item="home"]', context).addClass('active');
      } else if (currentPath.startsWith('/specialities')) {
        $('.main-nav .nav-link[data-nav-item="specialities"]', context).addClass('active');
      } else if (currentPath.startsWith('/doctors') || currentPath.startsWith('/medici')) {
        $('.main-nav .nav-link[data-nav-item="doctors"]', context).addClass('active');
      } else if (currentPath.startsWith('/centers') || currentPath.startsWith('/centre')) {
        $('.main-nav .nav-link[data-nav-item="centers"]', context).addClass('active');
      } else if (currentPath.startsWith('/packages') || currentPath.startsWith('/pachete')) {
        $('.main-nav .nav-link[data-nav-item="packages"]', context).addClass('active');
      }

    }
  };

})(jQuery, Drupal);

