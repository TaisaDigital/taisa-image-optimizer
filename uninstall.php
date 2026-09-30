<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$options = array(
    'taisa_image_optimizer_settings',
    'taisa_image_optimizer_size_stats',
    'taisa_image_optimizer_usage_stats',
    'taisa_image_optimizer_large_stats',
    'taisa_image_optimizer_cleanup_report',
    'taisa_image_optimizer_resize_report',
);

foreach ( $options as $option_name ) {
    delete_option( $option_name );
}

$meta_keys = array(
    '_taisa_imgopt_usage_status',
    '_taisa_imgopt_usage_sources',
    '_taisa_imgopt_usage_sizes',
    '_taisa_imgopt_large_status',
    '_taisa_imgopt_large_info',
    '_taisa_imgopt_backup_file',
);

foreach ( $meta_keys as $meta_key ) {
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
}

