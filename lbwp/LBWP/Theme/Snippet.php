<?php

namespace LBWP\Theme;

/**
 * Various HTML / Script/ CSS Snippets that we want centrally managed
 * @package LBWP\Theme
 * @author Michael Sebel <michael@comotive.ch>
 */
class Snippet {

  /**
   * @return string styles to make responsivenes in ie10 on tablets work
   */
  public static function getIE10ResponsiveStyles() {
    return '
      <style type="text/css">
        @-webkit-viewport { width:device-width; }
        @-moz-viewport { width:device-width; }
        @-ms-viewport { width:device-width; }
        @-o-viewport { width:device-width; }
        @viewport { width:device-width; }
      </style>
      <script type="text/javascript">
        if (navigator.userAgent.match(/IEMobile\/10\.0/)) {
          var msViewportStyle = document.createElement("style");
          msViewportStyle.appendChild(
            document.createTextNode("@-ms-viewport{ width:auto!important }")
          );
          document.getElementsByTagName("head")[0].appendChild(msViewportStyle);
        }
      </script>
    ';
  }
} 