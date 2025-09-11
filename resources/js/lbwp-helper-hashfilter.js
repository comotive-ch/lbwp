/**
 * This is the JS equivalent to LBWP\Helper\HashFilter. It can translate
 * the filter links to a meaningful object that can easily be accessed.
 * @author Michael Sebel <michael@comotive.ch>
 */
var LBWP_Helper_HashFilter = {

	/**
	 * The filters that have been set to the hash
	 */
	filters : [],

	/**
	 * Parses the hash and fills it into the filters
	 */
	readFromHash : function()
	{
		var filterParts, key, values;
		var base = window.location.hash.substring(1);
		var filters = base.split('/');

		if (filters.length > 0 && filters[0].length > 0) {
			for (var index = 0; index < (filters.length - 1); index++) {
				filterParts = filters[index].split(':');
				key = filterParts[0];
				values = filterParts[1].split(',');
				this.filters[key] = values;
			}
		} else {
			// If no hash, then reset the filters too
			this.filters = [];
		}
	},

	/**
	 * Updates the hash according to the current filters data
	 */
	updateHash : function()
	{
		var values;
		var newHash = '';

		for (var key in this.filters) {
			values = this.filters[key];
			newHash += key  + ':' + values.join(',') + '/';
		}

		// And set the hash
		window.location.hash = newHash;
	},

	/**
	 * Remove a specific filter variable
	 * @param key the filter to remove a value from
	 * @param value the key to remove
	 */
	removeFilterValue : function(key, value)
	{
		// Search the variable
		var index = jQuery.inArray(value, this.filters[key]);

		// If the value is the last item, remove the whole filter
		if (index >= 0 && this.filters[key].length == 1) {
			delete this.filters[key];
			this.updateHash();
			return;
		}

		// If not, remove the value alone
		if (index >= 0) {
			this.filters[key].splice(index, 1);
			this.updateHash();
		}
	},

	/**
	 * Add a specific filter variable
	 * @param key the filter to remove a value from
	 * @param value the key to remove
	 */
	addFilterValue : function(key, value)
	{
		if (typeof(this.filters[key]) == 'undefined') {
			this.filters[key] = [];
		}

		// check if already existing, then don't add
		var index = jQuery.inArray(value, this.filters[key]);

		if (index == -1) {
			// Remove value and update the hash
			this.filters[key].push(value);
			this.updateHash();
		}
	},

	/**
	 * Much like "add", it adds if not existing, but overrides completely.
	 * This only works for single, not multi values
	 * @param key the filter to remove a value from
	 * @param value the key to remove
	 */
	setFilterValue : function(key, value)
	{
		this.filters[key] = [];
		this.filters[key].push(value);
		this.updateHash();
	}
};