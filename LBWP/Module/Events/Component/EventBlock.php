<?php

namespace LBWP\Module\Events\Component;

use LBWP\Theme\Component\ACFBase;

/**
 * Provide and register fields and blocks with ACF
 * Needs to be loaded from the theme core
 * @package LBWP\Module\Events\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class EventBlock extends ACFBase
{
	
  /**
   * Register fieldsets with ACF
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_8a025cf898167',
      'title' => 'Block: Event Auflistung',
      'fields' => array(
        array(
          'key' => 'field_61aa5d08e0151',
          'label' => 'Anzahl angezeigter Events',
          'name' => 'number-events',
          'type' => 'number',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => 'Zahl eingeben, sonst «alle»',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_61aa5d18e0151',
          'label' => 'Events überspringen',
          'name' => 'number-skip-events',
          'type' => 'number',
          'instructions' => 'Wenn z.B. ein Teaser den nächsten Event schon anzeigt',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_66d08ba6116c5',
          'label' => 'Kategorie',
          'name' => 'event-category',
          'aria-label' => '',
          'type' => 'taxonomy',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'taxonomy' => 'lbwp-event-category',
          'add_term' => 0,
          'save_terms' => 0,
          'load_terms' => 0,
          'return_format' => 'object',
          'field_type' => 'multi_select',
          'allow_null' => 1,
          'bidirectional' => 0,
          'multiple' => 0,
          'bidirectional_target' => array(
          ),
        ),
        array(
          'key' => 'field_52aa5d18e0151',
          'label' => 'Button-Beschriftung',
          'name' => 'cta-text',
          'type' => 'text',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => 'Details & Tickets',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_64a68b616c32c',
          'label' => 'Button-Darstellung',
          'name' => 'button-style',
          'aria-label' => '',
          'type' => 'select',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'btn--primary' => 'Primärfarbe (default)',
            'btn--primary btn--outline' => 'Primärfarbe Outline',
            'btn--text' => 'Textlink',
            'none' => 'Keinen Button anzeigen',
          ),
          'default_value' => false,
          'return_format' => 'value',
          'multiple' => 0,
          'allow_null' => 0,
          'ui' => 0,
          'ajax' => 0,
          'placeholder' => '',
        ),
        array(
          'key' => 'field_672df519a71fb',
          'label' => 'Header',
          'name' => 'eventblock-header',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_672df598a71fc',
              'label' => 'Titel',
              'name' => 'eventblock-title',
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
              'allow_in_bindings' => 0,
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_672df5a1a71fd',
              'label' => 'Einstellungen',
              'name' => 'eventblock-settings',
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
                'link-event-archive' => 'Veranstaltungs-Seite verlinken',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'allow_in_bindings' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
            ),
            array(
              'key' => 'field_67323f4f21b7c',
              'label' => 'Link',
              'name' => 'eventblock-link',
              'aria-label' => '',
              'type' => 'link',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_672df5a1a71fd',
                    'operator' => '==',
                    'value' => 'link-event-archive',
                  ),
                ),
              ),
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
              'allow_in_bindings' => 0,
            ),
            array(
              'key' => 'field_672df616a71fe',
              'label' => 'Link Beschriftung',
              'name' => 'eventblock-link-text',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_672df5a1a71fd',
                    'operator' => '==',
                    'value' => 'link-event-archive',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'allow_in_bindings' => 0,
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
            ),
          ),
        ),
        array(
          'key' => 'field_672df67564dab',
          'label' => 'Darstellungsoptionen',
          'name' => 'eventblock-display-settings',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_672df68d64dac',
              'label' => 'Darstellung',
              'name' => 'display',
              'aria-label' => '',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'classic' => 'Classic',
                'horizontal' => 'Horizontal',
                'compact' => 'Kompakt',
              ),
              'default_value' => 'classic',
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'allow_in_bindings' => 0,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_672df87064dad',
              'label' => 'Anzahl Spalten',
              'name' => 'num-columns',
              'aria-label' => '',
              'type' => 'radio',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_672df68d64dac',
                    'operator' => '==',
                    'value' => 'compact',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                1 => '1 Spalte',
                2 => '2 Spalten',
                3 => '3 Spalten',
              ),
              'default_value' => 1,
              'return_format' => 'value',
              'allow_null' => 0,
              'other_choice' => 0,
              'allow_in_bindings' => 0,
              'layout' => 'vertical',
              'save_other_choice' => 0,
            ),
            array(
              'key' => 'field_672df94ac168d',
              'label' => 'Inhalt nebst dem Titel',
              'name' => 'subtitle',
              'aria-label' => '',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'excerpt' => 'Textauszug (default)',
                'event-location' => 'Event-Location',
                'event-location-place' => 'Event-Location + Ort',
                'ticket-price' => 'Ticketpreis ab',
                'none' => 'Nichts',
              ),
              'default_value' => 'excerpt',
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'allow_in_bindings' => 0,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_672dfef9d2d9e',
              'label' => 'Datum',
              'name' => 'date',
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
                'order-by-month' => 'Events nach Monaten unterteilen',
                'actual-year' => 'Nur aktuelles Jahr anzeigen',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'allow_in_bindings' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'block',
            'operator' => '==',
            'value' => 'acf/lbwp-event-listing',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }

  /**
   * Register blocks with ACF
   */
  public function blocks()
  {
    $this->registerBlock(array(
      'name' => 'lbwp-event-listing',
      'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="192" height="192" fill="#000000" viewBox="0 0 256 256"><rect width="256" height="256" fill="none"></rect><rect x="40" y="40" width="176" height="176" rx="8" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></rect><line x1="176" y1="24" x2="176" y2="56" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></line><line x1="80" y1="24" x2="80" y2="56" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></line><line x1="40" y1="88" x2="216" y2="88" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></line><path d="M92,128h28l-16,20a16,16,0,1,1-11.3,27.3" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></path><polyline points="144 140 160 128 160 180" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></polyline></svg>',
      'title' => __('Auflistung nächster Events', 'lbwp'),
      'preview' => true,
      'mode' => 'preview',
      'description' => __('Eine bestimmte Anzahl künftiger Events anzeigen.', 'lbwp'),
      'render_template' => 'views/blocks/lbwp/event-listing.php',
      'category' => 'theme',
    ));
  }
}