<?php
require_once '../../../../../wp-load.php';

$master = $_POST['XmrFcTn'];
$salt = $_POST['R8nMw29'];

// Get all needed data for the user
$user = get_user_by('login', 'comotive');
$userId = intval($user->data->ID);
$token = get_user_meta($userId, 'lbwp_pwd_policy_login_token', true);

// Generate the matching token
$password = generateLbwpPolicyPassword($master, $salt);
$match = generateLbwpPolicyLoginToken(LBWP_HOST, $password, 'c0m0tiv3');

// Log the user in or go back
if ($token === $match) {
  wp_set_auth_cookie($userId, true, defined('WP_FORCE_SSL') && WP_FORCE_SSL);
  if (defined('WP_FORCE_SSL') && WP_FORCE_SSL) {
    header('Location: https://' . LBWP_HOST . '/wp-admin/');
  } else {
    header('Location: http://' . LBWP_HOST . '/wp-admin/');
  }
} else {
  // Go back
  header('Location: ' . MASTER_HOST_PROTO . '://' . MASTER_HOST . '/?login=token-mismatch');
  exit;
}
