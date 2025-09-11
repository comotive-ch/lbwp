<?php

namespace LBWP\Module\General\Cms;

use LBWP\Core as LbwpCore;
use LBWP\Module\BaseSingleton;
use LBWP\Util\File;

/**
 * Adds a editor to all textareas in widget page
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class WidgetEditor extends BaseSingleton
{
  /**
   * Only executed in the widgets admin interface
   */
  public function run()
  {
    // Add thickbox assets and load our stuff in admin footer
    add_action('admin_footer', array($this, 'onAdminFooter'));
    add_action('admin_enqueue_scripts', array($this, 'onEnqueueAssets'));
  }

  /**
   * Enqueue all assets needed
   */
  public function onEnqueueAssets()
  {
    // Assets for media upload / inserting images
    wp_enqueue_media();
    // Our own asset
    $url = File::getResourceUri() . '/js/lbwp-widget-editor.js';
    wp_enqueue_script('lbwp-widget-editor', $url, array('jquery'), LbwpCore::REVISION);
  }

  /**
   * Prints needed html/js in admin footer
   */
  public function onAdminFooter()
  {
    // Print our HTML template code
    $this->printThickboxEditor();
    $this->printStyles();
  }

  /**
   * @return string the thickbox output to be opened
   */
  protected function printThickboxEditor()
  {
    echo  '
      <div class="media-modal-backdrop-editor" style="display:none;"></div>
      <div id="widgetEditorContainer">
        <h2>' . __('Widget Inhalt bearbeiten', 'lbwp') . '</h2>
    ';
    wp_editor('', 'widgetEditor');
    echo '
        <div class="buttons">
          <a class="widget-editor-save button-primary">' . __('Übernehmen', 'lbwp') . '</a>
          <a class="widget-editor-close button">' . __('Schliessen', 'lbwp') . '</a>
        </div>
      </div>
    ';
  }

  /**
   * Styles are simple, don't need a file here
   */
  protected function printStyles()
  {
    echo '
      <style type="text/css">
        #widgetEditorContainer {
          /* positioning in the middle */
          position:fixed;
          top: 0;
          right: 0;
          left: 0;
          bottom: -10000px;
          width:800px;
          height:670px;
          margin: auto;
          z-index:10010;
          /* styling of the box */
          border:1px solid #333;
          padding:30px;
          background-color:#fff;
        }
        .media-modal-backdrop-editor {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          min-height: 360px;
          background: #000;
          opacity: .7;
          z-index: 10000;
        }
        #widgetEditorContainer .buttons {
          text-align:right;
          margin-top:20px;
        }
        .edit-with-tinymce {
          cursor:pointer;
        }
        .editor-widget-content {
          width:100%;
          box-sizing: border-box;
          border:1px solid #ccc;
          margin-top:15px;
          padding:10px;
          overflow:auto;
          cursor:pointer;
        }
        .editor-widget-content img {
          max-width:100%;
        }
        .editor-widget-content img.alignleft {
          margin:0px 10px 10px 0px;
        }
        .editor-widget-content img.alighright {
          margin:0px 0px 10px 10px;
        }
      </style>
    ';
  }
} 