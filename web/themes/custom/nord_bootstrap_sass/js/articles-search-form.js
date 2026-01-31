(function (Drupal, once) {
    'use strict';
  
    Drupal.behaviors.articlesSearchAutoSubmit = {
      attach: function (context) {
        // Apply once() to the actual form element
        const forms = once('articles-search-autosubmit', '.articles-search-form', context);
        
        forms.forEach(function(formElement) {
          const form = formElement.closest('form');
          if (!form) return;
  
          const selectField = formElement.querySelector('.articles-search-form__select');
          const inputField = formElement.querySelector('.articles-search-form__input');
          const submitButton = form.querySelector('[data-drupal-selector*="submit"]');
          
          // Auto-submit on dropdown change
          if (selectField && submitButton) {
            selectField.addEventListener('change', function() {
              submitButton.click();
            });
          }
          
          // Optional: debounced auto-submit on text input
          if (inputField && submitButton) {
            let timeout;
            inputField.addEventListener('keyup', function() {
              clearTimeout(timeout);
              timeout = setTimeout(function() {
                submitButton.click();
              }, 500);
            });
          }
        });
      }
    };
  
  })(Drupal, once);