/**
 * @file
 * Doctors Search Auto-submit functionality.
 *
 * Features:
 * - Auto-submits on dropdown change (immediate)
 * - Auto-submits on text input (debounced 500ms)
 * - Preserves input value during AJAX updates (prevents lag/reset)
 * - Preserves focus and cursor position after AJAX
 * - Fixes scrollTop AJAX error
 */

(function ($, Drupal, once) {
  'use strict';

  // Store the current input values to preserve during AJAX
  var currentInputValues = {
    title: '',
    speciality: 'All'
  };

  // Track which element had focus before AJAX
  var focusedElement = null;
  var cursorPosition = 0;

  // Track if we're currently in an AJAX request
  var isAjaxInProgress = false;

  /**
   * Override scrollTop AJAX command to prevent errors
   */
  if (Drupal.AjaxCommands && Drupal.AjaxCommands.prototype) {
    var originalScrollTop = Drupal.AjaxCommands.prototype.scrollTop;
    Drupal.AjaxCommands.prototype.scrollTop = function (ajax, response, status) {
      var $target = $(response.selector);
      if ($target.length && $target.offset() && typeof $target.offset().top !== 'undefined') {
        try {
          originalScrollTop.call(this, ajax, response, status);
        } catch (e) {
          // Silently fail
        }
      }
    };
  }

  /**
   * Override insert AJAX command to preserve input values and focus
   */
  if (Drupal.AjaxCommands && Drupal.AjaxCommands.prototype) {
    var originalInsert = Drupal.AjaxCommands.prototype.insert;
    Drupal.AjaxCommands.prototype.insert = function (ajax, response, status) {
      // Call original insert
      originalInsert.call(this, ajax, response, status);

      // After DOM is updated, restore the current input values
      var $titleInput = $('.doctors-search-form__input');
      if ($titleInput.length && currentInputValues.title !== '') {
        $titleInput.val(currentInputValues.title);
      }

      // Restore dropdown if needed
      var $select = $('.doctors-search-form__select');
      if ($select.length && currentInputValues.speciality) {
        $select.val(currentInputValues.speciality);
      }

      // Restore focus to the text input if it was focused before
      if (focusedElement === 'title' && $titleInput.length) {
        $titleInput.focus();
        
        // Restore cursor position
        var input = $titleInput[0];
        if (input.setSelectionRange) {
          var pos = Math.min(cursorPosition, currentInputValues.title.length);
          input.setSelectionRange(pos, pos);
        }
      } else if (focusedElement === 'speciality' && $select.length) {
        $select.focus();
      }

      isAjaxInProgress = false;
    };
  }

  /**
   * Main behavior for doctors search auto-submit
   */
  Drupal.behaviors.doctorsSearchAutosubmit = {
    attach: function (context, settings) {
      once('doctors-search-autosubmit', '.doctors-search-form', context).forEach(function (formWrapper) {
        var $wrapper = $(formWrapper);
        var $submitBtn = $wrapper.find('input[type="submit"], button[type="submit"], .form-submit');
        var $textInput = $wrapper.find('.doctors-search-form__input');
        var $dropdown = $wrapper.find('.doctors-search-form__select');

        var typingTimer = null;
        var doneTypingInterval = 500;

        // Initialize current values from the form
        if ($textInput.length) {
          currentInputValues.title = $textInput.val() || '';
        }
        if ($dropdown.length) {
          currentInputValues.speciality = $dropdown.val() || 'All';
        }

        /**
         * Trigger form submission
         */
        function triggerSubmit() {
          if ($submitBtn.length && !isAjaxInProgress) {
            isAjaxInProgress = true;
            $submitBtn.first().trigger('mousedown').trigger('click');
          }
        }

        /**
         * Track focus on text input
         */
        $textInput.on('focus.doctorsSearch', function () {
          focusedElement = 'title';
          currentInputValues.title = $(this).val();
        });

        /**
         * Track focus on dropdown
         */
        $dropdown.on('focus.doctorsSearch', function () {
          focusedElement = 'speciality';
        });

        /**
         * Track blur (but don't clear if AJAX is in progress)
         */
        $textInput.on('blur.doctorsSearch', function () {
          if (!isAjaxInProgress) {
            focusedElement = null;
          }
        });

        $dropdown.on('blur.doctorsSearch', function () {
          if (!isAjaxInProgress) {
            focusedElement = null;
          }
        });

        /**
         * Dropdown change - immediate submission
         */
        $dropdown.on('change.doctorsSearch', function () {
          currentInputValues.speciality = $(this).val();
          focusedElement = 'speciality';

          if (typingTimer) {
            clearTimeout(typingTimer);
            typingTimer = null;
          }
          triggerSubmit();
        });

        /**
         * Text input - debounced submission
         * Always update the stored value immediately
         */
        $textInput.on('input.doctorsSearch', function () {
          // Always store the current value and cursor position immediately
          currentInputValues.title = $(this).val();
          cursorPosition = this.selectionStart || currentInputValues.title.length;
          focusedElement = 'title';

          if (typingTimer) {
            clearTimeout(typingTimer);
          }

          typingTimer = setTimeout(function () {
            // Update cursor position right before submit
            if ($textInput.length && $textInput[0].selectionStart !== undefined) {
              cursorPosition = $textInput[0].selectionStart;
            }
            triggerSubmit();
            typingTimer = null;
          }, doneTypingInterval);
        });

        /**
         * Prevent form submission on Enter, trigger AJAX instead
         */
        $textInput.on('keydown.doctorsSearch', function (e) {
          if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            currentInputValues.title = $(this).val();
            cursorPosition = this.selectionStart || currentInputValues.title.length;
            focusedElement = 'title';

            if (typingTimer) {
              clearTimeout(typingTimer);
              typingTimer = null;
            }
            triggerSubmit();
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);