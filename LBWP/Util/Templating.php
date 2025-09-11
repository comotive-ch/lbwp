<?php

namespace LBWP\Util;

use LBWP\Helper\Converter;
use LBWP\Module\Forms\Action\SendMail;
use LBWP\Core as LbwpCore;

/**
 * Helper for templating and html generation
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Templating
{
  /**
   * Simple html block replacing
   * @param string $html the html block to use
   * @param array $data the data to be replaced
   * @return string created html block
   */
  public static function getBlock($html, $data)
  {
    foreach ($data as $key => $value) {
      $html = str_replace("{$key}", $value, $html);
    }

    return $html;
  }

  /**
   * Put something into a container
   * @param string $container the container
   * @param string $html the content of the container
   * @param string $varName the variable name to replace
   * @return string full content in container
   */
  public static function getContainer($container, $html, $varName = '{content}')
  {
    if (strlen($html) == 0) {
      return '';
    }

    return str_replace($varName, $html, $container);
  }

  /**
   * @param array $items key value pair of html items to be listed
   * @param string $class an additional class to be set
   * @param string $type the type of list, defaults to unordered
   * @return string the built html block
   */
  public static function getListHtml($items, $class = '', $type = 'ul')
  {
    $html = '<' . $type . ' class="' . $class . '">';
    foreach ($items as $key => $value) {
      $html .= '<li class="item-' .esc_attr($key)  . '">' . $value . '</li>';
    }
    $html .= '</' . $type . '>';

    return $html;
  }

  /**
   * @param array $options key value pair of options
   * @param string $name name of the field
   * @param string $selectedValue selected value
   * @param string $first the first element, if needed
   * @param string|int $firstValue the value of the first option
   * @param string $attr additional attributes
   * @return string html
   */
  public static function getSelectItem($options, $name, $selectedValue, $first = '', $firstValue = 0, $attr = '')
  {
    $html = '';

    // Initialize the select item
    $html .= '<select name="' . $name . '" ' . $attr . '>';
    // First option, if needed
    if (strlen($first) > 0) {
      $selected = selected($firstValue, $selectedValue, false);
      $html .= '<option value="' . $firstValue . '" ' . $selected . '>' . $first . '</option>';
    }

    // Add all the options, preselect given
    foreach ($options as $key => $value) {
      $selected = selected($key, $selectedValue, false);
      $html .= '<option value="' . $key . '" ' . $selected . '>' . $value . '</option>';
    }

    $html .= '</select>';

    return $html;
  }

  /**
   * Returns a target="_blank" attribute with prefix string, if the url is external
   * @param string $url the url to target blank or not
   * @param string $prefix the html prefix to the attribute, a single space by default
   * @return string empty or '$prefix.target="_blank"'
   */
  public static function autoTargetBlank($url, $prefix = ' ')
  {
    // Only do something if the string is an analyzeable url
    if (Strings::isURL($url)) {
      $parts = parse_url($url);
      if ($parts['host'] != LBWP_HOST || Strings::endsWith($url, '.pdf')) {
        return $prefix . 'target="_blank"';
      }
    }

    return '';
  }

	public static function getEmailTemplate($subject, $content, $args = array()){
    $content = '
      <div style="color:#555555;font-family:Arial, Helvetica Neue, Helvetica, sans-serif;line-height:1.5;padding-top:15px;margin-right:30px;padding-bottom:15px;margin-left:30px;">
        <div style="line-height: 1.5; font-size: 12px; color: #555555; font-family: Arial, Helvetica Neue, Helvetica, sans-serif; mso-line-height-alt: 18px;">' .
          str_replace('<p>', '<p style="font-size: 14px; line-height: 1.5; word-break: break-word; mso-line-height-alt: 21px;">', strip_tags($content, '<p><br><strong><em><span>')) .
        '</div>
      </div>';
    // Setup and send the email with form action template
    $templateName = apply_filters('lbwp_default_email_template_key', SendMail::DEFAULT_TEMPLATE_KEY);
    $templating = new SendMail(null);
    $template = $templating->getHtmlTemplate($templateName);
    $template = file_get_contents($template['file']);

    // Get settings for colors and logo
    $config = LbwpCore::getInstance()->getConfig();

		
		$settings = array_merge(array(
			'{email-subject}' => $subject,
			'{email-content}' => $content,
			'{email-meta}' => '',
			'{meta-info}' => '',
			'{email-heading}' => '',
			'{email-addition-subject}' => '',
			'{email-greeting}' => '',
			'{email-footer}' => '',
			'{header-centering}' => 0,
			// Settings for display
			'{email-background-color}' => Strings::isEmpty($config['EmailTemplates:BackgroundColor']) ? '#f6f6f6' : $config['EmailTemplates:BackgroundColor'],
			'{email-primary-color}' => Strings::isEmpty($config['EmailTemplates:PrimaryColor']) ? '#2d2d2d' : $config['EmailTemplates:PrimaryColor'],
			'{email-logo}' => '
				<a href="' . get_bloginfo('url') . '" style="line-height: 1.5; font-size: 18px; color: #555555; font-weight: bold; font-family: Arial, Helvetica Neue, Helvetica, sans-serif; mso-line-height-alt: 27px; text-decoration: none; ">' . 
					(Strings::isEmpty($config['EmailTemplates:LogoImageUrl']) ? 
						get_bloginfo('name') : 
						'<img align="center" border="0" class="center fixedwidth" src="' . Converter::forceNonWebpImageUrl($config['EmailTemplates:LogoImageUrl']) . '" width="112"
						style="text-decoration: none; -ms-interpolation-mode: bicubic; height: auto; border: 0; width: 100%; max-width: 112px; display: block;"/> ' 
					) .
				'</a>
			'
		), $args);
    // Create the mail body
    $body = Templating::getBlock($template, $settings);

		return $body;
	}
	
	/**
	 * Get an image from a remote url
	 *
	 * @param  string $remoteUrl the remote url
	 * @return string|bool the image source or false if no image has been found
	 */
	public static function getRemoteImage($remoteUrl){
		$remoteContent = file_get_contents($remoteUrl);

		// First check for "og:image" in the head meta
		preg_match_all('/og:image/', $remoteContent, $matches, PREG_OFFSET_CAPTURE);

		foreach($matches[0] as $match){
      // Get start of tag from actual match position as og:image can be anywhere in the tag
      $pos = strrpos(substr($remoteContent, 0, $match[1]), '<meta');
			$cutString = substr($remoteContent, $pos, strpos($remoteContent, '>', $pos) - $pos);
			preg_match('/(?<=content=")([^"]*)|(?<=content=\')([^\']*)/i', $cutString, $image);

			if(filter_var($image[0], FILTER_VALIDATE_URL) !== false){
				return $image[0];
			}
		}

		// If no "og:image" has been found, then look for article or main and then take the first image
		preg_match('/article/i', $remoteContent, $content, PREG_OFFSET_CAPTURE);

		if(!empty($content)){
			preg_match('/(?<=<img)([^>]*)/i', substr($remoteContent, $content[1]), $imageTag);
			preg_match('/(?<=src=")([^"]*)/i', $imageTag[0], $image);

			// Add base url if the image is linked dynamicly
			if(Strings::startsWith($image[0], '/')){
				$image[0] = parse_url($remoteUrl)['host'] . $image[0];
			}

			return $image[0];
		}

		return false;
	}
}