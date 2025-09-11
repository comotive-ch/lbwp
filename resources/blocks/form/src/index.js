const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const { SelectControl } = wp.components;
const { useSelect } = wp.data;

registerBlockType('lbwp/form', {
  title: 'Formular',
  icon: 'editor-table',
  category: 'widgets',
  attributes: {
    formId: {
      type: 'number',
      default: 0
    }
  },
  edit: function (props) {
    let activeFormId = props.attributes.formId;
    let theForms = getForms();

    if (theForms === null) {
      return __('Formulare werden geladen...');
    }

    if (theForms.length === 0) {
      props.setAttributes({formId: parseInt(0)});
      return __('Keine Formulare vorhanden');
    }

    var optionValues = [{label: __('Formular auswählen...'), value: 0}];
    if (theForms.length > 0) {
      for (var i = 0; i < theForms.length; i++) {
        optionValues.push({
          label: theForms[i].title.raw,
          value: theForms[i].id
        });
      }
    }

    return (
      <div>
        <span>{__('Bitte wählen Sie ein Formular aus:', 'lbwp')}</span>
        <SelectControl
          label={__('Formulare', 'lbwp')}
          hideLabelFromVision={true}
          options={optionValues}
          onChange={(activeFormId) => {
            props.setAttributes({formId: parseInt(activeFormId)});
          }}
          value={props.attributes.formId}
        />
      </div>
    );
  },
  save: props => null
});

function getForms() {
  var args = {
    per_page: -1,
    orderby: 'title',
    order: 'asc',
  }


  return useSelect((select) => {
    return select('core').getEntityRecords('postType', 'lbwp-form', args);
  }, []);
}
