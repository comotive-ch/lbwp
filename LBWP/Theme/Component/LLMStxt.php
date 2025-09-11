<?php

namespace LBWP\Theme\Component;

/**
 * LEt user configure llms.txt and print it with a 404 handler
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class LLMStxt extends ACFBase
{
  /**

  /**
   * Register all needed types and filters to control access
   */
  public function init()
  {
    add_action('wp', array($this, 'handleLlmsTxt'), 10);
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    if (function_exists('acf_add_options_page')) {
      acf_add_options_page(array(
        'page_title' => 'LLMS.txt Einstellungen',
        'menu_title' => 'LLMS.txt',
        'menu_slug'  => 'llms-txt-settings',
        'parent_slug' => 'options-general.php',
        'capability' => 'manage_options',
      ));
    }

    acf_add_local_field_group(array(
      'key' => 'group_llms_settings',
      'title' => 'LLMS.txt Konfiguration',
      'fields' => array(
        array(
          'key' => 'field_company_description',
          'label' => 'Unternehmensbeschreibung und Mission',
          'name' => 'company_description',
          'type' => 'textarea',
          'maxlength' => 1000,
          'rows' => 4,
        ),
        array(
          'key' => 'field_main_products',
          'label' => 'Hauptprodukte/Dienstleistungen', 
          'name' => 'main_products',
          'type' => 'textarea',
          'maxlength' => 1000,
          'rows' => 4,
        ),
        array(
          'key' => 'field_target_audience',
          'label' => 'Zielgruppe und Positionierung',
          'name' => 'target_audience', 
          'type' => 'textarea',
          'maxlength' => 1000,
          'rows' => 4,
        ),
        array(
          'key' => 'field_special_expertise',
          'label' => 'Besondere Expertise oder Alleinstellungsmerkmale',
          'name' => 'special_expertise',
          'type' => 'textarea', 
          'maxlength' => 1000,
          'rows' => 4,
        ),
        array(
          'key' => 'field_contact_info',
          'label' => 'Kontaktinformationen und wichtige URLs',
          'name' => 'contact_info',
          'type' => 'textarea',
          'maxlength' => 1000, 
          'rows' => 4,
        ),
        array(
          'key' => 'field_show_woo_categories',
          'label' => 'Produkte-Kategorien anzeigen',
          'name' => 'show_woo_categories',
          'message' => 'Zeige der KI die Hauptkategorien aus WooCommerce an, sofern vorhanden.',
          'type' => 'true_false',
        ),
        array(
          'key' => 'field_posts_display',
          'label' => 'Beiträge anzeigen',
          'name' => 'posts_display',
          'type' => 'radio',
          'choices' => array(
            'auto' => 'Automatisch die neusten Beiträge anzeigen',
            'manual' => 'Manuelle Beitragsauswahl',
          ),
          'default_value' => 'auto',
        ),
        array(
          'key' => 'field_selected_posts',
          'label' => 'Beiträge auswählen',
          'name' => 'selected_posts',
          'type' => 'relationship',
          'post_type' => array('post'),
          'min' => 1,
          'max' => 5,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_posts_display',
                'operator' => '==',
                'value' => 'manual',
              ),
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'llms-txt-settings',
          ),
        ),
      ),
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks()
  {

  }

  /**
   * Handle /llms.txt requests
   */
  public function handleLlmsTxt()
  {
    if ($_SERVER['REQUEST_URI'] === '/llms.txt') {
      header('HTTP/1.1 200 OK');
      header('Content-Type: text/plain; charset=utf-8');
      echo $this->generateLlmsTxtContent();
      exit;
    }
  }

  /**
   * Generate the content for llms.txt
   */
  private function generateLlmsTxtContent()
  {
    $content = '# ' . get_bloginfo('name') . "\n\n";
    
    $companyDescription = get_field('company_description', 'option');
    if ($companyDescription) {
      $content .= "## Unternehmensbeschreibung und Mission\n\n";
      $content .= $companyDescription . "\n\n";
    }
    
    $mainProducts = get_field('main_products', 'option');
    if ($mainProducts) {
      $content .= "## Hauptprodukte/Dienstleistungen\n\n";
      $content .= $mainProducts . "\n\n";
    }
    
    $targetAudience = get_field('target_audience', 'option');
    if ($targetAudience) {
      $content .= "## Zielgruppe und Positionierung\n\n";
      $content .= $targetAudience . "\n\n";
    }
    
    $specialExpertise = get_field('special_expertise', 'option');
    if ($specialExpertise) {
      $content .= "## Besondere Expertise oder Alleinstellungsmerkmale\n\n";
      $content .= $specialExpertise . "\n\n";
    }
    
    $contactInfo = get_field('contact_info', 'option');
    if ($contactInfo) {
      $content .= "## Kontaktinformationen und wichtige URLs\n\n";
      $content .= $contactInfo . "\n\n";
    }
    
    if (class_exists('WooCommerce') && get_field('show_woo_categories', 'option')) {
      $content .= $this->generateWooCommerceCategories();
    }
    
    $content .= $this->generateBlogPosts();
    
    return $content;
  }

  /**
   * Generate WooCommerce categories section
   */
  private function generateWooCommerceCategories()
  {
    $content = "## Die wichtigsten Produkte-Kategorien\n\n";
    
    $categories = get_terms(array(
      'taxonomy' => 'product_cat',
      'hide_empty' => true,
      'parent' => 0,
    ));
    
    if ($categories && !is_wp_error($categories)) {
      foreach ($categories as $category) {
        $content .= "## " . $category->name . "\n\n";
        
        if ($category->description) {
          $content .= $category->description . "\n\n";
        }
        
        $category_link = get_term_link($category);
        if (!is_wp_error($category_link)) {
          $content .= "Link: " . $category_link . "\n\n";
        }
      }
    }
    
    return $content;
  }

  /**
   * Generate blog posts section
   */
  private function generateBlogPosts()
  {
    $content = "## Beiträge aus dem Blog\n\n";
    
    $postsDisplay = get_field('posts_display', 'option');
    
    if ($postsDisplay === 'manual') {
      $selectedPosts = get_field('selected_posts', 'option');
      $posts = $selectedPosts ? $selectedPosts : array();
    } else {
      $posts = get_posts(array(
        'posts_per_page' => 3,
        'post_status' => 'publish',
      ));
    }
    
    if ($posts) {
      foreach ($posts as $post) {
        $content .= "### " . $post->post_title . "\n\n";
        
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
          $excerpt = wp_trim_words(strip_tags(do_blocks($post->post_content)), 100, '...');
        }
        $content .= $excerpt . "\n\n";
        
        $content .= "Link: " . get_permalink($post) . "\n\n";
      }
    }
    
    return $content;
  }
} 