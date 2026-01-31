/**
 * Prices Page JavaScript
 * Handles search, navigation, filtering and smooth scrolling
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.pricesPage = {
    attach: function (context, settings) {
      const $search = $('#prices-search', context);
      const $sidebar = $('.prices-sidebar', context);
      const $content = $('.prices-content', context);
      const $sidebarLinks = $('.sidebar-category__link', context);
      const $mainCategoryButtons = $('.prices-sidebar-accordion > .accordion-item > .accordion-header > .accordion-button', context);
      
      // Initialize: Hide all main categories except first, and show only first category's content
      once('prices-init', $content.get(0)).forEach(function() {
        const $allCategories = $('.price-category-section', $content);
        const $firstCategory = $allCategories.first();
        
        // Hide all categories first
        $allCategories.hide();
        
        // Show only the first category and its content
        if ($firstCategory.length) {
          $firstCategory.show();
          $firstCategory.find('.price-subcategory').show();
          $firstCategory.find('.accordion-item').show();
          $firstCategory.find('.price-item').show();
        }
      });
      
      // Search functionality
      if ($search.length) {
        once('prices-search', $search.get(0)).forEach(function(element) {
          $(element).on('input', function() {
            const searchTerm = $(this).val().toLowerCase().trim();
            
            // Clear any active filtering when searching
            if (searchTerm !== '') {
              $sidebarLinks.removeClass('active');
            }
            
            if (searchTerm === '') {
              // Show all items
              $('.price-item', $content).show();
              $('.accordion-item', $content).show();
              $('.price-subcategory', $content).show();
              $('.price-category-section', $content).show();
              return;
            }
            
            // Filter price items
            $('.price-item', $content).each(function() {
              const $item = $(this);
              const text = $item.text().toLowerCase();
              
              if (text.includes(searchTerm)) {
                $item.show();
                $item.closest('.accordion-item').show();
                $item.closest('.price-subcategory').show();
                $item.closest('.price-category-section').show();
              } else {
                $item.hide();
              }
            });
            
            // Hide empty accordions
            $('.accordion-item', $content).each(function() {
              const $accordion = $(this);
              const visibleItems = $accordion.find('.price-item:visible').length;
              if (visibleItems === 0) {
                $accordion.hide();
              }
            });
            
            // Hide empty subcategories
            $('.price-subcategory', $content).each(function() {
              const $subcategory = $(this);
              const visibleContent = $subcategory.find('.price-item:visible').length;
              if (visibleContent === 0) {
                $subcategory.hide();
              }
            });
            
            // Hide empty main categories
            $('.price-category-section', $content).each(function() {
              const $category = $(this);
              const visibleSubcategories = $category.find('.price-subcategory:visible').length;
              if (visibleSubcategories === 0) {
                $category.hide();
              }
            });
          });
        });
      }
      
      // Main category button clicks - ONLY for expanding/collapsing sidebar, NOT for content switching
      once('prices-main-cat', $mainCategoryButtons.toArray()).forEach(function(element) {
        $(element).on('click', function(e) {
          // Let Bootstrap handle the accordion expand/collapse
          // Do NOT change the displayed content
        });
      });
      
      // Subcategory link clicks
      once('prices-nav', $sidebarLinks.toArray()).forEach(function(element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const $link = $(this);
          const targetId = $link.attr('href');
          const $target = $(targetId);
          
          if ($target.length) {
            // Clear search
            if ($search.length) {
              $search.val('');
            }
            
            // Remove active from all links
            $sidebarLinks.removeClass('active');
            
            // Add active to clicked link
            $link.addClass('active');
            
            // Hide all main categories
            $('.price-category-section', $content).hide();
            
            // Show the parent main category
            const $parentCategory = $target.closest('.price-category-section');
            $parentCategory.show();
            
            // Hide all subcategories in this category
            $parentCategory.find('.price-subcategory').hide();
            
            // Show only the target subcategory and its content
            $target.show();
            $target.find('.accordion-item').show();
            $target.find('.price-item').show();
            
            // Smooth scroll to target
            $('html, body').animate({
              scrollTop: $target.offset().top - 100
            }, 500);
            
            // Open first accordion if it exists and is collapsed
            const $accordion = $target.find('.accordion-item:first .accordion-collapse');
            if ($accordion.length && !$accordion.hasClass('show')) {
              $accordion.collapse('show');
            }
          }
        });
      });
      
      // Highlight active section on scroll
      once('prices-scroll', window).forEach(function() {
        $(window).on('scroll', function() {
          // Skip if there's an active filter
          if ($sidebarLinks.filter('.active').length > 0) {
            return;
          }
          
          const scrollPos = $(window).scrollTop() + 150;
          
          $('.price-subcategory:visible', $content).each(function() {
            const $section = $(this);
            const sectionTop = $section.offset().top;
            const sectionBottom = sectionTop + $section.outerHeight();
            const sectionId = $section.attr('id');
            
            if (scrollPos >= sectionTop && scrollPos < sectionBottom) {
              $sidebarLinks.removeClass('active');
              $sidebarLinks.filter('[href="#' + sectionId + '"]').addClass('active');
            }
          });
        });
      });
      
      // Clear search button
      if ($search.length) {
        const searchElements = once('prices-clear', $search.get(0));
        if (searchElements.length > 0) {
          $search.wrap('<div class="search-wrapper position-relative"></div>');
          
          $clearBtn.hide();
          
          $search.on('input', function() {
            if ($(this).val().length > 0) {
              $clearBtn.show();
            } else {
              $clearBtn.hide();
            }
          });
          
          $clearBtn.on('click', function() {
            $search.val('').trigger('input');
            $clearBtn.hide();
            
            // Reset to initial state - show only first category
            $sidebarLinks.removeClass('active');
            
            const $allCategories = $('.price-category-section', $content);
            const $firstCategory = $allCategories.first();
            
            $allCategories.hide();
            
            if ($firstCategory.length) {
              $firstCategory.show();
              $firstCategory.find('.price-subcategory').show();
              $firstCategory.find('.accordion-item').show();
              $firstCategory.find('.price-item').show();
            }
          });
        }
      }
    }
  };

})(jQuery, Drupal, once);