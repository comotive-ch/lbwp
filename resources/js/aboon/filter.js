/**
 * Main entry point for shop filter feature
 * @author Michael Sebel <michael@comotive.ch>
 */
BhFilter = {
  /**
   * The parsed filter data
   */
  vars: {},
  /**
   * If given, the currently selectable tertiary categories
   */
  tertiaryCategories: {},
  /**
   * The current page
   */
  page: 1,
  /**
   * The page we need to reload to on browser back
   */
  sideLoadUntil: 0,
  /**
   * The ids of the current resultset
   */
  resultIds: [],
  /**
   * Number of products per page
   */
  productsPerPage: 12,
  /**
   * switch some functions to mobile at this width
   */
  mobileSwitchAt: 992,
  /**
   * Set dynamically
   */
  isMobile: false,
  /**
   * Opened the mobile filter
   */
  openedMobileFilter: false,
  /**
   * Tells if the filter does it's first page reload
   */
  firstLoad : true,
  /**
   * Updates filters on clicking checkboxes automatically
   */
  autoUpdate : false,
  /**
   * Has updates that need to be done in auto update mode
   */
  autoUpdateableChanges : false,
  /**
   * Reduced filters to terms that still get a result
   */
  autoReduceFilters : true,
  /**
   * Triggers auto open of mobile filter when needed
   */
  triggerIntermediateMobileFilter : true,
  /**
   * Show all filters or just 8
   */
  showAllfilters: 0,
  /**
   * Filter sort default
   */
  filterSort: '',
  /**
   * timeout id for "intelligent" mouseover
   */
  mmMouseOverId: 0,
  mmMouseLeaveId: 0,
  screenResizeId: 0,
  /**
   * Frontend apis
   */
  filterApi: '/wp-json/custom/products/filter',
  productApi: '/wp-json/custom/products/get',

  /**
   * Unit for the unit change/calculation
   */
  massUnit: {
    'width': {
      'mm': 1,
      'cm': 10,
      'm': 1000,
      'minRange': 10,
      'default': 'mm'
    },
    'filling': {
      'ml': 1,
      'cl': 10,
      'dl': 100,
      'l': 1000,
      'minRange': 10,
      'default': 'ml'
    },
    'weight': {
      'g': 1,
      'kg': 1000,
      'minRange': 50,
      'default': 'g'
    }
  },

  /**
   * Called on dom loading of the page
   */
  initialize: function () {
    var hasSettings = typeof (filterBaseSettings) !== 'undefined';
    if (hasSettings) {
      BhFilter.filterSort = filterBaseSettings.sortDefault;
      BhFilter.autoUpdate = filterBaseSettings.autoUpdate;
      BhFilter.autoReduceFilters = filterBaseSettings.autoReduceFilters;
      // If given set it to previous sort once
      var lastOrder = BhFilter.getLastFilterOrder(true);
      if (lastOrder.length > 0) {
        BhFilter.filterSort = lastOrder;
        BhFilter.setSelectedSort(BhFilter.filterSort);
      }
    }

    BhFilter.setBaseProperties();
    BhFilter.handleMobileFilterDisplay();
    BhFilter.handleMegaMenu();
    BhFilter.handleResultSort();

    if (hasSettings) {
      BhFilter.setDefaultHash();
      BhFilter.setSelectedMainCategory();
      BhFilter.handleAutoLoadMore();
    }
  },

  /**
   * Track the last clicked sku
   */
  trackLastClickedSku : function()
  {
    jQuery('.lbwp-wc__product-listing .lbwp-wc-product__inner a').off('click').on('click', function() {
      try {
        var sku = jQuery(this).closest('.lbwp-wc-product__inner').find('.lbwp-wc-sku').data('sku');
        sessionStorage.setItem('lastClickedSku', sku);
      } catch (e) {}
    });
  },

  /**
   * Set selected sort, when changed programmatically
   * @param sort
   */
  setSelectedSort: function (sort) {
    // Remove current selection
    jQuery('.sort-dropdown li.shop-dropdown__entry').removeClass('shop-dropdown__entry--current');
    jQuery('.sort-dropdown [data-order=' + sort + ']').addClass('shop-dropdown__entry--current')
  },

  /**
   * Gathers a few information about the screen
   */
  setBaseProperties: function () {
    BhFilter.screen = jQuery('body,html');
    BhFilter.lastKnowScreenWidth = BhFilter.screen.width();
    BhFilter.isMobile = BhFilter.lastKnowScreenWidth < BhFilter.mobileSwitchAt;

    // Handle changing of screensize to reload if changed significantly
    jQuery(window).on('resize', function () {
      if (BhFilter.screenResizeId > 0) {
        clearTimeout(BhFilter.screenResizeId);
      }

      BhFilter.screenResizeId = setTimeout(function () {
        var width = BhFilter.screen.width();
        if (BhFilter.lastKnowScreenWidth >= BhFilter.mobileSwitchAt && width < BhFilter.mobileSwitchAt) {
          document.location.reload();
        } else if (BhFilter.lastKnowScreenWidth < BhFilter.mobileSwitchAt && width >= BhFilter.mobileSwitchAt) {
          document.location.reload();
        }
        BhFilter.lastKnowScreenWidth = width;
      }, 500);
    });
  },

  /**
   * Handle the mega menu (just click for the moment)
   */
  handleMegaMenu: function () {
    var currentActive = false;

    jQuery('.shop-subnav__link').on('click', function () {
      var link = jQuery(this);
      var id = link.data('id');
      var alreadyOpen = jQuery('.shop-overlay[data-id=' + id + ']').hasClass('open');
      // If clickable, hovering or already open, let the link be clicked
      if (link.hasClass('clickable') || alreadyOpen) {
        return true;
      }

      // Mark the link as active
      if (currentActive === false) {
        currentActive = jQuery('.shop-subnav__entry.shop-subnav__entry--active');
      }
      jQuery('.shop-subnav__entry--active').removeClass('shop-subnav__entry--active');
      link.closest('.shop-subnav__entry').addClass('shop-subnav__entry--active');
      // And open solely this overlay while closing others
      jQuery('.shop-overlay').removeClass('open');
      jQuery('.shop-overlay[data-id=' + id + ']').addClass('open');

      lbwpReRunFocusPoint();
      return false;
    });

    jQuery('.shop-overlay__wrapper .shop-overlay__header button').on('click', function () {
      jQuery('.shop-subnav__entry').removeClass('shop-subnav__entry--active');
      jQuery('.shop-overlay').removeClass('open');

      if (currentActive.length > 0) {
        currentActive.addClass('shop-subnav__entry--active');
        currentActive = false;
      }
    });

    // Skip mouse events on touch so that open on second touch of link works
    /* TODO
    if (Banholzer.isTouch()) {
      return;
    }
    */

    // Hover handling for desktop, triggering clicks whenever it makes sense
    jQuery('.shop-subnav__entry').on('mouseover', function () {
      var element = jQuery(this);
      if (BhFilter.mmMouseOverId > 0) {
        clearTimeout(BhFilter.mmMouseOverId);
      }
      BhFilter.mmMouseOverId = setTimeout(function () {
        element.find('.shop-subnav__link').trigger('click');
      }, 400);
    });

    // When entering shop-megamenu__wrapper, cancel mouse over on topmenu to prevent accidental selection of another mega menu while moving the mouse to the categories in the currently shown
    jQuery('.shop-megamenu__wrapper').on('mouseover', function () {
      if (BhFilter.mmMouseOverId > 0) {
        clearTimeout(BhFilter.mmMouseOverId);
      }
    });

    // Leaving is handeled if the mouse leaves the whole wrapper for a short time
    jQuery('.shop-subnav__container').on('mouseleave', function () {
      if (BhFilter.mmMouseOverId > 0) {
        clearTimeout(BhFilter.mmMouseOverId);
      }
      if (BhFilter.mmMouseLeaveId > 0) {
        clearTimeout(BhFilter.mmMouseLeaveId);
      }
      BhFilter.mmMouseLeaveId = setTimeout(function () {
        jQuery('.shop-subnav__entry').removeClass('shop-subnav__entry--active');
        jQuery('.shop-overlay').removeClass('open');
      }, 700);
    });

    // Special case, mark full sortiment menu active if needed
    if (window.location.hash.indexOf('f:kundensortiment') >= 0) {
      jQuery('.shop-subnav__full-list').addClass('shop-subnav__entry--active');
    }
  },

  /**
   * Handles sorting of products
   */
  handleResultSort: function () {
    jQuery('.sort-dropdown .shop-dropdown__entry').on('click', function () {
      var element = jQuery(this);
      // Optical rep of selection
      element.parent().find('li').removeClass('shop-dropdown__entry--current');
      element.addClass('shop-dropdown__entry--current');
      // Run filter with that sort, starting at page 1
      BhFilter.isUpdating = true;
      BhFilter.page = 1;
      BhFilter.filterSort = element.data('order');
      BhFilter.runFilter();
      // Remember the last sort with exactly that filter
      BhFilter.rememberLastFilterOrder(BhFilter.filterSort);
    });
  },

  /**
   * Remember the last order the user made with an exact filter key
   * @param filterOrder
   */
  rememberLastFilterOrder: function (filterOrder) {
    try {
      sessionStorage.setItem(window.location.hash + '_filter_order', filterOrder);
    } catch (e) {
    }
  },

  /**
   * Get last filter order and maybe reset
   * @param reset
   * @returns {string}
   */
  getLastFilterOrder: function (reset) {
    var lastOrder = '';
    try {
      lastOrder = sessionStorage.getItem(window.location.hash + '_filter_order');
      lastOrder = (lastOrder === null) ? '' : lastOrder;
      if (reset) {
        sessionStorage.removeItem(window.location.hash + '_filter_order');
      }
    } catch (e) {
    }

    return lastOrder;
  },

  /**
   * Handle filter events after filters have been reloaded
   */
  handleFilterEvents: function (data) {
    BhFilter.handleFilterDropdown();
    BhFilter.handleFilterUpdate();
    BhFilter.handleFilterResets();
    BhFilter.handleFilterInlineSearch();

    BhFilter.updateResultCounter(data);
    BhFilter.updateActiveFiltersCounter();
    BhFilter.updateSelectableProductCounter(data);
    BhFilter.updateFilterSelection(data);
    BhFilter.handleHasSubitemsCheckboxes();
  },

  /**
   * Handle the checkboxes of main objects that have sub items for selection
   */
  handleHasSubitemsCheckboxes: function () {
    // Check all/uncheck all when main item is clicked
    jQuery('.filter-item.has-subcategories input').on('click', function () {
      var input = jQuery(this);
      var id = input.val();
      var checked = input.is(':checked');
      // Remove or set all corresponding subs of this item
      jQuery('[data-sub-of=' + id + '] input').prop('checked', checked);
      BhFilter.updateSubSelectionClasses();
    });

    // Make sure to check main item (just for optics, it doesn't do anything else) when sub is selected
    jQuery('.filter-item.subcategory-item input').on('click', function () {
      var input = jQuery(this);
      var checked = input.is(':checked');
      var mainId = input.closest('.filter-item').data('sub-of');
      // Only run when sub is checked active
      if (checked) {
        jQuery('#has-subs-' + mainId).prop('checked', true);
      } else if (jQuery('[data-sub-of=' + mainId + '] input:checked').length == 0) {
        // When none are selected, actually uncheck the main item
        jQuery('#has-subs-' + mainId).prop('checked', false);
      }
      BhFilter.updateSubSelectionClasses();
    });

    // On re-loading the filter, always select main items, web subs are checked
    jQuery('.filter-item.subcategory-item input[checked=checked]').each(function () {
      var mainId = jQuery(this).closest('.filter-item').data('sub-of');
      jQuery('#has-subs-' + mainId).prop('checked', true);
      BhFilter.updateSubSelectionClasses();
    });
  },

  /**
   * Sets classes for sub-category checkbox to differentiate between all selected or partial selected
   */
  updateSubSelectionClasses: function () {
    jQuery('.filter-item.has-subcategories').each(function () {
      var item = jQuery(this);
      var id = item.find('input').prop('value');
      // Count total of sub items and checked subitems
      var total = jQuery('[data-sub-of=' + id + ']').length;
      var checked = jQuery('[data-sub-of=' + id + '] input:checked').length;
      // Basically remove both classes
      item.removeClass('selected-some').removeClass('selected-all');
      // And now add one of them eventually
      if (total === checked) {
        item.addClass('selected-all');
      } else if (total !== checked && checked > 0) {
        item.addClass('selected-some');
      }
    });
  },

  /**
   * Updates buttons showing how many results will be shown
   */
  updateSelectableProductCounter: function (data) {
    var button = jQuery('.show-results');
    if (button.length === 0) {
      return;
    }

    var text = button.data('template-single');
    if (data.results > 1) {
      text = button.data('template').replace('{x}', data.results);
    }
    // Update in search block eventually
    // TODO Banholzer.addShopResultCount(data.results);
    // Add text to button
    button.text(text);

    // Handle clicking on that button (just hide the entrypoint filter for mobile)
    button.off('click').on('click', function () {
      jQuery('.filter-entrypoint').removeClass('single-filter--open');
    });
  },

  /**
   * Resets the filter back to m/s/f basic filter and removes al p,pp and t
   */
  resetFilterAndHash: function () {
    var m = (typeof (BhFilter.vars.m) != 'undefined') ? BhFilter.vars.m : '';
    var s = (typeof (BhFilter.vars.s) != 'undefined') ? BhFilter.vars.s : '';
    var f = (typeof (BhFilter.vars.f) != 'undefined') ? BhFilter.vars.f : '';
    // Reset hash while omitting all secondary filters (t and p, pp)
    BhFilter.setFilterHash(m, s, '', '', f, '');
    BhFilter.reParseFilterHash();
    // Run search with that, starting with page one again
    BhFilter.page = 1;
    BhFilter.runFilter();
  },

  /**
   * Handle reset of all or single filters
   */
  handleFilterResets: function () {
    // Handle the reset of all filters within mobile filter
    jQuery('.filter-summary__reset').off('click').on('click', function () {
      BhFilter.resetFilterAndHash();
    });

    // Handle the reset of all filter outside filter
    jQuery('.filter-button__reset').off('click').on('click', function () {
      // Set opened to false so filter doesn't get opened after reset
      BhFilter.openedMobileFilter = false;
      BhFilter.resetFilterAndHash();
    });

    // Handle single filter removals once they're implemented
    jQuery('.reset-according-filters').off('click').on('click', function () {
      var label = jQuery(this).closest('.filter-nudge');
      // Get the actual dropdown depending on mobile or not
      if (label.hasClass('is-mobile')) {
        var filterId = label.closest('.mobile-filter').data('filter-id');
        var filter = jQuery('.single-filter__selection[data-filter-id=' + filterId + ']');
      } else {
        var filter = label.closest('.single-filter__selection');
      }

      // Unselect all checkboxes and toggle the filter within
      filter.find('input[type=checkbox]').removeAttr('checked');
      filter.find('.update-filter').trigger('click');

      return false;
    });
  },

  /**
   * Update active filters
   */
  updateActiveFiltersCounter: function () {
    var resetter = jQuery('.filter-summary__reset');
    var counter = jQuery('.filter-summary__number');
    // Make the resetter invisible, show if needed
    resetter.hide();
    // Now count how many filters are selected
    var t = (typeof (BhFilter.vars.t) != 'undefined') ? BhFilter.vars.t.split(',').length : 0;
    var p = (typeof (BhFilter.vars.p) != 'undefined') ? BhFilter.vars.p.split(',').length : 0;
    // Show active counter and resetter if there are filters
    if ((t + p) > 0) {
      resetter.show();
      var content = counter.data('template').replace('{x}', (t + p));
      counter.text(content);
    }
  },

  /**
   * Disable unselectable elements and select preselections from hash
   * @param data
   */
  updateFilterSelection: function (data) {
    // Set checkboxes for t/p filters
    if (typeof (BhFilter.vars.t) != 'undefined') {
      jQuery.each(BhFilter.vars.t.split(','), function (key, value) {
        jQuery('#term-' + value).attr('checked', 'checked');
      });
    }
    if (typeof (BhFilter.vars.p) != 'undefined') {
      jQuery.each(BhFilter.vars.p.split(','), function (key, value) {
        jQuery('#term-' + value).attr('checked', 'checked');
      });
    }

    // Disable everything that is not a selectable term
    if (typeof (data.selectable) == 'object') {
      // Build whitelist of primary property if given to prevent disabling
      var whitelist = [];
      if (typeof (BhFilter.vars.pp) == 'string') {
        whitelist = jQuery.map(BhFilter.vars.pp.split(','), function (val) {
          return parseInt(val);
        });
      }

      // Set actually selectable properties and remove what isn't selctable
      jQuery('.single-filter__prop input[id^=term]').each(function () {
        var checkbox = jQuery(this);
        var id = parseInt(checkbox.val());
        if (jQuery.inArray(id, data.selectable) == -1 && jQuery.inArray(id, whitelist) == -1) {
          //checkbox.parent().parent().remove();
        }
      });
    }

    jQuery('.single-filter__prop').each(function () {
      var filterId = jQuery(this).data('filter-id');
      var checkboxes = jQuery(this).find('input[id^=term]');
      // Show if there are selectable boxes
      if (checkboxes.length > checkboxes.filter('[disabled=disabled]').length) {
        if (BhFilter.isMobile) {
          // On Mobile we make the entrypoints visible, they trigger the actual filters only on click
          jQuery('.mobile-filter[data-filter-id=' + filterId + ']').closest('li').show();
        } else {
          // On Desktop we directly make the filters visible/invisible
          jQuery(this).show();
        }
      }
    });

    // Special case on mobile, we need to make the mobile filter for tertiary visible
    if (BhFilter.isMobile) {
      jQuery('.entrypoint-list__category').show();
    }

    // Set "has selection" class on all dropdowns that have a selection inside
    jQuery('.single-filter__selection').each(function () {
      var dropdown = jQuery(this);
      if (dropdown.find('input[type=checkbox]:checked').length > 0) {
        dropdown.addClass('single-filter--selected');
      }
    });
  },

  /**
   * On scrolling call load more button automatically
   */
  handleAutoLoadMore: function () {
    jQuery(document).on('scroll', function (ev) {
      if (BhFilter.isUpdating) {
        return;
      }
      var threshold = jQuery(document).height() - jQuery(window).height() - 350;
      if (threshold < jQuery(document).scrollTop()) {
        jQuery('.load-more').trigger('click').remove();
      }
    });
  },

  /**
   * Handles inline search in text based filter properties
   */
  handleFilterInlineSearch: function () {
    jQuery('.search-input input').on('keyup', function (ev) {
      var input = jQuery(this);
      var items = input.closest('.single-filter__selection').find('.filter-item');
      var term = input.val().toLowerCase();

      // First, make everything visible that isn't
      items.show();

      // If there is a search term, make invisible what doesn't match
      items.each(function () {
        var item = jQuery(this);
        // Check if not matching, then hide
        if (item.find('.filter-checkbox').text().trim().toLowerCase().indexOf(term) === -1) {
          item.hide();
        }
      });

      // Look for every has-sub element, show it anyway if subs are shown
      var mains = input.closest('.single-filter__selection').find('.has-subcategories');
      mains.each(function () {
        var item = jQuery(this);
        var parent = item.parent();
        var id = item.find('[name*=hs]').val();
        // See if there are visible subs if yes, make sure to show the item
        if (parent.find('[data-sub-of=' + id + ']:visible').length > 0) {
          item.show();
        }
      });

      // Make all subs visible if main element contains the searched term
      jQuery('.has-subcategories').each(function () {
        var element = jQuery(this);
        var id = element.find('input').val();
        // Show all sub elements of it when main element is shown
        if (element.css('display') == 'block' && element.text().toLowerCase().indexOf(term) >= 0) {
          element.parent().find('[data-sub-of=' + id + ']').show();
        }
      })
    });
  },

  /**
   * Override the nudge with a range
   */
  handleFilterNudge: function () {
    var nudges = jQuery('.filter-nudge');

    jQuery.each(nudges, function () {
      var nudge = jQuery(this);
      var filterContent = nudge.closest('.single-filter__inner').find('.filter-content__range');

      if (nudge.hasClass('is-mobile')) {
        var filterId = nudge.closest('.list-entry__inner').attr('data-filter-id');
        filterContent = jQuery('.single-filter[data-filter-id="' + filterId + '"]').find('.filter-content__range');
      }

      if (filterContent.length > 0) {
        var inputs = filterContent.find('.range-text-input-container input');
        var text = inputs.eq(0).val() + ' - ' + inputs.eq(1).val() + inputs.eq(1).parent().attr('data-unit');

        if (text !== undefined) {
          nudge.find('.filter-nudge__inner span').text(text);
          nudge.find('.nudge-counter').remove();
        }
      }
    });
  },

  /**
   * Handle when the filter needs an update and search for products
   */
  handleFilterUpdate: function () {
    jQuery('.update-filter').on('click', function () {
      var button = jQuery(this);
      BhFilter.doFilterUpdate(button, true);
    });
  },

  /**
   * Runs a filter update and eventually closes the current filter
   * @param close
   */
  doFilterUpdate: function (button, close) {
    if (close) {
      // First of all, close the current container
      var ppRebuilt = false;
      var container = button.closest('.single-filter__inner');
      container.find('.single-filter__header').removeClass('single-filter__header--open');
      container.find('.single-filter__content').removeClass('single-filter__content--open');
    }

    // As we do the changes, set the variable back
    BhFilter.autoUpdateableChanges = false;
    // Get all checkboxes for t and p and override in our variable
    var m = (typeof (BhFilter.vars.m) != 'undefined') ? BhFilter.vars.m : '';
    var s = (typeof (BhFilter.vars.s) != 'undefined') ? BhFilter.vars.s : '';
    var f = (typeof (BhFilter.vars.f) != 'undefined') ? BhFilter.vars.f : '';
    var pp = (typeof (BhFilter.vars.pp) != 'undefined') ? BhFilter.vars.pp : '';
    var pElement = null;

    var p = '', t = '';
    // Add all hs (has subs) that are *fully* selected as p
    jQuery('.has-subcategories.selected-all input[name^=hs]:checked').each(function () {
      pElement = jQuery(this);
      switch (pElement.data('type')) {
        case 't':
          t += pElement.val() + ',';
          break;
        case 'p':
          p += pElement.val() + ',';
          break;
      }
    });
    jQuery('input[name^=t]:checked').each(function () {
      t += jQuery(this).val() + ',';
    });
    jQuery('input[name^=p]:checked').each(function () {
      pElement = jQuery(this);
      p += pElement.val() + ',';
    });

    // Set pp whitelist of properties to not be disabled
    if (p.length > 0 && pp.length === 0) {
      ppRebuilt = true;
      // Set every currently selectable term on the pp whitelist
      var checkboxes = pElement.closest('.filter-content__list').find('input');
      checkboxes.each(function () {
        var checkbox = jQuery(this);
        if (checkbox.attr('disabled') != 'disabled') {
          pp += checkbox.val() + ','
        }
      });
    }

    // If they have a length remove the last comma
    if (p.length > 0) p = p.substring(0, p.length - 1);
    if (t.length > 0) t = t.substring(0, t.length - 1);

    // Remove pp if there is no more p or if none of p is in pp anymore
    if (pp.length > 0) {
      // Simple case, no more p means no more pp
      if (p.length == 0) {
        pp = '';
      } else {
        // Harder case, if p has length, check if all p are in pp
        var matches = 0;
        p.split(',').forEach(function (value) {
          if (pp.indexOf(value) >= 0) ++matches;
        });
        if (matches === 0) {
          pp = '';
        }
      }
    }

    // Validate pp if it has been rebuilt
    if (pp.length > 0 && ppRebuilt) pp = pp.substring(0, pp.length - 1);
    // Set new filter hash in window
    jQuery(document).trigger('filter:updatebutton');
    BhFilter.setFilterHash(m, s, t, p, f, pp);
    BhFilter.reParseFilterHash();
    // Run search with that, starting with page one again
    BhFilter.page = 1;
    BhFilter.runFilter();
  },

  /**
   * Open the filter-dropdown
   */
  handleFilterDropdown: function () {
    let filterHeader = jQuery('.single-filter__header');
    filterHeader.on('click', function (event) {
      var target = jQuery(event.target);
      var filter = jQuery(this).closest('.single-filter__wrapper');
      var isOpened = filter.hasClass('single-filter--open');

      // For mobile we need to make sure that the filter gets visible and then is opened
      if (BhFilter.isMobile && !filter.hasClass('filter-entrypoint')) {
        (!isOpened) ? filter.show() : filter.hide();
      }

      /* TODO this would work, but close what the user just opened
      var currentFilter = jQuery('.single-filter--open');
      // In auto mode, if there's an open filter, apply and close it
      if (currentFilter.length === 1 && BhFilter.autoUpdate) {
        BhFilter.doFilterUpdate(currentFilter.find('.update-filter'), false);
      }
      */

      // Close all other filters (also closing an open filter that was clicked for closing)
      jQuery('.single-filter__wrapper').removeClass('single-filter--open');
      // Open selected filter only, when closed initially
      if (!isOpened) {
        filter.addClass('single-filter--open');
      }

      // Reopen the main filter if this was just a "back" not an actual close on mobile
      if (BhFilter.isMobile && isOpened && !target.hasClass('filter-icon__close') && !filter.hasClass('filter-entrypoint')) {
        jQuery('.filter-entrypoint').toggleClass('single-filter--open');
      }
    });

    filterHeader.on('keyup', function (e) {
      if (e.keyCode === 13) {
        jQuery(this).trigger('click');
      }
    });

    // Automatically set the focus on the search (needs to wait until the input is visible)
    jQuery('.single-filter__content').on('transitionend', function (event) {
      if (event.originalEvent.propertyName == 'visibility') {
        var filter = jQuery(this).closest('.single-filter__wrapper');

        if (filter.hasClass('single-filter--open')) {
          filter.find('.search-input input').focus();
        }
      }
    })

    // Close filter on click outside of the filter
    if (!BhFilter.isMobile) {
      jQuery(document).click(function (e) {
        var target = jQuery(e.target);
        // If the filter isn't in the anchester three, then the click is outside the filter
        if (target.closest('.single-filter--open').length === 0) {
          var currentFilter = jQuery('.single-filter--open');
          // In auto mode, if there's an open filter, apply and close it
          if (BhFilter.autoUpdate && BhFilter.autoUpdateableChanges) {
            BhFilter.doFilterUpdate(currentFilter.find('.update-filter'), false);
          }
          currentFilter.removeClass('single-filter--open');
        }
      });
    }

    // Moble filter menu just triggers the full menu in open state
    jQuery('.mobile-filter').off('click').on('click', function () {
      var id = jQuery(this).data('filter-id');
      jQuery('.single-filter__selection[data-filter-id=' + id + '] .single-filter__header').trigger('click');
    });

    // Open entry points
    jQuery('.filter-button__open').off('click').on('click', function () {
      jQuery('.filter-entrypoint').toggleClass('single-filter--open');
      BhFilter.openedMobileFilter = true;
    });
  },

  /**
   * Updates the result counter
   */
  updateResultCounter: function (data) {
    var element = jQuery('.filter__results');
    var template = element.data('template');
    // Replace values into it and set content of element
    template = template.replace('{x}', data.results);
    template = template.replace('{y}', data.total);
    element.text(template);
    element.data('current-results', data.results);
  },

  /**
   * Set the default config hash of the block if none is given
   */
  setDefaultHash: function () {
    if (window.location.hash.length === 0) {
      window.location.hash = filterBaseSettings.preloadHash;
    }

    // No matter if already set or just set, run the filter on that
    if (window.location.hash.length > 0) {
      BhFilter.reParseFilterHash();
      BhFilter.setInitialPage();
      BhFilter.runFilter();
    }
  },

  /**
   * Sets the initial page if we come back from another page and need to reload filter in certain context
   */
  setInitialPage : function()
  {
    try {
      var id = window.location.hash.hashCode();
      var page = parseInt(sessionStorage.getItem('lastknownpage_' + id));
      if (!isNaN(page) && page > 1) {
        BhFilter.page = page;
        BhFilter.sideLoadUntil = (page-1);
        sessionStorage.removeItem('lastknownpage_' + id);
      }
    } catch (e) {}
  },

  /**
   * Calculates the last known page when the users scrolls up
   */
  calcScrollupPage : function()
  {
    setInterval(function() {
      var skipped = 0;
      var relation = jQuery(window).scrollTop();
      // Get the sku that is visible on top of screen
      jQuery('[data-sku]').each(function() {
        var element = jQuery(this);
        var position = element.offset().top - relation;
        // Count products shown
        ++skipped;
        // Break on first positive offset
        if (position > 0) {
          return false;
        }
      });

      // Set the last page if it's lower than the current filter page
      var page = Math.ceil(skipped / BhFilter.productsPerPage);
      var id = window.location.hash.hashCode();
      if (page === 1) {
        sessionStorage.removeItem('lastknownpage_' + id);
      } else if (page < BhFilter.page) {
        sessionStorage.setItem('lastknownpage_' + id, page);
      }
    }, 2000);
  },

  /**
   * Set a new filter hash to be parsed
   */
  setFilterHash: function (m, s, t, p, f, pp) {
    var hash = '';
    if (f.length > 0) hash += 'f:' + f + ';'
    if (m.length > 0) hash += 'm:' + m + ';'
    if (s.length > 0) hash += 's:' + s + ';'
    if (t.length > 0) hash += 't:' + t + ';'
    if (p.length > 0) hash += 'p:' + p + ';'
    if (pp.length > 0) hash += 'pp:' + pp + ';'
    window.location.hash = hash;
  },

  /**
   * Parses current hash into the filter object
   */
  reParseFilterHash: function () {
    var hash = window.location.hash.substring(1);
    var parts = hash.split(';');
    var keyvalue = null;

    // Loop that to sub parse objects in it
    BhFilter.vars = {};
    for (var i = 0; i < parts.length; i++) {
      keyvalue = parts[i].split(':');
      if (keyvalue[0] == 'f') {
        keyvalue[1] = keyvalue[1].replace(/%20/g, ' ', keyvalue[1]);
        keyvalue[1] = decodeURIComponent(keyvalue[1]);
      }
      BhFilter.vars[keyvalue[0]] = keyvalue[1];
    }
  },

  /**
   * Set main category and h1 title of the page
   */
  setSelectedMainCategory: function () {
    var setH1 = false;
    var mainElement = jQuery('.shop-subnav__entry[data-id=' + BhFilter.vars.m + ']');
    mainElement.addClass('shop-subnav__entry--active');
    // Get the name of the subcategory to be in h1
    subCategories = BhFilter.getSecondaryCategories(BhFilter.vars.m);
    jQuery.each(subCategories, function (key, sub) {
      if (sub.id == BhFilter.vars.s) {
        BhFilter.tertiaryCategories = sub.sub;
        jQuery('h1').html(jQuery('[data-subid=' + sub.id + ']').text().trim());
        setH1 = true;
      }
    });

    // Set a fallback and make sure to set class
    if (!setH1 && jQuery('.site-search').length == 0) {
      var h1 = jQuery('h1');
      var parent = h1.parent();
      if (parent.length != 0 && parent.attr('class').length == 0) {
        parent.attr('class', 'grid-row');
      }
      h1.addClass('filter__main-title').html(h1.data('template'));
    }
  },

  /**
   * Get the secondary category objects of a main category
   */
  getSecondaryCategories: function (mainId) {
    var branch = null;
    jQuery.each(filterBaseSettings.categoryTree, function (key, category) {
      if (category.id == mainId) {
        branch = category.sub;
      }
    });

    return branch;
  },

  /**
   * Reset the filter html
   */
  resetFilterHtml: function () {
    jQuery('.lbwp-wc__product-listing').html('');
    jQuery('.lbwp-wc__product-filter').html('');
    jQuery('.filter__results').html('');
    jQuery('.shop-result-count').data('counter', '0').html('');
  },

  /**
   * Runs filter and updates selectable filters and results
   */
  runFilter: function () {
    jQuery('.lbwp-wc__no-results').removeClass('show');
    // Show that we're loading results
    jQuery('.lbwp-wc__product-listing').html('');
    BhFilter.vars.sort = BhFilter.filterSort;
    BhFilter.vars.showall = BhFilter.showAllfilters;
    BhFilter.vars.lang = lbwpGlobal.language;

    jQuery.get(BhFilter.filterApi, BhFilter.vars, function (response) {
      // Parallely load the desired products
      jQuery('.lbwp-wc__product-filter').html(response.html);
      jQuery('.lbwp-wc__filter-breadcrumbs').html(response.breadcrumbs);
      // Skip and redirect if only one product has been found
      if (response.redirect.length > 0) {
        document.location.href = response.redirect;
        // in case it was just a hash change, rerun the filter from the changed hash
        // we need a 500ms delay, so this doesn't create and endless loop
        setTimeout(function() {
          BhFilter.reParseFilterHash();
          BhFilter.runFilter();
        }, 500);
        return;
      }

      BhFilter.handleMobileFilterDisplay();
      BhFilter.handleInlineBreadcrumbs();
      BhFilter.handleMoreFilterLoad(response);
      BhFilter.showIntermediateMobileFilter();
      BhFilter.resultIds = response.ids;
      BhFilter.loadProductPage();
      BhFilter.handleFilterEvents(response);
      BhFilter.setupRangeSlider();
      BhFilter.handleFilterNudge();
      // Handle registering of changes for auto update mode
      if (BhFilter.autoUpdate) {
        BhFilter.handleAutoUpdateChanges();
      }

      // Moves all selected checkbox filters to the top when opening them
      if (filterBaseSettings.selectionsOnTop) {
        BhFilter.moveSelectedFiltersToTop();
      }

      if (response.results === 0) {
        jQuery('.lbwp-wc__loading-skeleton').removeClass('loading');
        jQuery('.lbwp-wc__no-results').addClass('show');
      }
      BhFilter.isUpdating = false;
    });
  },

  /**
   * Remember that there are changes to be auto updated
   */
  handleAutoUpdateChanges : function() {
    jQuery('.filter-item input[type=checkbox]').on('change', function() {
      BhFilter.autoUpdateableChanges = true;
    });
  },

  /**
   * Hides the entrypoint filter menu if there are not filters at all
   */
  handleMobileFilterDisplay: function () {
    if (BhFilter.isMobile) {
      if (jQuery('.filter-entrypoint .entrypoint-list li').length == 0) {
        jQuery('.open-filter__wrapper').hide();
      } else {
        jQuery('.open-filter__wrapper').show();
      }
    }
  },

  /**
   * Moves, if the setting is active, all selected checkbox filters to the top when opening them
   */
  moveSelectedFiltersToTop: function () {
    var filters = jQuery('.single-filter.single-filter--selected');

    jQuery.each(filters, function () {
      var filter = jQuery(this);

      filter.find('input:checked').closest('.filter-item').prependTo(filter.find('.filter-content__list'));
    });
  },

  /**
   * Inline links are just hashes which don't run the filter
   */
  handleInlineBreadcrumbs: function () {
    jQuery('.filter__breacrumb .item a').on('click', function () {
      // Use a timeout, as the click changes the hash AFTER the click actually, so wait a few ms
      setTimeout(function () {
        BhFilter.openedMobileFilter = false;
        BhFilter.page = 1;
        BhFilter.reParseFilterHash();
        BhFilter.runFilter();
      }, 100);
    });
  },

  /**
   * Handles the more filters link, if there are more
   */
  handleMoreFilterLoad: function (response) {
    if (response.morefilters) {
      jQuery('.show-all-filters').on('click', function () {
        BhFilter.showAllfilters = 1;
        BhFilter.page--; // because runFilter counts up, but we need to stay on the same page
        BhFilter.runFilter();
      });
    } else {
      jQuery('.show-less-filters').on('click', function () {
        BhFilter.showAllfilters = 0;
        BhFilter.page--; // because runFilter counts up, but we need to stay on the same page
        BhFilter.runFilter();
      });
    }
  },

  /**
   * Handle ajax add to cart
   */
  handleAjaxAddToCart: function () {
    jQuery('.btn-add-to-cart').on('click', function (ev) {
      ev.preventDefault();
      var button = jQuery(this);
      var url = button.attr('href');
      // Add quantity to the url
      var qty = parseInt(button.closest('.product-footer').find('input[name=quantity]').val());
      if (!isNaN(qty) && qty > 0) {
        url += '&quantity=' + qty;
      }
      button.addClass('adding-to-cart');
      jQuery.post(url, function () {
        jQuery.ajax({
          url: wc_cart_fragments_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'get_notices' ),
          type: 'GET', // might deliver cached data
          timeout: wc_cart_fragments_params.request_timeout,
          success: function( response ) {
            if (response.html.length > 0) {
              jQuery('body').append(response.html);

              // Update cart number
              let qtyInput = button.closest('.lbwp-wc-product').find('input[name="quantity"]');
              let cartNumValue = Number(qtyInput.val());

              // Handle cart stock error
              if (response.html.indexOf('woocommerce-error') <= 0) {
                AboonJS.updateCartIcon(cartNumValue, true);
              }
            }
          },
          complete : function(){
            button.removeClass('adding-to-cart');
            AboonJS.removeAddToCartMessages();
          },
        });
      });
    });
  },

  /**
   * Handles changing of product varians within the filter
   */
  handleChangerDropdowns: function () {
    jQuery('.shop-dropdown__entry').on('click', function () {
      var entry = jQuery(this);
      var product = entry.closest('.lbwp-wc-product');
      var productId = parseInt(entry.data('rel-id'));
      // Return if not a number
      if (isNaN(productId)) {
        return;
      }
      // Set add to cart immediately if customer is fast
      product.find('.btn-add-to-cart').attr('href', '?add-to-cart=' + productId);
      // Load product data
      jQuery.get('/wp-json/custom/products/variation?id=' + productId, function (response) {
        product.find('h3 a, figure a').attr('href', response.url);
        product.find('.product-description h3 a').html(response.title);
        product.find('.product-description p:first').html(response.subtitle);
        product.find('figure img').replaceWith(response.image);
        product.find('.product-price__current').html(response.price);
      });
      // Set correct classes for selection
      product.find('.shop-dropdown__entry')
        .removeClass('shop-dropdown__entry--current')
        .addClass('shop-dropdown__entry--selectable');
      entry.addClass('shop-dropdown__entry--current');
      // Put the name of the entry into the header
      product.find('.shop-dropdown__header').text(entry.text());
    });
  },

  /**
   * Only on mobile, called after filter update, immediately show the mobile filter first step
   */
  showIntermediateMobileFilter: function () {
    console.log(BhFilter.triggerIntermediateMobileFilter);
    if (BhFilter.isMobile && BhFilter.openedMobileFilter && BhFilter.triggerIntermediateMobileFilter) {
      jQuery('.filter-entrypoint').addClass('single-filter--open');
      console.log('hee hee');
    }
  },

  /**
   * Pretty prototypey at the moment
   */
  handleProductEvents: function () {
    // Product variant dropdown, just open close for demoing
    jQuery('.shop-dropdown').off('click').on('click', function () {
      var element = jQuery(this);
      var isOpen = element.hasClass('shop-dropdown--open');
      jQuery('.shop-dropdown').removeClass('shop-dropdown--open');
      if (!isOpen) {
        element.addClass('shop-dropdown--open');
      }
    });

    // Track last click on a sku
    BhFilter.trackLastClickedSku();
  },

  /**
   * Loads the currently desired product page
   */
  loadProductPage: function () {
    // Get an array slice of current page
    var ids = BhFilter.resultIds;
    var offset = ((BhFilter.page - 1) * BhFilter.productsPerPage);
    var endset = (offset + BhFilter.productsPerPage)
    var products = ids.slice(offset, endset);
    // When on first page, flush content of the product listing
    if (BhFilter.page === 1) {
      jQuery('.lbwp-wc__product-listing').html('');
      jQuery('.wp-block-product-filter .load-more').remove();
    }
    // Save last known page for that hash
    try {
      sessionStorage.setItem('lastknownpage_' + window.location.hash.hashCode(), BhFilter.page);
    } catch (e) {}

    // Show that we're loading products
    jQuery('.lbwp-wc__loading-skeleton').addClass('loading');
    // Get actual product results
    jQuery.get(BhFilter.productApi, {products: products, filterVars: BhFilter.vars}, function (response) {
      // Loading is done
      jQuery('.lbwp-wc__loading-skeleton').removeClass('loading');
      // Show products, add them to the list and attach events
      jQuery('.lbwp-wc__product-listing').append(response.html);
      BhFilter.runEventualFocuspoint();
      BhFilter.handleProductEvents();
      BhFilter.handleAjaxAddToCart();
      BhFilter.handleChangerDropdowns();
      BhFilter.eventuallyShowLoadButton(endset, ids.length);
      if (typeof (PU) == 'object') PU.setupInputs();
      if (typeof (SVAR) == 'object') SVAR.setDynamicPriceSwitch();
      // Sideload previous results if needed
      if (BhFilter.sideLoadUntil > 0) {
        BhFilter.sideLoadPages(1, BhFilter.sideLoadUntil);
        BhFilter.sideLoadUntil = 0;
        // Only necessary to provide this listener once when first loading
        if (BhFilter.firstLoad) {
          BhFilter.calcScrollupPage();
        }
      } else if (BhFilter.firstLoad) {
        BhFilter.scrollToMostRecentSku();
      }

      // Trigger update when products has been loaded
      jQuery(document.body).trigger('lbwp-products-loaded');
      // Make sure to do some things only once
      BhFilter.firstLoad = false;
    });
    // Raise page number for next call
    ++BhFilter.page;
  },

  /**
   * Sideloads all products to prepend them in filter before already loaded results
   * Happens on coming back into a filter from a single
   * @param from
   * @param to
   */
  sideLoadPages : function (from, to)
  {
    var ids = BhFilter.resultIds;
    var offset = ((from-1) * BhFilter.productsPerPage);
    var endset = (offset + (to * BhFilter.productsPerPage));
    var products = ids.slice(offset, endset);

    // Get actual product results
    jQuery.get(BhFilter.productApi, {products: products}, function (response) {
      jQuery('.lbwp-wc__product-listing').prepend(response.html);
      BhFilter.handleProductEvents();
      BhFilter.handleAjaxAddToCart();
      BhFilter.handleChangerDropdowns();
      BhFilter.scrollToMostRecentSku();
    });
  },

  /**
   * Scroll back to the most recent sku
   */
  scrollToMostRecentSku : function()
  {
    var elem = jQuery('.lbwp-wc__product-listing [data-sku=' + BhFilter.getLastClickedSku(true) + ']');
    if (elem.length > 0) {
      jQuery([document.documentElement, document.body]).scrollTop(
        elem.closest('.lbwp-wc-product__inner').offset().top - 100
      );
    }
  },

  /**
   * Try getting the last clicked or see sku in the filter
   * @returns {number}
   */
  getLastClickedSku : function(reset)
  {
    var sku = 0;
    // Get the sku of the product that is show first
    try {
      sku = parseInt(sessionStorage.getItem('lastClickedSku'));
      if (reset) {
        sessionStorage.removeItem('lastClickedSku');
      }
    } catch (e) {}

    return sku;
  },

  /**
   * Update focuspoint positions if configured to do so
   */
  runEventualFocuspoint: function () {
    if (typeof (filterBaseSettings.useFocuspoint) == 'boolean' && filterBaseSettings.useFocuspoint) {
      lbwpReRunFocusPoint();
    }
  },

  /**
   * Show the load button if applicable
   */
  eventuallyShowLoadButton: function (endIndex, total) {
    var button = '<a class="load-more" style="display:none">Weitere Produkte laden</a>';
    if (endIndex < total) {
      jQuery('.load-more').remove();
      jQuery('.lbwp-wc__product-listing').after(button);
    }

    // Add event to the button
    jQuery('.wp-block-product-filter .load-more').on('click', function () {
      // Run another product show event and remove the button (it is added, when there are still more results
      BhFilter.loadProductPage();
      jQuery(this).remove();
    });
  },

  /**
   * @param array
   * @returns {number}
   */
  getMin: function (array) {
    return Math.min.apply(Math, array);
  },

  /**
   * @param array
   * @returns {number}
   */
  getMax: function (array) {
    return Math.max.apply(Math, array);
  },

  /**
   * Setup the range slider with the available checkboxes
   */
  setupRangeSlider: function () {
    var getFilters = jQuery('.single-filter[data-type="number"]');
    jQuery.each(getFilters, function () {
      var filter = jQuery(this);
      var unit = filter.attr('data-unit');

      filter.addClass('has-range');
      filter.find('.single-filter__content').prepend('<div class="filter-content__range"><input type="text" class="filter-range-slider"></div>');

      var checkboxes = filter.find('input[type="checkbox"]:not([disabled="disabled"])');
      var values = [];
      var selected = [];
      checkboxes.filter(function (index, elem) {
        var val = elem.getAttribute('data-filter-value');
        values.push(Number(val));

        if (elem.checked) {
          selected.push(checkboxes.index(elem));
        }

        return true;
      });

      var minVal = BhFilter.getMin(values);
      var median = values[Math.round(values.length / 2)];
      var maxVal = BhFilter.getMax(values);
      var currentUnit = BhFilter.massUnit[unit]['default'];

      jQuery.each(BhFilter.massUnit[unit], function (key) {
        if (key === 'minRange') {
          return;
        }

        var item = this;
        if (
          (minVal >= item || median >= item) &&
          maxVal >= item &&
          maxVal - minVal >= BhFilter.massUnit[unit].minRange
        ) {
          currentUnit = key;
        }
      });

      values.forEach(function (part, index) {
        if (BhFilter.massUnit[unit][currentUnit] !== undefined) {
          values[index] = part / BhFilter.massUnit[unit][currentUnit];
        }
      });

      var rangeSlider = filter.find('.filter-range-slider');
      rangeSlider.ionRangeSlider({
        type: 'double',
        grid: false,
        values: values,
        from: selected.length > 0 ? selected[0] : null,
        to: selected.length > 0 ? selected[selected.length - 1] : null,
        postfix: currentUnit,
        onUpdate: function (data) {
          BhFilter.handleRangeSliderChange(data, checkboxes, BhFilter.massUnit[unit][currentUnit]);
        },
        onChange: function (data) {
          BhFilter.handleRangeSliderChange(data, checkboxes, BhFilter.massUnit[unit][currentUnit]);

          var inputs = rangeSlider.parent().find('.range-text-input');
          jQuery.each(inputs, function (i) {
            if (i % 2 === 0) {
              jQuery(this).val(data.from_value);
            } else {
              jQuery(this).val(data.to_value);
            }
          });
        }
      });

      var inputAttr = 'type="number" class="range-text-input" step="1" ' +
        'min="' + BhFilter.getMin(values) + '" max="' + BhFilter.getMax(values) + '"';

      jQuery('<div class="range-text-input-container">' +
        '<div class="range-text-input-inner" data-unit="' + currentUnit + '"><input ' + inputAttr + ' value="' + (selected.length > 0 ? values[selected[0]] : BhFilter.getMin(values)) + '"></div>' +
        '<div class="range-text-input-inner" data-unit="' + currentUnit + '"><input ' + inputAttr + ' value="' + (selected.length > 0 ? values[selected[selected.length - 1]] : BhFilter.getMax(values)) + '"></div>' +
        '</div>').insertAfter(rangeSlider);
    });

    // Add events to the range input fields
    var rangeInputs = jQuery('.range-text-input');
    jQuery.each(rangeInputs, function (i) {
      var input = jQuery(this);

      input.on('input', function () {
        var range = input.parent().parent().siblings('.filter-range-slider').data('ionRangeSlider');
        var inVal = input.val();
        var values = range.options.values;

        if (inVal !== '') {
          var newVal = BhFilter.findeClosestNumber(inVal, values, (i % 2 === 0 ? false : true));

          if (i % 2 === 0) {
            range.update({from: values.indexOf(newVal)});
          } else {
            range.update({to: values.indexOf(newVal)});
          }
        }
      });
    })
  },

  handleRangeSliderChange: function (data, checkboxes, unit) {
    var fromToData = [
      Number(data.from_value) * unit,
      Number(data.to_value) * unit
    ]

    jQuery.each(checkboxes, function () {
      var cb = jQuery(this);
      var cbVal = Number(cb.attr('data-filter-value'));

      cb[0].checked = false;
      if (cbVal >= fromToData[0] && cbVal <= fromToData[1]) {
        cb[0].checked = true;
      }
    });
  },

  /**
   * Find the closest number from an array
   * @param {number} sNum the searched number
   * @param {array} sArray the array to search
   * @param {bool} getBigger if it should get the bigger closest number. Default: false
   * @returns the closest number
   */
  findeClosestNumber: function (sNum, sArray, getBigger) {
    var difArray = sArray.map(function (k) {
      return Math.abs(k - sNum)
    });
    var min = Math.min.apply(Math, difArray);
    var cIndex = difArray.indexOf(min);

    if (getBigger === true) {
      var indexes = difArray.reduce(function (a, b, c) {
        if (b === min) {
          a.push(c);
        }
        return a;
      }, []);

      cIndex = indexes.length > 1 ? indexes[1] : cIndex;
    }

    return sArray[cIndex];
  }
};

// Actually initialize on load
jQuery(function () {
  BhFilter.initialize();
});

String.prototype.hashCode = function() {
  var hash = 0;
  for (var i = 0; i < this.length; i++) {
    var char = this.charCodeAt(i);
    hash = ((hash<<5)-hash)+char;
    hash = hash & hash; // Convert to 32bit integer
  }
  return hash;
}