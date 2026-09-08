<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component as BaseComponent;

/**
 * Obfuscates mailto links in the frontend HTML output, so that they cannot
 * be harvested by bots, while still working normally for human visitors.
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class MailProtection extends BaseComponent
{
  /**
   * Matches an anchor tag containing a mailto href, splitting it into the
   * attributes before and after the href, so the href can be stripped out
   * while every other attribute is preserved.
   */
  const string MAILTO_TAG_PATTERN = '/<a\s+([^>]*?)href=(["\'])mailto:([^"\']*)\2([^>]*)>(.*?)<\/a>/is';

  /**
   * Matches an email address inside the visible tag content, so it can be
   * masked with asterisks around the @ sign.
   */
  const string EMAIL_TEXT_PATTERN = '/([A-Za-z0-9._%+-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/';

  /**
   * Charset the random noise characters are drawn from.
   */
  const string NOISE_CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

  /**
   * Number of real base64 characters between two inserted noise characters,
   * randomized per request so it can not be hardcoded by a scraper.
   * @var int
   */
  protected int $noiseChunkSize = 4;

  /**
   * Registers the output buffer filter that obfuscates mailto links.
   * @return void
   */
  public function init(): void
  {
    if (!is_admin()) {
      add_filter('output_buffer', [$this, 'obfuscateMailtoLinks']);
    }
  }

  /**
   * Replaces mailto hrefs with an encoded data attribute and injects the
   * reveal script right before the closing body tag.
   * @param string $html the fully rendered page html.
   * @return string the html with obfuscated mailto links.
   */
  public function obfuscateMailtoLinks(string $html): string
  {
    if (stripos($html, 'mailto:') === false) {
      return $html;
    }

    $this->noiseChunkSize = wp_rand(3, 6);
    $html = preg_replace_callback(self::MAILTO_TAG_PATTERN, [$this, 'replaceMailtoTag'], $html);

    return $this->injectRevealScript($html);
  }

  /**
   * Rebuilds a single anchor tag, replacing its mailto href with an encoded
   * data attribute that the reveal script can later decode, and masking any
   * email address visible in the tag content.
   * @param array $matches the regex matches of a single anchor tag.
   * @return string the rebuilt anchor tag.
   */
  protected function replaceMailtoTag(array $matches): string
  {
    $attributesBefore = trim($matches[1]);
    $mailtoTarget = $matches[3];
    $attributesAfter = trim($matches[4]);
    $content = $this->maskEmailAddresses($matches[5]);
    $encoded = $this->insertNoise(base64_encode(rawurlencode($mailtoTarget)));

    return sprintf(
      '<a %s data-mailto-enc="%s" %s>%s</a>',
      $attributesBefore,
      $encoded,
      $attributesAfter,
      $content
    );
  }

  /**
   * Masks any email address found in the given text by replacing the last
   * half of the local part and the last half of the domain part with
   * asterisks, so the address is no longer readable as plain text.
   * @param string $content the tag content to mask.
   * @return string the masked tag content.
   */
  protected function maskEmailAddresses(string $content): string
  {
    return preg_replace_callback(self::EMAIL_TEXT_PATTERN, function (array $matches): string {
      return $this->maskHalf($matches[1]) . '@' . $this->maskHalf($matches[2]);
    }, $content);
  }

  /**
   * Replaces the last half of the given string with asterisks, rounding the
   * visible (first) half down so short strings stay fully masked.
   * @param string $part the local or domain part of an email address.
   * @return string the partially masked string.
   */
  protected function maskHalf(string $part): string
  {
    $visibleLength = (int) floor(strlen($part) / 2);
    $maskedLength = strlen($part) - $visibleLength;

    return substr($part, 0, $visibleLength) . str_repeat('*', $maskedLength);
  }

  /**
   * Inserts one random noise character after every noiseChunkSize real
   * characters of the given base64 string, so a scraper reading the
   * attribute directly can not decode it without knowing the chunk size.
   * @param string $base64 the base64 string to noisify.
   * @return string the base64 string with noise characters inserted.
   */
  protected function insertNoise(string $base64): string
  {
    $output = '';

    for ($i = 0; $i < strlen($base64); $i++) {
      $output .= $base64[$i];
      if (($i + 1) % $this->noiseChunkSize === 0) {
        $output .= self::NOISE_CHARSET[wp_rand(0, strlen(self::NOISE_CHARSET) - 1)];
      }
    }

    return $output;
  }

  /**
   * Injects the mailto reveal script right before the closing body tag.
   * @param string $html the html to inject the script into.
   * @return string the html with the script appended.
   */
  protected function injectRevealScript(string $html): string
  {
    $script = $this->getRevealScriptMarkup();

    if (stripos($html, '</body>') !== false) {
      return str_ireplace('</body>', $script . '</body>', $html);
    }

    return $html . $script;
  }

  /**
   * Provides the inline script markup that reveals mailto links once a
   * human interaction threshold (mouse/scroll distance, or touch on mobile)
   * has been reached.
   * @return string the script tag markup.
   */
  protected function getRevealScriptMarkup(): string
  {
    $chunkSize = $this->noiseChunkSize;

    return <<<HTML
    <script>
    (function () {
      var noiseChunkSize = {$chunkSize};
      var isTouch = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
      var threshold = isTouch ? 20 : 150;
      var distance = 0;
      var lastX = null;
      var lastY = null;
      var revealed = false;

      function denoise(value) {
        var output = '';
        var real = 0;
        for (var i = 0; i < value.length; i++) {
          output += value[i];
          real++;
          if (real % noiseChunkSize === 0) {
            i++;
          }
        }
        return output;
      }

      function reveal() {
        if (revealed) {
          return;
        }
        revealed = true;

        document.querySelectorAll('a[data-mailto-enc]').forEach(function (link) {
          try {
            var target = decodeURIComponent(atob(denoise(link.getAttribute('data-mailto-enc'))));
            link.setAttribute('href', 'mailto:' + target);
          } catch (error) {
            // Ignore malformed encodings, link simply stays without href
          }
          link.removeAttribute('data-mailto-enc');
        });

        window.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('touchstart', onTouch);
        window.removeEventListener('touchmove', onTouch);
      }

      function onMouseMove(event) {
        if (lastX !== null) {
          distance += Math.abs(event.clientX - lastX) + Math.abs(event.clientY - lastY);
        }
        lastX = event.clientX;
        lastY = event.clientY;

        if (distance >= threshold) {
          reveal();
        }
      }

      function onScroll() {
        distance += 30;

        if (distance >= threshold) {
          reveal();
        }
      }

      function onTouch() {
        distance += threshold;
        reveal();
      }

      window.addEventListener('mousemove', onMouseMove, { passive: true });
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('touchstart', onTouch, { passive: true });
      window.addEventListener('touchmove', onTouch, { passive: true });
    })();
    </script>
    HTML;
  }
}
