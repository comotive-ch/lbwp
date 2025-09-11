const { registerBlockType } = wp.blocks;
const { InnerBlocks } = wp.editor;

registerBlockType('lbwp/container', {
  title: 'Container',
  icon: 'admin-page',
  category: 'layout',
  attributes: {},
  edit: props => {
    const { className } = props;
    return (
      <div className={ className }>
        {
          typeof(props.insertBlocksAfter) !== "undefined" &&
          <InnerBlocks/>
        }
      </div>
    );
  },
  save: props => {
    return (
      <div>
        <InnerBlocks.Content />
      </div>
    );
  },
});
