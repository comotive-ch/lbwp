/**
 * Block editor status panel for working copies of published posts (see PostWorkingCopy component)
 */
(() => {
  const config = window.lbwpWorkingCopy;
  const { createElement: el, useState } = wp.element;
  const { Button, Notice } = wp.components;
  const { select, dispatch, subscribe, useSelect } = wp.data;
  const PluginPostStatusInfo = (wp.editor && wp.editor.PluginPostStatusInfo) || wp.editPost.PluginPostStatusInfo;

  /**
   * Resolve once the post and its classic meta boxes (ACF) are saved
   * @returns {Promise<void>}
   */
  const waitUntilSaved = () => new Promise((resolve) => {
    const isSaving = () => select('core/editor').isSavingPost() || select('core/edit-post').isSavingMetaBoxes();
    if (!isSaving()) {
      resolve();
      return;
    }
    const unsubscribe = subscribe(() => {
      if (!isSaving()) {
        unsubscribe();
        resolve();
      }
    });
  });

  /**
   * Save the copy including meta boxes
   * @param {object} edits post attributes to change before saving
   * @returns {Promise<boolean>} true if saving succeeded
   */
  const save = async (edits = {}) => {
    dispatch('core/editor').editPost(edits);
    await dispatch('core/editor').savePost();
    await waitUntilSaved();
    return !select('core/editor').didPostSaveRequestFail();
  };

  /**
   * Render the working copy panel inside the post summary
   * @returns {object} element
   */
  const WorkingCopyPanel = () => {
    const [busy, setBusy] = useState(false);
    const status = useSelect((store) => store('core/editor').getCurrentPostAttribute('status'), []);
    const labels = config.labels;

    const merge = async () => {
      setBusy(true);
      if (await save()) {
        window.location.href = config.mergeUrl;
        return;
      }
      setBusy(false);
    };

    const submit = async () => {
      setBusy(true);
      if (await save({ status: 'pending' })) {
        dispatch('core/notices').createSuccessNotice(labels.submitted, { type: 'snackbar' });
      }
      setBusy(false);
    };

    const discard = () => {
      if (window.confirm(labels.confirmDiscard)) {
        window.location.href = config.discardUrl;
      }
    };

    return el('div', { className: 'lbwp-working-copy-panel', style: { width: '100%' } },
      el('p', null, labels.copyOf + ' ', el('a', { href: config.originalUrl }, config.originalTitle)),
      el('p', null, labels.info),
      status === 'pending' && el(Notice, { status: 'info', isDismissible: false }, labels.pending),
      el('div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '8px' } },
        config.canMerge && el(Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: merge }, labels.merge),
        config.canSubmit && status !== 'pending' && el(Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: submit }, labels.submit),
        config.canDiscard && el(Button, { variant: 'secondary', isDestructive: true, disabled: busy, onClick: discard }, labels.discard)
      )
    );
  };

  wp.plugins.registerPlugin('lbwp-working-copy', {
    render: () => el(PluginPostStatusInfo, null, el(WorkingCopyPanel))
  });
})();
