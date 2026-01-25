<?php

namespace LBWP\Theme\Base;

use \WP;
use \WP_Query;
use \WP_Admin_Bar;
use \WP_Object_Cache;
use \wpdb;
use \WP_Rewrite;
use \WP_Roles;
use \WP_Post;
use \WP_Scripts;
use \WP_Styles;
use \WP_Widget_Factory;

/**
 * This wrapper loads less stuff in corev2 automatically and is 1:1 compat to WpWrapper
 * @package LBWP\Theme\Base
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 * @author Michael Sebel <michael@comotive.ch>
 */
class MinimalWrapper extends WpWrapper
{

}