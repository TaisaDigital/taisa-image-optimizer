<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function taisa_image_optimizer_latest_release() {
    $response = wp_remote_get(
        'https://api.github.com/repos/' . TAISA_IMAGE_OPTIMIZER_GITHUB_REPO . '/releases/latest',
        array(
            'timeout' => 8,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Taisa-Image-Optimizer/' . TAISA_IMAGE_OPTIMIZER_VERSION,
            ),
        )
    );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return false; }
    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $release ) && ! empty( $release['tag_name'] ) ? $release : false;
}

add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {
    if ( ! is_object( $transient ) ) { return $transient; }
    $release = get_transient( 'taisa_image_optimizer_github_release' );
    if ( false === $release ) {
        $release = taisa_image_optimizer_latest_release();
        set_transient( 'taisa_image_optimizer_github_release', $release ? $release : array(), 6 * HOUR_IN_SECONDS );
    }
    if ( empty( $release['tag_name'] ) ) { return $transient; }
    $version = ltrim( (string) $release['tag_name'], 'vV' );
    if ( ! version_compare( $version, TAISA_IMAGE_OPTIMIZER_VERSION, '>' ) ) { return $transient; }

    $package = ! empty( $release['zipball_url'] ) ? $release['zipball_url'] : '';
    if ( ! empty( $release['assets'] ) ) {
        foreach ( $release['assets'] as $asset ) {
            if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
                $package = $asset['browser_download_url'];
                break;
            }
        }
    }
    $plugin_file = plugin_basename( dirname( __DIR__ ) . '/taisa-image-optimizer.php' );
    $transient->response[ $plugin_file ] = (object) array(
        'slug'        => dirname( $plugin_file ),
        'plugin'      => $plugin_file,
        'new_version' => $version,
        'url'         => ! empty( $release['html_url'] ) ? $release['html_url'] : '',
        'package'     => $package,
    );
    return $transient;
} );

