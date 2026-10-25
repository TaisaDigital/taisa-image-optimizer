<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Taisa_Image_Optimizer {
    const SETTINGS_OPTION = 'taisa_image_optimizer_settings';
    const SIZE_STATS_OPTION = 'taisa_image_optimizer_size_stats';
    const USAGE_STATS_OPTION = 'taisa_image_optimizer_usage_stats';
    const LARGE_STATS_OPTION = 'taisa_image_optimizer_large_stats';
    const CLEANUP_REPORT_OPTION = 'taisa_image_optimizer_cleanup_report';
    const RESIZE_REPORT_OPTION = 'taisa_image_optimizer_resize_report';

    const USAGE_STATUS_META = '_taisa_imgopt_usage_status';
    const USAGE_SOURCES_META = '_taisa_imgopt_usage_sources';
    const USAGE_SIZES_META = '_taisa_imgopt_usage_sizes';
    const LARGE_STATUS_META = '_taisa_imgopt_large_status';
    const LARGE_INFO_META = '_taisa_imgopt_large_info';
    const BACKUP_META = '_taisa_imgopt_backup_file';

    public static function init() {
        // Use the same saved size policy as Taisa Tools without replacing an older installation.
        add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'filter_generated_sizes' ), 999 );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
            add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_usage_column' ), 10, 2 );
            add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_usage_column' ) );
            add_action( 'restrict_manage_posts', array( __CLASS__, 'render_media_usage_filter' ) );
            add_action( 'pre_get_posts', array( __CLASS__, 'filter_media_by_usage_status' ) );

            add_action( 'wp_ajax_taisa_imgopt_prepare_size_scan', array( __CLASS__, 'ajax_prepare_size_scan' ) );
            add_action( 'wp_ajax_taisa_imgopt_size_scan_batch', array( __CLASS__, 'ajax_size_scan_batch' ) );
            add_action( 'wp_ajax_taisa_imgopt_prepare_usage_scan', array( __CLASS__, 'ajax_prepare_usage_scan' ) );
            add_action( 'wp_ajax_taisa_imgopt_usage_scan_batch', array( __CLASS__, 'ajax_usage_scan_batch' ) );
            add_action( 'wp_ajax_taisa_imgopt_frontend_scan_batch', array( __CLASS__, 'ajax_frontend_scan_batch' ) );
            add_action( 'wp_ajax_taisa_imgopt_finish_usage_scan', array( __CLASS__, 'ajax_finish_usage_scan' ) );
            add_action( 'wp_ajax_taisa_imgopt_prepare_large_scan', array( __CLASS__, 'ajax_prepare_large_scan' ) );
            add_action( 'wp_ajax_taisa_imgopt_large_scan_batch', array( __CLASS__, 'ajax_large_scan_batch' ) );
            add_action( 'wp_ajax_taisa_imgopt_prepare_cleanup', array( __CLASS__, 'ajax_prepare_cleanup' ) );
            add_action( 'wp_ajax_taisa_imgopt_cleanup_batch', array( __CLASS__, 'ajax_cleanup_batch' ) );
            add_action( 'wp_ajax_taisa_imgopt_prepare_resize', array( __CLASS__, 'ajax_prepare_resize' ) );
            add_action( 'wp_ajax_taisa_imgopt_resize_batch', array( __CLASS__, 'ajax_resize_batch' ) );
        }
    }

    public static function activate() {
        if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
            add_option( self::SETTINGS_OPTION, self::default_settings(), '', false );
        }
    }

    public static function default_settings() {
        return array(
            'include_frontend_scan' => true,
            'large_max_width'       => 2560,
            'large_max_height'      => 2560,
            'large_max_mb'          => 1.5,
            'backup_originals'      => true,
        );
    }

    public static function get_settings() {
        $settings = get_option( self::SETTINGS_OPTION, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $settings = wp_parse_args( $settings, self::default_settings() );
        $settings['include_frontend_scan'] = ! empty( $settings['include_frontend_scan'] );
        $settings['large_max_width'] = max( 1, absint( $settings['large_max_width'] ) );
        $settings['large_max_height'] = max( 1, absint( $settings['large_max_height'] ) );
        $settings['large_max_mb'] = max( 0.1, (float) $settings['large_max_mb'] );
        $settings['backup_originals'] = ! empty( $settings['backup_originals'] );

        return $settings;
    }

    public static function register_admin_page() {
        add_media_page(
            __( 'Taisa Image Optimizer', 'taisa-image-optimizer' ),
            __( 'Optimizador de imagenes', 'taisa-image-optimizer' ),
            'manage_options',
            'taisa-image-optimizer',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function enqueue_admin_assets( $hook ) {
        $is_plugin_page = 'media_page_taisa-image-optimizer' === $hook;
        $is_upload_page = 'upload.php' === $hook;

        if ( ! $is_plugin_page && ! $is_upload_page ) {
            return;
        }

        wp_enqueue_style(
            'taisa-imgopt-admin',
            TAISA_IMAGE_OPTIMIZER_URL . 'assets/admin.css',
            array(),
            TAISA_IMAGE_OPTIMIZER_VERSION
        );

        if ( $is_plugin_page ) {
            wp_enqueue_script(
                'taisa-imgopt-admin',
                TAISA_IMAGE_OPTIMIZER_URL . 'assets/admin.js',
                array( 'jquery' ),
                TAISA_IMAGE_OPTIMIZER_VERSION,
                true
            );

            wp_localize_script(
                'taisa-imgopt-admin',
                'TaisaImgOpt',
                array(
                    'nonce'   => wp_create_nonce( 'taisa_imgopt_admin' ),
                    'strings' => array(
                        'working'      => __( 'Trabajando...', 'taisa-image-optimizer' ),
                        'done'         => __( 'Listo.', 'taisa-image-optimizer' ),
                        'error'        => __( 'No se pudo completar la accion.', 'taisa-image-optimizer' ),
                        'confirmClean' => __( 'Marca la confirmacion antes de borrar derivados.', 'taisa-image-optimizer' ),
                        'confirmResize'=> __( 'Marca la confirmacion antes de redimensionar originales.', 'taisa-image-optimizer' ),
                    ),
                )
            );
        }
    }

    public static function get_disabled_image_sizes() {
        $disabled = get_option( 'taisa_tools_disabled_image_sizes', array() );
        return is_array( $disabled ) ? self::sanitize_size_names( $disabled ) : array();
    }

    public static function filter_generated_sizes( $sizes ) {
        foreach ( self::get_disabled_image_sizes() as $name ) {
            unset( $sizes[ $name ] );
        }
        return $sizes;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'taisa-image-optimizer' ) );
        }

        self::handle_settings_post();
        self::handle_media_policy_post();
        self::handle_single_cleanup_post();
        settings_errors( 'taisa_imgopt' );

        $settings = self::get_settings();
        $sizes = self::get_known_image_sizes();
        $size_stats = self::get_option_array( self::SIZE_STATS_OPTION );
        $usage_stats = self::get_option_array( self::USAGE_STATS_OPTION );
        $large_stats = self::get_option_array( self::LARGE_STATS_OPTION );
        $cleanup_report = self::get_option_array( self::CLEANUP_REPORT_OPTION );
        $resize_report = self::get_option_array( self::RESIZE_REPORT_OPTION );

        ?>
        <div class="wrap taisa-imgopt-wrap">
            <h1><?php echo esc_html__( 'Taisa Image Optimizer', 'taisa-image-optimizer' ); ?></h1>

            <div class="taisa-imgopt-grid">
                <div class="taisa-imgopt-card">
                    <h2><?php echo esc_html__( 'Escaneos', 'taisa-image-optimizer' ); ?></h2>
                    <p><?php echo esc_html__( 'Lanza escaneos por lotes para no bloquear el admin en bibliotecas grandes.', 'taisa-image-optimizer' ); ?></p>
                    <div class="taisa-imgopt-actions">
                        <button type="button" class="button button-primary" data-taisa-action="size-scan"><?php echo esc_html__( 'Escanear tamanos generados', 'taisa-image-optimizer' ); ?></button>
                        <button type="button" class="button" data-taisa-action="usage-scan"><?php echo esc_html__( 'Escanear uso de imagenes', 'taisa-image-optimizer' ); ?></button>
                        <button type="button" class="button" data-taisa-action="large-scan"><?php echo esc_html__( 'Detectar originales grandes', 'taisa-image-optimizer' ); ?></button>
                    </div>
                    <div class="taisa-imgopt-progress" id="taisa-imgopt-progress" aria-live="polite"></div>
                </div>

                <div class="taisa-imgopt-card">
                    <h2><?php echo esc_html__( 'Acciones seguras', 'taisa-image-optimizer' ); ?></h2>
                    <p><?php echo esc_html__( 'Estas acciones modifican archivos. Revisa los resultados del escaneo antes de ejecutarlas. El favicon y el icono del sitio están protegidos.', 'taisa-image-optimizer' ); ?></p>
                    <label class="taisa-imgopt-confirm">
                        <input type="checkbox" id="taisa-confirm-cleanup">
                        <?php echo esc_html__( 'Confirmo que quiero borrar derivados de tamanos desactivados.', 'taisa-image-optimizer' ); ?>
                    </label>
                    <button type="button" class="button button-secondary" data-taisa-action="cleanup-disabled"><?php echo esc_html__( 'Borrar derivados de tamaños desactivados', 'taisa-image-optimizer' ); ?></button>

                    <label class="taisa-imgopt-confirm">
                        <input type="checkbox" id="taisa-confirm-resize">
                        <?php echo esc_html__( 'Confirmo que quiero redimensionar originales grandes.', 'taisa-image-optimizer' ); ?>
                    </label>
                    <button type="button" class="button button-secondary" data-taisa-action="resize-large"><?php echo esc_html__( 'Redimensionar originales grandes', 'taisa-image-optimizer' ); ?></button>
                </div>
            </div>

            <?php self::render_summary_cards( $size_stats, $usage_stats, $large_stats, $cleanup_report, $resize_report ); ?>
            <?php self::render_large_candidates_table(); ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'taisa_imgopt_save_settings', 'taisa_imgopt_settings_nonce' ); ?>
                <input type="hidden" name="taisa_imgopt_action" value="save_settings">

                <div class="taisa-imgopt-card">
                    <h2><?php echo esc_html__( 'Tamanos de medios', 'taisa-image-optimizer' ); ?></h2>
                    <p><?php echo esc_html__( 'Revisa los archivos generados y el uso detectado antes de limpiar derivados. La configuración de tamaños futuros aparece más abajo.', 'taisa-image-optimizer' ); ?></p>
                    <?php self::render_sizes_table( $sizes, $settings, $size_stats, $usage_stats ); ?>
                </div>

                <div class="taisa-imgopt-card">
                    <h2><?php echo esc_html__( 'Escaneo de uso y originales grandes', 'taisa-image-optimizer' ); ?></h2>
                    <div class="taisa-imgopt-settings-grid">
                        <label>
                            <input type="checkbox" name="include_frontend_scan" value="1" <?php checked( $settings['include_frontend_scan'] ); ?>>
                            <?php echo esc_html__( 'Incluir escaneo de URLs publicas', 'taisa-image-optimizer' ); ?>
                        </label>
                        <label>
                            <?php echo esc_html__( 'Ancho maximo original', 'taisa-image-optimizer' ); ?>
                            <input type="number" min="1" name="large_max_width" value="<?php echo esc_attr( $settings['large_max_width'] ); ?>">
                        </label>
                        <label>
                            <?php echo esc_html__( 'Alto maximo original', 'taisa-image-optimizer' ); ?>
                            <input type="number" min="1" name="large_max_height" value="<?php echo esc_attr( $settings['large_max_height'] ); ?>">
                        </label>
                        <label>
                            <?php echo esc_html__( 'Peso maximo original (MB)', 'taisa-image-optimizer' ); ?>
                            <input type="number" min="0.1" step="0.1" name="large_max_mb" value="<?php echo esc_attr( $settings['large_max_mb'] ); ?>">
                        </label>
                        <label>
                            <input type="checkbox" name="backup_originals" value="1" <?php checked( $settings['backup_originals'] ); ?>>
                            <?php echo esc_html__( 'Crear copia antes de redimensionar originales', 'taisa-image-optimizer' ); ?>
                        </label>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo esc_html__( 'Guardar ajustes', 'taisa-image-optimizer' ); ?></button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&taisa_imgopt_usage_filter=unused' ) ); ?>"><?php echo esc_html__( 'Ver imagenes no detectadas en Medios', 'taisa-image-optimizer' ); ?></a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&taisa_imgopt_usage_filter=used' ) ); ?>"><?php echo esc_html__( 'Ver imagenes usadas en Medios', 'taisa-image-optimizer' ); ?></a>
                </p>
            </form>

            <div class="taisa-imgopt-card">
                <h2><?php echo esc_html__( 'Generación futura de variantes', 'taisa-image-optimizer' ); ?></h2>
                <p><?php echo esc_html__( 'Desmarca los tamaños que no quieras crear en nuevas subidas. La miniatura de catálogo puede conservarse.', 'taisa-image-optimizer' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'taisa_imgopt_media_policy', 'taisa_imgopt_media_nonce' ); ?>
                    <input type="hidden" name="taisa_imgopt_action" value="save_media_policy">
                    <?php foreach ( get_intermediate_image_sizes() as $name ) : ?>
                        <label style="display:block"><input type="checkbox" name="enabled_sizes[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! in_array( $name, self::get_disabled_image_sizes(), true ) ); ?>> <?php echo esc_html( $name ); ?></label>
                    <?php endforeach; ?>
                    <p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Guardar tamaños futuros', 'taisa-image-optimizer' ); ?></button></p>
                </form>
            </div>

            <div class="taisa-imgopt-card">
                <h2><?php echo esc_html__( 'Prueba con una imagen', 'taisa-image-optimizer' ); ?></h2>
                <p><?php echo esc_html__( 'Borra una sola variante registrada y actualiza sus metadatos. La imagen original se conserva.', 'taisa-image-optimizer' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'taisa_imgopt_single_cleanup', 'taisa_imgopt_single_nonce' ); ?>
                    <input type="hidden" name="taisa_imgopt_action" value="delete_single_size">
                    <label><?php echo esc_html__( 'ID del medio', 'taisa-image-optimizer' ); ?> <input type="number" min="1" name="attachment_id" required></label>
                    <label><?php echo esc_html__( 'Nombre del tamaño', 'taisa-image-optimizer' ); ?> <input type="text" name="size_name" required></label>
                    <p><button type="submit" class="button button-secondary"><?php echo esc_html__( 'Borrar solo esta variante', 'taisa-image-optimizer' ); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_large_candidates_table() {
        $ids = self::get_flagged_large_ids( 10 );
        if ( empty( $ids ) ) {
            return;
        }

        ?>
        <div class="taisa-imgopt-card">
            <h2><?php echo esc_html__( 'Originales grandes detectados', 'taisa-image-optimizer' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( 'Imagen', 'taisa-image-optimizer' ); ?></th>
                        <th><?php echo esc_html__( 'Dimensiones', 'taisa-image-optimizer' ); ?></th>
                        <th><?php echo esc_html__( 'Peso', 'taisa-image-optimizer' ); ?></th>
                        <th><?php echo esc_html__( 'Motivo', 'taisa-image-optimizer' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $ids as $attachment_id ) : ?>
                        <?php
                        $info = get_post_meta( $attachment_id, self::LARGE_INFO_META, true );
                        if ( ! is_array( $info ) ) {
                            $info = array();
                        }
                        $status = get_post_meta( $attachment_id, self::LARGE_STATUS_META, true );
                        $reason = implode( ', ', isset( $info['reasons'] ) && is_array( $info['reasons'] ) ? $info['reasons'] : array() );
                        if ( 'weight_only' === $status ) {
                            $reason .= ' - pendiente de compresion';
                        }
                        ?>
                        <tr>
                            <td>
                                <?php echo wp_get_attachment_image( $attachment_id, array( 48, 48 ), true ); ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $attachment_id ) ); ?>"><?php echo esc_html( get_the_title( $attachment_id ) ); ?></a>
                            </td>
                            <td><?php echo esc_html( sprintf( '%1$s x %2$s', absint( $info['width'] ?? 0 ), absint( $info['height'] ?? 0 ) ) ); ?></td>
                            <td><?php echo esc_html( self::format_bytes( absint( $info['filesize'] ?? 0 ) ) ); ?></td>
                            <td><?php echo esc_html( $reason ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__( 'Se muestran hasta 10 candidatos. El redimensionado procesa todos los pendientes marcados por el ultimo escaneo.', 'taisa-image-optimizer' ); ?></p>
        </div>
        <?php
    }

    private static function handle_settings_post() {
        if ( empty( $_POST['taisa_imgopt_action'] ) || 'save_settings' !== sanitize_key( wp_unslash( $_POST['taisa_imgopt_action'] ) ) ) {
            return;
        }

        if ( empty( $_POST['taisa_imgopt_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taisa_imgopt_settings_nonce'] ) ), 'taisa_imgopt_save_settings' ) ) {
            add_settings_error( 'taisa_imgopt', 'bad_nonce', __( 'No se pudieron guardar los ajustes.', 'taisa-image-optimizer' ), 'error' );
            return;
        }

        $large_mb = isset( $_POST['large_max_mb'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['large_max_mb'] ) ) ) : '1.5';

        $settings = array(
            'include_frontend_scan' => ! empty( $_POST['include_frontend_scan'] ),
            'large_max_width'       => isset( $_POST['large_max_width'] ) ? max( 1, absint( $_POST['large_max_width'] ) ) : 2560,
            'large_max_height'      => isset( $_POST['large_max_height'] ) ? max( 1, absint( $_POST['large_max_height'] ) ) : 2560,
            'large_max_mb'          => max( 0.1, (float) $large_mb ),
            'backup_originals'      => ! empty( $_POST['backup_originals'] ),
        );

        update_option( self::SETTINGS_OPTION, $settings, false );
        add_settings_error( 'taisa_imgopt', 'saved', __( 'Ajustes guardados.', 'taisa-image-optimizer' ), 'updated' );
    }

    private static function handle_media_policy_post() {
        if ( ! isset( $_POST['taisa_imgopt_action'] ) || 'save_media_policy' !== sanitize_key( wp_unslash( $_POST['taisa_imgopt_action'] ) ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['taisa_imgopt_media_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taisa_imgopt_media_nonce'] ) ), 'taisa_imgopt_media_policy' ) ) {
            add_settings_error( 'taisa_imgopt', 'media_denied', __( 'No se pudo guardar la configuración de tamaños.', 'taisa-image-optimizer' ), 'error' );
            return;
        }
        $registered = get_intermediate_image_sizes();
        $enabled = isset( $_POST['enabled_sizes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['enabled_sizes'] ) ) : array();
        update_option( 'taisa_tools_disabled_image_sizes', array_values( array_diff( $registered, $enabled ) ), false );
        add_settings_error( 'taisa_imgopt', 'media_saved', __( 'Tamaños futuros actualizados.', 'taisa-image-optimizer' ), 'updated' );
    }

    private static function handle_single_cleanup_post() {
        if ( ! isset( $_POST['taisa_imgopt_action'] ) || 'delete_single_size' !== sanitize_key( wp_unslash( $_POST['taisa_imgopt_action'] ) ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['taisa_imgopt_single_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taisa_imgopt_single_nonce'] ) ), 'taisa_imgopt_single_cleanup' ) ) {
            add_settings_error( 'taisa_imgopt', 'single_denied', __( 'No se pudo autorizar la limpieza individual.', 'taisa-image-optimizer' ), 'error' );
            return;
        }
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( self::is_protected_favicon_attachment( $attachment_id ) ) {
            add_settings_error( 'taisa_imgopt', 'single_protected', __( 'No se puede borrar una variante del favicon o icono del sitio protegido.', 'taisa-image-optimizer' ), 'error' );
            return;
        }
        $size_name = isset( $_POST['size_name'] ) ? sanitize_key( wp_unslash( $_POST['size_name'] ) ) : '';
        $metadata = $attachment_id ? wp_get_attachment_metadata( $attachment_id ) : array();
        if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) || empty( $metadata['sizes'][ $size_name ] ) ) {
            add_settings_error( 'taisa_imgopt', 'single_missing', __( 'No existe esa variante registrada para el medio indicado.', 'taisa-image-optimizer' ), 'error' );
            return;
        }
        $result = self::delete_disabled_derivatives( $attachment_id, array( $size_name ) );
        add_settings_error( 'taisa_imgopt', 'single_result', sprintf( __( 'Prueba: %1$d archivo borrado, %2$d referencia conservada, %3$d errores.', 'taisa-image-optimizer' ), $result['deleted'], $result['retained'], $result['errors'] ), $result['errors'] ? 'error' : 'updated' );
    }

    private static function render_summary_cards( $size_stats, $usage_stats, $large_stats, $cleanup_report, $resize_report ) {
        $size_total = isset( $size_stats['processed'] ) ? absint( $size_stats['processed'] ) : 0;
        $size_bytes = isset( $size_stats['bytes'] ) ? absint( $size_stats['bytes'] ) : 0;
        $used = isset( $usage_stats['used'] ) ? absint( $usage_stats['used'] ) : 0;
        $unused = isset( $usage_stats['unused'] ) ? absint( $usage_stats['unused'] ) : 0;
        $large = isset( $large_stats['large'] ) ? absint( $large_stats['large'] ) : 0;
        ?>
        <div class="taisa-imgopt-summary">
            <div><strong><?php echo esc_html( number_format_i18n( $size_total ) ); ?></strong><span><?php echo esc_html__( 'imagenes revisadas en tamanos', 'taisa-image-optimizer' ); ?></span></div>
            <div><strong><?php echo esc_html( self::format_bytes( $size_bytes ) ); ?></strong><span><?php echo esc_html__( 'en derivados detectados', 'taisa-image-optimizer' ); ?></span></div>
            <div><strong><?php echo esc_html( number_format_i18n( $used ) ); ?></strong><span><?php echo esc_html__( 'imagenes usadas', 'taisa-image-optimizer' ); ?></span></div>
            <div><strong><?php echo esc_html( number_format_i18n( $unused ) ); ?></strong><span><?php echo esc_html__( 'sin uso detectado', 'taisa-image-optimizer' ); ?></span></div>
            <div><strong><?php echo esc_html( number_format_i18n( $large ) ); ?></strong><span><?php echo esc_html__( 'originales grandes', 'taisa-image-optimizer' ); ?></span></div>
        </div>
        <?php if ( ! empty( $cleanup_report['completed'] ) || ! empty( $resize_report['completed'] ) ) : ?>
            <div class="taisa-imgopt-card taisa-imgopt-report">
                <?php if ( ! empty( $cleanup_report['completed'] ) ) : ?>
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                __( 'Ultima limpieza: %1$s archivos borrados, %2$s referencias compartidas conservadas, %3$s liberados.', 'taisa-image-optimizer' ),
                                number_format_i18n( absint( $cleanup_report['deleted'] ?? 0 ) ),
                                number_format_i18n( absint( $cleanup_report['retained'] ?? 0 ) ),
                                self::format_bytes( absint( $cleanup_report['bytes'] ?? 0 ) )
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ( ! empty( $resize_report['completed'] ) ) : ?>
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                __( 'Ultimo redimensionado: %1$s originales actualizados, %2$s errores.', 'taisa-image-optimizer' ),
                                number_format_i18n( absint( $resize_report['resized'] ?? 0 ) ),
                                number_format_i18n( absint( $resize_report['errors'] ?? 0 ) )
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function render_sizes_table( $sizes, $settings, $size_stats, $usage_stats ) {
        $disabled = self::get_disabled_image_sizes();
        $stats_by_size = isset( $size_stats['sizes'] ) && is_array( $size_stats['sizes'] ) ? $size_stats['sizes'] : array();
        $usage_by_size = isset( $usage_stats['sizes'] ) && is_array( $usage_stats['sizes'] ) ? $usage_stats['sizes'] : array();
        ?>
        <table class="widefat striped taisa-imgopt-size-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Generación', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Tamano', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Dimensiones', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Origen', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Archivos', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Espacio', 'taisa-image-optimizer' ); ?></th>
                    <th><?php echo esc_html__( 'Uso detectado', 'taisa-image-optimizer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sizes as $name => $size ) : ?>
                    <?php
                    $row_stats = isset( $stats_by_size[ $name ] ) && is_array( $stats_by_size[ $name ] ) ? $stats_by_size[ $name ] : array();
                    $row_usage = isset( $usage_by_size[ $name ] ) && is_array( $usage_by_size[ $name ] ) ? $usage_by_size[ $name ] : array();
                    $files = absint( $row_stats['exists'] ?? 0 );
                    $bytes = absint( $row_stats['bytes'] ?? 0 );
                    $used_database = absint( $row_usage['database'] ?? 0 );
                    $used_frontend = absint( $row_usage['frontend'] ?? 0 );
                    ?>
                    <tr>
                        <td><?php echo in_array( $name, $disabled, true ) ? esc_html__( 'Desactivado', 'taisa-image-optimizer' ) : esc_html__( 'Activo', 'taisa-image-optimizer' ); ?></td>
                        <td>
                            <code><?php echo esc_html( $name ); ?></code>
                        </td>
                        <td><?php echo esc_html( self::format_dimensions( $size ) ); ?></td>
                        <td><?php echo esc_html( $size['source'] ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $files ) ); ?></td>
                        <td><?php echo esc_html( self::format_bytes( $bytes ) ); ?></td>
                        <td>
                            <?php
                            if ( $used_database || $used_frontend ) {
                                echo esc_html(
                                    sprintf(
                                        __( 'DB: %1$s / Web: %2$s', 'taisa-image-optimizer' ),
                                        number_format_i18n( $used_database ),
                                        number_format_i18n( $used_frontend )
                                    )
                                );
                            } else {
                                echo esc_html__( 'Sin referencias', 'taisa-image-optimizer' );
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( empty( $size_stats['completed'] ) ) : ?>
            <p class="description"><?php echo esc_html__( 'Ejecuta "Escanear tamanos generados" para ver archivos y espacio ocupado.', 'taisa-image-optimizer' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public static function add_media_usage_column( $columns ) {
        $columns['taisa_imgopt_usage'] = __( 'Uso detectado', 'taisa-image-optimizer' );
        return $columns;
    }

    public static function render_media_usage_column( $column_name, $attachment_id ) {
        if ( 'taisa_imgopt_usage' !== $column_name ) {
            return;
        }

        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            echo '&mdash;';
            return;
        }

        $status = get_post_meta( $attachment_id, self::USAGE_STATUS_META, true );
        $sources = get_post_meta( $attachment_id, self::USAGE_SOURCES_META, true );
        if ( ! is_array( $sources ) ) {
            $sources = array();
        }

        if ( 'used' === $status ) {
            echo '<span class="taisa-imgopt-badge ok">' . esc_html__( 'Usada', 'taisa-image-optimizer' ) . '</span>';
        } elseif ( 'unused' === $status ) {
            echo '<span class="taisa-imgopt-badge warning">' . esc_html__( 'No detectada', 'taisa-image-optimizer' ) . '</span>';
        } else {
            echo '<span class="taisa-imgopt-badge muted">' . esc_html__( 'Sin escanear', 'taisa-image-optimizer' ) . '</span>';
        }

        if ( ! empty( $sources ) ) {
            echo '<details class="taisa-imgopt-sources"><summary>' . esc_html( sprintf( __( '%s ubicaciones', 'taisa-image-optimizer' ), number_format_i18n( count( $sources ) ) ) ) . '</summary><ul>';
            foreach ( array_slice( $sources, 0, 8 ) as $source ) {
                $label = isset( $source['label'] ) ? $source['label'] : '';
                $url = isset( $source['url'] ) ? $source['url'] : '';
                echo '<li>';
                if ( $url ) {
                    echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
                } else {
                    echo esc_html( $label );
                }
                echo '</li>';
            }
            echo '</ul></details>';
        }
    }

    public static function render_media_usage_filter() {
        global $pagenow;

        if ( 'upload.php' !== $pagenow ) {
            return;
        }

        $current = isset( $_GET['taisa_imgopt_usage_filter'] ) ? sanitize_key( wp_unslash( $_GET['taisa_imgopt_usage_filter'] ) ) : '';
        ?>
        <select name="taisa_imgopt_usage_filter">
            <option value=""><?php echo esc_html__( 'Uso de imagen: todos', 'taisa-image-optimizer' ); ?></option>
            <option value="used" <?php selected( $current, 'used' ); ?>><?php echo esc_html__( 'Usadas: si', 'taisa-image-optimizer' ); ?></option>
            <option value="unused" <?php selected( $current, 'unused' ); ?>><?php echo esc_html__( 'Usadas: no detectadas', 'taisa-image-optimizer' ); ?></option>
            <option value="unknown" <?php selected( $current, 'unknown' ); ?>><?php echo esc_html__( 'Sin escanear', 'taisa-image-optimizer' ); ?></option>
        </select>
        <?php
    }

    public static function filter_media_by_usage_status( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( 'upload.php' !== $pagenow ) {
            return;
        }

        $status = isset( $_GET['taisa_imgopt_usage_filter'] ) ? sanitize_key( wp_unslash( $_GET['taisa_imgopt_usage_filter'] ) ) : '';
        if ( ! in_array( $status, array( 'used', 'unused', 'unknown' ), true ) ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( 'unknown' === $status ) {
            $meta_query[] = array(
                'key'     => self::USAGE_STATUS_META,
                'compare' => 'NOT EXISTS',
            );
        } else {
            $meta_query[] = array(
                'key'     => self::USAGE_STATUS_META,
                'value'   => $status,
                'compare' => '=',
            );
        }
        $query->set( 'meta_query', $meta_query );
    }

    public static function ajax_prepare_size_scan() {
        self::ajax_guard();

        $total = self::count_image_attachments();
        update_option(
            self::SIZE_STATS_OPTION,
            array(
                'started'   => current_time( 'mysql' ),
                'completed' => '',
                'total'     => $total,
                'processed' => 0,
                'bytes'     => 0,
                'sizes'     => array(),
            ),
            false
        );

        wp_send_json_success(
            array(
                'total' => $total,
                'limit' => 25,
            )
        );
    }

    public static function ajax_size_scan_batch() {
        self::ajax_guard();

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 100, absint( $_POST['limit'] ) ) ) : 25;
        $ids = self::get_image_attachment_ids( $offset, $limit );
        $stats = self::get_option_array( self::SIZE_STATS_OPTION );
        $processed = 0;
        $bytes = absint( $stats['bytes'] ?? 0 );
        $sizes = isset( $stats['sizes'] ) && is_array( $stats['sizes'] ) ? $stats['sizes'] : array();

        foreach ( $ids as $attachment_id ) {
            $processed++;
            $file = get_attached_file( $attachment_id );
            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( empty( $file ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
                continue;
            }

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $sizes[ $size_name ] ) ) {
                    $sizes[ $size_name ] = array(
                        'width'     => absint( $size_data['width'] ?? 0 ),
                        'height'    => absint( $size_data['height'] ?? 0 ),
                        'generated' => 0,
                        'exists'    => 0,
                        'missing'   => 0,
                        'bytes'     => 0,
                    );
                }

                $sizes[ $size_name ]['generated']++;
                $path = self::get_size_file_path( $file, $size_data );
                if ( $path && file_exists( $path ) && self::is_path_in_uploads( $path ) ) {
                    $file_size = (int) filesize( $path );
                    $sizes[ $size_name ]['exists']++;
                    $sizes[ $size_name ]['bytes'] += $file_size;
                    $bytes += $file_size;
                } else {
                    $sizes[ $size_name ]['missing']++;
                }
            }
        }

        $done = count( $ids ) < $limit;
        $stats['processed'] = absint( $stats['processed'] ?? 0 ) + $processed;
        $stats['bytes'] = $bytes;
        $stats['sizes'] = $sizes;
        if ( $done ) {
            $stats['completed'] = current_time( 'mysql' );
        }
        update_option( self::SIZE_STATS_OPTION, $stats, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'done'      => $done,
                'message'   => sprintf( __( '%s imagenes revisadas.', 'taisa-image-optimizer' ), number_format_i18n( absint( $stats['processed'] ) ) ),
            )
        );
    }

    public static function ajax_prepare_usage_scan() {
        self::ajax_guard();

        global $wpdb;
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::USAGE_STATUS_META ) );
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::USAGE_SOURCES_META ) );
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::USAGE_SIZES_META ) );

        $settings = self::get_settings();
        $total = self::count_image_attachments();
        $url_total = $settings['include_frontend_scan'] ? self::count_public_scan_urls() : 0;

        update_option(
            self::USAGE_STATS_OPTION,
            array(
                'started'            => current_time( 'mysql' ),
                'completed'          => '',
                'total'              => $total,
                'processed'          => 0,
                'used'               => 0,
                'unused'             => 0,
                'frontend_total'     => $url_total,
                'frontend_processed' => 0,
                'sizes'              => array(),
            ),
            false
        );

        wp_send_json_success(
            array(
                'total'     => $total,
                'url_total' => $url_total,
                'limit'     => 10,
                'url_limit' => 5,
            )
        );
    }

    public static function ajax_usage_scan_batch() {
        self::ajax_guard();

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 50, absint( $_POST['limit'] ) ) ) : 10;
        $ids = self::get_image_attachment_ids( $offset, $limit );
        $stats = self::get_option_array( self::USAGE_STATS_OPTION );
        $processed = 0;
        $used = 0;
        $unused = 0;
        $size_hits = array();

        foreach ( $ids as $attachment_id ) {
            $processed++;
            $result = self::find_attachment_usage( $attachment_id );
            $sources = self::sanitize_sources( $result['sources'] );
            $used_sizes = self::sanitize_size_names( array_keys( $result['sizes'] ) );

            if ( ! empty( $sources ) ) {
                update_post_meta( $attachment_id, self::USAGE_STATUS_META, 'used' );
                update_post_meta( $attachment_id, self::USAGE_SOURCES_META, $sources );
                update_post_meta( $attachment_id, self::USAGE_SIZES_META, $used_sizes );
                $used++;
            } else {
                update_post_meta( $attachment_id, self::USAGE_STATUS_META, 'unused' );
                update_post_meta( $attachment_id, self::USAGE_SOURCES_META, array() );
                update_post_meta( $attachment_id, self::USAGE_SIZES_META, array() );
                $unused++;
            }

            foreach ( $used_sizes as $size_name ) {
                if ( 'original' === $size_name ) {
                    continue;
                }
                if ( empty( $size_hits[ $size_name ] ) ) {
                    $size_hits[ $size_name ] = 0;
                }
                $size_hits[ $size_name ]++;
            }
        }

        $stats['processed'] = absint( $stats['processed'] ?? 0 ) + $processed;
        $stats['used'] = absint( $stats['used'] ?? 0 ) + $used;
        $stats['unused'] = absint( $stats['unused'] ?? 0 ) + $unused;
        $stats['sizes'] = self::merge_usage_size_hits( $stats['sizes'] ?? array(), $size_hits, 'database' );
        update_option( self::USAGE_STATS_OPTION, $stats, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'done'      => count( $ids ) < $limit,
                'message'   => sprintf( __( '%s imagenes revisadas para uso.', 'taisa-image-optimizer' ), number_format_i18n( absint( $stats['processed'] ) ) ),
            )
        );
    }

    public static function ajax_frontend_scan_batch() {
        self::ajax_guard();

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 20, absint( $_POST['limit'] ) ) ) : 5;
        $urls = self::get_public_scan_urls( $offset, $limit );
        $stats = self::get_option_array( self::USAGE_STATS_OPTION );
        $processed = 0;
        $promoted = 0;
        $size_hits = array();

        foreach ( $urls as $url ) {
            $processed++;
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'     => 8,
                    'redirection' => 3,
                    'user-agent'  => 'Taisa Image Optimizer/' . TAISA_IMAGE_OPTIMIZER_VERSION,
                )
            );

            if ( is_wp_error( $response ) ) {
                continue;
            }

            $html = wp_remote_retrieve_body( $response );
            if ( ! $html ) {
                continue;
            }

            $asset_urls = self::extract_image_urls_from_html( $html, $url );
            foreach ( $asset_urls as $asset_url ) {
                $attachment_id = self::attachment_id_from_asset_url( $asset_url );
                if ( ! $attachment_id ) {
                    continue;
                }

                $size_name = self::detect_size_from_asset_url( $attachment_id, $asset_url );
                if ( self::mark_attachment_used_from_frontend( $attachment_id, $url, $asset_url, $size_name ) ) {
                    $promoted++;
                }
                if ( $size_name && 'original' !== $size_name ) {
                    if ( empty( $size_hits[ $size_name ] ) ) {
                        $size_hits[ $size_name ] = 0;
                    }
                    $size_hits[ $size_name ]++;
                }
            }
        }

        $stats['frontend_processed'] = absint( $stats['frontend_processed'] ?? 0 ) + $processed;
        $stats['used'] = absint( $stats['used'] ?? 0 ) + $promoted;
        $stats['unused'] = max( 0, absint( $stats['unused'] ?? 0 ) - $promoted );
        $stats['sizes'] = self::merge_usage_size_hits( $stats['sizes'] ?? array(), $size_hits, 'frontend' );
        update_option( self::USAGE_STATS_OPTION, $stats, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'done'      => count( $urls ) < $limit,
                'message'   => sprintf( __( '%s URLs publicas revisadas.', 'taisa-image-optimizer' ), number_format_i18n( absint( $stats['frontend_processed'] ) ) ),
            )
        );
    }

    public static function ajax_finish_usage_scan() {
        self::ajax_guard();

        $stats = self::get_option_array( self::USAGE_STATS_OPTION );
        $stats['completed'] = current_time( 'mysql' );
        update_option( self::USAGE_STATS_OPTION, $stats, false );

        wp_send_json_success(
            array(
                'message' => __( 'Escaneo de uso completado.', 'taisa-image-optimizer' ),
            )
        );
    }

    public static function ajax_prepare_large_scan() {
        self::ajax_guard();

        $total = self::count_image_attachments();
        update_option(
            self::LARGE_STATS_OPTION,
            array(
                'started'   => current_time( 'mysql' ),
                'completed' => '',
                'total'     => $total,
                'processed' => 0,
                'large'     => 0,
                'resizeable' => 0,
                'bytes'     => 0,
            ),
            false
        );

        wp_send_json_success(
            array(
                'total' => $total,
                'limit' => 25,
            )
        );
    }

    public static function ajax_large_scan_batch() {
        self::ajax_guard();

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 100, absint( $_POST['limit'] ) ) ) : 25;
        $ids = self::get_image_attachment_ids( $offset, $limit );
        $settings = self::get_settings();
        $stats = self::get_option_array( self::LARGE_STATS_OPTION );
        $processed = 0;
        $large = 0;
        $bytes = 0;

        foreach ( $ids as $attachment_id ) {
            $processed++;
            $result = self::inspect_large_attachment( $attachment_id, $settings );
            if ( ! $result['large'] ) {
                $status = 'no';
            } elseif ( $result['resizeable'] ) {
                $status = 'yes';
            } else {
                $status = 'weight_only';
            }
            update_post_meta( $attachment_id, self::LARGE_STATUS_META, $status );
            update_post_meta( $attachment_id, self::LARGE_INFO_META, $result );

            if ( $result['large'] ) {
                $large++;
                $bytes += absint( $result['filesize'] ?? 0 );
            }
            if ( $result['resizeable'] ) {
                $stats['resizeable'] = absint( $stats['resizeable'] ?? 0 ) + 1;
            }
        }

        $done = count( $ids ) < $limit;
        $stats['processed'] = absint( $stats['processed'] ?? 0 ) + $processed;
        $stats['large'] = absint( $stats['large'] ?? 0 ) + $large;
        $stats['bytes'] = absint( $stats['bytes'] ?? 0 ) + $bytes;
        if ( $done ) {
            $stats['completed'] = current_time( 'mysql' );
        }
        update_option( self::LARGE_STATS_OPTION, $stats, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'done'      => $done,
                'message'   => sprintf( __( '%s imagenes revisadas para originales grandes.', 'taisa-image-optimizer' ), number_format_i18n( absint( $stats['processed'] ) ) ),
            )
        );
    }

    public static function ajax_prepare_cleanup() {
        self::ajax_guard();

        if ( empty( self::get_disabled_image_sizes() ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay tamaños desactivados. Configúralos en esta pantalla antes de limpiar.', 'taisa-image-optimizer' ) ) );
        }

        $total = self::count_image_attachments();
        update_option(
            self::CLEANUP_REPORT_OPTION,
            array(
                'started'   => current_time( 'mysql' ),
                'completed' => '',
                'total'     => $total,
                'processed' => 0,
                'deleted'   => 0,
                'retained'  => 0,
                'bytes'     => 0,
                'errors'    => 0,
            ),
            false
        );

        wp_send_json_success(
            array(
                'total' => $total,
                'limit' => 20,
            )
        );
    }

    public static function ajax_cleanup_batch() {
        self::ajax_guard();

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 50, absint( $_POST['limit'] ) ) ) : 20;
        $ids = self::get_image_attachment_ids( $offset, $limit );
        $disabled = self::get_disabled_image_sizes();
        $report = self::get_option_array( self::CLEANUP_REPORT_OPTION );
        $processed = 0;
        $deleted = 0;
        $retained = 0;
        $bytes = 0;
        $errors = 0;

        foreach ( $ids as $attachment_id ) {
            $processed++;
            $result = self::delete_disabled_derivatives( $attachment_id, $disabled );
            $deleted += $result['deleted'];
            $retained += $result['retained'];
            $bytes += $result['bytes'];
            $errors += $result['errors'];
        }

        $done = count( $ids ) < $limit;
        $report['processed'] = absint( $report['processed'] ?? 0 ) + $processed;
        $report['deleted'] = absint( $report['deleted'] ?? 0 ) + $deleted;
        $report['retained'] = absint( $report['retained'] ?? 0 ) + $retained;
        $report['bytes'] = absint( $report['bytes'] ?? 0 ) + $bytes;
        $report['errors'] = absint( $report['errors'] ?? 0 ) + $errors;
        if ( $done ) {
            $report['completed'] = current_time( 'mysql' );
        }
        update_option( self::CLEANUP_REPORT_OPTION, $report, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'done'      => $done,
                'message'   => sprintf( __( '%1$s archivos borrados, %2$s referencias compartidas conservadas, %3$s liberados.', 'taisa-image-optimizer' ), number_format_i18n( absint( $report['deleted'] ) ), number_format_i18n( absint( $report['retained'] ) ), self::format_bytes( absint( $report['bytes'] ) ) ),
            )
        );
    }

    public static function ajax_prepare_resize() {
        self::ajax_guard();

        $total = self::count_large_candidates();
        if ( ! $total ) {
            wp_send_json_error( array( 'message' => __( 'No hay originales grandes pendientes.', 'taisa-image-optimizer' ) ) );
        }

        update_option(
            self::RESIZE_REPORT_OPTION,
            array(
                'started'   => current_time( 'mysql' ),
                'completed' => '',
                'total'     => $total,
                'processed' => 0,
                'resized'   => 0,
                'errors'    => 0,
            ),
            false
        );

        wp_send_json_success(
            array(
                'total' => $total,
                'limit' => 5,
            )
        );
    }

    public static function ajax_resize_batch() {
        self::ajax_guard();

        $limit = isset( $_POST['limit'] ) ? max( 1, min( 10, absint( $_POST['limit'] ) ) ) : 5;
        $ids = self::get_large_candidate_ids( $limit );
        $settings = self::get_settings();
        $report = self::get_option_array( self::RESIZE_REPORT_OPTION );
        $processed = 0;
        $resized = 0;
        $errors = 0;

        foreach ( $ids as $attachment_id ) {
            $processed++;
            $result = self::resize_large_attachment( $attachment_id, $settings );
            if ( $result['success'] ) {
                $resized++;
                update_post_meta( $attachment_id, self::LARGE_STATUS_META, 'no' );
            } else {
                $errors++;
                update_post_meta( $attachment_id, self::LARGE_STATUS_META, 'error' );
            }
            update_post_meta( $attachment_id, self::LARGE_INFO_META, $result );
        }

        $remaining = self::count_large_candidates();
        $report['processed'] = absint( $report['processed'] ?? 0 ) + $processed;
        $report['resized'] = absint( $report['resized'] ?? 0 ) + $resized;
        $report['errors'] = absint( $report['errors'] ?? 0 ) + $errors;
        if ( 0 === $remaining ) {
            $report['completed'] = current_time( 'mysql' );
        }
        update_option( self::RESIZE_REPORT_OPTION, $report, false );

        wp_send_json_success(
            array(
                'processed' => $processed,
                'remaining' => $remaining,
                'done'      => 0 === $remaining,
                'message'   => sprintf( __( '%1$s originales redimensionados, %2$s errores.', 'taisa-image-optimizer' ), number_format_i18n( absint( $report['resized'] ) ), number_format_i18n( absint( $report['errors'] ) ) ),
            )
        );
    }

    private static function ajax_guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'taisa-image-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'taisa_imgopt_admin', 'nonce' );
    }

    private static function get_known_image_sizes() {
        global $_wp_additional_image_sizes;

        $registered = get_intermediate_image_sizes();
        $stats = self::get_option_array( self::SIZE_STATS_OPTION );
        if ( ! empty( $stats['sizes'] ) && is_array( $stats['sizes'] ) ) {
            $registered = array_unique( array_merge( $registered, array_keys( $stats['sizes'] ) ) );
        }

        $sizes = array();
        foreach ( $registered as $size_name ) {
            $source = __( 'WordPress', 'taisa-image-optimizer' );
            $width = 0;
            $height = 0;
            $crop = false;

            if ( isset( $_wp_additional_image_sizes[ $size_name ] ) ) {
                $width = absint( $_wp_additional_image_sizes[ $size_name ]['width'] ?? 0 );
                $height = absint( $_wp_additional_image_sizes[ $size_name ]['height'] ?? 0 );
                $crop = ! empty( $_wp_additional_image_sizes[ $size_name ]['crop'] );
                $source = in_array( $size_name, array( '1536x1536', '2048x2048' ), true ) ? __( 'WordPress', 'taisa-image-optimizer' ) : __( 'Tema o plugin', 'taisa-image-optimizer' );
            } else {
                $width = absint( get_option( "{$size_name}_size_w" ) );
                $height = absint( get_option( "{$size_name}_size_h" ) );
                $crop = (bool) get_option( "{$size_name}_crop" );
                if ( ! $width && ! $height && ! empty( $stats['sizes'][ $size_name ] ) ) {
                    $width = absint( $stats['sizes'][ $size_name ]['width'] ?? 0 );
                    $height = absint( $stats['sizes'][ $size_name ]['height'] ?? 0 );
                    $source = __( 'Detectado en biblioteca', 'taisa-image-optimizer' );
                }
            }

            $sizes[ $size_name ] = array(
                'name'   => $size_name,
                'width'  => $width,
                'height' => $height,
                'crop'   => $crop,
                'source' => $source,
            );
        }

        ksort( $sizes );
        return $sizes;
    }

    private static function count_image_attachments() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            )
        );

        return absint( $query->found_posts );
    }

    private static function get_image_attachment_ids( $offset, $limit ) {
        return get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
    }

    private static function find_attachment_usage( $attachment_id ) {
        global $wpdb;

        $sources = array();
        $sizes = array();
        $filenames = self::get_attachment_filenames_by_size( $attachment_id );

        foreach ( $filenames as $filename => $size_name ) {
            if ( ! $filename ) {
                continue;
            }

            $like = '%' . $wpdb->esc_like( $filename ) . '%';

            $post_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE ID <> %d AND post_type <> 'revision' AND post_status NOT IN ('auto-draft', 'trash') AND (post_content LIKE %s OR post_excerpt LIKE %s) LIMIT 12",
                    $attachment_id,
                    $like,
                    $like
                )
            );
            foreach ( $post_rows as $row ) {
                self::add_source( $sources, 'post-' . $row->ID . '-' . $filename, self::format_post_source_label( $row ), get_edit_post_link( $row->ID, '' ) );
                $sizes[ $size_name ] = true;
            }

            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s AND meta_key NOT IN ('_wp_attachment_metadata', '_wp_attached_file', '_taisa_imgopt_usage_status', '_taisa_imgopt_usage_sources', '_taisa_imgopt_usage_sizes', '_taisa_imgopt_large_info', '_taisa_imgopt_backup_file') LIMIT 12",
                    $attachment_id,
                    $like
                )
            );
            foreach ( $meta_rows as $row ) {
                self::add_source( $sources, 'postmeta-' . $row->post_id . '-' . $row->meta_key . '-' . $filename, sprintf( __( 'Meta %1$s en contenido #%2$d', 'taisa-image-optimizer' ), $row->meta_key, $row->post_id ), get_edit_post_link( $row->post_id, '' ) );
                $sizes[ $size_name ] = true;
            }

            $term_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT term_id, meta_key FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 12",
                    $like
                )
            );
            foreach ( $term_rows as $row ) {
                self::add_source( $sources, 'termmeta-' . $row->term_id . '-' . $row->meta_key . '-' . $filename, sprintf( __( 'Meta %1$s en termino #%2$d', 'taisa-image-optimizer' ), $row->meta_key, $row->term_id ), admin_url( 'term.php?tag_ID=' . absint( $row->term_id ) ) );
                $sizes[ $size_name ] = true;
            }

            $option_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE '_transient_%%' AND option_name NOT LIKE '_site_transient_%%' AND option_name NOT LIKE 'taisa_image_optimizer_%%' LIMIT 12",
                    $like
                )
            );
            foreach ( $option_rows as $row ) {
                self::add_source( $sources, 'option-' . $row->option_name . '-' . $filename, sprintf( __( 'Opcion %s', 'taisa-image-optimizer' ), $row->option_name ), admin_url( 'options.php' ) );
                $sizes[ $size_name ] = true;
            }
        }

        $id_sources = self::find_attachment_id_usage( $attachment_id );
        foreach ( $id_sources as $source ) {
            self::add_source( $sources, $source['key'], $source['label'], $source['url'] );
        }

        return array(
            'sources' => $sources,
            'sizes'   => $sizes,
        );
    }

    private static function find_attachment_id_usage( $attachment_id ) {
        global $wpdb;

        $sources = array();
        $thumb_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 30",
                $attachment_id
            )
        );
        foreach ( $thumb_rows as $row ) {
            $sources[] = array(
                'key'   => 'featured-' . $row->post_id,
                'label' => sprintf( __( 'Imagen destacada en contenido #%d', 'taisa-image-optimizer' ), $row->post_id ),
                'url'   => get_edit_post_link( $row->post_id, '' ),
            );
        }

        $named_image_meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value = %s AND (meta_key LIKE '%%image%%' OR meta_key LIKE '%%imagen%%' OR meta_key LIKE '%%photo%%' OR meta_key LIKE '%%foto%%' OR meta_key LIKE '%%logo%%' OR meta_key LIKE '%%icon%%' OR meta_key LIKE '%%banner%%' OR meta_key LIKE '%%thumbnail%%' OR meta_key LIKE '%%gallery%%' OR meta_key LIKE '%%galeria%%' OR meta_key LIKE '%%background%%' OR meta_key LIKE '%%fondo%%') LIMIT 30",
                $attachment_id,
                (string) $attachment_id
            )
        );
        foreach ( $named_image_meta_rows as $row ) {
            $sources[] = array(
                'key'   => 'named-image-meta-' . $row->post_id . '-' . $row->meta_key,
                'label' => sprintf( __( 'Referencia por ID en %1$s, contenido #%2$d', 'taisa-image-optimizer' ), $row->meta_key, $row->post_id ),
                'url'   => get_edit_post_link( $row->post_id, '' ),
            );
        }

        $id_like = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';
        $post_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE ID <> %d AND post_status NOT IN ('auto-draft', 'trash') AND post_content LIKE %s LIMIT 20",
                $attachment_id,
                $id_like
            )
        );
        foreach ( $post_rows as $row ) {
            $sources[] = array(
                'key'   => 'wp-image-' . $row->ID,
                'label' => self::format_post_source_label( $row ),
                'url'   => get_edit_post_link( $row->ID, '' ),
            );
        }

        $gallery_like = '%' . $wpdb->esc_like( (string) $attachment_id ) . '%';
        $gallery_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s LIMIT 20",
                $gallery_like
            )
        );
        foreach ( $gallery_rows as $row ) {
            $ids = array_map( 'absint', explode( ',', (string) $row->meta_value ) );
            if ( in_array( absint( $attachment_id ), $ids, true ) ) {
                $sources[] = array(
                    'key'   => 'product-gallery-' . $row->post_id,
                    'label' => sprintf( __( 'Galeria de producto #%d', 'taisa-image-optimizer' ), $row->post_id ),
                    'url'   => get_edit_post_link( $row->post_id, '' ),
                );
            }
        }

        if ( absint( get_option( 'site_icon', 0 ) ) === absint( $attachment_id ) ) {
            $sources[] = array(
                'key'   => 'site-icon',
                'label' => __( 'Icono del sitio', 'taisa-image-optimizer' ),
                'url'   => admin_url( 'customize.php' ),
            );
        }

        $serialized_id = '%i:' . absint( $attachment_id ) . ';%';
        $theme_mod_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%%' AND option_value LIKE %s LIMIT 10",
                $serialized_id
            )
        );
        foreach ( $theme_mod_rows as $row ) {
            $sources[] = array(
                'key'   => 'theme-mod-' . $row->option_name,
                'label' => sprintf( __( 'Ajuste del tema: %s', 'taisa-image-optimizer' ), $row->option_name ),
                'url'   => admin_url( 'customize.php' ),
            );
        }

        return $sources;
    }

    private static function inspect_large_attachment( $attachment_id, $settings ) {
        $file = get_attached_file( $attachment_id );
        $mime = get_post_mime_type( $attachment_id );
        $result = array(
            'large'      => false,
            'resizeable' => false,
            'width'      => 0,
            'height'     => 0,
            'filesize'   => 0,
            'reasons'    => array(),
            'message'    => '',
        );

        if ( self::is_protected_favicon_attachment( $attachment_id ) ) {
            $result['message'] = __( 'Icono del sitio protegido; se omite.', 'taisa-image-optimizer' );
            return $result;
        }

        if ( ! $file || ! file_exists( $file ) || ! self::is_path_in_uploads( $file ) ) {
            $result['message'] = __( 'Archivo no encontrado.', 'taisa-image-optimizer' );
            return $result;
        }

        if ( in_array( $mime, array( 'image/svg+xml', 'image/gif' ), true ) ) {
            $result['message'] = __( 'Tipo omitido por seguridad.', 'taisa-image-optimizer' );
            return $result;
        }

        $dimensions = @getimagesize( $file );
        if ( empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
            $result['message'] = __( 'No se pudieron leer dimensiones.', 'taisa-image-optimizer' );
            return $result;
        }

        $filesize = (int) filesize( $file );
        $result['width'] = absint( $dimensions[0] );
        $result['height'] = absint( $dimensions[1] );
        $result['filesize'] = $filesize;

        if ( $result['width'] > $settings['large_max_width'] ) {
            $result['large'] = true;
            $result['resizeable'] = true;
            $result['reasons'][] = 'width';
        }
        if ( $result['height'] > $settings['large_max_height'] ) {
            $result['large'] = true;
            $result['resizeable'] = true;
            $result['reasons'][] = 'height';
        }
        if ( $filesize > self::mb_to_bytes( $settings['large_max_mb'] ) ) {
            $result['large'] = true;
            $result['reasons'][] = 'filesize';
        }

        return $result;
    }

    private static function delete_disabled_derivatives( $attachment_id, $disabled_sizes ) {
        $result = array(
            'deleted'  => 0,
            'retained' => 0,
            'bytes'    => 0,
            'errors'   => 0,
        );
        if ( self::is_protected_favicon_attachment( $attachment_id ) ) {
            $result['protected'] = 1;
            return $result;
        }
        $file = get_attached_file( $attachment_id );
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( empty( $file ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return $result;
        }

        $changed = false;
        $files_to_delete = array();
        foreach ( $disabled_sizes as $size_name ) {
            if ( empty( $metadata['sizes'][ $size_name ] ) ) {
                continue;
            }

            $path = self::get_size_file_path( $file, $metadata['sizes'][ $size_name ] );
            $shared_with_active_size = false;
            foreach ( $metadata['sizes'] as $other_size_name => $other_size_data ) {
                if ( $other_size_name === $size_name || in_array( $other_size_name, $disabled_sizes, true ) ) {
                    continue;
                }
                if ( ! empty( $other_size_data['file'] ) && $other_size_data['file'] === $metadata['sizes'][ $size_name ]['file'] ) {
                    $shared_with_active_size = true;
                    break;
                }
            }

            if ( $shared_with_active_size ) {
                unset( $metadata['sizes'][ $size_name ] );
                $result['retained']++;
                $changed = true;
                continue;
            }

            if ( $path && file_exists( $path ) && self::is_path_in_uploads( $path ) && realpath( $path ) !== realpath( $file ) ) {
                $files_to_delete[ $path ] = (int) filesize( $path );
                unset( $metadata['sizes'][ $size_name ] );
                $changed = true;
            } elseif ( ! $path || ! file_exists( $path ) ) {
                unset( $metadata['sizes'][ $size_name ] );
                $changed = true;
            } else {
                $result['errors']++;
            }
        }

        if ( $changed ) {
            if ( ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
                $result['errors'] += count( $files_to_delete );
                return $result;
            }
            // Once WordPress falls back to the original, physical files can be removed.
            foreach ( $files_to_delete as $path => $file_size ) {
                wp_delete_file( $path );
                if ( ! file_exists( $path ) ) {
                    $result['deleted']++;
                    $result['bytes'] += $file_size;
                } else {
                    $result['errors']++;
                }
            }
        }

        return $result;
    }

    /** Protect the WordPress Site Icon and favicon-named attachments from all destructive optimizer actions. */
    private static function is_protected_favicon_attachment( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return false;
        }

        if ( $attachment_id === absint( get_option( 'site_icon', 0 ) ) ) {
            return true;
        }

        if ( 'site-icon' === get_post_meta( $attachment_id, '_wp_attachment_context', true ) ) {
            return true;
        }

        $file = get_attached_file( $attachment_id );
        return $file && false !== stripos( wp_basename( $file ), 'favicon' );
    }

    private static function resize_large_attachment( $attachment_id, $settings ) {
        $file = get_attached_file( $attachment_id );
        $mime = get_post_mime_type( $attachment_id );
        $result = array(
            'success' => false,
            'message' => '',
        );

        if ( self::is_protected_favicon_attachment( $attachment_id ) ) {
            $result['message'] = __( 'Icono del sitio protegido; no se redimensiona.', 'taisa-image-optimizer' );
            return $result;
        }

        if ( ! $file || ! file_exists( $file ) || ! self::is_path_in_uploads( $file ) ) {
            $result['message'] = __( 'Archivo no encontrado.', 'taisa-image-optimizer' );
            return $result;
        }

        if ( in_array( $mime, array( 'image/svg+xml', 'image/gif' ), true ) ) {
            $result['message'] = __( 'Tipo omitido por seguridad.', 'taisa-image-optimizer' );
            return $result;
        }

        $dimensions = @getimagesize( $file );
        if ( empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
            $result['message'] = __( 'No se pudieron leer dimensiones.', 'taisa-image-optimizer' );
            return $result;
        }

        if ( $dimensions[0] <= $settings['large_max_width'] && $dimensions[1] <= $settings['large_max_height'] ) {
            $result['message'] = __( 'Dimensiones dentro del limite; no se redimensiona solo por peso.', 'taisa-image-optimizer' );
            return $result;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( ! empty( $settings['backup_originals'] ) ) {
            $backup = self::backup_original_file( $attachment_id, $file );
            if ( is_wp_error( $backup ) ) {
                $result['message'] = $backup->get_error_message();
                return $result;
            }
            update_post_meta( $attachment_id, self::BACKUP_META, $backup );
        }

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            $result['message'] = $editor->get_error_message();
            return $result;
        }

        $resized = $editor->resize( $settings['large_max_width'], $settings['large_max_height'], false );
        if ( is_wp_error( $resized ) ) {
            $result['message'] = $resized->get_error_message();
            return $result;
        }

        $saved = $editor->save( $file );
        if ( is_wp_error( $saved ) ) {
            $result['message'] = $saved->get_error_message();
            return $result;
        }

        clearstatcache( true, $file );
        $new_dimensions = @getimagesize( $file );
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $metadata ) ) {
            $metadata = array();
        }
        if ( ! empty( $new_dimensions[0] ) && ! empty( $new_dimensions[1] ) ) {
            $metadata['width'] = absint( $new_dimensions[0] );
            $metadata['height'] = absint( $new_dimensions[1] );
        }
        $metadata['filesize'] = file_exists( $file ) ? (int) filesize( $file ) : 0;
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $result['success'] = true;
        $result['message'] = __( 'Original redimensionado.', 'taisa-image-optimizer' );
        $result['width'] = absint( $metadata['width'] ?? 0 );
        $result['height'] = absint( $metadata['height'] ?? 0 );
        $result['filesize'] = absint( $metadata['filesize'] ?? 0 );

        return $result;
    }

    private static function backup_original_file( $attachment_id, $file ) {
        $uploads = wp_upload_dir();
        $base = wp_normalize_path( $uploads['basedir'] );
        $normalized = wp_normalize_path( $file );

        if ( 0 !== strpos( $normalized, trailingslashit( $base ) ) ) {
            return new WP_Error( 'taisa_imgopt_bad_path', __( 'La ruta no esta dentro de uploads.', 'taisa-image-optimizer' ) );
        }

        $relative = ltrim( substr( $normalized, strlen( trailingslashit( $base ) ) ), '/' );
        $backup_relative = 'taisa-image-optimizer-backups/' . gmdate( 'Y/m/d' ) . '/' . $attachment_id . '-' . wp_basename( $relative );
        $backup_file = trailingslashit( $uploads['basedir'] ) . $backup_relative;

        if ( ! wp_mkdir_p( dirname( $backup_file ) ) ) {
            return new WP_Error( 'taisa_imgopt_backup_dir', __( 'No se pudo crear la carpeta de copia.', 'taisa-image-optimizer' ) );
        }

        if ( ! copy( $file, $backup_file ) ) {
            return new WP_Error( 'taisa_imgopt_backup_copy', __( 'No se pudo crear la copia del original.', 'taisa-image-optimizer' ) );
        }

        return $backup_relative;
    }

    private static function count_large_candidates() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => self::LARGE_STATUS_META,
                'meta_value'     => 'yes',
            )
        );

        return absint( $query->found_posts );
    }

    private static function get_large_candidate_ids( $limit ) {
        return get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $limit,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_key'       => self::LARGE_STATUS_META,
                'meta_value'     => 'yes',
            )
        );
    }

    private static function get_flagged_large_ids( $limit ) {
        return get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $limit,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => self::LARGE_STATUS_META,
                        'value'   => array( 'yes', 'weight_only' ),
                        'compare' => 'IN',
                    ),
                ),
            )
        );
    }

    private static function get_attachment_filenames_by_size( $attachment_id ) {
        $filenames = array();
        $file = get_attached_file( $attachment_id );
        if ( $file ) {
            $filenames[ wp_basename( $file ) ] = 'original';
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $filenames[ wp_basename( $size_data['file'] ) ] = $size_name;
                }
            }
        }

        return $filenames;
    }

    private static function count_public_scan_urls() {
        $count = 1;
        $post_types = self::get_public_scan_post_types();
        foreach ( $post_types as $post_type ) {
            $counts = wp_count_posts( $post_type );
            if ( ! empty( $counts->publish ) ) {
                $count += absint( $counts->publish );
            }
        }
        return $count;
    }

    private static function get_public_scan_urls( $offset, $limit ) {
        $urls = array();
        $remaining = $limit;
        $post_offset = $offset;

        if ( 0 === $offset ) {
            $urls[] = home_url( '/' );
            $remaining--;
            $post_offset = 0;
        } else {
            $post_offset = $offset - 1;
        }

        if ( $remaining <= 0 ) {
            return $urls;
        }

        $ids = get_posts(
            array(
                'post_type'      => self::get_public_scan_post_types(),
                'post_status'    => 'publish',
                'posts_per_page' => $remaining,
                'offset'         => $post_offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

        foreach ( $ids as $post_id ) {
            $permalink = get_permalink( $post_id );
            if ( $permalink ) {
                $urls[] = $permalink;
            }
        }

        return $urls;
    }

    private static function get_public_scan_post_types() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );
        return array_values( $post_types );
    }

    private static function extract_image_urls_from_html( $html, $base_url ) {
        $urls = array();

        if ( preg_match_all( '/(?:src|href|data-src|data-lazy-src|data-original)=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                if ( self::looks_like_image_url( $url ) ) {
                    $urls[] = self::normalize_asset_url( $url, $base_url );
                }
            }
        }

        if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $srcset ) {
                $parts = explode( ',', $srcset );
                foreach ( $parts as $part ) {
                    $candidate = trim( preg_replace( '/\s+\d+[wx]$/', '', trim( $part ) ) );
                    if ( self::looks_like_image_url( $candidate ) ) {
                        $urls[] = self::normalize_asset_url( $candidate, $base_url );
                    }
                }
            }
        }

        if ( preg_match_all( '/url\(([^)]+)\)/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $url = trim( $url, " \t\n\r\0\x0B\"'" );
                if ( self::looks_like_image_url( $url ) ) {
                    $urls[] = self::normalize_asset_url( $url, $base_url );
                }
            }
        }

        return array_values( array_filter( array_unique( $urls ) ) );
    }

    private static function looks_like_image_url( $url ) {
        return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$/i', trim( $url ) );
    }

    private static function normalize_asset_url( $url, $base_url ) {
        $url = trim( html_entity_decode( $url ), " \t\n\r\0\x0B\"'" );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
            return '';
        }

        $url = explode( '#', $url )[0];
        if ( 0 === strpos( $url, '//' ) ) {
            $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
            return $scheme . ':' . $url;
        }

        if ( 0 === strpos( $url, '/' ) ) {
            return home_url( $url );
        }

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return trailingslashit( dirname( $base_url ) ) . $url;
        }

        return $url;
    }

    private static function attachment_id_from_asset_url( $url ) {
        global $wpdb;

        $clean_url = preg_replace( '/\?.*$/', '', $url );
        $attachment_id = attachment_url_to_postid( $clean_url );
        if ( $attachment_id ) {
            return absint( $attachment_id );
        }

        $path = wp_parse_url( $clean_url, PHP_URL_PATH );
        if ( ! $path ) {
            return 0;
        }

        $uploads = wp_upload_dir();
        $uploads_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
        if ( $uploads_path && false === strpos( $path, $uploads_path ) ) {
            return 0;
        }

        $basename = rawurldecode( wp_basename( $path ) );
        $original = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $basename );
        if ( ! $original ) {
            return 0;
        }

        $like = '%' . $wpdb->esc_like( $original );
        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
                    $like
                )
            )
        );
    }

    private static function detect_size_from_asset_url( $attachment_id, $url ) {
        $path = wp_parse_url( preg_replace( '/\?.*$/', '', $url ), PHP_URL_PATH );
        if ( ! $path ) {
            return '';
        }

        $filename = rawurldecode( wp_basename( $path ) );
        $file = get_attached_file( $attachment_id );
        if ( $file && wp_basename( $file ) === $filename ) {
            return 'original';
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) && wp_basename( $size_data['file'] ) === $filename ) {
                    return $size_name;
                }
            }
        }

        return '';
    }

    private static function mark_attachment_used_from_frontend( $attachment_id, $page_url, $asset_url, $size_name ) {
        $previous_status = get_post_meta( $attachment_id, self::USAGE_STATUS_META, true );
        $sources = get_post_meta( $attachment_id, self::USAGE_SOURCES_META, true );
        if ( ! is_array( $sources ) ) {
            $sources = array();
        }

        self::add_source(
            $sources,
            'frontend-' . md5( $page_url . '|' . $asset_url ),
            sprintf( __( 'URL publica: %s', 'taisa-image-optimizer' ), esc_url_raw( $page_url ) ),
            $page_url
        );

        update_post_meta( $attachment_id, self::USAGE_STATUS_META, 'used' );
        update_post_meta( $attachment_id, self::USAGE_SOURCES_META, self::sanitize_sources( $sources ) );

        if ( $size_name ) {
            $sizes = get_post_meta( $attachment_id, self::USAGE_SIZES_META, true );
            if ( ! is_array( $sizes ) ) {
                $sizes = array();
            }
            $sizes[] = $size_name;
            update_post_meta( $attachment_id, self::USAGE_SIZES_META, self::sanitize_size_names( array_unique( $sizes ) ) );
        }

        return 'used' !== $previous_status;
    }

    private static function merge_usage_size_hits( $stats, $hits, $channel ) {
        if ( ! is_array( $stats ) ) {
            $stats = array();
        }

        foreach ( $hits as $size_name => $count ) {
            if ( empty( $stats[ $size_name ] ) ) {
                $stats[ $size_name ] = array(
                    'database' => 0,
                    'frontend' => 0,
                );
            }
            $stats[ $size_name ][ $channel ] = absint( $stats[ $size_name ][ $channel ] ?? 0 ) + absint( $count );
        }

        return $stats;
    }

    private static function add_source( &$sources, $key, $label, $url = '' ) {
        $key = sanitize_key( $key );
        if ( count( $sources ) >= 30 ) {
            return;
        }

        foreach ( $sources as $source ) {
            if ( isset( $source['key'] ) && $source['key'] === $key ) {
                return;
            }
        }

        $sources[] = array(
            'key'   => $key,
            'label' => sanitize_text_field( $label ),
            'url'   => esc_url_raw( $url ),
        );
    }

    private static function sanitize_sources( $sources ) {
        if ( ! is_array( $sources ) ) {
            return array();
        }

        $clean = array();
        foreach ( array_slice( $sources, 0, 30 ) as $source ) {
            if ( ! is_array( $source ) || empty( $source['label'] ) ) {
                continue;
            }
            $clean[] = array(
                'key'   => isset( $source['key'] ) ? sanitize_key( $source['key'] ) : md5( wp_json_encode( $source ) ),
                'label' => sanitize_text_field( $source['label'] ),
                'url'   => isset( $source['url'] ) ? esc_url_raw( $source['url'] ) : '',
            );
        }

        return $clean;
    }

    private static function sanitize_size_names( $sizes ) {
        if ( ! is_array( $sizes ) ) {
            return array();
        }

        $clean = array();
        foreach ( $sizes as $size ) {
            $size = sanitize_key( $size );
            if ( $size ) {
                $clean[] = $size;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    private static function get_option_array( $option_name ) {
        $value = get_option( $option_name, array() );
        return is_array( $value ) ? $value : array();
    }

    private static function get_size_file_path( $original_file, $size_data ) {
        if ( empty( $original_file ) || empty( $size_data['file'] ) ) {
            return '';
        }
        return trailingslashit( dirname( $original_file ) ) . $size_data['file'];
    }

    private static function is_path_in_uploads( $path ) {
        $uploads = wp_upload_dir();
        $base = wp_normalize_path( $uploads['basedir'] );
        $target = wp_normalize_path( $path );
        return $target === $base || 0 === strpos( $target, trailingslashit( $base ) );
    }

    private static function format_post_source_label( $row ) {
        $title = $row->post_title ? $row->post_title : __( '(sin titulo)', 'taisa-image-optimizer' );
        return sprintf( '%1$s #%2$d: %3$s', $row->post_type, $row->ID, $title );
    }

    private static function format_dimensions( $size ) {
        $width = absint( $size['width'] ?? 0 );
        $height = absint( $size['height'] ?? 0 );
        if ( ! $width && ! $height ) {
            return '-';
        }

        $crop = ! empty( $size['crop'] ) ? __( 'recorte', 'taisa-image-optimizer' ) : __( 'proporcional', 'taisa-image-optimizer' );
        return sprintf( '%1$s x %2$s (%3$s)', $width ? $width : '-', $height ? $height : '-', $crop );
    }

    private static function format_bytes( $bytes ) {
        $bytes = absint( $bytes );
        if ( function_exists( 'size_format' ) ) {
            return size_format( $bytes, 2 );
        }
        return number_format_i18n( $bytes ) . ' B';
    }

    private static function mb_to_bytes( $mb ) {
        return (int) round( (float) $mb * 1024 * 1024 );
    }
}

