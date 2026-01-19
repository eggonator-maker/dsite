(function() {
    // Store UTMs in sessionStorage on first page load
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('utm_source') && !sessionStorage.getItem('utm_source')) {
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(param => {
        const value = urlParams.get(param);
        if (value) sessionStorage.setItem(param, value);
      });
    }
    
    // Populate hidden form fields
    document.addEventListener('DOMContentLoaded', function() {
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(param => {
        const value = sessionStorage.getItem(param);
        const field = document.querySelector(`input[name="${param}"]`);
        if (field && value) field.value = value;
      });
    });
  })();