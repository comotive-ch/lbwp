<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Theme\Feature\FocusPoint;
use LBWP\Util\Strings;

/**
 * Provides a block to build a product/cart assistant
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Assistant extends ACFBase
{
  private static $urlParamatersHandled;

  /**
   * Initialize the watchlist component, which is nice
   */
  public function init()
  {
    add_action('wp', array($this, 'handlePostData'));
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_64c215238ce69',
      'title' => 'Produktassistent',
      'fields' => array(
        array(
          'key' => 'field_64c21523d7b5f',
          'label' => 'Anzeigemodus',
          'name' => 'mode',
          'aria-label' => '',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'question' => 'Fragen anzeigen',
            'product' => 'Produkte anzeigen',
          ),
          'default_value' => '',
          'return_format' => 'value',
          'allow_null' => 0,
          'other_choice' => 0,
          'layout' => 'vertical',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_64c21609edc21',
          'label' => 'Anzeigebedingungen',
          'name' => 'condition',
          'aria-label' => '',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'none' => 'Keine Bedingung, immer anzeigen',
            'question' => 'Anzeige, wenn vorab bestimmten Antworten gegeben wurden',
            'product' => 'Anzeige, wenn vorab bestimmte Produkte gewählt wurden',
          ),
          'default_value' => '',
          'return_format' => 'value',
          'allow_null' => 0,
          'other_choice' => 0,
          'layout' => 'vertical',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_64c2169a7c241',
          'label' => 'Gegebene Antworten',
          'name' => 'question-conditions',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => 'Bei mehreren Antworten wird UND Logik angewendet. Für ODER Logik verwenden Sie mehrere Assistent-Blöcke.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_64c21609edc21',
                'operator' => '==',
                'value' => 'question',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_64c216bc7c242',
              'label' => 'Frage-ID',
              'name' => 'question-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2169a7c241',
            ),
            array(
              'key' => 'field_64c216d87c243',
              'label' => 'Antwort-ID',
              'name' => 'answer-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2169a7c241',
            ),
          ),
        ),
        array(
          'key' => 'field_64c2178c4480c',
          'label' => 'Gewählte Produkte',
          'name' => 'product-conditions',
          'aria-label' => '',
          'type' => 'post_object',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_64c21609edc21',
                'operator' => '==',
                'value' => 'product',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'product',
          ),
          'post_status' => '',
          'taxonomy' => '',
          'return_format' => 'id',
          'multiple' => 1,
          'allow_null' => 0,
          'ui' => 1,
        ),
        array(
          'key' => 'field_64c2183f520e0',
          'label' => 'Zu stellende Fragen',
          'name' => 'questions',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_64c21523d7b5f',
                'operator' => '==',
                'value' => 'question',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_64c21851520e1',
              'label' => 'Frage-ID',
              'name' => 'question-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2183f520e0',
            ),
            array(
              'key' => 'field_64c21978bfc1e',
              'label' => 'Antwort-ID',
              'name' => 'answer-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2183f520e0',
            ),
            array(
              'key' => 'field_64c2187c520e3',
              'label' => 'Titel',
              'name' => 'title',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2183f520e0',
            ),
            array(
              'key' => 'field_64c2189c520e4',
              'label' => 'Beschreibung',
              'name' => 'content',
              'aria-label' => '',
              'type' => 'textarea',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'rows' => '',
              'placeholder' => '',
              'new_lines' => '',
              'parent_repeater' => 'field_64c2183f520e0',
            ),
            array(
              'key' => 'field_64c21871520e2',
              'label' => 'Bild',
              'name' => 'image',
              'aria-label' => '',
              'type' => 'image',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'return_format' => 'id',
              'library' => 'all',
              'min_width' => '',
              'min_height' => '',
              'min_size' => '',
              'max_width' => '',
              'max_height' => '',
              'max_size' => '',
              'mime_types' => '',
              'preview_size' => 'medium',
              'parent_repeater' => 'field_64c2183f520e0',
            ),
            array(
              'key' => 'field_6602748e7a33a',
              'label' => 'Zeile Deaktivieren',
              'name' => 'deactivate',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                1 => 'Deaktivieren',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
            ),
          ),
        ),
        array(
          'key' => 'field_64c2199b72c4d',
          'label' => 'Darzustellende Produkte',
          'name' => 'products',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_64c21523d7b5f',
                'operator' => '==',
                'value' => 'product',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_64c2199b72c4e',
              'label' => 'Produkt',
              'name' => 'product-id',
              'aria-label' => '',
              'type' => 'post_object',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'post_type' => array(
                0 => 'product',
              ),
              'post_status' => '',
              'taxonomy' => '',
              'return_format' => 'id',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 1,
              'parent_repeater' => 'field_64c2199b72c4d',
            ),
            array(
              'key' => 'field_64c2199b72c50',
              'label' => 'Titel',
              'name' => 'title',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64c2199b72c4d',
            ),
            array(
              'key' => 'field_64c2199b72c51',
              'label' => 'Beschreibung',
              'name' => 'content',
              'aria-label' => '',
              'type' => 'textarea',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'rows' => '',
              'placeholder' => '',
              'new_lines' => '',
              'parent_repeater' => 'field_64c2199b72c4d',
            ),
            array(
              'key' => 'field_64c2199b72c52',
              'label' => 'Bild',
              'name' => 'image',
              'aria-label' => '',
              'type' => 'image',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'return_format' => 'id',
              'library' => 'all',
              'min_width' => '',
              'min_height' => '',
              'min_size' => '',
              'max_width' => '',
              'max_height' => '',
              'max_size' => '',
              'mime_types' => '',
              'preview_size' => 'medium',
              'parent_repeater' => 'field_64c2199b72c4d',
            ),
            array(
              'key' => 'field_64f8d187ef13f',
              'label' => 'Einstellungen',
              'name' => 'settings',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'details' => 'Link zum Produkt zeigen'
              ),
              'default_value' => array(),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
              'parent_repeater' => 'field_64c2199b72c4d',
            ),
            array(
              'key' => 'field_6602748e7a33b',
              'label' => 'Zeile Deaktivieren',
              'name' => 'deactivate',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                1 => 'Deaktivieren',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
            ),
          ),
        ),
        array(
          'key' => 'field_64c21a4f58fb7',
          'label' => 'Vorherige Seite',
          'name' => 'prev-page-id',
          'aria-label' => '',
          'type' => 'post_object',
          'instructions' => 'Seite für den "Zurück Button"',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'post',
            1 => 'page',
          ),
          'post_status' => '',
          'taxonomy' => '',
          'return_format' => 'id',
          'multiple' => 0,
          'allow_null' => 1,
          'ui' => 1,
        ),
        array(
          'key' => 'field_64c21a4f58fa6',
          'label' => 'Folgeseite',
          'name' => 'next-page-id',
          'aria-label' => '',
          'type' => 'post_object',
          'instructions' => 'Der letzte Block hat in der Regel den Warenkorb / Kasse als Folgeseite.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'post',
            1 => 'page',
          ),
          'post_status' => '',
          'taxonomy' => '',
          'return_format' => 'id',
          'multiple' => 0,
          'allow_null' => 0,
          'ui' => 1,
        ),
        array(
          'key' => 'field_64c2199b72c55',
          'label' => 'Button Text',
          'name' => 'button-text',
          'aria-label' => '',
          'type' => 'text',
          'instructions' => 'Defaul: Weiter',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_64f9d197ef13f',
          'label' => 'Einstellungen',
          'name' => 'settings',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'not-required' => 'Es muss keine Antwort angegeben werden',
            'empty-cart' => 'Warenkorb leeren',
            'conditional-redirect' => 'Bedingte Weiterleitung aktivieren (und damit die generelle Weiterleitung überschreiben)'
          ),
          'default_value' => array(),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0,
          'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
        ),
        array(
          'key' => 'field_6512b5f00e020',
          'label' => 'Bedingte Weiterleitungen',
          'name' => 'conditional-redirects',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_64f9d197ef13f',
                'operator' => '==',
                'value' => 'conditional-redirect'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_6512b6120e021',
              'label' => 'Frage ID',
              'name' => 'rd-question-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_6512b5f00e020',
            ),
            array(
              'key' => 'field_6512b6460e022',
              'label' => 'Antwort ID',
              'name' => 'rd-answer-id',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_6512b5f00e020',
            ),
            array(
              'key' => 'field_6512b65f0e023',
              'label' => 'Seite',
              'name' => 'page',
              'aria-label' => '',
              'type' => 'page_link',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'post_type' => '',
              'post_status' => '',
              'taxonomy' => '',
              'allow_archives' => 1,
              'multiple' => 0,
              'allow_null' => 0,
              'parent_repeater' => 'field_6512b5f00e020',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'block',
            'operator' => '==',
            'value' => 'acf/aboon-product-assistant',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * @return void
   */
  public function getAssistantBlockHtml($block)
  {
    $this->handleUrlParameters();

    if (!$this->conditionsFullfilled()) {
      echo '';
      return;
    }

    $settings = get_field('settings');
    // Check for empty cart setting
    if (is_array($settings) && in_array('empty-cart', $settings) && WC()->cart !== null) {
      WC()->cart->empty_cart();

      // Also empty session vars
      foreach ($_SESSION as $key => $value) {
        if (Strings::startsWith($key, 'question') || Strings::startsWith($key, 'product-selection')) {
          unset($_SESSION[$key]);
        }
      }
    }

    $html =
      '<section class="wp-block-wrapper wp-block-aboon-assistant s03-default-grid ' . $block['id'] . '">
        <div class="grid-container">
          <div class="grid-row">
            <div class="grid-column">
              <form method="post" action="{REDIRECT_URL}" data-redirect="{REDIRECT_URL}">
                {BLOCK_CONTENT}
              </form>
            </div>
          </div>
        </div>
      </section>';

    $backPage = get_field('prev-page-id');
    $btnHtml = '<div class="assistant-button">
      ' . ($backPage !== null && $backPage !== false ? '<a href="' . get_permalink($backPage) . '" class="btn btn--secondary btn--lg">' . __('Zurück', 'aboon') . '</a>' : '') . '
      <input type="submit" class="btn btn--primary btn--lg" name="aboon-asst_submit" value="' .
      (Strings::isEmpty(get_field('button-text')) || get_field('button-text') === null ? __('Weiter', 'aboon') : get_field('button-text')) .
      '"></div>';
    $contentHtml = '';

    switch (get_field('mode')) {
      case 'question':
        $contentHtml = $this->getQuestionsHtml() . $btnHtml;
        break;

      case 'product':
        $contentHtml = $this->getProductsHtml() . $btnHtml;
        break;
    }

    $html = str_replace(array('{BLOCK_CONTENT}', '{REDIRECT_URL}'), array(
      $contentHtml,
      get_permalink(get_field('next-page-id'))
    ), $html);

    echo $html;
    echo '<script>
      var selector = "' . $block['id'] . '";
      var questions = document.querySelectorAll("." + selector + " input.answer-radio");
      
      questions.forEach((question) => {
        question.addEventListener("click", function(){
          if(question.getAttribute("required") == null){
            let selected = document.querySelector("." + selector + " .answer-radio.selected");
            
            if(selected !== null && selected !== question){
              selected.classList.remove("selected");
            }
            
            if(question.classList.contains("selected")){
              question.classList.remove("selected");
              question.checked = false;
            }else{
              question.classList.add("selected");  
            }
          }
        });
        question.addEventListener("change", function(){
          let form = question.closest("form");
          let redirect = question.getAttribute("data-redirect");
          
          form.setAttribute("action", redirect !== "" ? redirect : form.getAttribute("data-redirect"));
        });
      });
      
      // Force page reload if browser back is used
      if(typeof windowEventSet === "undefined"){
        let windowEventSet = true;
        window.addEventListener("pageshow", function ( event ) {
          const entries = performance.getEntriesByType("navigation");
          entries.forEach((entry) => {
            if(entry.type === "back_forward"){
              location.reload(true);
            }
          });
        });
      }
      
      // Initialy disable the next button until required fields are enabled
      var fields = document.querySelectorAll("." + selector + " .answer-radio");
      var nextBtn = document.querySelector("." + selector + " input[name=\'aboon-asst_submit\']");
      fields.forEach((field) => {
        if(field.getAttribute("required") !== null){
          nextBtn.setAttribute("disabled", true);
          
          // If on load already selected
          if(field.checked){
            nextBtn.removeAttribute("disabled");
          }
          
          // Else detect it on change
          field.addEventListener("change", () => {
            if(field.checked){
              nextBtn.removeAttribute("disabled");
            }
          });
        }
      });

    </script>';
  }

  private function conditionsFullfilled()
  {
    if (is_admin()) {
      return true;
    }
    $show = true;

    switch (get_field('condition')) {
      case 'question':
        $conditions = get_field('question-conditions');
        foreach ($conditions as $condition) {
          if ($_SESSION['question-' . $condition['question-id']] !== $condition['answer-id']) {
            $show = false;
            break;
          }
        }

        break;

      case 'product':
        $products = get_field('product-conditions');
        $show = false;

        foreach ($products as $product) {
          if (WC()->cart !== null) {
            $productCartId = WC()->cart->generate_cart_id($product);
            if (!Strings::isEmpty(WC()->cart->find_product_in_cart($productCartId))) {
              $show = true;
              break;
            }
          }
        }

        break;
    }

    return $show;
  }

  public function handlePostData()
  {
    if (isset($_POST['aboon-asst_submit'])) {
      foreach ($_POST as $id => $answer) {
        if (Strings::startsWith($id, 'question')) {
          $_SESSION[$id] = $answer;
        }
      }

      foreach ($_POST as $id => $productId) {
        if (Strings::startsWith($id, 'product-selection')) {
          $productId = intval($productId);
          $productCartId = WC()->cart->generate_cart_id($productId);

          if (Strings::isEmpty(WC()->cart->find_product_in_cart($productCartId))) {
            WC()->cart->add_to_cart($productId);
          }
        }
      }
    }
  }

  private function getQuestionsHtml()
  {
    $questionHtml = [];
    $settings = get_field('settings');

    foreach (get_field('questions') as $question) {
      if(is_array($question['deactivate']) && in_array(1, $question['deactivate'])){
        continue;
      }

      $redirectLink = '';

      if (is_array($settings) && in_array('conditional-redirect', $settings)) {
        $redirects = get_field('conditional-redirects');

        foreach ($redirects as $redirect) {
          if ($redirect['rd-question-id'] === $question['question-id'] && $redirect['rd-answer-id'] === $question['answer-id']) {
            $redirectLink = $redirect['page'];
            break;
          }
        }
      }

      $questionHtml[$question['question-id']] .= $this->getAnswerHtml(
        'question-' . $question['question-id'],
        $question['answer-id'],
        FocusPoint::getImage($question['image']),
        $question['title'],
        $question['content'],
        '',
        $redirectLink,
        !(is_array($settings) && in_array('not-required', $settings))
      );


    }

    return implode('</div><div class="answer-group">', $questionHtml);
  }

  private function getProductsHtml()
  {
    $productHtml = '';
    $tempId = rand(0, 999);
    $settings = get_field('settings');

    foreach (get_field('products') as $product) {
      if(is_array($product['deactivate']) && in_array(1, $product['deactivate'])){
        continue;
      }

      $wcProduct = $product['product-id'] !== false ? wc_get_product($product['product-id']) : false;
      $productData = $wcProduct !== false ? $wcProduct->get_data() : [];
      $content = Strings::isEmpty($product['content']) && $wcProduct !== false ? $productData['short_description'] : $product['content'];
      $preselect = false;

      // Check if product already in cart and if so, remove it
      if(WC()->cart !== null && $wcProduct !== false){
        $productCartId = WC()->cart->generate_cart_id($wcProduct->get_id());
        if(WC()->cart->find_product_in_cart($productCartId)){
          WC()->cart->remove_cart_item($productCartId);
          $preselect = true;
        }
      }

      if ($wcProduct !== false && isset($product['settings']) && in_array('details', $product['settings'])) {
        $content .= ' <a href="' . get_permalink($product['product-id']) . '" target="_blank">' . __('Details ansehen.', 'lbwp') . '</a>';
      }
      $price = '';
      if ($wcProduct !== false) {
        if ($wcProduct->is_on_sale()) {
          $price = $wcProduct->get_sale_price() . '&nbsp;' . get_woocommerce_currency();
        } else {
          $price = $wcProduct->get_regular_price() . '&nbsp;' . get_woocommerce_currency();
        }
      }

      $productHtml .= $this->getAnswerHtml(
        'product-selection-' . $tempId,
        $product['product-id'],
        FocusPoint::getImage($product['image'] === false && $wcProduct !== false ? $wcProduct->get_image_id() : $product['image']),
        Strings::isEmpty($product['title']) && $wcProduct !== false ? $productData['name'] : $product['title'],
        $content,
        $price,
        '',
        !(is_array($settings) && in_array('not-required', $settings)),
        $preselect
      );
    }

    return $productHtml;
  }

  private function getAnswerHtml($name, $value, $image, $title, $text, $price = '', $redirect = '', $required = true, $preselect = false)
  {
    return '<label class="answer-option">
      <input type="radio" name="' . $name . '" value="' . $value . '" class="answer-radio" data-redirect="' . $redirect . '" ' .
        ($required ? 'required ' : '') .
        ($preselect ? 'checked' : '') .
      '>
      <div class="answer-option__row">
        <div class="answer-option__image">' . $image . '</div>
        <div class="answer-option__text">
          <h3>' . $title . '</h3>
          <p>' . $text . '</p>
        </div>
        <div class="answer-option__price">' . ($price !== '' ? $price : '') . '</div>
      </div>
    </label>';
  }

  /**
   * Handles url GET parameters to set answers and add product to cart on page load
   * @return void
   */
  private function handleUrlParameters(){
    // Rund through the parameters only once
    if(self::$urlParamatersHandled !== true){
      self::$urlParamatersHandled = true;

      foreach($_GET as $key => $data){
        if(Strings::startsWith($key, 'q_')){
          $_SESSION['question-' . str_replace('q_', '', $key)] = $data;
        }else if($key === 'add_product'){
          $products = is_array($data) ? $data : array($data);

          foreach($products as $productId){
            $productId = intval($productId);
            $productCartId = WC()->cart->generate_cart_id($productId);

            if (Strings::isEmpty(WC()->cart->find_product_in_cart($productCartId))) {
              WC()->cart->add_to_cart($productId);
            }
          }
        }
      }
    }
  }

  /**
   * Registers no own blocks
   */
  public function blocks()
  {
    $this->registerBlock(array(
      'name' => 'aboon-product-assistant',
      'icon' => 'products',
      'title' => __('Produkt Assistent', 'lbwp'),
      'preview' => true,
      'description' => __('Hiermit kann über mehrere Seiten ein Produkt-Beratungsassistent gebaut werden.', 'lbwp'),
      'render_callback' => array($this, 'getAssistantBlockHtml'),
      'post_types' => array('post', 'page'),
      'category' => 'theme',
    ));
  }
}