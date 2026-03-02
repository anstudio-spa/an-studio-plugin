<?php
/**
 * AN STUDIO — an-cron.php
 * ═══════════════════════════════════════════════════════
 * Snippet independiente: tareas programadas
 * Requiere: an-database.php activo
 *
 * Tareas:
 *   - Cada hora: elimina reservas pendientes con más de 30 min de antigüedad
 */

// Registrar intervalo personalizado
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['an_hourly'])) {
        $schedules['an_hourly'] = [
            'interval' => 3600,
            'display' => 'AN Studio — cada hora',
        ];
    }
    return $schedules;
});

// Programar el evento si no existe
if (!wp_next_scheduled('an_limpiar_pendientes_cron')) {
    wp_schedule_event(time(), 'an_hourly', 'an_limpiar_pendientes_cron');
}

// Ejecutar limpieza
add_action('an_limpiar_pendientes_cron', function () {
    global $wpdb;
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->prefix}an_reservas_v4
         WHERE estado = 'pendiente'
           AND fecha_turno > NOW()
           AND id IN (
               SELECT id FROM (
                   SELECT id FROM {$wpdb->prefix}an_reservas_v4
                   WHERE estado    = 'pendiente'
                   AND   created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
               ) AS tmp
           )"
    );
    if ($deleted) {
        error_log("AN Studio cron: eliminadas {$deleted} reservas pendientes expiradas.");
    }
});