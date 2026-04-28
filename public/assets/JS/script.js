function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function buildIngredientSuggestionMarkup(data) {
  const suggestions = data.suggestions || [];
  const best = data.best_guess || null;
  let html = '';

  if (best && best.name_ing) {
    html += '<div class="cv-suggest-title">Did you mean:</div>';
    html += `<div class="ing-item cv-suggest-item cv-suggest-item--highlight" data-id="${best.id_ing}" data-name="${escapeHtml(best.name_ing)}">${escapeHtml(best.name_ing)}</div>`;
    html += '<hr class="cv-suggest-divider">';
  }

  if (!suggestions.length && !best) {
    html += '<div class="cv-suggest-empty">No matches. Will add as new ingredient.</div>';
    return html;
  }

  suggestions.forEach((item) => {
    html += `<div class="ing-item cv-suggest-item" data-id="${item.id_ing}" data-name="${escapeHtml(item.name_ing)}">${escapeHtml(item.name_ing)}</div>`;
  });

  return html;
}

function wireSuggestionItems(box, onPick) {
  box.querySelectorAll('.ing-item').forEach((item) => {
    item.addEventListener('click', () => onPick(item));
  });
}

function showSuggestionBox(box, markup) {
  box.innerHTML = markup;
  box.classList.add('is-visible');
}

function hideSuggestionBox(box) {
  if (!box) return;
  box.classList.remove('is-visible');
}

const ingredientAutocompleteTimers = new WeakMap();

function runIngredientAutocomplete(inputEl, hiddenSelector, boxSelector) {
  const scope = inputEl.closest('.ingredient-row, .pantry-ingredient-wrap') || inputEl.parentElement;
  const hiddenId = scope?.querySelector(hiddenSelector);
  const box = scope?.querySelector(boxSelector);
  if (!scope || !hiddenId || !box) return;

  hiddenId.value = '';
  const query = inputEl.value.trim();
  if (query.length < 1) {
    box.innerHTML = '';
    hideSuggestionBox(box);
    return;
  }

  clearTimeout(ingredientAutocompleteTimers.get(inputEl));
  const timer = window.setTimeout(async () => {
    try {
      const res = await fetch(`ingredient_search.php?q=${encodeURIComponent(query)}`);
      const data = await res.json();
      showSuggestionBox(box, buildIngredientSuggestionMarkup(data));
      wireSuggestionItems(box, (item) => {
        inputEl.value = item.getAttribute('data-name') || '';
        hiddenId.value = item.getAttribute('data-id') || '';
        hideSuggestionBox(box);
      });
    } catch {
      hideSuggestionBox(box);
    }
  }, 180);

  ingredientAutocompleteTimers.set(inputEl, timer);
}

function setupCheckboxToggle(checkboxId, targetId, onHide) {
  const checkbox = document.getElementById(checkboxId);
  const target = document.getElementById(targetId);
  if (!checkbox || !target) return;

  function sync() {
    const isVisible = checkbox.checked;
    target.classList.toggle('is-hidden', !isVisible);
    if (!isVisible && typeof onHide === 'function') {
      onHide();
    }
  }

  checkbox.addEventListener('change', sync);
  sync();
}

function closeSuggestionBoxesOutsideClick() {
  document.addEventListener('click', (event) => {
    document.querySelectorAll('.ing-suggest').forEach((box) => {
      const scope = box.closest('.ingredient-row, .pantry-ingredient-wrap');
      if (scope && !scope.contains(event.target)) {
        hideSuggestionBox(box);
      }
    });
  });
}

