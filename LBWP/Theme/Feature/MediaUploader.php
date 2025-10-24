<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;

/**
 * Media Uploader and Text editor to render in Frontend
 * @package Whatnext\Component
 * @author Mirko Baffa <mirko@wesign.ch>
 * @source https://derekspringer.wordpress.com/2015/05/07/using-the-wordpress-media-loader-in-the-front-end/
 */
Class MediaUploader{
  /**
   * Defailt upload mimes
   * @var string[]
   */
  private $uploadMimes = array(
    'jpg|jpeg|jpe2' =>	'image/jpeg',
    'gif' => 'image/gif',
    'png' => 'image/png',
    'avi2' => 'video/avi',
    'mov|qt2' => 'video/quicktime',
    'mpeg|mpg|mpe2' => 'video/mpeg',
    'mp4|m4v2' => 'video/mp4',
    'webm2' => 'video/webm',
    'pdf2' => 'application/pdf',
  );

  private $settings = array(
    'multiple' => true,
    'button_text' => 'Bilder Hochladen',
    'popup_button_text' => 'Auswählen',
    'popup_title' => 'Medien',
    'class' => 'media-uploader',
    'container_class' => 'media-uploader__container',
    'image_class' => 'media-uploader__container--image',
    'html_container' => '<div class="media-uploader__container">{UPLOADER}</div>',
  );

  /**
   * Initilize the component
   */
	public function __construct($settings = array(), $uploadMimes = array()){
    // Merge the settings
    if(!empty($settings)){
      $this->settings = array_merge($this->settings, $settings);
    }

    // Override the upload mimes
    if(!empty($uploadMimes)){
      $this->uploadMimes = $uploadMimes;
    }

    add_action('upload_mimes', array($this, 'restrictUserUploadsType'));
    add_action('wp_enqueue_scripts', array($this, 'assets'));
		add_filter('ajax_query_attachments_args', array($this, 'filterUploadMediaUser'));
  }

	/**
	 * Register the assets
	 */
	public function assets(){
		// Enqueues all needed wordpress scripts
		wp_enqueue_media(); // TODO: Check what scripts are actually enqueued

		// and our own
		wp_enqueue_script('lbwp-media-uploader', File::getResourceUri() . '/js/frontend-media-uploader.js', array('jquery'), LbwpCore::REVISION, true);
	}
	
	/**
	 * Filter the media the user sees
	 *
	 * @param  array $query the image query
	 * @return array the query
	 */
	public function filterUploadMediaUser($query){
			if(!current_user_can('manage_options')){
				$query['author'] = get_current_user_id();
			}

			return $query;
	}

  /**
   * Restrict the user uploads file type
   * @param $mimes
   * @return string[]
   */
	public function restrictUserUploadsType($mimes = array()){
		return $this->uploadMimes;
	}

	/**
	 * Render the upload
	 */
	public function render($echo = true){
		$btn = '
      <div class="' . $this->settings['class'] . '">
        <div class="' . $this->settings['container_class'] . '">
          <div class="' . $this->settings['image_class'] . '"></div>  
        </div>
        <input type="hidden" value="" name="media-uploader-images"/>
        <input type="button" value="' . $this->settings['button_text'] . '" class="media-uploader__button" 
          data-button-text="' . $this->settings['popup_button_text'] . '" 
          data-popup-title="' . $this->settings['popup_title'] . '"
          data-image-container="' . $this->settings['image_class'] . '"
        />
      </div>';

    $html = str_replace('{UPLOADER}', $btn, $this->settings['html_container']);

		if($echo){
			echo $html;
		}else{
			return $html;
		}
	}
}