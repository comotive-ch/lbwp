/**
 * Helper script to load API results from backend class providing the search engine
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpSearchApi = {

	/**
	 * Number of results with the last call, to prevent endless loops
	 */
	lastResultCount : 999,
	/**
	 * Currently called api search page
	 */
	currentPage : 0,
	/**
	 * Minimum results to run another auto search junk
	 */
	reRunThreshold : 2,

	/**
	 * Adds an event to our button to start searching, also starts the to load the first
	 * set of results within the api search
	 */
	initialize : function()
	{
		// Click on button to load more results
		jQuery('#getApiResults').click(function() {
			LbwpSearchApi.getResults();
		});

		// Also, on load, run the search for the first page to load
		LbwpSearchApi.getResults();
	},

	/**
	 * Gets the next page of results and maybe calls another if there were to few
	 */
	getResults : function()
	{
		// Prepare the cached get to our internal api
		var data = {
			search : QueryString.q,
			page : ++LbwpSearchApi.currentPage,
			action : 'getApiSearchResults'
		};

		// Get the actual result from API
		jQuery.post('/wp-admin/admin-ajax.php', data, function(response) {
			var container = jQuery('.lbwp-gss-results');
			// If this is the first result, flush the container first
			if (LbwpSearchApi.currentPage == 1) {
				container.html('');
			}

			// Append content to container if there is content, or we have the first page
			if (response.resultCount > 0 || LbwpSearchApi.currentPage == 1) {
				container.append(response.html);
			}

			// Start another search if below threshold
			if (response.resultCount <= LbwpSearchApi.reRunThreshold && response.nativeCount > 0) {
				LbwpSearchApi.getResults();
			}

			// If there are no more results afterwards, hide the button
			if (response.nativeCount == 0) {
				jQuery('#getApiResults').remove();
			}
		});
	}
};

jQuery(function() {
	LbwpSearchApi.initialize();
});


var QueryString = function () {
  // This function is anonymous, is executed immediately and
  // the return value is assigned to QueryString!
  var query_string = {};
  var query = window.location.search.substring(1);
  var vars = query.split("&");
  for (var i=0;i<vars.length;i++) {
    var pair = vars[i].split("=");
        // If first entry with this name
    if (typeof query_string[pair[0]] === "undefined") {
      query_string[pair[0]] = decodeURIComponent(pair[1]);
        // If second entry with this name
    } else if (typeof query_string[pair[0]] === "string") {
      var arr = [ query_string[pair[0]],decodeURIComponent(pair[1]) ];
      query_string[pair[0]] = arr;
        // If third or later entry with this name
    } else {
      query_string[pair[0]].push(decodeURIComponent(pair[1]));
    }
  }
  return query_string;
}();