(function setupNavbar() {
  const burger = document.querySelector('.cv-burger');
  const utilityMenu = document.getElementById('cv-utility-menu');
  const nav = document.getElementById('cv-nav');
  const dropdowns = document.querySelectorAll('.cv-dropdown');
  const mobileSearchToggle = document.querySelector('.cv-mobile-search-toggle');
  const mobileSearchForm = document.getElementById('cv-navbar-search');
  const mobileSearchInput = mobileSearchForm?.querySelector('.cv-search-input');
  const mobileQuery = window.matchMedia('(max-width: 900px)');

  if (!nav) return;

  const isMobile = () => mobileQuery.matches;

  function openDropdown(dropdown) {
    const button = dropdown?.querySelector('.cv-dropbtn');
    dropdown?.classList.add('is-open');
    button?.setAttribute('aria-expanded', 'true');
  }

  function closeDropdown(dropdown) {
    const button = dropdown?.querySelector('.cv-dropbtn');
    dropdown?.classList.remove('is-open');
    button?.setAttribute('aria-expanded', 'false');
  }

  function closeAllDropdowns(except = null) {
    dropdowns.forEach((dropdown) => {
      if (dropdown !== except) {
        closeDropdown(dropdown);
      }
    });
  }

  function closeUtilityMenu() {
    if (!utilityMenu || !burger) return;
    utilityMenu.classList.remove('is-open');
    burger.setAttribute('aria-expanded', 'false');
  }

  function closeMobileSearch() {
    if (!mobileSearchToggle || !mobileSearchForm) return;
    mobileSearchForm.classList.remove('is-open');
    mobileSearchToggle.setAttribute('aria-expanded', 'false');
  }

  function openMobileSearch() {
    if (!mobileSearchToggle || !mobileSearchForm) return;
    mobileSearchForm.classList.add('is-open');
    mobileSearchToggle.setAttribute('aria-expanded', 'true');
    mobileSearchInput?.focus();
  }

  function toggleMobileSearch() {
    if (!mobileSearchToggle || !mobileSearchForm) return;
    const willOpen = !mobileSearchForm.classList.contains('is-open');
    closeAllDropdowns();
    closeUtilityMenu();
    if (willOpen) {
      openMobileSearch();
    } else {
      closeMobileSearch();
    }
  }

  function toggleUtilityMenu() {
    if (!utilityMenu || !burger) return;
    const isOpen = utilityMenu.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (isOpen) {
      closeAllDropdowns();
      closeMobileSearch();
    }
  }

  burger?.addEventListener('click', (event) => {
    event.preventDefault();
    toggleUtilityMenu();
  });

  mobileSearchToggle?.addEventListener('click', (event) => {
    event.preventDefault();
    toggleMobileSearch();
  });

  dropdowns.forEach((dropdown) => {
    const button = dropdown.querySelector('.cv-dropbtn');
    if (!button) return;

    let closeTimer = null;

    function cancelCloseTimer() {
      if (!closeTimer) return;
      clearTimeout(closeTimer);
      closeTimer = null;
    }

    function scheduleClose() {
      cancelCloseTimer();
      closeTimer = window.setTimeout(() => {
        if (!dropdown.matches(':focus-within')) {
          closeDropdown(dropdown);
        }
      }, 120);
    }

    button.addEventListener('click', (event) => {
      event.preventDefault();
      closeUtilityMenu();
      closeMobileSearch();
      const isOpen = dropdown.classList.contains('is-open');
      closeAllDropdowns(dropdown);
      if (isOpen) {
        closeDropdown(dropdown);
      } else {
        openDropdown(dropdown);
      }
    });

    button.addEventListener('keydown', (event) => {
      if (!['ArrowDown', 'Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      closeUtilityMenu();
      closeMobileSearch();
      closeAllDropdowns(dropdown);
      openDropdown(dropdown);
      dropdown.querySelector('.cv-dropmenu a')?.focus();
    });

    dropdown.addEventListener('mouseenter', () => {
      if (isMobile()) return;
      cancelCloseTimer();
      closeUtilityMenu();
      closeMobileSearch();
      closeAllDropdowns(dropdown);
      openDropdown(dropdown);
    });

    dropdown.addEventListener('mouseleave', () => {
      if (isMobile()) return;
      scheduleClose();
    });

    dropdown.addEventListener('focusin', () => {
      if (isMobile()) return;
      closeUtilityMenu();
      closeMobileSearch();
      closeAllDropdowns(dropdown);
      openDropdown(dropdown);
    });

    dropdown.addEventListener('focusout', () => {
      window.setTimeout(() => {
        if (!dropdown.contains(document.activeElement)) {
          closeDropdown(dropdown);
        }
      }, 0);
    });
  });

  document.addEventListener('click', (event) => {
    if (
      event.target.closest('.cv-dropdown') ||
      event.target.closest('.cv-burger-wrap') ||
      event.target.closest('.cv-mobile-search-toggle') ||
      event.target.closest('.cv-search')
    ) {
      return;
    }

    closeAllDropdowns();
    closeUtilityMenu();
    closeMobileSearch();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeAllDropdowns();
    closeUtilityMenu();
    closeMobileSearch();
  });

  utilityMenu?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closeUtilityMenu);
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      if (isMobile()) {
        closeAllDropdowns();
      }
    });
  });

  window.addEventListener('resize', () => {
    closeAllDropdowns();
    closeUtilityMenu();
    if (!isMobile()) {
      closeMobileSearch();
    }
  });
})();

