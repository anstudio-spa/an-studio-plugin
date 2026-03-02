<?php
/**
 * AN STUDIO — api.php
 * ═══════════════════════════════════════════════════════
 * REST API del dashboard de locales
 * Requiere: an-config.php + an-database.php + an-confirmar.php activos
 *
 * Base URL: /wp-json/an-luxury/v4/
 *
 * Endpoints públicos (solo el webhook de MP):
 *   POST /pago                  → Webhook MercadoPago (en an-confirmar.php)
 *
 * Endpoints autenticados (requieren JWT o sesión WP con permisos):
 *   GET  /dashboard/stats       → Estadísticas generales
 *   GET  /dashboard/reservas    → Listado de reservas (filtrable)
 *   GET  /dashboard/hoy         → Turnos de hoy
 *   GET  /dashboard/proximos    → Próximos turnos
 *   GET  /dashboard/staff       → Lista de profesionales
 *   GET  /dashboard/locations   → Lista de sucursales
 *   PUT  /dashboard/reserva/{id}→ Actualizar estado de reserva
 *   POST /dashboard/reserva     → Crear reserva manual (sin pago)
 *
 * Autenticación: cookie de sesión WordPress (is_user_logged_in) +
 * registro activo en an_dashboard_users. El nonce se pasa en header
 * X-AN-Nonce o como parámetro _nonce.
 */

if (!defined('ABSPATH')) exit;


// ═══════════════════════════════════════════════════════════════════
// REGISTRAR RUTAS
// ═══════════════════════════════════════════════════════════════════
add_action('rest_api_init', 'an_register_dashboard_api');

