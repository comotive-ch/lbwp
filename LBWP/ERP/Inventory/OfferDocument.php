<?php

namespace LBWP\ERP\Inventory;

use LBWP\Theme\Base\Component;

/**
 * Provide inventory functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class OfferDocument extends Component
{
  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    // Register a button to PDF create an offer (based on billing pdf plugin)
    add_filter('wpo_wcpdf_meta_box_actions', array($this, 'addPdfOfferButton'));
    add_filter('wpo_wcpdf_html_content', array($this, 'changeInvoiceToOfferHtml'));
  }

  /**
   * @param $actions
   * @return mixed
   */
  public function addPdfOfferButton($actions)
  {
    // Copy invoice action of "offer" and extend the url
    $actions['offer'] = $actions['invoice'];
    $actions['offer']['exists'] = false;
    $actions['offer']['mark_printed_url'] = false;
    $actions['offer']['alt'] = 'PDF Offerte';
    $actions['offer']['title'] = 'PDF Offerte';
    $actions['offer']['url'] .= '&pdf-is-offer-mode=1';

    // Rearrange keys so offer is in front
    $new = ['offer' => $actions['offer']];
    foreach ($actions as $key => $value) {
      if ($key !== 'offer') {
        $new[$key] = $value;
      }
    }

    return $new;
  }

  /**
   * @param $html
   * @return void
   */
  public function changeInvoiceToOfferHtml($html)
  {
    if (isset($_GET['pdf-is-offer-mode'])) {
      // That's just a few string replaces
      $html = str_replace('Zahlbar innert 30 Tagen.', '', $html);
      $html = str_replace('Rechnungsdatum', 'Erstelldatum', $html);
      $html = str_replace('Bestellnummer', 'Offertnummer', $html);
      $html = str_replace('Bestelldatum', 'Ausgestellt am', $html);
      $html = str_replace('Zahlungsart', 'Status', $html);
      $html = str_replace('Rechnung', 'Offerte', $html);
    }

    return $html;
  }
}