// start index js
(function setupHomepageCarousels() {
  const shells = document.querySelectorAll('.recipe-carousel-shell');
  if (!shells.length) return;

  shells.forEach((shell) => {
    const carousel = shell.querySelector('.recipe-carousel');
    const prevButton = shell.querySelector('[data-direction="prev"]');
    const nextButton = shell.querySelector('[data-direction="next"]');
    const mobileCarouselQuery = window.matchMedia('(max-width: 700px)');
    if (!carousel || !prevButton || !nextButton) return;

    function getStepSize() {
      const firstCard = carousel.querySelector('.recipe-carousel-card');
      if (!firstCard) return carousel.clientWidth * 0.85;
      const styles = window.getComputedStyle(carousel);
      const gap = parseFloat(styles.columnGap || styles.gap || '0');
      return firstCard.getBoundingClientRect().width + gap;
    }

    function updateButtons() {
      const maxScroll = Math.max(0, carousel.scrollWidth - carousel.clientWidth);
      const canScroll = maxScroll > 4;
      prevButton.disabled = !canScroll;
      nextButton.disabled = !canScroll;
    }

    function moveFirstCardToEnd() {
      const firstCard = carousel.querySelector('.recipe-carousel-card');
      if (firstCard) {
        carousel.appendChild(firstCard);
      }
    }

    function moveLastCardToFront() {
      const cards = carousel.querySelectorAll('.recipe-carousel-card');
      const lastCard = cards[cards.length - 1];
      if (lastCard) {
        carousel.insertBefore(lastCard, cards[0]);
      }
    }

    function scrollCarousel(direction) {
      if (!mobileCarouselQuery.matches) {
        if (direction === 'next') {
          moveFirstCardToEnd();
        } else {
          moveLastCardToFront();
        }
        updateButtons();
        return;
      }

      const maxScroll = Math.max(0, carousel.scrollWidth - carousel.clientWidth);
      const atStart = carousel.scrollLeft <= 4;
      const atEnd = carousel.scrollLeft >= maxScroll - 4;
      const stepSize = getStepSize();

      if (direction === 'next' && atEnd) {
        moveFirstCardToEnd();
        carousel.scrollLeft = Math.max(0, carousel.scrollLeft - stepSize);
        return;
      }

      if (direction === 'prev' && atStart) {
        moveLastCardToFront();
        carousel.scrollLeft += stepSize;
        return;
      }

      carousel.scrollBy({
        left: direction === 'next' ? stepSize : -stepSize,
        behavior: 'smooth'
      });
    }

    prevButton.addEventListener('click', () => scrollCarousel('prev'));
    nextButton.addEventListener('click', () => scrollCarousel('next'));
    carousel.addEventListener('scroll', () => {
      if (mobileCarouselQuery.matches) {
        updateButtons();
      }
    }, { passive: true });
    window.addEventListener('resize', updateButtons);
    updateButtons();
  });
})();
// end index js

