<?php
/**
 * AN STUDIO — an-database.php
 * ═══════════════════════════════════════════════════════
 * Snippet independiente: crea y mantiene las 3 tablas.
 * Requiere: an-config.php activo
 *
 * Tablas:
 *   wp_an_reservas_v4  — reservas de turnos
 *   wp_an_locations    — sucursales
 *   wp_an_staff        — profesionales
 *   wp_an_dashboard_users — usuarios del dashboard de locales (nuevo)
 */

add_action('init', function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ── Reservas ────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}an_reservas_v4 (
        id           mediumint(9)  NOT NULL AUTO_INCREMENT,
        fecha_turno  datetime      NOT NULL,
        servicio     text          NOT NULL,
        precio       float         NOT NULL,
        nombre_cliente text        NOT NULL,
        email        text          NOT NULL DEFAULT '',
        whatsapp     text          NOT NULL,
        estado       text          DEFAULT 'pendiente',
        staff_id     int           DEFAULT 0,
        location_id  int           DEFAULT 0,
        guia_ia      text,
        gcal_event_id text,
        created_at   datetime      DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;");

    // ── Sucursales ───────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}an_locations (
        id         mediumint(9)  NOT NULL AUTO_INCREMENT,
        name       varchar(255)  NOT NULL,
        address    text          NOT NULL DEFAULT '',
        city       varchar(100)  DEFAULT '',
        lat        decimal(10,7) DEFAULT NULL,
        lng        decimal(10,7) DEFAULT NULL,
        phone      varchar(50)   DEFAULT '',
        whatsapp   varchar(50)   DEFAULT '',
        active     tinyint(1)    DEFAULT 1,
        created_at datetime      DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;");

    // ── Profesionales ────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}an_staff (
        id          mediumint(9) NOT NULL AUTO_INCREMENT,
        name        varchar(255) NOT NULL,
        role        varchar(100) DEFAULT '',
        bio         text         DEFAULT '',
        photo_url   text         DEFAULT '',
        location_id int          DEFAULT 0,
        calendar_id varchar(255) DEFAULT '',
        active      tinyint(1)   DEFAULT 1,
        sort_order  int          DEFAULT 0,
        created_at  datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;");

    // ── Dashboard usuarios (dueños de locales) ───────────────────────
    // wp_user_id   → usuario de WordPress al que se le dio acceso
    // location_ids → JSON array con los IDs de sucursales que puede ver
    // role         → 'owner' | 'staff' | 'viewer'
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}an_dashboard_users (
        id           mediumint(9) NOT NULL AUTO_INCREMENT,
        wp_user_id   bigint(20)   NOT NULL,
        location_ids text         NOT NULL DEFAULT '[]',
        role         varchar(20)  NOT NULL DEFAULT 'viewer',
        active       tinyint(1)   DEFAULT 1,
        created_at   datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY   wp_user_id (wp_user_id)
    ) $charset;");

    // ── Upgrades: agregar columnas si la tabla ya existía ───────────
    $upgrades = [
        [$wpdb->prefix . 'an_reservas_v4', 'email', "text NOT NULL DEFAULT ''"],
        [$wpdb->prefix . 'an_reservas_v4', 'staff_id', 'int DEFAULT 0'],
        [$wpdb->prefix . 'an_reservas_v4', 'location_id', 'int DEFAULT 0'],
        [$wpdb->prefix . 'an_reservas_v4', 'gcal_event_id', 'text'],
    ];
    foreach ($upgrades as [$tbl, $col, $def]) {
        if (empty($wpdb->get_results("SHOW COLUMNS FROM `$tbl` LIKE '$col'"))) {
            $wpdb->query("ALTER TABLE `$tbl` ADD COLUMN $col $def");
        }
    }

    // ── Sucursal default si la tabla está vacía ──────────────────────
    if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}an_locations")) {
        $wpdb->insert(
            $wpdb->prefix . 'an_locations',
            [
                'name' => 'AN Studio Recoleta',
                'address' => 'Recoleta, Buenos Aires',
                'city' => 'Buenos Aires',
                'lat' => -34.5875,
                'lng' => -58.3972,
                'phone' => '',
                'whatsapp' => '',
                'active' => 1
            ]
        );
    }
});