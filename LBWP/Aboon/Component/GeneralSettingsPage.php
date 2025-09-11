<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;

/**
 * Provide and register fields and blocks with ACF
 * @package LbwpSubscriptions\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class GeneralSettingsPage extends ACFBase
{
  /**
   * Make sure to call the main functions later than all other acf field componentes
   */
  public function setup()
  {
    add_action('acf/init', array($this, 'acfInit'), 20);
    add_action('acf/init', array($this, 'fields'), 20);
    add_action('acf/init', array($this, 'blocks'), 20);
  }

  /**
   * Add settings pages and other init stuff
   */
  public function acfInit()
  {
    if (is_admin()) {
      $this->addSettingsPages();
    }
  }

  /**
   * Do some features, that come with general settings
   */
  public function init()
  {
    if (!is_admin()) {
      add_action('wp', array($this, 'handleShopMainPage'));
    }
  }

  /**
   * Handles redirection to home, when the shop page is called
   * This can be configured in the future (there will be robots)
   */
  public function handleShopMainPage()
  {
    if (is_shop()) {
      $pageId = intval(get_option('options_main-shop-redirect'));
      if ($pageId > 0) {
        $redirectUrl = get_permalink($pageId);
      } else {
        $redirectUrl = get_bloginfo('url');
      }

      header('Location: ' . $redirectUrl, null, 302);
      exit;
    }
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}

  /**
   * Register fieldsets with ACF
   */
  public function fields()
  {
    do_action('aboon_general_settings_page');
    // Config for main shop site
    $this->addMainShopConfig();
  }

  /**
   * Config for main is_shop redirect
   */
  protected function addMainShopConfig()
  {
    acf_add_local_field_group(array(
      'key' => 'group_607fe7577c046',
      'title' => 'Allgemeine Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_607fe76167e70',
          'label' => 'Wichtigste Seite in deinem Shop',
          'name' => 'main-shop-redirect',
          'type' => 'page_link',
          'instructions' => 'Manchmal ist die in WooCommerce konfigurierte Standard Shop-Seite nicht die, auf der deine besucher primär landen sollen. Hier kannst du definieren, welches die wichtigste Shop Seite ist, damit Besucher dorthin weitergeleitet werden, wenn sie die Standard Shop-Seite finden.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'page',
          ),
          'taxonomy' => '',
          'allow_null' => 0,
          'allow_archives' => 0,
          'multiple' => 0,
        ),
        array(
          'key' => 'field_5fcf1908c3117',
          'label' => 'Verhalten bei offenen Verlängerungsbestellungen',
          'name' => 'sumup-debts',
          'type' => 'checkbox',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Offene Bestellungen im konfigurierten Zyklus aufaddieren',
          ),
          'instructions' => 'Wenn offene Abo-Verlängerungen nicht bezahlt werden, wird standardmässig die Dienstleistung eingestellt und die Rechnung nicht um eine weitere Positionen erweitert. Bei Ausleih-Produkten zum Beispiel kann das problematisch sein. Mit diesem Feature wird der jeweils fällige Betrag pro Zyklus (Monat, Jahr) aufaddiert solange die Rechnung nicht bezahlt wurde.',
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5fdd1908c3117',
          'label' => 'Abobestellungen abschliessen',
          'name' => 'auto-complete-subs',
          'type' => 'checkbox',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            2 => 'Erstmalige Bestellungen von Abonnementen sollen sofort auf abgeschlossen gestellt werden',
            1 => 'Wiederkehrende Bestellungen von Abonnementen sollen sofort auf abgeschlossen gestellt werden',
          ),
          'instructions' => '',
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_519faadefb57c',
          'label' => 'Produktvarianten',
          'name' => 'simple-variation-active',
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
            1 => 'Vereinfachte Produktvarianten für Produkte aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
				array(
					'key' => 'field_61a5cb545d331',
					'label' => 'Merklisten aktivieren',
					'name' => 'watchlist-active',
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
						1 => 'Merklisten im Shop aktivieren',
					),
					'allow_custom' => 0,
					'default_value' => array(
					),
					'layout' => 'vertical',
					'toggle' => 0,
					'return_format' => 'value',
					'save_custom' => 0,
				),
				array(
					'key' => 'field_61a5d2d1520a7',
					'label' => 'Merklisten Einstellungen',
					'name' => 'watchlist-settings',
					'type' => 'group',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => array(
            array(
              array(
                'field' => 'field_61a5cb545d331',
                'operator' => '!=empty',
              ),
            ),
          ),
					'wrapper' => array(
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'layout' => 'block',
					'sub_fields' => array(
						array(
							'key' => 'field_61a5d3e08e7a8',
							'label' => 'Merklisten umbenennen',
							'name' => 'rename-watchlists',
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
								1 => 'Umbenennen',
							),
							'allow_custom' => 0,
							'default_value' => array(
							),
							'layout' => 'vertical',
							'toggle' => 0,
							'return_format' => 'value',
							'save_custom' => 0,
						),
						array(
							'key' => 'field_61a5d306520a8',
							'label' => 'Name singular',
							'name' => 'wl-name-singular',
							'type' => 'text',
							'instructions' => '',
							'required' => 1,
							'conditional_logic' => array(
								array(
									array(
										'field' => 'field_61a5d3e08e7a8',
										'operator' => '!=empty',
									),
								),
							),
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
							'key' => 'field_61a5d31d520a9',
							'label' => 'Name plural',
							'name' => 'wl-name-plural',
							'type' => 'text',
							'instructions' => '',
							'required' => 1,
							'conditional_logic' => array(
								array(
									array(
										'field' => 'field_61a5d3e08e7a8',
										'operator' => '!=empty',
									),
								),
							),
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
					),
				),
        array(
          'key' => 'field_62725fed22b6b',
          'label' => 'Depot aktivieren',
          'name' => 'depot-active',
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
            1 => 'Depot für Produkte im Shop aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_62727c3677d7c',
          'label' => 'Depot Einstellungen',
          'name' => 'depot-settings',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_62725fed22b6b',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_62727c6b77d7d',
              'label' => 'Depot umbenennen',
              'name' => 'rename',
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
                1 => 'Umbenennen',
              ),
              'allow_custom' => 0,
              'default_value' => array(
              ),
              'layout' => 'vertical',
              'toggle' => 0,
              'return_format' => 'value',
              'save_custom' => 0,
            ),
            array(
              'key' => 'field_62727c8c77d7e',
              'label' => 'Name singular',
              'name' => 'name-singular',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_62727c6b77d7d',
                    'operator' => '!=empty',
                  ),
                ),
              ),
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
              'key' => 'field_62727ca177d7f',
              'label' => 'Name plural',
              'name' => 'name-plural',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_62727c6b77d7d',
                    'operator' => '!=empty',
                  ),
                ),
              ),
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
          ),
        ),
        array(
          'key' => 'field_632d96d5c7926',
          'label' => 'Kauf Verlauf',
          'name' => 'purchase-history-active',
          'type' => 'checkbox',
          'instructions' => 'Zeigt eine Benutzer-Unterseite mit allen bereits gekauften Artikel.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_632d971ac7927',
          'label' => 'Kauf Verlauf Einstellungen',
          'name' => 'purchase-history-settings',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_632d96d5c7926',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_632d9747c7928',
              'label' => 'Menubezeichnung',
              'name' => 'menu-title',
              'type' => 'text',
              'instructions' => 'Default: Gekaufte Artikel',
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
              'key' => 'field_632d9756c7929',
              'label' => 'Überschrift',
              'name' => 'heading',
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
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'maxlength' => '',
            ),
            array(
              'key' => 'field_632d9762c792a',
              'label' => 'Einleitungstext',
              'name' => 'text',
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
              'placeholder' => '',
              'maxlength' => '',
              'rows' => 3,
              'new_lines' => '',
            ),
            array(
              'key' => 'field_633404df5afc3',
              'label' => 'Keine Produkte Text',
              'name' => 'no-products-text',
              'type' => 'textarea',
              'instructions' => 'Wird anstelle der Einleitungstext angezeigt, wenn noch keine Einkäufe vorhanden sind.',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'placeholder' => '',
              'maxlength' => '',
              'rows' => 3,
              'new_lines' => '',
            ),
          ),
        ),
        array(
          'key' => 'field_61a5cb545d378',
          'label' => 'Affiliate Modell aktivieren',
          'name' => 'affiliate-active',
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
            1 => 'Affiliate Modell im Shop aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_650317fa23f64',
          'label' => 'Einleitungstext',
          'name' => 'affiliate-page-text',
          'aria-label' => '',
          'type' => 'textarea',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_61a5cb545d378',
                'operator' => '!=empty',
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
          'rows' => '',
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_6511951dc6208',
          'label' => 'Standard Provision',
          'name' => 'standard-interest',
          'aria-label' => '',
          'type' => 'number',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_61a5cb545d378',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'min' => '',
          'max' => '',
          'placeholder' => '',
          'step' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_65253edf82093',
          'label' => 'Cookie Gültigkeit',
          'name' => 'affiliate-cookie-expiration',
          'aria-label' => '',
          'type' => 'number',
          'instructions' => 'Anzahl Tagen der Cookie gültig bleiben soll',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_61a5cb545d378',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'min' => '',
          'max' => '',
          'placeholder' => '',
          'step' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_65253f2d82094',
          'label' => 'Weiterleitung',
          'name' => 'affiliate-redirect',
          'aria-label' => '',
          'type' => 'page_link',
          'instructions' => 'Default: Home-Seite',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_61a5cb545d378',
                'operator' => '!=empty',
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
          'allow_null' => 1,
        ),
        array(
          'key' => 'field_6644a494bad26',
          'label' => 'Google Shopping Integration',
          'name' => 'google-shopping-integration',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => 'Die XML Datei wird jede Nacht um 2 Uhr erzeugt: ' . get_bloginfo('url') . '/assets/lbwp-cdn/' . ASSET_KEY . '/files/shop/google-shopping.xml',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Aktivieren',
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
        array(
          'key' => 'field_6644c19253685',
          'label' => 'Google Shopping Einstellungen',
          'name' => 'google-shopping-settings',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6644a494bad26',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_6644a541bad27',
              'label' => 'Firmenname',
              'name' => 'company-name',
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
            ),
            array(
              'key' => 'field_6644a555bad28',
              'label' => 'Logo',
              'name' => 'logo',
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
            ),
          ),
        )
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-display',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));

    acf_add_local_field_group(array(
      'key' => 'group_61f0230703620',
      'title' => 'Hinweisbox Warenkorb',
      'fields' => array(
        array(
          'key' => 'field_61f023250eb24',
          'label' => 'Inhalt',
          'name' => 'infobox-content',
          'type' => 'wysiwyg',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'tabs' => 'all',
          'toolbar' => 'basic',
          'media_upload' => 0,
          'delay' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-display',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * Adds Settings pages
   */
  protected function addSettingsPages()
  {
    acf_add_options_page(array(
      'page_title' => 'Aboon',
      'menu_title' => 'Aboon',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-settings',
      'icon_url' => 'dashicons-cart'
    ));
    acf_add_options_page(array(
      'page_title' => 'Aboon &raquo; Einstellungen',
      'menu_title' => 'Einstellungen',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-display',
      'parent_slug' => 'aboon-settings'
    ));
  }
}