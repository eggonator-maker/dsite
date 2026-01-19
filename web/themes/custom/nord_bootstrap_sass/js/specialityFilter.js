((Drupal, once) => {
    /**
     * Normalizes text by making it lowercase, trimming whitespace,
     * and removing diacritics (accents).
     * e.g., "  Cardiologie â  " -> "cardiologie a"
     * @param {string} text - The text to normalize.
     * @returns {string} The normalized text.
     */
    function normalizeText(text) {
      if (!text) {
        return '';
      }
      return text
        .trim()
        .toLowerCase()
        .normalize('NFD') // Separates combined characters (e.g., 'â' -> 'a' + 'ˆ')
        .replace(/[\u0300-\u036f]/g, ''); // Removes the diacritical marks
    }
  
    /**
     * Filters the speciality list based on the search input.
     * @param {Event} event - The 'input' event from the search box.
     */
    function handleSearchFilter(event) {
      const input = event.currentTarget;
      const filterText = normalizeText(input.value);
      
      // Find the parent navigation block to search within
      const navBlock = input.closest('#speciality-navigation-block');
      if (!navBlock) {
        return;
      }
  
      // Find all the list items to be filtered
      const items = navBlock.querySelectorAll('li.nav-item');
      console.log("Trying!!!!")
      console.log(items);
      items.forEach(item => {
        // Get the text content of the link inside the list item
        const itemText = normalizeText(item.textContent);
        
        // Check if the item's text includes the filter text
        if (itemText.includes(filterText)) {
          item.style.display = ''; // Show the item
        } else {
          item.style.display = 'none'; // Hide the item
        }
      });
    }
  
    /**
     * Attaches the search filter behavior.
     */
    Drupal.behaviors.specialitySearchFilter = {
      attach: function (context, settings) {
        // Find all search boxes within the speciality nav
        const searchInputs = once('speciality-search', '#speciality-navigation-block .search-box__input', context);
        
        if (searchInputs.length > 0) {
          console.log('Attaching speciality search filter behavior.');
          searchInputs.forEach(input => {
            // Use 'input' event to filter as the user types
            input.addEventListener('input', handleSearchFilter);
          });
        }
      }
    };
  
  })(Drupal, once);