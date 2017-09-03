/**
 * Tracks a local history of the user with a max of items
 * This can be utilized for more intelligent frontend "back" functions
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpLocalHistory = {

	/**
	 * Default setting for maximum number of entries in the history
	 */
	maxEntries : 15,
	/**
	 * Indicates if there is local storage or not
	 */
	supportsLocalStorage : false,

	/**
	 * Starts the library and tracks the next history point
	 */
	initialize : function()
	{
		// Find out if local storage is available
		try {
			LbwpLocalHistory.supportsLocalStorage = 'localStorage' in window && window['localStorage'] !== null;
		} catch (e) {
			LbwpLocalHistory.supportsLocalStorage = false;
		}

		// Only if local storage is supported, track the history now
		if (LbwpLocalHistory.supportsLocalStorage) {
			LbwpLocalHistory.trackHistory();
		}
	},

	/**
	 * Track the history with our current link
	 */
	trackHistory : function()
	{
		var history = LbwpLocalHistory.getNativeHistoryObject();
		var isNewPage = true;

		// Check if the current page was already the last one
		if (history.length > 0) {
			isNewPage = (history[0] != document.location.href + document.location.hash);
		}

		// Add the current location at the beginning of our array
		if (isNewPage) {
			history.unshift(document.location.href + document.location.hash);
		}

		// Maybe pop the oldest one out, if length is reached
		if (history.length > LbwpLocalHistory.maxEntries) {
			history.pop();
		}

		// Save back to local storage
		localStorage.setItem('lbwpLocalHistory', history.join('$$'));
	},

	/**
	 * The native, validated history object, surely an array
	 */
	getNativeHistoryObject : function()
	{
		var history = localStorage.getItem('lbwpLocalHistory');

		// Create an array, if not one
		if (history == null) {
			history = [];
		} else {
			history = history.split('$$');
		}

		return history;
	},

	/**
	 * Get the current users link history
	 */
	getHistory : function()
	{
		if (LbwpLocalHistory.supportsLocalStorage) {
			// Return the history, but omit the current link by shifting it out
			var history = LbwpLocalHistory.getNativeHistoryObject();
			history.shift();
			return history;
		} else {
			// Fallback, use the last known link as the only history point
			var history = [];
			history.push(document.referrer);
			return history;
		}
	}
};

jQuery(function() {
	LbwpLocalHistory.initialize();
});