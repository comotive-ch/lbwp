/*
 * Add ACF content (rendered post content) to the yoast content
 * Source: https://developer.yoast.com/customization/yoast-seo/adding-custom-data-analysis/
 */

class AnalyseRenderedContent {
  constructor() {
    // Ensure YoastSEO.js is present and can access the necessary features.
    if ( typeof YoastSEO === "undefined" || typeof YoastSEO.analysis === "undefined" || typeof YoastSEO.analysis.worker === "undefined" ) {
      return;
    }
    YoastSEO.app.registerPlugin( "AnalyseRenderedContent", { status: "ready" } );

    this.registerModifications();
  }

  /**
   * Registers the addContent modification.
   *
   * @returns {void}
   */
  registerModifications() {
    const callback = this.addContent.bind( this );

    // Ensure that the additional data is being seen as a modification to the content.
    YoastSEO.app.registerModification( "content", callback, "AnalyseRenderedContent", 10 );
  }

  /**
   * Adds to the content to be analyzed by the analyzer.
   * Replace the content with the rendered content
   *
   * @param {string} data The current data string.
   * @returns {string} The data string parameter with the added content.
   */
  addContent(data) {
    if(typeof lbwpYoastData.content === 'string'){
      data = lbwpYoastData.content;
    }
    return data;
  }
}

/**
 * Fetch content form rest api
 * @returns {Promise<void>}
 */
function modifyYoastContent() {
  // Don't do things if wordpress api not available
  if(typeof wp.api.models !== 'object'){
    console.warn('Yoast override: WP API unavailable')
    return false;
  }

  switch(lbwpYoastData.post_type){
    case 'post':
      let post = new wp.api.models.Post( { id: lbwpYoastData.post_id } );
      post.fetch().done((response) => {
        lbwpYoastData.content = response.content.rendered;
        new AnalyseRenderedContent();
      });
      break;

    case 'page':
      let page = new wp.api.models.Page( { id: lbwpYoastData.post_id } );
      page.fetch().done((response) => {
        lbwpYoastData.content = response.content.rendered;
        new AnalyseRenderedContent();
      });

      break;

    default:
      console.warn(`Yoast override: No API available for post type ${lbwpYoastData.post_type}`);
  }
}

/**
 * Adds eventlistener to load the plugin.
 */
if ( typeof YoastSEO !== "undefined" && typeof YoastSEO.app !== "undefined" ) {
  modifyYoastContent()
} else {
  jQuery( window ).on(
    "YoastSEO:ready",
    function() {
      modifyYoastContent()
    }
  );
}