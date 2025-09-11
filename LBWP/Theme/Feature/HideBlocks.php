<?php

namespace LBWP\Theme\Feature;

use LBWP\Theme\Base\CoreV2 as Core;
use LBWP\Util\File;

/**
 * Enables the hide-block functionality for gutenberg blocks
 * @author Mirko Baffa <mirko@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class HideBlocks
{
  /**
   * @var HideBlocks the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array();

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return HideBlocks the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new HideBlocks($options);
    self::$instance->initialize();
  }

  /**
   * Set the hide functionality for blocks
   */
  public function initialize()
  {
		add_filter('admin_body_class', array($this, 'addHideBodyClass'));
		add_action('enqueue_block_editor_assets', array($this, 'enqueueHideAssets'), 100);
		add_filter('render_block', array($this, 'hideBlocksOnRender'), 9999, 2);
  }
	
	/**
	 * Adds a body class if the hide functionality is active
	 *
	 * @param  string $classes the body classes
	 * @return string all the classes
	 */
	public function addHideBodyClass($classes){
		$classes .= ' lbwp-hide-block-enabled';
		return $classes;
	}

	/**
	 * Enqueue the JS for the hide functionality
	 */
	public function enqueueHideAssets(){
		$resUri = File::getResourceUri(); 
		$assetFile = include(File::getResourcePath() . '/js/jsx/build/index.asset.php');
	
		wp_register_script(
				'lbwp-hide-block-js',
				$resUri . '/js/jsx/build/index.js',
				$assetFile['dependencies'],
				$assetFile['version']
		);

		wp_enqueue_script('lbwp-hide-block-js');
		wp_enqueue_style('lbwp-hide-block-css', $resUri . '/css/hide-blocks.css');
	}
	
	/**
	 * Hide the blocks based on their attributes
	 *
	 * @param  mixed $content
	 * @param  mixed $block
	 * @return void
	 */
	public function hideBlocksOnRender($content, $block){
		// If block attribute is set to hide and user is logged in
		if(isset($block['attrs']['hideBlock'])){
			if(is_user_logged_in()){
				$content = '<div class="lbwp-hidden-block-wrapper">' . $content . '</div>';
			}else{
				$content = '';
			}
		}

		return $content;
	}
}