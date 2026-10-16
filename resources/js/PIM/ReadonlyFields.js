/**
 * Locks whitelisted PIM product fields in the backend UI.
 * Named inputs are disabled with an own ACF lock key, so conditional logic can't re-enable them
 * and the browser doesn't submit them, which keeps the stored values untouched.
 * A capture phase guard additionally swallows every interaction within locked scopes, which also
 * covers unnamed controls (relationship search, date picker) and content loaded later via ajax.
 */
(function ($, acf, config) {
  'use strict';

  var LOCK_KEY = 'lbwpPimReadonly';
  var SCOPE_SELECTOR = '.acf-field.' + config.lockedClass + ' > .acf-input, .postbox.' + config.lockedClass + ' .inside';
  var GUARDED_EVENTS = ['click', 'dblclick', 'mousedown', 'keydown', 'keypress', 'paste', 'drop', 'dragover', 'dragenter'];

  /**
   * Check if a DOM node lies within a locked scope
   * @param {EventTarget} target the node to check
   * @return {boolean} true if the node is locked
   */
  function isLocked(target) {
    return target instanceof Element && target.closest(SCOPE_SELECTOR) !== null;
  }

  /**
   * Swallow an event that targets a locked scope, before any other handler sees it
   * @param {Event} event the event to check
   */
  function guardEvent(event) {
    if (isLocked(event.target)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }

  /**
   * Move the focus away from a locked element, e.g. when reached by tab, and drop it from the tab order.
   * Catches controls rendered after locking, like quicktags buttons or ajax loaded relationship items.
   * @param {FocusEvent} event the focus event
   */
  function guardFocus(event) {
    if (isLocked(event.target) && typeof event.target.blur === 'function') {
      event.target.setAttribute('tabindex', '-1');
      event.target.blur();
    }
  }

  /**
   * Disable all controls within an element and remove its focusable elements from the tab order
   * @param {jQuery} $el the element to lock
   */
  function lockElement($el) {
    acf.disable($el, LOCK_KEY);
    $el.find('input:not([name]), select:not([name]), textarea:not([name]), button').prop('disabled', true);
    $el.find('a, [tabindex], [contenteditable]').attr('tabindex', '-1').attr('aria-disabled', 'true');
  }

  /**
   * Lock every ACF field marked as readonly by the server
   */
  function lockAcfFields() {
    $('.acf-field.' + config.lockedClass).each(function () {
      lockElement($(this).children('.acf-input'));
    });
  }

  /**
   * Lock the native meta boxes (thumbnail, taxonomies) configured as readonly
   */
  function lockMetaBoxes() {
    $.each(config.metaBoxes || [], function (index, id) {
      var $box = $('#' + id);
      $box.addClass(config.lockedClass);
      lockElement($box.find('.inside'));
    });
  }

  /**
   * Switch TinyMCE editors of locked wysiwyg fields to readonly mode
   * @param {object} editor the TinyMCE editor instance
   * @param {string} id the editor id
   * @param {object} init the TinyMCE init settings
   * @param {object} field the ACF field instance
   */
  function lockEditor(editor, id, init, field) {
    if (field.$el.closest('.' + config.lockedClass).length > 0) {
      editor.setMode('readonly');
    }
  }

  GUARDED_EVENTS.forEach(function (type) {
    document.addEventListener(type, guardEvent, true);
  });
  document.addEventListener('focusin', guardFocus, true);

  acf.addAction('wysiwyg_tinymce_init', lockEditor);
  acf.addAction('ready', function () {
    lockAcfFields();
    lockMetaBoxes();
  });
})(jQuery, acf, lbwpPimReadonly);
