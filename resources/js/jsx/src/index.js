const { createHigherOrderComponent } = wp.compose;
const { Fragment } = wp.element;
const { BlockControls } = wp.blockEditor;
const { Toolbar } = wp.components;
const { ToolbarButton } = wp.components;

/**
 * Add the hide block attribute and register the filter
 */
function addHideAttribute(settings, name) {
	if (typeof settings.attributes !== 'undefined') {
		settings.attributes = Object.assign(settings.attributes, {
			hideBlock: {
				type: 'boolean',
				default: false
			}
		});
	}
	return settings;
}

// Add the function to the filter
wp.hooks.addFilter(
	'blocks.registerBlockType',
	'lbwp/hide-block-attribute',
	addHideAttribute
);

/**
 * Toggle the block visibility
 */
const setVisibility = (props) => {
	var setTo = !props.attributes.hideBlock;
	var classAttr = props.attributes.className == undefined ? '' : props.attributes.className;
	var classes = setTo ? classAttr + ' block-is-hidden'  : classAttr.replace(' block-is-hidden', '');

	props.setAttributes({
    hideBlock: setTo,
    className: classes
  });
}

/**
 * Set the toggle-icon in the backend
 */
const hideBlockControl = createHigherOrderComponent( ( BlockEdit ) => {
	console.log('test');
	return ( props ) =>
	{
		return(
			<Fragment>
					<BlockControls>
            <Toolbar label="Sichtbarkeit" data-name="hide-button-container">
							<ToolbarButton { ...{
									onClick: function(){
										setVisibility(props);
									},
									class: 'components-button components-toolbar-button has-icon',
									label: props.attributes.hideBlock ? 'Block anzeigen' : 'Block verstecken',
									icon: props.attributes.hideBlock ? 'hidden' : 'visibility'
								}
							 } />
            </Toolbar>
          </BlockControls>
					<BlockEdit { ...props }/>
			</Fragment>
		);
	};
}, "hideBlockControl" );

// Add the function to the filter
wp.hooks.addFilter(
	'editor.BlockEdit', 
	'lbwp/add-hide-control',
	 hideBlockControl 
);