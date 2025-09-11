
// Whenever an inline block is clicked, try resetting the UI as it needs to be completely rebuilt
var setupIntervalId = setInterval(function() {
  var triggerElements = jQuery('.edit-post-visual-editor, .block-editor-block-breadcrumb');
  if (triggerElements.length === 2) {
    triggerElements.on('click', function() {
      setTimeout(function() {
        lbwp_setupMultiBlockstyles();
      }, 20);
    });

    // Register it on all acf blocks, as they don't bubble up to visual editor or breadcrumb
    jQuery(document).on('mousedown', '[class*=wp-block-acf-]', function() {
      setTimeout(function() {
        lbwp_setupMultiBlockstyles();
      }, 10);
    });
    // Register on blocks in the left sidebar tree view
    jQuery(document).on('mousedown', '.block-editor-list-view-block-select-button__label-wrapper', function() {
      setTimeout(function() {
        lbwp_setupMultiBlockstyles();
      }, 100);
    });
    // Register on the style tabs, where block styles have been hidden in a tab
    jQuery(document).on('mousedown', '.block-editor-block-inspector__tabs button', function() {
      // Get the id and check if it has the tabs-*-styles syntax
      var tabId = jQuery(this).attr('id');
      if (typeof(tabId) == 'string' && tabId.indexOf('tabs-') >= 0 && tabId.indexOf('-styles') >= 0) {
        setTimeout(function() {
          lbwp_setupMultiBlockstyles();
        }, 200);
      }
    });

    // Make the selector left-hand list work have timeouts to wait for react to update DOM
    jQuery('.edit-post-header-toolbar__list-view-toggle').on('click', function() {
      setTimeout(function() {
        jQuery('.edit-post-editor__list-view-panel-content').off('click').on('click', function() {
          setTimeout(function() {
            lbwp_setupMultiBlockstyles();
          }, 10);
        });
      }, 20);
    });

    // When someone clicks on the switch within the toolbar
    jQuery('.block-editor-block-switcher__toggle').on('click', function() {
      setTimeout(function() {
        lbwp_setupMultiBlockstyles();
      }, 50);
    });

    // Register everything only once
    clearInterval(setupIntervalId);
  }
}, 20);

/**
 * Oh YAY, lets interfere with reacts magic a bit
 * @author Michael Sebel <michael@comotive.ch> (the author made this against his will)
 */
function lbwp_setupMultiBlockstyles()
{
  var blockStyles = jQuery('.block-editor-block-styles');

  if (blockStyles.length > 0 && blockStyles.closest('.components-panel__body').hasClass('is-opened')) {
    // Unregister all the events on the items
    var items = jQuery('.block-editor-block-styles__item');
    items.removeClass('is-active');
    // Also hide the "standard item" as it wouldn't do anyting
    items.first().hide();

    var currentClasses = multistyles_getSelectedBlockClasses();
    // Put the actual class of every item as data attribute to make usage easier
    items.each(function() {
      var item = jQuery(this);
      // Get the classes from previously generated array from extend.js
      var styleClass = lbwp_multi_block_styles[item.attr("aria-label").trim()];
      item.attr('data-style-class', styleClass);
      // Also, if this class is already selected, make it "active"
      setTimeout(function() {
        // Check if the styleClass is in currentClasses array
        if (currentClasses.indexOf(styleClass) >= 0) {
          item.addClass('is-active');
        }
      }, 50);
    });

    // Add our own events, also set active classes for all classes in styles input
    items.off('click').on('click', function() {
      var styleElement = jQuery(this);
      var styleClass = styleElement.attr('data-style-class');
      var currentClasses = multistyles_getSelectedBlockClasses();

      // Add styleClass if not existing yet and mark active
      if (currentClasses.indexOf(styleClass) < 0) {
        currentClasses.push(styleClass);
        styleElement.addClass('is-active');
      } else {
        // Remove styleClass if existing already, as it was removed
        styleElement.removeClass('is-active');
        currentClasses = currentClasses.filter(function(value, index, arr) {
          return value !== styleClass;
        });
      }
      // Also, natively save back to block
      multistyles_saveSelectedBlockClasses(currentClasses);
      return false;
    });

    // Make sure to not repeat this always
    blockStyles.find('.block-editor-block-styles__item:last').data('multi-select-override', 1);
  }
}

function multistyles_getSelectedBlockClasses() {
  // Get the selected block IDs
  const selectedBlockIds = wp.data.select('core/block-editor').getSelectedBlockClientIds();
  let blockClasses = [];
  // Iterate over the selected block IDs
  selectedBlockIds.forEach(blockId => {
    // Get the block's attributes
    const attributes = wp.data.select('core/block-editor').getBlockAttributes(blockId);
    // Check if the block has a className attribute
    if (attributes.className) {
      // Add the className to the blockClasses array
      blockClasses = attributes.className.split(' ');
      return true;
    }
  });

  return blockClasses;
}

function multistyles_saveSelectedBlockClasses(newClasses) {
  // Get the selected block IDs
  const selectedBlockIds = wp.data.select('core/block-editor').getSelectedBlockClientIds();
  // Iterate over the selected block IDs
  selectedBlockIds.forEach(blockId => {
    // Get the block's current attributes
    const attributes = wp.data.select('core/block-editor').getBlockAttributes(blockId);
    // Update the className attribute with the new classes
    wp.data.dispatch('core/block-editor').updateBlockAttributes(blockId, {
      className: newClasses.join(' ')
    });
  });
}

var lbwp_multi_block_styles = [];
wp.domReady(() => {
  wp.blocks.getBlockTypes().forEach(function(blockType) {
    wp.data.select('core/blocks').getBlockStyles(blockType.name).forEach(function(styles) {
      lbwp_multi_block_styles[""+styles.label] = 'is-style-' + styles.name;
    })
  });
});
