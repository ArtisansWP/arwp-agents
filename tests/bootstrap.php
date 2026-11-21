<?php
/**
 * Load the decision helpers without WordPress.
 *
 * @package ARWP_Agents
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require dirname( __DIR__ ) . '/includes/class-arwp-usage.php';
require dirname( __DIR__ ) . '/includes/class-arwp-privacy.php';
