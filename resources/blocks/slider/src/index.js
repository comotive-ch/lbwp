const { registerBlockType } = wp.blocks;
import {LbwpBlockHelper} from "../../block-helper";

registerBlockType('lbwp/slider', {
  title: 'Slider-Galerie',
  icon: 'format-gallery',
  category: 'common',
  attributes: {
    imageIds: {
      type: 'array',
      default : []
    },
    imageUrls: {
      type: 'array',
      default : []
    },
  },
  edit: props => {
    const onChangeGallerySelection = images => {
      setAttributes({
        imageIds : images.map(image => image.id),
        imageUrls : images.map(function(image) {
          if (typeof(image.sizes.medium) !== 'undefined') {
            return image.sizes.medium.url;
          } else if (typeof(image.sizes.large) !== 'undefined') {
            return image.sizes.large.url;
          }else if (typeof(image.sizes.full) !== 'undefined') {
            return image.sizes.full.url;
          } else {
            return image.sizes.thumbnail.url;
          }
        })
      });
    };

    const { attributes, className, setAttributes } = props;

    return (
      <div className={className}>
        {LbwpBlockHelper.getGalleryUpload(
          attributes.imageIds,
          attributes.imageUrls,
          onChangeGallerySelection,
          'Wählen Sie in der Mediathek die Bilder für Ihre Galerie aus.',
          'Mediathek öffnen',
          'Galerie bearbeiten'
        )}
      </div>
    );
  },
  save: props => null,
});


