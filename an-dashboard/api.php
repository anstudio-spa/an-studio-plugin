<?php
/**
 * Plugin Name: AN Studio — Sistema de Reservas
 * Plugin URI:  https://anstudio.com.ar
 * Description: Sistema completo de reservas con MercadoPago, Google Calendar y dashboard de locales.
 * Version:     4.6.0
 * Author:      AN Studio
 * Text Domain: an-studio
 *
 * ═══════════════════════════════════════════════════════════════════
 * INSTALACIÓN (sin Code Snippets):
 *   1. Subir esta carpeta completa a /wp-content/plugins/an-studio/
 *   2. Activar desde Plugins en WP Admin
 *   3. Editar an-config.php con tus credenciales
 *
 * ESTRUCTURA DE ARCHIVOS:
 *   an-studio.php        ← este archivo (loader principal)
 *   an-config.php        ← credenciales y constantes
 *   an-database.php      ← creación de tablas
 *   an-gcal.php          ← Google Calendar
 *   an-booking.php       ← AJAX reservas + MercadoPago
 *   an-confirmar.php     ← webhook MP + confirmación
 *   an-cron.php          ← tareas programadas
 *   an-frontend.php      ← shortcode [an_booking]
 *   an-admin-wp.php      ← panel WP Admin
 *   auth.php             ← dashboard de locales /admin/
 *   api.php              ← REST API del dashboard
 *   index.php            ← vista principal del dashboard
 * ═══════════════════════════════════════════════════════════════════
 */

if (!defined('ABSPATH'))
    exit;

define('AN_STUDIO_VERSION', '4.6.0');
define('AN_STUDIO_DIR', plugin_dir_path(__FILE__));
define('AN_STUDIO_URL', plugin_dir_url(__FILE__));

/**
 * Carga todos los módulos en orden.
 * Se ejecuta en plugins_loaded para garantizar que WP y $wpdb estén listos.
 */
add_action('plugins_loaded', function () {

    // 1 — Configuración y constantes (siempre primero)
    require_once AN_STUDIO_DIR . 'an-config.php';

    // 2 — Base de datos (tablas)
    require_once AN_STUDIO_DIR . 'an-database.php';

    // 3 — Google Calendar (helpers que otros módulos usan)
    require_once AN_STUDIO_DIR . 'an-gcal.php';

    // 4 — Booking: AJAX disponibilidad + crear reserva + GTM
    require_once AN_STUDIO_DIR . 'an-booking.php';

    // 5 — Confirmación: webhook MP + página /pago-exitoso
    require_once AN_STUDIO_DIR . 'an-confirmar.php';

    // 6 — Cron: limpieza de pendientes
    require_once AN_STUDIO_DIR . 'an-cron.php';

    // 7 — Frontend: shortcode [an_booking]
    require_once AN_STUDIO_DIR . 'an-frontend.php';

    // 8 — Admin WP: panel de reservas, sucursales, profesionales
    if (is_admin()) {
        require_once AN_STUDIO_DIR . 'an-admin-wp.php';
    }

    // 9 — Dashboard de locales + autenticación (/admin/)
    require_once AN_STUDIO_DIR . 'auth.php';

    // 10 — REST API del dashboard
    require_once AN_STUDIO_DIR . 'api.php';
});

/**
 * Activación del plugin: flush rewrite rules para que funcionen
 * las rutas /admin/ y /pago-exitoso sin necesitar guardar permalinks.
 */
register_activation_hook(__FILE__, function () {
    // Forzar re-registro de rewrite rules
    delete_option('an_dashboard_rewrite_flushed');
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    // Limpiar el cron
    $timestamp = wp_next_scheduled('an_limpiar_pendientes_cron');
    if ($timestamp)
        wp_unschedule_event($timestamp, 'an_limpiar_pendientes_cron');
});