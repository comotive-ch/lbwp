<?php

namespace LBWP\Theme\Component;

use LBWP\Core as LbwpCore;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * A simple flyout configurator that is cookie based
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class UserFeedback extends ACFBase
{
  /**
   * Configuration for the feedback form
   * @var array
   */
  protected $config = array(
    'recipients' => array(),
    'bccRecipients' => array()
  );
  /**
   * @var bool If the form was sent
   */
  protected $sentForm = false;
  /**
   * @var bool If the form should be at frontend too for logged in users
   */
  protected $useFrontend = false;

  /**
   * Nothing to do here
   */
  public function init()
  {
    add_action('admin_footer', array($this, 'addFeedbackForm'));
    if ($this->useFrontend && !is_admin() && current_user_can('edit_posts')) {
      add_action('wp_footer', array($this, 'addFeedbackForm'));
    }

    if (isset($_POST['lbwpUserFeedback']) && strlen($_POST['lbwpUserFeedback']) > 0) {
      $this->sendFeedback();
    }
  }

  /**
   * Send feedback to configured email addresses
   * @return void
   */
  protected function sendFeedback()
  {
    // Gather user info in a understandable string
    $subjectUserInfo = $_POST['user_display_name'] . ', ' . Strings::obfuscate($_POST['user_email'], 3, 10);
    $userInfo = 'Benutzer: ' . $_POST['user_display_name'] . ' (' . $_POST['user_email'] . ', ID: ' . $_POST['user_id'] . ')';
    $message = $userInfo . '<br><br>Nachricht: <br>'. nl2br($_POST['main_feedback']) . '<br><br>';
    // Remove values as to not show them in debug infos
    unset($_REQUEST['user_display_name'],$_REQUEST['user_email'],$_REQUEST['user_id'],$_REQUEST['main_feedback'],$_REQUEST['lbwpUserFeedback']);
    // Attach debug info
    $message .= 'Debug Infos:<br>';
    foreach ($_REQUEST as $key => $value) {
      $message .= ' - ' . $key . ': ' . $value . '<br>';
    }

    // Send with php mails
    $mail = External::PhpMailer();
    $mail->Subject = '[' . LBWP_HOST . '] Backend Feedback von ' . $subjectUserInfo;
    foreach ($this->config['recipients'] as $email) {
      $mail->addAddress($email);
    }
    foreach ($this->config['bccRecipients'] as $email) {
      $mail->addBCC($email);
    }
    // Att attachment if given
    if (isset($_FILES['main_screenshot']) && $_FILES['main_screenshot']['error'] === UPLOAD_ERR_OK) {
      $mail->addAttachment($_FILES['main_screenshot']['tmp_name'], $_FILES['main_screenshot']['name']);
    }

    // Set body and send
    $mail->Body = $message;
    $mail->send();
    $this->sentForm = true;
  }

  /**
   * @return string
   */
  protected function getCss()
  {
    return '<link rel="stylesheet" href="' . File::getResourceUri() . '/css/lbwp-userfeedback.css?v=' . LbwpCore::REVISION . '">';
  }

  /**
   * Add the feedback form to the footer
   */
  public function addFeedbackForm()
  {
    // Create debug info array
    $user = get_user_by('ID', get_current_user_id());
    $debugInfos = array();

    if (function_exists('get_current_screen')) {
      $screen = get_current_screen();
      $debugInfos['scr_id'] = $screen->id;
      $debugInfos['scr_base'] = $screen->base;
      $debugInfos['scr_post_type'] = $screen->post_type;
    }

    $debugInfos['user_display_name'] = $user->display_name;
    $debugInfos['user_id'] = $user->ID;
    $debugInfos['user_email'] = $user->user_email;
    $debugInfos['srv_request_uri'] = get_bloginfo('url') . $_SERVER['REQUEST_URI'];
    $debugInfos['srv_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    foreach ($_REQUEST as $key => $value) {
      $debugInfos[$key] = $value;
    }
    foreach ($_SESSION as $key => $value) {
      $debugInfos[$key] = $value;
    }
    // Build html input files for the debug infos
    $debugHtml = '';
    foreach ($debugInfos as $key => $value) {
      $debugHtml .= '<input type="hidden" name="' . $key . '" value="' . esc_attr($value) . '">' . PHP_EOL;
    }

    // Print HTML for the form
    echo '
      ' . $this->getCss() . '
      ' . $this->getScripts() . '
      <div class="lbwp-feedback-form feedback-form">
        <div class="lbwp-feedback-form__header"> 
          <a href="javascript:void(0);">
            <h2>Feedback' . ($this->sentForm ? '<span class="lbwp-feedback-sent"> gesendet!</span>' : '') . '</h2>
            <span class="lbwp-feedback-form__close">✖</span>
          </a>
        </div>
                
        <div class="lbwp-feedback-form__content"> 
          <form class="lbwp-feedback-form__form" action="" method="post" enctype="multipart/form-data">
            <textarea name="main_feedback" placeholder="Ihre Nachricht"></textarea><br>
            <label class="screenshot-label">
              Screenshot
              <input type="file" id="screenshotFile" name="main_screenshot">
            </label>
            ' . $debugHtml . '
            <!--<button type="button" onclick="screenshotThis()">Screenshot erstellen (experimentell)</button>-->
            <input class="button button-primary button-large" type="submit" name="lbwpUserFeedback" value="Feedback senden">
          </form>
        </div>
      </div>
    ';
  }

  /**
   * @return string
   */
  protected function getScripts()
  {
    return '
      <!--<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>-->
      <script>
        jQuery(function() {
          jQuery(".feedback-form a").click(function() {
            jQuery(".feedback-form").toggleClass("open");
          });
          // Remove sent info after 5 seconds
          setTimeout(function() {
            jQuery(".lbwp-feedback-sent").fadeOut();
          }, 5000);
        });
        
        
        // Function to ensure all elements are visible or removed if needed
        function cleanCloneForScreenshot(clone) {
            // Force reflow to ensure layout is updated
            clone.offsetHeight; // Reflow
            // Ensure html and body have a non-zero height
            clone.style.height = "100%";
            clone.style.width = "100%";
            
            setTimeout(function() {
              clone.querySelectorAll("*").forEach(el => {
                const rect = el.getBoundingClientRect();
                
                // If element has no width or height, remove it from the clone
                if (rect.width === 0 || rect.height === 0) {
                    el.style.display = "none"; // Hide zero-sized elements
                } 
              });
              // Remove all screen-reader-text
              clone.querySelectorAll(".screen-reader-text, thead,tfoot").forEach(el => el.remove());
            }, 1000)
            
        
            return clone;
        }
        
        // Function to capture the screenshot after cleaning the clone
        function captureScreenshot() {
            const targetElement = document.querySelector("#wpwrap"); // Change this selector if needed
            if (!targetElement) {
                console.error("Target element not found.");
                return;
            }
        
            // Clone the target element
            const clone = targetElement.cloneNode(true);
            
            // Clean the clone (ensure all elements are visible or removed)
            cleanCloneForScreenshot(clone);
        
            // Append the cleaned clone temporarily to the document body for screenshotting
            document.body.appendChild(clone);
        
            // Capture the screenshot of the clone
            setTimeout(() => {
                html2canvas(clone, {
                  useCORS: true,
                  ignoreElements: (el) => el.tagName === "IFRAME" || el.tagName === "SVG" || el.tagName === "IMG" || el.tagName === "SCRIPT",
                  logging: true
              }).then(canvas => {
                  document.body.removeChild(clone); // Remove the cloned element
                  console.log("Screenshot captured!");
                  console.log("Canvas size:", canvas.width, canvas.height);
          
                  if (canvas.width === 0 || canvas.height === 0) {
                      console.error("Captured canvas is empty! Possible CSS issue?");
                      return;
                  }
          
                  canvas.toBlob(blob => {
                      if (!blob) {
                          console.error("Failed to create blob from canvas.");
                          return;
                      }
          
                      console.log("Blob created:", blob);
                      const file = new File([blob], "screenshot.png", { type: "image/png" });
                      const dataTransfer = new DataTransfer();
                      dataTransfer.items.add(file);
                      document.getElementById("screenshotFile").files = dataTransfer.files;
          
                      console.log("Screenshot successfully attached.");
                  }, "image/png");
                  
                  // Show the canvas in 300x width after #screenshotFile
                  canvas.style.width = "300px";
                  canvas.style.height = "auto";
                  document.getElementById("screenshotFile").after(canvas);
                  
              }).catch(error => {
                  console.error("Error capturing screenshot:", error);
              });
            }, 1000);
          }
      </script>
    ';
  }

  public function blocks()
  {

  }

  public function fields()
  {

  }
} 