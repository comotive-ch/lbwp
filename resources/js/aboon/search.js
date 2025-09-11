/**
 * Main entry point for javascript frontend featreus
 * @author Michael Sebel <michael@comotive.ch>
 */
AboonSearch = {

	/**
	 * Mobile breakpoint pixel
	 */
	mobileBreackpoint: 576,
	/**
	 * Search key up wait to trigger xhr only when stopped typing
	 */
	searchKeyUpWait : 0,
	/**
	 * Tells if we're on the search site
	 */
	isSearchPage : false,

	/**
	 * Called on dom loading of the page
	 */
	initialize : function()
	{
		AboonSearch.handleSearchBlock();
		AboonSearch.handleSiteSearch();
		AboonSearch.handleSearchAutocomplete();
	},

	/**
	 * Handles the search block results
	 */
	handleSearchBlock : function()
	{
		if (jQuery('.wp-block-search-results').length == 0) {
			return;
		}

		AboonSearch.isSearchPage = true;
		var searchTerm = window.location.hash.substring(3);
		if (searchTerm.length === 0) return;
		// Check for semicolon, and cut there
		if (searchTerm.indexOf(';') >= 0) {
			searchTerm = searchTerm.substring(0, searchTerm.indexOf(';'));
		}
		// Make sure to replace out some %XX Entities
		searchTerm = window.decodeURIComponent(searchTerm).replace(/[\u00A0-\u9999<>\&]/g, i => '&#'+i.charCodeAt(0)+';');
		// Keep html entities intact as native characters we can use in the api call
		var textarea = document.createElement('textarea');
		textarea.innerHTML = searchTerm;
		searchTerm = textarea.value;
		console.log(searchTerm);

		// Set the search term into visual html
		var visualTerm = jQuery('.searched-term');
		var value = visualTerm.data('template').replace('{term}', searchTerm);
		visualTerm.html(value);

		// Load categories from search term
		jQuery.get('/wp-json/custom/search/categories', { term : searchTerm }, function(response) {
			if (response.results > 0) {
				jQuery('.search-categories ul').html(response.html);
				var countElement = jQuery('.found-categories');
				countElement.text(countElement.data('template').replace('{x}', response.results));

				var searchMenu = jQuery('.sidescroll-list__wrapper');
				var searchSubMenu = searchMenu.find('.sidescroll-list__inner');
				if(searchSubMenu[0].scrollWidth > searchSubMenu[0].clientWidth){
					searchMenu.addClass('is-scrollable left-end');
					searchMenu.append('<div class="prev"></div><div class="next"></div>');
				}
				
			} else {
				jQuery('.categories__results-wrapper').remove();
			}
		});
	},

	/**
	 * Handles typing of a search, handling type stop
	 */
	handleSearchAutocomplete : function()
	{
		jQuery('.sc-search input[type=text], .sc-search input[type=search]').on('keyup', function(event) {
			// Test if enter was pressed, if yes, show complete search results
			if (event.which === 13) {
				AboonSearch.showFullSearch();
				jQuery(this).blur();
			}

			if (AboonSearch.searchKeyUpWait > 0) {
				clearTimeout(AboonSearch.searchKeyUpWait);
			}

			AboonSearch.searchKeyUpWait = setTimeout(function() {
				AboonSearch.runSearchAutocomplete();
			}, 300);
		});

		// On clicking the icon, also run full search
		jQuery('.sc-search button[type=submit]').on('click', function() {
			AboonSearch.showFullSearch();
		});
	},

	/**
	 * Runs actual search autocomplete on the server
	 */
	runSearchAutocomplete : function()
	{
		var term = jQuery('.sc-search input').val();
		if (term.length >= 2) {
			jQuery.get('/wp-json/custom/search/autocomplete', {'term': term, 'lang': lbwpGlobal.language} , function (response) {
				if (response.results > 0) {
					jQuery('.sc__suggestions').html(response.result).addClass('open');
					AboonSearch.trackAutocompleteWordClicks();
					AboonSearch.handleSameSiteHashLinks();
					AboonSearch.handleSearchClose();
					lbwpReRunFocusPoint();
				} else {
					// When no results, eventually remove open class if there was html before
					jQuery('.sc__suggestions').html('').removeClass('open');
				}
			});
		} else {
			jQuery('.sc__suggestions').html('').removeClass('open');
		}
	},

	/**
	 * Handles closing of search
	 */
	handleSearchClose : function()
	{
		jQuery('.suggestions__closer').on('click', function() {
			jQuery('.sc__suggestions').html('').removeClass('open');
		})
	},

	/**
	 * Makes sure page is reloaded if autocomplete links are clicked on the same site handling the hashes
	 */
	handleSameSiteHashLinks : function()
	{
		jQuery('.search-form__suggestion a').on('click', function() {
			var url = jQuery(this).attr('href');
			url = url.substring(0, url.indexOf('#'));
			// If same, force reload
			if (url == document.location.origin + document.location.pathname) {
				setTimeout(function() {
					document.location.reload();
				}, 100);
			}
		});
	},

	/**
	 * Tracks clicks on autocomplete suggestions
	 */
	trackAutocompleteWordClicks : function()
	{
		jQuery('.search-form__terms a').on('click', function() {
			var suggestion = jQuery(this).text().trim();
			jQuery.post('/wp-json/custom/search/tracktermclick', { term : suggestion });
		});
	},

	/**
	 * Redirect to the full search results page
	 */
	showFullSearch : function()
	{
		// Simply go to search page if not already on search
		var input = jQuery('.sc-search input');
		var term = input.val();
		var url = input.data('url');
		// Have a fallback if nothing is defined
		if (typeof(url) == 'undefined' || url.length === 0) {
			url = '/suchergebnisse/';
		}
		document.location.href = url + '#f:' + term;

		// If we're already on that page, it doesn't reload - do it manually
		if (AboonSearch.isSearchPage) {
			AboonSearch.hideAutocomplete();
			AboonSearch.handleSearchBlock();
			BhFilter.resetFilterHtml();
			BhFilter.reParseFilterHash();
			// Run search with that, starting with page one again
			BhFilter.page = 1;
			BhFilter.runFilter();
		}
	},

	/**
	 * On run search, remove text from search field
	 */
	hideAutocomplete : function()
	{
		jQuery('.sc-search input').val('');
		jQuery('.sc__suggestions').html('');
	},

	/**
	 * Handle the site search possibilities
	 */
	handleSiteSearch : function()
	{
		// Run website search on click of tab
		jQuery('.run-website-search').on('click', function() {
			var url = jQuery(this).attr('href');
			url += '?q=' + window.location.hash.substring(3);
			document.location.href = url;
			return false;
		});

		// If after 6 seconds there are no shop results, force website search
		setTimeout(function() {
			var results = parseInt(jQuery('.filter__results').data('current-results'));
			if (isNaN(results) || results === 0) {
				jQuery('.run-website-search').trigger('click');
			}
		}, 6000);
	},

	/**
	 * Checks if it's a touch device
	 */
	isTouch : function() {
		return (('ontouchstart' in window) ||
			(navigator.maxTouchPoints > 0) ||
			(navigator.msMaxTouchPoints > 0));
	},
};

// Actually initialize on load
jQuery(function() {
	AboonSearch.initialize();
});