function an_register_dashboard_api(): void
{
    $ns = 'an-luxury/v4';

    // ── Stats ──────────────────────────────────────────────────────
    register_rest_route($ns, '/dashboard/stats', [
        'methods'             => 'GET',
        'callback'            => 'an_api_stats',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Reservas (listado filtrable) ───────────────────────────────
    register_rest_route($ns, '/dashboard/reservas', [
        'methods'             => 'GET',
        'callback'            => 'an_api_reservas',
        'permission_callback' => 'an_api_check_permission',
        'args'                => [
            'estado'      => ['sanitize_callback' => 'sanitize_key'],
            'fecha_desde' => ['sanitize_callback' => 'sanitize_text_field'],
            'fecha_hasta' => ['sanitize_callback' => 'sanitize_text_field'],
            'staff_id'    => ['sanitize_callback' => 'absint'],
            'location_id' => ['sanitize_callback' => 'absint'],
            'search'      => ['sanitize_callback' => 'sanitize_text_field'],
            'page'        => ['sanitize_callback' => 'absint', 'default' => 1],
            'per_page'    => ['sanitize_callback' => 'absint', 'default' => 50],
        ],
    ]);

    // ── Turnos de hoy ──────────────────────────────────────────────
    register_rest_route($ns, '/dashboard/hoy', [
        'methods'             => 'GET',
        'callback'            => 'an_api_hoy',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Próximos turnos ────────────────────────────────────────────
    register_rest_route($ns, '/dashboard/proximos', [
        'methods'             => 'GET',
        'callback'            => 'an_api_proximos',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Actualizar estado de una reserva ──────────────────────────
    register_rest_route($ns, '/dashboard/reserva/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'an_api_update_reserva',
        'permission_callback' => 'an_api_check_permission',
        'args'                => [
            'id'     => ['validate_callback' => fn($v) => is_numeric($v)],
            'estado' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
        ],
    ]);

    // ── Crear reserva manual ───────────────────────────────────────
    register_rest_route($ns, '/dashboard/reserva', [
        'methods'             => 'POST',
        'callback'            => 'an_api_create_reserva',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Staff ──────────────────────────────────────────────────────
    register_rest_route($ns, '/dashboard/staff', [
        'methods'             => 'GET',
        'callback'            => 'an_api_staff',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Locations ─────────────────────────────────────────────────
    register_rest_route($ns, '/dashboard/locations', [
        'methods'             => 'GET',
        'callback'            => 'an_api_locations',
        'permission_callback' => 'an_api_check_permission',
    ]);

    // ── Slots disponibles (público) ────────────────────────────────
    register_rest_route($ns, '/slots', [
        'methods'             => 'GET',
        'callback'            => 'an_api_slots',
        'permission_callback' => '__return_true',
        'args'                => [
            'fecha'       => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'staff_id'    => ['sanitize_callback' => 'absint', 'default' => 0],
            'location_id' => ['sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);
}


// ═══════════════════════════════════════════════════════════════════
// PERMISSION CHECK — sesión WP + registro activo en dashboard_users
// ═══════════════════════════════════════════════════════════════════
function an_api_check_permission(WP_REST_Request $request): bool|WP_Error
{
    // Validar nonce REST (enviado como header X-WP-Nonce o parámetro _nonce)
    $nonce = $request->get_header('X-WP-Nonce')
          ?? $request->get_param('_nonce')
          ?? '';

    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        // Permitir también a admins de WordPress sin nonce (WP Admin)
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'an_unauthorized',
                'No autorizado. Incluí el nonce en X-WP-Nonce.',
                ['status' => 401]
            );
        }
    }

    if (!is_user_logged_in()) {
        return new WP_Error('an_unauthorized', 'Sesión requerida.', ['status' => 401]);
    }

    // Super admin siempre puede
    if (current_user_can('manage_options')) {
        return true;
    }

    // Verificar registro en dashboard_users
    if (!function_exists('an_dashboard_can_access') || !an_dashboard_can_access()) {
        return new WP_Error('an_forbidden', 'Sin acceso al dashboard.', ['status' => 403]);
    }

    return true;
}


// ═══════════════════════════════════════════════════════════════════
// HELPER — obtener location_ids permitidos para el usuario actual
// ═══════════════════════════════════════════════════════════════════
function an_api_allowed_locations(): array
{
    if (current_user_can('manage_options')) {
        return []; // vacío = sin restricción = ver todo
    }

    if (!function_exists('an_get_dashboard_user')) return [];
    $du = an_get_dashboard_user();
    if (!$du) return [];

    $ids = json_decode($du->location_ids ?: '[]', true);
    return is_array($ids) ? array_map('intval', $ids) : [];
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/stats
// ═══════════════════════════════════════════════════════════════════
function an_api_stats(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $tz = new DateTimeZone(AN_TIMEZONE);
    $hoy = (new DateTime('now', $tz))->format('Y-m-d');

    $locs = an_api_allowed_locations();
    $loc_cond = '';
    if (!empty($locs)) {
        $ph = implode(',', array_fill(0, count($locs), '%d'));
        $loc_cond = $wpdb->prepare("AND r.location_id IN ($ph)", ...$locs);
    }

    $stats = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN estado='pagado'    THEN 1 ELSE 0 END) AS pagadas,
            SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN estado='cancelado' THEN 1 ELSE 0 END) AS canceladas,
            SUM(CASE WHEN estado='pagado'    THEN precio ELSE 0 END) AS recaudado,
            SUM(CASE WHEN estado='pagado' AND DATE(fecha_turno)='{$hoy}' THEN precio ELSE 0 END) AS recaudado_hoy
         FROM {$wpdb->prefix}an_reservas_v4 r
         WHERE 1=1 $loc_cond"
    );

    $turnos_hoy = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}an_reservas_v4 r
         WHERE DATE(r.fecha_turno) = %s AND r.estado != 'cancelado' $loc_cond",
        $hoy
    ));

    return new WP_REST_Response([
        'total'          => (int) ($stats->total ?? 0),
        'pagadas'        => (int) ($stats->pagadas ?? 0),
        'pendientes'     => (int) ($stats->pendientes ?? 0),
        'canceladas'     => (int) ($stats->canceladas ?? 0),
        'recaudado'      => (float) ($stats->recaudado ?? 0),
        'recaudado_hoy'  => (float) ($stats->recaudado_hoy ?? 0),
        'turnos_hoy'     => $turnos_hoy,
        'timestamp'      => (new DateTime('now', new DateTimeZone(AN_TIMEZONE)))->format('c'),
    ], 200);
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/reservas
// ═══════════════════════════════════════════════════════════════════
function an_api_reservas(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $estado      = $request->get_param('estado') ?: '';
    $fecha_desde = $request->get_param('fecha_desde') ?: '';
    $fecha_hasta = $request->get_param('fecha_hasta') ?: '';
    $staff_id    = (int) $request->get_param('staff_id');
    $location_id = (int) $request->get_param('location_id');
    $search      = $request->get_param('search') ?: '';
    $page        = max(1, (int) $request->get_param('page'));
    $per_page    = min(200, max(1, (int) $request->get_param('per_page')));
    $offset      = ($page - 1) * $per_page;

    $where  = 'WHERE 1=1';
    $params = [];

    // Restricción de sucursales del usuario
    $locs = an_api_allowed_locations();
    if (!empty($locs)) {
        $ph = implode(',', array_fill(0, count($locs), '%d'));
        $where .= " AND r.location_id IN ($ph)";
        $params = array_merge($params, $locs);
    }

    if ($estado) {
        $where .= ' AND r.estado = %s';
        $params[] = $estado;
    }
    if ($staff_id) {
        $where .= ' AND r.staff_id = %d';
        $params[] = $staff_id;
    }
    if ($location_id) {
        $where .= ' AND r.location_id = %d';
        $params[] = $location_id;
    }
    if ($fecha_desde) {
        $where .= ' AND DATE(r.fecha_turno) >= %s';
        $params[] = $fecha_desde;
    }
    if ($fecha_hasta) {
        $where .= ' AND DATE(r.fecha_turno) <= %s';
        $params[] = $fecha_hasta;
    }
    if ($search) {
        $where .= ' AND (r.nombre_cliente LIKE %s OR r.email LIKE %s OR r.servicio LIKE %s OR r.whatsapp LIKE %s)';
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql_base = "FROM {$wpdb->prefix}an_reservas_v4 r
                 LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id    = s.id
                 LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
                 $where";

    $total = (int) $wpdb->get_var(!empty($params)
        ? $wpdb->prepare("SELECT COUNT(*) $sql_base", ...$params)
        : "SELECT COUNT(*) $sql_base"
    );

    $sql = "SELECT r.id, r.fecha_turno, r.servicio, r.precio, r.nombre_cliente,
                   r.email, r.whatsapp, r.estado, r.guia_ia, r.gcal_event_id, r.created_at,
                   s.name AS staff_name, l.name AS location_name
            $sql_base
            ORDER BY r.fecha_turno DESC
            LIMIT %d OFFSET %d";

    $params_paged = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_paged));

    return new WP_REST_Response([
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $per_page,
        'pages'      => (int) ceil($total / $per_page),
        'reservas'   => $rows,
    ], 200);
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/hoy
// ═══════════════════════════════════════════════════════════════════
function an_api_hoy(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $tz  = new DateTimeZone(AN_TIMEZONE);
    $hoy = (new DateTime('now', $tz))->format('Y-m-d');

    $locs = an_api_allowed_locations();
    $loc_cond = '';
    if (!empty($locs)) {
        $ph = implode(',', array_fill(0, count($locs), '%d'));
        $loc_cond = ' AND r.location_id IN (' . implode(',', $locs) . ')';
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.fecha_turno, r.servicio, r.precio, r.nombre_cliente,
                r.email, r.whatsapp, r.estado, r.guia_ia,
                s.name AS staff_name, l.name AS location_name
         FROM {$wpdb->prefix}an_reservas_v4 r
         LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id    = s.id
         LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
         WHERE DATE(r.fecha_turno) = %s $loc_cond
         ORDER BY r.fecha_turno ASC",
        $hoy
    ));

    return new WP_REST_Response(['fecha' => $hoy, 'turnos' => $rows], 200);
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/proximos
// ═══════════════════════════════════════════════════════════════════
function an_api_proximos(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $tz     = new DateTimeZone(AN_TIMEZONE);
    $manana = (new DateTime('+1 day', $tz))->format('Y-m-d');

    $locs = an_api_allowed_locations();
    $loc_cond = '';
    if (!empty($locs)) {
        $loc_cond = ' AND r.location_id IN (' . implode(',', $locs) . ')';
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.fecha_turno, r.servicio, r.precio, r.nombre_cliente,
                r.email, r.whatsapp, r.estado, r.guia_ia,
                s.name AS staff_name, l.name AS location_name
         FROM {$wpdb->prefix}an_reservas_v4 r
         LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id    = s.id
         LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
         WHERE DATE(r.fecha_turno) >= %s AND r.estado != 'cancelado' $loc_cond
         ORDER BY r.fecha_turno ASC
         LIMIT 100",
        $manana
    ));

    return new WP_REST_Response(['desde' => $manana, 'turnos' => $rows], 200);
}


// ═══════════════════════════════════════════════════════════════════
// PUT /dashboard/reserva/{id}
// Body JSON: { "estado": "pagado" | "pendiente" | "cancelado" }
// ═══════════════════════════════════════════════════════════════════
function an_api_update_reserva(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    global $wpdb;
    $id = (int) $request->get_param('id');
    $body = $request->get_json_params();
    $estado = sanitize_key($body['estado'] ?? $request->get_param('estado') ?? '');

    if (!in_array($estado, ['pagado', 'pendiente', 'cancelado'], true)) {
        return new WP_Error('an_invalid_estado', 'Estado inválido. Usa: pagado, pendiente, cancelado.', ['status' => 400]);
    }

    $reserva = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, l.id AS loc_chk FROM {$wpdb->prefix}an_reservas_v4 r
         LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
         WHERE r.id = %d LIMIT 1",
        $id
    ));

    if (!$reserva) {
        return new WP_Error('an_not_found', 'Reserva no encontrada.', ['status' => 404]);
    }

    // Verificar que el usuario tenga acceso a esta sucursal
    $locs = an_api_allowed_locations();
    if (!empty($locs) && !in_array((int) $reserva->location_id, $locs, true)) {
        return new WP_Error('an_forbidden', 'Sin acceso a esta reserva.', ['status' => 403]);
    }

    // Si se está confirmando como pagado, usar an_confirmar_reserva (con idempotencia)
    if ($estado === 'pagado' && $reserva->estado !== 'pagado') {
        if (function_exists('an_confirmar_reserva')) {
            an_confirmar_reserva($reserva);
        } else {
            $wpdb->update(
                $wpdb->prefix . 'an_reservas_v4',
                ['estado' => 'pagado'],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
        }
    } else {
        $wpdb->update(
            $wpdb->prefix . 'an_reservas_v4',
            ['estado' => $estado],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    $updated = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id = %d",
        $id
    ));

    return new WP_REST_Response(['success' => true, 'reserva' => $updated], 200);
}


// ═══════════════════════════════════════════════════════════════════
// POST /dashboard/reserva — Crear reserva manual (sin pago)
// Body JSON: { nombre, email, whatsapp, servicio, precio, fecha_turno,
//              staff_id, location_id, estado }
// ═══════════════════════════════════════════════════════════════════
function an_api_create_reserva(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    global $wpdb;
    $b = $request->get_json_params() ?: [];

    $nombre      = sanitize_text_field($b['nombre']      ?? '');
    $email       = sanitize_email($b['email']            ?? '');
    $whatsapp    = sanitize_text_field($b['whatsapp']     ?? '');
    $servicio    = sanitize_text_field($b['servicio']     ?? '');
    $precio      = floatval($b['precio']                 ?? 0);
    $fecha_turno = sanitize_text_field($b['fecha_turno'] ?? '');
    $staff_id    = (int) ($b['staff_id']                 ?? 0);
    $location_id = (int) ($b['location_id']              ?? 0);
    $estado      = sanitize_key($b['estado']             ?? 'pagado');

    if (!$nombre || !$servicio || !$precio || !$fecha_turno) {
        return new WP_Error('an_missing_fields', 'Faltan campos requeridos: nombre, servicio, precio, fecha_turno.', ['status' => 400]);
    }

    if (!in_array($estado, ['pagado', 'pendiente', 'cancelado'], true)) {
        $estado = 'pagado';
    }

    // Verificar acceso de sucursal
    $locs = an_api_allowed_locations();
    if (!empty($locs) && $location_id > 0 && !in_array($location_id, $locs, true)) {
        return new WP_Error('an_forbidden', 'Sin acceso a esa sucursal.', ['status' => 403]);
    }

    $wpdb->insert(
        $wpdb->prefix . 'an_reservas_v4',
        [
            'fecha_turno'    => $fecha_turno,
            'servicio'       => $servicio,
            'precio'         => $precio,
            'nombre_cliente' => $nombre,
            'email'          => $email,
            'whatsapp'       => $whatsapp,
            'estado'         => $estado,
            'staff_id'       => $staff_id,
            'location_id'    => $location_id,
        ],
        ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d']
    );

    $rid = $wpdb->insert_id;
    if (!$rid) {
        return new WP_Error('an_db_error', 'Error al guardar en base de datos.', ['status' => 500]);
    }

    // Si es pagado, disparar confirmación (email + GCal)
    if ($estado === 'pagado' && function_exists('an_confirmar_reserva')) {
        $reserva = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id = %d",
            $rid
        ));
        if ($reserva) {
            // Resetear estado a pendiente para que an_confirmar_reserva lo procese
            $wpdb->update(
                $wpdb->prefix . 'an_reservas_v4',
                ['estado' => 'pendiente'],
                ['id' => $rid],
                ['%s'],
                ['%d']
            );
            $reserva->estado = 'pendiente';
            an_confirmar_reserva($reserva);
        }
    }

    return new WP_REST_Response([
        'success'    => true,
        'reserva_id' => $rid,
        'mensaje'    => 'Reserva creada correctamente.',
    ], 201);
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/staff
// ═══════════════════════════════════════════════════════════════════
function an_api_staff(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT s.id, s.name, s.role, s.bio, s.photo_url, s.active,
                s.sort_order, s.location_id, l.name AS location_name
         FROM {$wpdb->prefix}an_staff s
         LEFT JOIN {$wpdb->prefix}an_locations l ON s.location_id = l.id
         ORDER BY s.sort_order ASC, s.name ASC"
    );

    $locs = an_api_allowed_locations();
    if (!empty($locs)) {
        $rows = array_filter($rows, fn($s) => !$s->location_id || in_array((int)$s->location_id, $locs));
        $rows = array_values($rows);
    }

    return new WP_REST_Response(['staff' => $rows], 200);
}


// ═══════════════════════════════════════════════════════════════════
// GET /dashboard/locations
// ═══════════════════════════════════════════════════════════════════
function an_api_locations(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, name, address, city, lat, lng, phone, whatsapp, active
         FROM {$wpdb->prefix}an_locations
         ORDER BY name ASC"
    );

    $locs = an_api_allowed_locations();
    if (!empty($locs)) {
        $rows = array_filter($rows, fn($l) => in_array((int)$l->id, $locs));
        $rows = array_values($rows);
    }

    return new WP_REST_Response(['locations' => $rows], 200);
}


// ═══════════════════════════════════════════════════════════════════
// GET /slots?fecha=YYYY-MM-DD&staff_id=0&location_id=0
// Endpoint público para verificar disponibilidad desde apps externas
// ═══════════════════════════════════════════════════════════════════
function an_api_slots(WP_REST_Request $request): WP_REST_Response
{
    $fecha       = sanitize_text_field($request->get_param('fecha'));
    $staff_id    = (int) $request->get_param('staff_id');
    $location_id = (int) $request->get_param('location_id');

    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return new WP_REST_Response(['error' => 'Fecha inválida. Formato: YYYY-MM-DD'], 400);
    }

    $all_slots = ['11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00'];

    if (!function_exists('an_occupied_slots_for')) {
        return new WP_REST_Response(['disponibles' => $all_slots, 'ocupados' => []], 200);
    }

    global $wpdb;
    $cal_id = '';
    if ($staff_id > 0) {
        $s = $wpdb->get_row($wpdb->prepare(
            "SELECT calendar_id FROM {$wpdb->prefix}an_staff WHERE id = %d",
            $staff_id
        ));
        $cal_id = $s->calendar_id ?? '';
    }

    $ocupados   = an_occupied_slots_for($fecha, $staff_id, $cal_id, $all_slots);
    $disponibles = array_values(array_diff($all_slots, $ocupados));

    return new WP_REST_Response([
        'fecha'       => $fecha,
        'disponibles' => $disponibles,
        'ocupados'    => array_values($ocupados),
        'all_slots'   => $all_slots,
    ], 200);
}


// ═══════════════════════════════════════════════════════════════════
// NONCE ENDPOINT — para que el dashboard frontend obtenga el nonce
// GET /wp-json/an-luxury/v4/nonce (requiere sesión activa)
// ═══════════════════════════════════════════════════════════════════
add_action('rest_api_init', function () {
    register_rest_route('an-luxury/v4', '/nonce', [
        'methods'             => 'GET',
        'callback'            => function () {
            if (!is_user_logged_in()) {
                return new WP_Error('an_unauthorized', 'Sesión requerida.', ['status' => 401]);
            }
            return new WP_REST_Response([
                'nonce'     => wp_create_nonce('wp_rest'),
                'user_id'   => get_current_user_id(),
                'is_admin'  => current_user_can('manage_options'),
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});
