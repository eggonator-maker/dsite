/**
 * @file
 * Global utilities.
 *
 */
(function($, Drupal, once) {

  'use strict';

  Drupal.behaviors.nord_bootstrap_sass = {
    attach: function(context, settings) {

      // Set active navigation item based on current URL
      var currentPath = window.location.pathname;

      // Remove active class from all nav links
      $('.site-header__nav .site-header__nav-item > a', context).removeClass('active');

      // Determine which nav item should be active
      if (currentPath === '/' || currentPath === '') {
        $('.site-header__nav a[data-nav-item="home"]', context).addClass('active');
      } else if (currentPath.startsWith('/specialities') || currentPath.startsWith('/specialitati')) {
        $('.site-header__nav a[data-nav-item="specialitati"]', context).addClass('active');
      } else if (currentPath.startsWith('/doctors') || currentPath.startsWith('/medici')) {
        $('.site-header__nav a[data-nav-item="medici"]', context).addClass('active');
      } else if (currentPath.startsWith('/centers') || currentPath.startsWith('/centre-medicale')) {
        $('.site-header__nav a[data-nav-item="centre-medicale"]', context).addClass('active');
      } else if (currentPath.startsWith('/prices') || currentPath.startsWith('/preturi') || currentPath.startsWith("/pachete")) {
        $('.site-header__nav a[data-nav-item="preturi"]', context).addClass('active');
      }

      // Desktop dropdown - double click to navigate
      once('dropdown-nav', '.site-header__nav-item--dropdown > a', context).forEach(function(element) {
        var $link = $(element);
        var clickCount = 0;
        var clickTimer = null;

        $link.on('click', function(e) {
          e.preventDefault();
          clickCount++;

          if (clickCount === 1) {
            clickTimer = setTimeout(function() {
              clickCount = 0;
            }, 300);
          } else if (clickCount === 2) {
            clearTimeout(clickTimer);
            clickCount = 0;
            window.location.href = $link.attr('href');
          }
        });
      });

      // Mobile submenu toggle
      once('mobile-submenu', '[data-submenu-toggle]', context).forEach(function(element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          var $toggle = $(this);
          var $submenu = $toggle.closest('.site-header__mobile-nav-row').next('.site-header__mobile-submenu');
          
          // Close other open submenus
          $('.site-header__mobile-nav-toggle.active').not($toggle).removeClass('active');
          $('.site-header__mobile-submenu.show').not($submenu).removeClass('show');
          
          // Toggle current submenu
          $toggle.toggleClass('active');
          $submenu.toggleClass('show');
        });
      });

      // Close mobile menu when clicking on a regular link (not expandable parent)
      once('mobile-close', '.site-header__mobile-nav-item:not(.site-header__mobile-nav-item--expandable) .site-header__mobile-nav-link', context).forEach(function(element) {
        $(element).on('click', function() {
          var $offcanvas = $('#mobileMenu');
          if ($offcanvas.length) {
            var bsOffcanvas = bootstrap.Offcanvas.getInstance($offcanvas[0]);
            if (bsOffcanvas) {
              bsOffcanvas.hide();
            }
          }
        });
      });

      // Close menu when clicking submenu links
      once('submenu-close', '.site-header__mobile-submenu a', context).forEach(function(element) {
        $(element).on('click', function() {
          var $offcanvas = $('#mobileMenu');
          if ($offcanvas.length) {
            var bsOffcanvas = bootstrap.Offcanvas.getInstance($offcanvas[0]);
            if (bsOffcanvas) {
              bsOffcanvas.hide();
            }
          }
        });
      });

    }
  };

})(jQuery, Drupal, once);