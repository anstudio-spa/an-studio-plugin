<?php
/**
 * Plugin Name: AN Studio — Sistema de Reservas
 * Plugin URI:  https://anstudio.com.ar
 * Description: Sistema completo de reservas con MercadoPago, Google Calendar y dashboard de locales.
 * Version:     4.6.0
 * Author:      AN Studio
 * Text Domain: an-studio
 */

if (!defined('ABSPATH')) exit;

define('AN_STUDIO_VERSION', '4.6.0');
define('AN_STUDIO_DIR', plugin_dir_path(__FILE__));
define('AN_STUDIO_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {

    require_once AN_STUDIO_DIR . 'an-config.php';
    require_once AN_STUDIO_DIR . 'an-database.php';
    require_once AN_STUDIO_DIR . 'an-gcal.php';
    require_once AN_STUDIO_DIR . 'an-booking.php';
    require_once AN_STUDIO_DIR . 'an-confirmar.php';
    require_once AN_STUDIO_DIR . 'an-cron.php';
    require_once AN_STUDIO_DIR . 'an-frontend.php';

    if (is_admin()) {
        require_once AN_STUDIO_DIR . 'an-admin-wp.php';
    }

    require_once AN_STUDIO_DIR . 'an-dashboard/auth.php';
    require_once AN_STUDIO_DIR . 'an-dashboard/api.php';
});

register_activation_hook(__FILE__, function () {
    delete_option('an_dashboard_rewrite_flushed');
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    $timestamp = wp_next_scheduled('an_limpiar_pendientes_cron');
    if ($timestamp) wp_unschedule_event($timestamp, 'an_limpiar_pendientes_cron');
});
