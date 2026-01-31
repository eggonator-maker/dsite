(function (Drupal, once) {
    'use strict';
  
    Drupal.behaviors.articlesTagsFilter = {
      attach: function (context) {
        // Use native once() function (NOT jQuery's .once())
        const scrollers = once('articles-tags-filter', '.articles-tags-filter__scroller', context);
        
        scrollers.forEach(function(scroller) {
          const container = scroller.closest('.articles-tags-filter');
          if (!container) return;
  
          const leftIndicator = container.querySelector('.articles-tags-filter__scroll-indicator--left');
          const rightIndicator = container.querySelector('.articles-tags-filter__scroll-indicator--right');
          const tagButtons = container.querySelectorAll('.articles-tags-filter__button');
          const form = container.closest('form');
          const hiddenSelect = form ? form.querySelector('select[name="field_tags_target_id"]') : null;
          const submitButton = form ? form.querySelector('button[type="submit"]') : null;
          const scrollEndBuffer = 5;
  
          // Check scroll position and update indicators
          const checkScroll = () => {
            const scrollLeft = scroller.scrollLeft;
            const scrollWidth = scroller.scrollWidth;
            const clientWidth = scroller.clientWidth;
            
            if (scrollLeft > 0) {
              leftIndicator.classList.add('is-visible');
            } else {
              leftIndicator.classList.remove('is-visible');
            }
  
            if (scrollLeft < scrollWidth - clientWidth - scrollEndBuffer) {
              rightIndicator.classList.add('is-visible');
            } else {
              rightIndicator.classList.remove('is-visible');
            }
          };
  
          // Scroll event listener
          scroller.addEventListener('scroll', checkScroll, { passive: true });
  
          // Resize event listener
          window.addEventListener('resize', checkScroll);
  
          // Click indicators to scroll
          if (leftIndicator) {
            leftIndicator.addEventListener('click', function() {
              scroller.scrollBy({ left: -250, behavior: 'smooth' });
            });
          }
  
          if (rightIndicator) {
            rightIndicator.addEventListener('click', function() {
              scroller.scrollBy({ left: 250, behavior: 'smooth' });
            });
          }
  
          // Tag button click handler
          tagButtons.forEach(function(button) {
            button.addEventListener('click', function() {
              const tagValue = button.getAttribute('data-tag-value');
  
              // Update button states
              tagButtons.forEach(function(btn) {
                btn.classList.remove('articles-tags-filter__button--active');
              });
              button.classList.add('articles-tags-filter__button--active');
  
              // Update hidden select field
              if (hiddenSelect) {
                hiddenSelect.value = tagValue;
              }
  
              // Trigger form submission
              if (submitButton) {
                submitButton.click();
              }
            });
          });
  
          // Initial check
          checkScroll();
  
          // Observe for dynamic content changes
          const observer = new MutationObserver(checkScroll);
          observer.observe(scroller, { childList: true, subtree: true });
        });
      }
    };
  
  })(Drupal, once);