const { Fragment } = wp.element;
const { Button, Placeholder } = wp.components;
const { MediaUpload, MediaUploadCheck } = wp.editor;

/**
 * Main Block Helper Library
 * @author Michael Sebel <michael@comotive.ch>
 */
const LbwpBlockHelper = {

  /**
   * Basic image upload component
   * @param url the image url to be displayed
   * @param onsave a saving callback
   * @param onremove a removing callback
   * @param description the text displayed above the button
   * @param button the text displayed in the button
   * @param change the text when the button can change the image
   * @param remove the text when the image can be removed
   * @returns {*}
   */
  getImageUpload : function(url, onsave, onremove, description, button, change, remove) {
    return (
      <MediaUploadCheck>
        <MediaUpload
          onSelect={onsave}
          render={({open}) => {
            if (url.length > 0) {
              // Elements in state where something is selected
              return (
                <Fragment>
                  <div>
                    <img src={url} />
                  </div>
                  <Button onClick={open} style={{ marginRight : '10px'}} isLarge isDefault>
                    {change}
                  </Button>
                  <Button onClick={onremove} isLink isDestructive>
                    {remove}
                  </Button>
                </Fragment>
              )
            }

            // Elements in initial placeholder state
            return (
              <Fragment>
                <Placeholder icon="format-image" label={description}>
                  <Button onClick={open} isLarge isDefault>
                    {button}
                  </Button>
                </Placeholder>
              </Fragment>
            );
          }}
        />
      </MediaUploadCheck>
    )
  },

  /**
   * Basic image upload component
   * @param ids the image ids
   * @param urls the image urls
   * @param onsave a saving callback
   * @param description the text displayed above the button
   * @param button the text displayed in the button to open the gallery
   * @param change the text displayed in the button to edit the gallery
   * @returns {*}
   */
  getGalleryUpload : function(ids, urls, onsave, description, button, change) {
    // Suggest empty string if empty array (as empty array or '0' trigger a gallery with all images, bug of gutenberg as of 14.10)
    if (ids.length == 0) ids = '';
    return (
      <MediaUploadCheck>
        <MediaUpload
          onSelect={onsave}
          multiple="true"
          gallery="true"
          value={ids}
          render={({open}) => {
            if (ids.length > 0 && urls.length > 0) {
              // Build the array of images
              let images = [];
              urls.forEach(function(url) {
                images.push(<li><img src={url} /></li>);
              });
              // Elements in state where something is selected
              return (
                <Fragment>
                  <ul className="gallery-list">{images}</ul>
                  <Button onClick={open} isLarge isDefault>{change}</Button>
                </Fragment>
              );
            }
            // Elements in initial placeholder state
            return (
              <Fragment>
                <Placeholder icon="format-image" label={description}>
                  <Button onClick={open} isLarge isDefault>
                    {button}
                  </Button>
                </Placeholder>
              </Fragment>
            );
          }}
        />
      </MediaUploadCheck>
    )
  }
};

export {LbwpBlockHelper};