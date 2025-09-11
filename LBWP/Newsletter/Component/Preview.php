<?php

namespace LBWP\Newsletter\Component;

use LBWP\Newsletter\Component\Base;
use LBWP\Util\WordPress;
use LBWP\Util\Strings;

/**
 * This class handles the newsletter preview
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Preview extends Base
{
  /**
   * @var array example data to populate the preview
   */
  protected $exampleData = array(
    'salutation' => 'Sehr geehrter Herr',
    'firstname' => 'Hans',
    'lastname' => 'Muster',
    'email' => 'hans.muster@beispiel.org',
    'unsubscribe' => '<a href="#">Abmelden</a>'
  );
  /**
   * Called after component construction
   */
  public function load()
  {
    //hoi
    add_action('template_redirect', array($this, 'handleTemplateRedirect'), 10);
  }

  /**
   * Called at init(50)
   */
  public function initialize() { }

  /**
   * This checks the query vars for lbwp-nl previews to render them
   */
  public function handleTemplateRedirect()
  {
    $query = WordPress::getQuery();
    $isNlPreview = false;

    // Check for preview or post type
    if (isset($_GET['p']) && $_GET['post_type'] == 'lbwp-nl') {
      $newsletterId = intval($_GET['p']);
      $isNlPreview = true;
    }

    // Check the page name to start with the lbwp-nl slug (for live single display)
    if (isset($query->query['pagename']) && Strings::startsWith($query->query['pagename'], 'lbwp-nl/')) {
      $newsletterId = WordPress::getPostIdByName($query->query_vars['name'], 'lbwp-nl');
      $isNlPreview = true;
    }

    if (isset($query->query['lbwp-nl'])) {
      $newsletterId = WordPress::getPostIdByName($query->query_vars['lbwp-nl'], 'lbwp-nl');
      $isNlPreview = true;
    }

    // If newsletter preview, show the NL and exit
    if ($isNlPreview) {
      echo $this->getPreview($newsletterId);
      exit;
    }
  }

  /**
   * @param int $newsletterId the newsletter to render
   */
  public function getPreview($newsletterId)
  {
    echo $this->renderPreview($newsletterId);
  }

  /**
   * @param int $newsletterId the newsletter to render
   * @return string html code that will display the whole newsletter
   */
  public function renderPreview($newsletterId)
  {
    // Get the newsletter including all metadata
    $type = $this->core->getTypeNewsletter();
    $newsletter = $type->getNewsletter($newsletterId);

    // Load the template engine
    $templating = $this->core->getTemplating();
    $template = $templating->getTemplate($newsletter->templateId);

    // Decide between text and html version
    if (isset($_GET['textVersion'])) {
      $html = $type->renderNewsletter($template, $newsletter, 'text');
    } else {
      $html = $type->renderNewsletter($template, $newsletter, 'html');
    }

    // Replace the variables with some real data
    $html = $this->populateExampleData($html);

    return $html;
  }

  /**
   * @param string $html the newsletter html
   * @return string html filled with example data
   */
  protected function populateExampleData($html)
  {
    // Get the current services variables to translate to standard vars
    $variables = $this->core->getService()->getVariables();
    foreach ($variables as $key => $serviceKey) {
      $translatedKey = '{lbwp:' . $key . '}';
      $html = str_replace($serviceKey, $translatedKey, $html);
      // and try to populate the variable
      if (isset($this->exampleData[$key])) {
        $html = str_replace($translatedKey, $this->exampleData[$key], $html);
      }
    }

    return $html;
  }
} 