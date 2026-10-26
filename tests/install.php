<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = 'localhost:8088'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$r = wp_install( 'Tipping Test', 'admin', 'admin@example.com', true, '', 'admin-pass-123' );
update_option( 'siteurl', 'http://localhost:8088' ); update_option( 'home', 'http://localhost:8088' );
update_option( 'timezone_string', 'Australia/Sydney' );
update_option( 'permalink_structure', '' );
echo "installed user " . $r['user_id'] . "\n";