(function setupSignupValidation() {
  const signupForm = document.querySelector('.login-signup-form');
  if (!signupForm) return;

  signupForm.addEventListener('submit', (event) => {
    const password = signupForm.querySelector("input[name='signup_password']")?.value || '';
    const confirm = signupForm.querySelector("input[name='confirm_signup_password']")?.value || '';
    if (password === confirm) return;

    event.preventDefault();
    alert('Passwords do not match.');
  });
})();

// start create recipe js
window.removeRow = function removeRow(button) {
  button.closest('.ingredient-row, .step-row')?.remove();
};

window.addIngredientRow = function addIngredientRow() {
  const container = document.getElementById('ingredients');
  const template = container?.querySelector('.ingredient-row');
  if (!container || !template) return;

  const clone = template.cloneNode(true);
  clone.querySelectorAll('input, select').forEach((field) => {
    if (field.type === 'hidden') {
      field.value = '';
    } else if (field.tagName.toLowerCase() === 'select') {
      field.selectedIndex = 0;
    } else {
      field.value = '';
    }
  });

  const box = clone.querySelector('.ing-suggest');
  if (box) {
    box.innerHTML = '';
    hideSuggestionBox(box);
  }

  container.appendChild(clone);
};

window.addStepRow = function addStepRow() {
  const container = document.getElementById('steps');
  if (!container) return;

  const div = document.createElement('div');
  div.className = 'create-step-row step-row';
  const stepCount = container.querySelectorAll('.step-row').length + 1;
  div.innerHTML = `
    <textarea name="step[]" rows="2" placeholder="Step ${stepCount}" required></textarea>
    <button type="button" onclick="removeRow(this)">Remove</button>
  `;
  container.appendChild(div);
};

window.ingredientType = function ingredientType(inputEl) {
  runIngredientAutocomplete(inputEl, "input[name='ingredient_id[]']", '.ing-suggest');
};

window.pantryIngredientType = function pantryIngredientType(inputEl) {
  runIngredientAutocomplete(inputEl, "input[name='ingredient_id']", '.ing-suggest');
};

setupCheckboxToggle('use_custom_cuisine', 'create-custom-cuisine-field', () => {
  const customCuisineInput = document.getElementById('recipe_custom_cuisine');
  if (customCuisineInput) {
    customCuisineInput.value = customCuisineInput.defaultValue || '';
  }
});
// end create recipe js

// start recipe js
setupCheckboxToggle('modified_recipe', 'modified-recipe-fields');
// end recipe js

closeSuggestionBoxesOutsideClick();

(function setupFilterDropdowns() {
  const filterDropdowns = document.querySelectorAll('.recipes-filter-dropdown');
  if (!filterDropdowns.length) return;

  filterDropdowns.forEach((dropdown) => {
    dropdown.addEventListener('toggle', () => {
      if (!dropdown.open) return;
      filterDropdowns.forEach((otherDropdown) => {
        if (otherDropdown !== dropdown) {
          otherDropdown.open = false;
        }
      });
    });
  });

  document.addEventListener('click', (event) => {
    if (event.target.closest('.recipes-filter-dropdown')) return;
    filterDropdowns.forEach((dropdown) => {
      dropdown.open = false;
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    filterDropdowns.forEach((dropdown) => {
      dropdown.open = false;
    });
  });
})();

// start shopping list js
(function setupShoppingListUi() {
  if (document.body?.dataset.autoPrint === '1') {
    window.setTimeout(() => {
      window.print();
    }, 250);
  }

  const selectAll = document.getElementById('shopping-select-all');
  if (!selectAll) return;

  const itemCheckboxes = Array.from(document.querySelectorAll("input[name='selected_items[]']"));
  if (!itemCheckboxes.length) return;

  function syncSelectAllState() {
    const checkedCount = itemCheckboxes.filter((checkbox) => checkbox.checked).length;
    selectAll.checked = checkedCount === itemCheckboxes.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < itemCheckboxes.length;
  }

  selectAll.addEventListener('change', () => {
    itemCheckboxes.forEach((checkbox) => {
      checkbox.checked = selectAll.checked;
    });
    selectAll.indeterminate = false;
  });

  itemCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', syncSelectAllState);
  });

  syncSelectAllState();
})();
// end shopping list js
