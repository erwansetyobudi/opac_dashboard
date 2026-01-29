<?php
/**
 * Plugin Name: OPAC Dashboard
 * Plugin URI: https://github.com/erwansetyobudi/opac_dashboard
 * Description: Summary Report in Public
 * Version: 1.0.0
 * Author: Erwan Setyo Budi (erwan817@gmail.com)
 * Author URI: https://github.com/erwansetyobudi/
 */

use SLiMS\Plugins;

$plugins = Plugins::getInstance();

// register path OPAC: index.php?p=opac_dashboard
$plugins->registerMenu('opac', 'opac_dashboard', __DIR__ . '/opac_dashboard.inc.php');
