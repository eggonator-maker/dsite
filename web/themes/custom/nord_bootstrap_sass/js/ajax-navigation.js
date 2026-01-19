((Drupal, once) => { // <-- Import Drupal's 'once' helper
  // Main content selector that will be replaced.
  const mainContentSelector = '#main'; // Adjust this to your theme's main content ID.
  
  // *** MODIFICATION ***
  // List all navigation block IDs that need AJAX state management.
  const navSelectors = [
    '#center-navigation-block', 
    '#speciality-navigation-block'
  ];
  
  function handleAjaxLinkClick(event) {
    console.log('AJAX link clicked! Preventing default reload.');

    event.preventDefault();
  
    const link = event.currentTarget;
    const url = new URL(link.href);
    url.searchParams.set('ajax_content', '1'); // Add our magic parameter.
    
    // Add a loading class for UX.
    document.querySelector(mainContentSelector).classList.add('is-loading');
    
    // Fetch the new content.
    fetch(url)
      .then((response) => response.json())
      .then((data) => {
        if (data.status) {
          // Replace the content and the page title.
          document.querySelector(mainContentSelector).innerHTML = data.content;
          document.title = data.title;
          
          // Update the browser history.
          history.pushState({}, data.title, link.href);

          // Update active classes on navigation LI elements.
          updateActiveLinks(link.href);

          // Re-attach Drupal behaviors to the new content.
          Drupal.attachBehaviors(document.querySelector(mainContentSelector));
        }
      })
      .catch((error) => {
        console.error('Error fetching content:', error);
        // On error, maybe just navigate the normal way.
        window.location.href = link.href;
      })
      .finally(() => {
        document.querySelector(mainContentSelector).classList.remove('is-loading');
      });
  }

  // *** MODIFIED FUNCTION ***
  // Updates which LI in *all* registered navigation blocks has the active classes.
  function updateActiveLinks(activeUrl) {
    const url = new URL(activeUrl);
    const path = url.pathname; // e.g., "/centers/test/about-the-center" or "/specialities/term-name"

    // Loop over BOTH navigation systems
    navSelectors.forEach(selector => {
      const navBlock = document.querySelector(selector);
      if (!navBlock) {
        return; // This nav isn't on the current page, so skip it.
      }

      // *** FIX: 1A. Remove 'is-active' from all LINKS ***
      const allLinks = navBlock.querySelectorAll('a.nav-link');
      allLinks.forEach(link => {
        link.classList.remove('is-active');
      });

      // 1B. Remove all active/trail classes from all LIST ITEMS
      const allListItems = navBlock.querySelectorAll('li.nav-item');
      allListItems.forEach(li => {
        li.classList.remove('is-active', 'in-active-trail');
      });

      // *** Reset all collapse states (specific to center-nav, but harmless on speciality-nav) ***
      const allCollapses = navBlock.querySelectorAll('.collapse');
      allCollapses.forEach(collapse => {
        collapse.classList.remove('show');
      });
      const allToggles = navBlock.querySelectorAll('.nav-toggle');
      allToggles.forEach(toggle => {
        toggle.setAttribute('aria-expanded', 'false');
      });

      // 2. Find the link that matches the new URL path *in this block*
      let activeLink = navBlock.querySelector(`a[href="${path}"]`);
      let currentLi = null;

      if (activeLink) {
        // Found a direct match
        currentLi = activeLink.closest('li.nav-item');
        console.log(`AJAX: Found matching link in ${selector}:`, activeLink);
      } else {
        // Default to first item logic (for center nav base page)
        // This logic is specific to the center nav, so we check the selector.
        if (selector === '#center-navigation-block' && path.match(/^\/centers\/[a-z0-9-]+$/i)) {
          console.log('AJAX: No matching link found in center-nav, defaulting to first item.');
          currentLi = navBlock.querySelector('li.nav-item'); // Get the first LI
          // *** FIX: Also get the link inside this default LI ***
          if (currentLi) {
            activeLink = currentLi.querySelector('a.nav-link');
          }
        } else {
          console.log(`AJAX: Could not find matching link or default for ${path} in ${selector}.`);
          return; // No match and not a base page, so do nothing for this block
        }
      }

      // 3. Add 'is-active' and 'in-active-trail' to its parent LI
      if (currentLi && activeLink) { // <-- Make sure we found both
        
        // *** FIX: Add 'is-active' to the LINK itself (for SCSS style) ***
        activeLink.classList.add('is-active');
        
        // Add classes to the parent LI (for Drupal state)
        currentLi.classList.add('is-active');
        currentLi.classList.add('in-active-trail'); // The active item is also in the trail

        // ***************************************************************
        // *** Check if the active LI *itself* is collapsible ***
        // (This applies to the center nav, harmless on speciality nav)
        if (currentLi.classList.contains('nav-item--collapsible')) {
          // Use :scope to find direct children only
          const selfToggle = currentLi.querySelector(':scope > .nav-collapsible > .nav-toggle');
          const selfCollapse = currentLi.querySelector(':scope > .collapse');
          
          if (selfToggle) {
            selfToggle.setAttribute('aria-expanded', 'true');
          }
          if (selfCollapse) {
            selfCollapse.classList.add('show');
          }
        }
        // ***************************************************************

        // 4. Walk up the DOM tree to add 'in-active-trail' to all ancestors
        //    AND programmatically expand their dropdowns.
        //    (This will only find parents in center-nav, and do nothing in speciality-nav, which is correct)
        let parentCollapse = currentLi.closest('.collapse');
        while (parentCollapse) {
          // A) Add 'show' to the collapse container we just found
          parentCollapse.classList.add('show');
          
          // B) Find the LI that this collapse lives in
          const parentLi = parentCollapse.closest('li.nav-item');
          if (parentLi) {
            parentLi.classList.add('in-active-trail');

            // C) Find the toggle that *controls* this collapse
            // Use :scope to ensure we only find the toggle at the current LI's level
            const toggle = parentLi.querySelector(`:scope > .nav-collapsible > .nav-toggle[data-bs-target="#${parentCollapse.id}"]`);
            if (toggle) {
              toggle.setAttribute('aria-expanded', 'true');
            }

            // D) Move up to the next level
            parentCollapse = parentLi.closest('.collapse');
          } else {
            // Stop if no parent LI is found
            parentCollapse = null;
          }
        }
      }
    }); // End of forEach navSelectors loop
  }

  // Handle the browser's back/forward buttons.
  window.addEventListener('popstate', (event) => {
    const url = new URL(window.location.href);
    url.searchParams.set('ajax_content', '1');

    document.querySelector(mainContentSelector).classList.add('is-loading');

    fetch(url)
      .then(response => response.json())
      .then(data => {
        if (data.status) {
          document.querySelector(mainContentSelector).innerHTML = data.content;
          document.title = data.title;
          updateActiveLinks(window.location.href);
          Drupal.attachBehaviors(document.querySelector(mainContentSelector));
        }
      })
      .finally(() => {
        document.querySelector(mainContentSelector).classList.remove('is-loading');
      });
  });

  // Attach the click handler to the links.
  Drupal.behaviors.ajaxNavigation = {
      attach: function (context, settings) {
        console.log('Attaching AJAX navigation behavior.');
  
        // Use Drupal's 'once' helper to ensure this only runs ONCE per link.
        // This selector finds ALL ajax links, in *both* blocks, which is what we want.
        const links = once('ajax-center-nav', 'a[data-ajax-link]', context);
  
        if (links.length > 0) {
          console.log('Found', links.length, 'new AJAX links to process.');
          links.forEach(link => {
            link.addEventListener('click', handleAjaxLinkClick);
          });
        } else {
          console.log('No new AJAX links found in the current context.');
        }
      }
    };

})(Drupal, once);