<?php
/**
 * Plugin Name: Taisa Image Optimizer
 * Description: Herramientas para revisar tamanos de imagen, detectar uso, limpiar derivados desactivados y localizar originales grandes.
 * Version: 0.2.0
 * Author: Taisa - Raquel Garcia Arevalo
 * Author URI: https://www.taisadigital.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: taisa-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TAISA_IMAGE_OPTIMIZER_VERSION', '0.2.0' );
define( 'TAISA_IMAGE_OPTIMIZER_GITHUB_REPO', 'TaisaDigital/taisa-image-optimizer' );
define( 'TAISA_IMAGE_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAISA_IMAGE_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

require_once TAISA_IMAGE_OPTIMIZER_PATH . 'includes/class-taisa-image-optimizer.php';
require_once TAISA_IMAGE_OPTIMIZER_PATH . 'includes/github-updater.php';

add_action( 'plugins_loaded', array( 'Taisa_Image_Optimizer', 'init' ) );
register_activation_hook( __FILE__, array( 'Taisa_Image_Optimizer', 'activate' ) );

