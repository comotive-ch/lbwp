<?php

namespace LBWP\Aboon\Subscription;

/**
 * Registers all ACF field groups used by the subscription feature (product settings and
 * the subscription post type's current-state fields).
 * @package LBWP\Aboon\Subscription
 * @author Michael Sebel <michael@comotive.ch>
 */
class Fields
{
  /**
   * Registers every field group.
   * @return void
   */
  public function register(): void
  {
    $this->registerProductFieldGroup();
    $this->registerSubscriptionFieldGroup();
  }

  /**
   * Registers the "Abonnement-Einstellungen" field group shown on the product edit screen.
   * @return void
   */
  protected function registerProductFieldGroup(): void
  {
    acf_add_local_field_group([
      'key' => 'group_lbwp_subscription_product',
      'title' => 'Abonnement-Einstellungen',
      'fields' => [
        [
          'key' => 'field_lbwp_sub_is_subscription_product',
          'label' => 'Als Abonnement anbieten',
          'name' => 'is_subscription_product',
          'type' => 'true_false',
          'instructions' => 'Der Preis dieses Produkts wird zur wiederkehrenden Zahlung über Payrexx.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'message' => 'Dieses Produkt als Abonnement verkaufen',
          'default_value' => 0,
          'ui' => 1,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ],
        [
          'key' => 'field_lbwp_sub_recurrence',
          'label' => 'Wiederkehrende Zahlung',
          'name' => 'subscription_recurrence',
          'type' => 'select',
          'instructions' => '',
          'required' => 1,
          'conditional_logic' => [
            [
              ['field' => 'field_lbwp_sub_is_subscription_product', 'operator' => '==', 'value' => '1'],
            ],
          ],
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'choices' => [
            'day' => 'Täglich',
            'week' => 'Wöchentlich',
            'month' => 'Monatlich',
            'year' => 'Jährlich',
          ],
          'default_value' => 'month',
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
        ],
        [
          'key' => 'field_lbwp_sub_allow_resize',
          'label' => 'Erlaube vergrössern der Menge',
          'name' => 'allow_subscription_resize',
          'type' => 'true_false',
          'instructions' => 'Erlaubt es dem Kunden im Mein-Konto-Bereich, die Menge (z.B. Anzahl Lizenzen) für die nächste Zahlung zu erhöhen.',
          'required' => 0,
          'conditional_logic' => [
            [
              ['field' => 'field_lbwp_sub_is_subscription_product', 'operator' => '==', 'value' => '1'],
            ],
          ],
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'message' => '',
          'default_value' => 0,
          'ui' => 1,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ],
        [
          'key' => 'field_lbwp_sub_allow_cancel',
          'label' => 'Kunde darf Abonnement selbst kündigen',
          'name' => 'allow_subscription_cancel',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => [
            [
              ['field' => 'field_lbwp_sub_is_subscription_product', 'operator' => '==', 'value' => '1'],
            ],
          ],
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'message' => '',
          'default_value' => 0,
          'ui' => 1,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ],
        [
          'key' => 'field_lbwp_sub_upgrade_products',
          'label' => 'Upgrade möglich zu',
          'name' => 'subscription_upgrade_products',
          'type' => 'relationship',
          'instructions' => 'Produkte, zu denen der Kunde von diesem Abonnement aus upgraden kann (einseitig, kein automatisches Downgrade).',
          'required' => 0,
          'conditional_logic' => [
            [
              ['field' => 'field_lbwp_sub_is_subscription_product', 'operator' => '==', 'value' => '1'],
            ],
          ],
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'post_type' => ['product'],
          'taxonomy' => '',
          'filters' => ['search'],
          'elements' => [],
          'min' => '',
          'max' => '',
          'return_format' => 'id',
        ],
      ],
      'location' => [
        [
          ['param' => 'post_type', 'operator' => '==', 'value' => 'product'],
        ],
      ],
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ]);
  }

  /**
   * Registers the current-state field group shown on the lbwp-subscription post type edit screen.
   * These fields are the authoritative storage for subscription state; they are written by code
   * (webhook/cron/admin actions), not edited by hand, hence marked readonly.
   * @return void
   */
  protected function registerSubscriptionFieldGroup(): void
  {
    acf_add_local_field_group([
      'key' => 'group_lbwp_subscription_meta',
      'title' => 'Abonnement-Status',
      'fields' => [
        [
          'key' => 'field_lbwp_sub_status',
          'label' => 'Status',
          'name' => 'subscription_status',
          'type' => 'text',
          'instructions' => 'Wird automatisch über Webhooks / den täglichen Sync von Payrexx aktualisiert.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'default_value' => '',
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_payrexx_subscription_id',
          'label' => 'Payrexx Subscription ID',
          'name' => 'payrexx_subscription_id',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'default_value' => '',
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_payrexx_psp_id',
          'label' => 'Payrexx PSP ID',
          'name' => 'payrexx_psp_id',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'default_value' => '',
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_linked_order_id',
          'label' => 'Verknüpfte Bestellung',
          'name' => 'linked_order_id',
          'type' => 'number',
          'instructions' => 'Kunden-, Rechnungs- und Lieferdaten werden aus dieser Bestellung gelesen, nicht kopiert.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'min' => '',
          'max' => '',
          'step' => '',
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_recurrence_snapshot',
          'label' => 'Turnus (bei Abschluss)',
          'name' => 'recurrence_snapshot',
          'type' => 'select',
          'instructions' => 'Momentaufnahme des Turnus bei Vertragsabschluss; ändert sich nicht rückwirkend, wenn das Produkt später angepasst wird.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'choices' => [
            'day' => 'Täglich',
            'week' => 'Wöchentlich',
            'month' => 'Monatlich',
            'year' => 'Jährlich',
          ],
          'default_value' => 'month',
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_current_quantity',
          'label' => 'Aktuelle Menge',
          'name' => 'current_quantity',
          'type' => 'number',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'default_value' => 1,
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'min' => 1,
          'max' => '',
          'step' => 1,
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_next_pay_date',
          'label' => 'Nächste Zahlung',
          'name' => 'next_pay_date',
          'type' => 'date_picker',
          'instructions' => 'Nur zur Anzeige, für Zugriffsprüfungen ist der Status massgebend.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'display_format' => 'd.m.Y',
          'return_format' => 'U',
          'first_day' => 1,
          'readonly' => 1,
        ],
        [
          'key' => 'field_lbwp_sub_valid_until',
          'label' => 'Gültig bis',
          'name' => 'valid_until',
          'type' => 'date_picker',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
          'display_format' => 'd.m.Y',
          'return_format' => 'U',
          'first_day' => 1,
          'readonly' => 1,
        ],
      ],
      'location' => [
        [
          ['param' => 'post_type', 'operator' => '==', 'value' => PostType::SLUG],
        ],
      ],
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ]);
  }
}
