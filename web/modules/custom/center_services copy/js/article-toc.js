/**
 * @file
 * Article Table of Contents functionality.
 */

(function (Drupal, once) {
    'use strict';
  
    Drupal.behaviors.articleTOC = {
      attach: function (context, settings) {
        once('article-toc', '.article-content-wrapper', context).forEach(function (wrapper) {
          const tocBox = wrapper.querySelector('.sidebar-box--toc');
          const tocLinks = wrapper.querySelectorAll('.toc-link');
          const sections = wrapper.querySelectorAll('.article-section');
          const progressBar = wrapper.querySelector('.toc-progress__bar');
          const sidebar = wrapper.querySelector('.article-sidebar');
          const articleMain = wrapper.querySelector('.article-main');
  
          if (!tocBox || !sections.length || !tocLinks.length) {
            return;
          }
  
          // Configuration
          const config = {
            scrollOffset: 120,
            stickyOffset: 20,
            throttleDelay: 10
          };
  
          // State
          let isSticky = false;
          let stickyStart = 0;
          let stickyEnd = 0;
          let ticking = false;
  
          /**
           * Initialize sticky boundaries.
           */
          function initStickyBounds() {
            const wrapperRect = wrapper.getBoundingClientRect();
            const sidebarRect = sidebar.getBoundingClientRect();
            
            stickyStart = window.scrollY + sidebarRect.top - config.stickyOffset;
            stickyEnd = window.scrollY + wrapperRect.bottom - tocBox.offsetHeight - config.stickyOffset - 100;
          }
  
          /**
           * Handle smooth scroll to section.
           */
          function scrollToSection(targetId) {
            const target = document.getElementById(targetId);
            if (!target) return;
  
            const targetPosition = target.getBoundingClientRect().top + window.scrollY - config.scrollOffset;
            
            window.scrollTo({
              top: targetPosition,
              behavior: 'smooth'
            });
          }
  
          /**
           * Update active TOC link based on scroll position.
           */
          function updateActiveSection() {
            const scrollPosition = window.scrollY + config.scrollOffset + 50;
            let currentSection = null;
            let currentIndex = 0;
  
            sections.forEach(function (section, index) {
              const sectionTop = section.offsetTop;
              const sectionBottom = sectionTop + section.offsetHeight;
  
              if (scrollPosition >= sectionTop && scrollPosition < sectionBottom) {
                currentSection = section.getAttribute('id');
                currentIndex = index;
              }
            });
  
            // If past all sections, select last one
            if (!currentSection && sections.length > 0) {
              const lastSection = sections[sections.length - 1];
              if (scrollPosition >= lastSection.offsetTop) {
                currentSection = lastSection.getAttribute('id');
                currentIndex = sections.length - 1;
              }
            }
  
            // Update active states
            tocLinks.forEach(function (link, index) {
              const isActive = link.getAttribute('data-target') === currentSection;
              link.classList.toggle('is-active', isActive);
              link.closest('.toc-list__item')?.classList.toggle('is-active', isActive);
            });
  
            // Update progress bar
            if (progressBar && sections.length > 0) {
              const progress = ((currentIndex + 1) / sections.length) * 100;
              progressBar.style.height = progress + '%';
            }
          }
  
          /**
           * Handle sticky positioning of TOC.
           */
          function handleSticky() {
            const scrollY = window.scrollY;
  
            if (scrollY >= stickyStart && scrollY <= stickyEnd) {
              if (!isSticky) {
                tocBox.classList.add('is-sticky');
                tocBox.classList.remove('is-bottom');
                isSticky = true;
              }
            } else if (scrollY > stickyEnd) {
              tocBox.classList.remove('is-sticky');
              tocBox.classList.add('is-bottom');
              isSticky = false;
            } else {
              tocBox.classList.remove('is-sticky', 'is-bottom');
              isSticky = false;
            }
          }
  
          /**
           * Throttled scroll handler.
           */
          function onScroll() {
            if (!ticking) {
              window.requestAnimationFrame(function () {
                updateActiveSection();
                handleSticky();
                ticking = false;
              });
              ticking = true;
            }
          }
  
          /**
           * Handle window resize.
           */
          function onResize() {
            initStickyBounds();
            
            // Check if mobile
            if (window.innerWidth < 1024) {
              tocBox.classList.remove('is-sticky', 'is-bottom');
              isSticky = false;
            }
          }
  
          // Event Listeners
          tocLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
              e.preventDefault();
              const targetId = this.getAttribute('data-target');
              scrollToSection(targetId);
              
              // Update URL hash without jumping
              history.pushState(null, null, '#' + targetId);
            });
          });
  
          window.addEventListener('scroll', onScroll, { passive: true });
          window.addEventListener('resize', Drupal.debounce(onResize, 200));
  
          // Initialize
          initStickyBounds();
          updateActiveSection();
  
          // Handle initial hash in URL
          if (window.location.hash) {
            const targetId = window.location.hash.substring(1);
            setTimeout(function () {
              scrollToSection(targetId);
            }, 100);
          }
        });
      }
    };
  
  })(Drupal, once);