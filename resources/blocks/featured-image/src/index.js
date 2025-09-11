const { registerBlockType } = wp.blocks;
const { Placeholder } = wp.components;

registerBlockType('lbwp/featured-image', {
  title: 'Beitragsbild',
  icon: 'format-image',
  category: 'common',
  attributes: {},
  edit: props => {
    const { className } = props;
    return (
      <div className={ className }>
        <Placeholder icon="format-image" label="Zeigt automatisch das Beitragsbild an" />
      </div>
    );
  },
  save: props => null,
});