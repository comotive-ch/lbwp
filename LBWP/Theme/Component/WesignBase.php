<?php

namespace LBWP\Theme\Component;

/**
 * Wesign base component that does some base stuff (used in wesign-instances)
 * @package LBWP\Theme\Component
 * @author Mirko Baffa <mirko@wesign.ch>
 */
class WesignBase
{
  public function __construct()
  {
    $this->init();
  }

  public function init(){
    add_filter('lbwp_maintenance_customize_html', array($this, 'customizeMaintenanceScreen'), 99, 3);
  }

  /**
   * Customize the maintenance screen
   * @param $html
   * @param $header
   * @param $settings
   * @return string
   */
  public function customizeMaintenanceScreen($html, $header, $settings){
    return '
      <!doctype html>
      <html class="no-js" lang="en">
      <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>wesign - Work in progress</title>
        <link href="//fonts.googleapis.com/css?family=Montserrat:400,700" rel="stylesheet" type="text/css">
        ' . $header . '
        <style>

        body {
          font-family: "Montserrat", sans-serif;
          background: #0a0a0a;
          height: 100vh;
          padding: 0;
          margin: 0;
          display: flex;
          flex-direction: column;
          justify-content: center;
        }

        .logo {
          width: 100%;
          height: 100px;
          background-size: contain;
          background-repeat: no-repeat;
          background-position: center;
        }

        .content {
          width: 80%;
          margin: 3rem auto;
          text-align: center;
          color: #d7f205;
        }

        .maintenance-password, .maintenance-submit {
          border: 1px solid #cfcfcf;
          border-radius:4px;
          padding:6px;
          font-size:14px;
        }

        h1 {
          font-size: 1.5em;
          height: 1.5em;
          overflow: hidden;
          position: relative;
        }
        .title-animation{
          display: block;
          width: 100%;
          position: absolute;
          top: 100%;
          animation: text 20s cubic-bezier(.68,-0.55,.27,1.55) infinite;
        } 
        .title-animation span{
          line-height: 1.5em;
          display: block;
        }
        
        p {
         font-size: 1em;
        }

        a,a:visited {
          color: rgb(94, 119, 140);
        }
        a:hover {
          color: rgb(22, 34, 42);
        }
        
        .maintenance-input-btn-group{
          margin-top: 2rem;
        }
        
        .maintenance-password{
          border-radius: 0;
          border-color: #d7f205;
          background: none;
          color: #d7f205;
          padding: 5px;
        }
        
        .maintenance-password:focus,
        .maintenance-password:active,
        .maintenance-password:focus-visible{
          outline: none;
        }
        
        .maintenance-submit{
          border-radius: 0;
          background: #a7bc00;
          border: none;
          color: #0a0a0a;
          text-transform: uppercase;
        }
        
        .maintenance-submit:focus,
        .maintenance-submit:active,
        .maintenance-submit:focus-visible,
        .maintenance-submit:hover{
          outline: none;
          background: #d7f205; 
        }

        @media only screen and (max-width: 480px) {
          h1 {
            font-size: 18px;
            height: 22px;
          }
          .title-animation span{
            line-height: 22px;
            display: block;
          }
        }
        
        @keyframes text {
          0% {top: 0%;}
          12.5% {top: -100%;}
          15% {top: -100%;}
          22.5% {top: -200%;}
          25% {top: -200%;}
          35% {top: -300%;}
          37.5% {top: -300%;}
          47.5% {top: -400%;}
          50% {top: -400%;}
          60% {top: -500%;}
          62.5% {top: -500%;}
          72.5% {top: -600%;}
          75% {top: -600%;}
          85% {top: -700%;}
          87.5% {top: -700%;}
          97.5% {top: -800%;}
          100% {top: -800%;}
        }
        ' . $settings['additionalCss'] . '

        </style>
      </head>
      <body>

      <a href="https://www.wesign.ch" target="_blank" title="wesign"><div class="logo" style="background-image: url(\'https://www.wesign.ch/assets/lbwp-cdn/wesign/files/1686563700/wesign-logo.png\')">
      </div></a>

      <div class="content">
        <h1>
          <div class="title-animation">
            <span>Erweiterungen programmieren</span>
            <span>SEO konfigurieren</span>
            <span>Logo platzieren</span>
            <span>Seiten kontrollieren</span>
            <span>Testing und review</span>
            <span>Qualitätsanalyse</span>
            <span>Codes optimieren</span>
            <span>Erweiterungen programmieren</span>
          </div>
        </h1>
        <p>Hier entsteht die neue Website von «' . get_bloginfo('name') . '»</p>
        {LOGIN_HTML}
      </div>
     
      </body>
      </html>
      ';
  }
}