<?php
/**
 * AN STUDIO — index.php
 * ═══════════════════════════════════════════════════════
 * Vista principal del dashboard de locales (/admin/)
 * Llamado desde auth.php después de verificar acceso.
 * Requiere: an-config.php + an-database.php + api.php activos
 *
 * Vista para dueños de locales / staff con acceso aprobado.
 * - Estadísticas de su sucursal
 * - Turnos de hoy
 * - Próximos turnos
 * - Crear reserva manual
 * - Logout
 */

if (!defined('ABSPATH')) exit;

// Verificar acceso (doble check)
if (!function_exists('an_dashboard_can_access') || !an_dashboard_can_access()) {
    wp_redirect(home_url('/admin/'));
    exit;
}

global $wpdb;
$du   = an_get_dashboard_user();
$user = wp_get_current_user();
$tz   = new DateTimeZone(AN_TIMEZONE);
$hoy  = (new DateTime('now', $tz))->format('Y-m-d');

// Sucursales permitidas
$loc_ids    = json_decode($du->location_ids ?: '[]', true);
$is_full    = empty($loc_ids); // Sin restricción = ve todo
$all_locs   = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}an_locations WHERE active=1 ORDER BY name ASC");
$all_staff  = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}an_staff WHERE active=1 ORDER BY name ASC");

// Filtro de sucursal para queries
$loc_where  = '';
if (!$is_full && !empty($loc_ids)) {
    $ph        = implode(',', array_map('intval', $loc_ids));
    $loc_where = " AND r.location_id IN ($ph)";
}

// Estadísticas
$stats = $wpdb->get_row(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado='pagado'    THEN 1 ELSE 0 END) AS pagadas,
        SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN estado='pagado'    THEN precio ELSE 0 END) AS recaudado,
        SUM(CASE WHEN estado='pagado' AND DATE(fecha_turno)='{$hoy}' THEN precio ELSE 0 END) AS recaudado_hoy
     FROM {$wpdb->prefix}an_reservas_v4 r
     WHERE 1=1 $loc_where"
);

// Turnos de hoy
$turnos_hoy = $wpdb->get_results($wpdb->prepare(
    "SELECT r.id, r.fecha_turno, r.nombre_cliente, r.whatsapp, r.email,
            r.servicio, r.precio, r.estado, r.guia_ia,
            s.name AS staff_name, l.name AS loc_name
     FROM {$wpdb->prefix}an_reservas_v4 r
     LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id    = s.id
     LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
     WHERE DATE(r.fecha_turno) = %s $loc_where
     ORDER BY r.fecha_turno ASC",
    $hoy
));

// Próximos (mañana en adelante)
$manana  = (new DateTime('+1 day', $tz))->format('Y-m-d');
$proximos = $wpdb->get_results($wpdb->prepare(
    "SELECT r.id, r.fecha_turno, r.nombre_cliente, r.whatsapp,
            r.servicio, r.precio, r.estado,
            s.name AS staff_name, l.name AS loc_name
     FROM {$wpdb->prefix}an_reservas_v4 r
     LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id    = s.id
     LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id = l.id
     WHERE DATE(r.fecha_turno) >= %s AND r.estado != 'cancelado' $loc_where
     ORDER BY r.fecha_turno ASC
     LIMIT 60",
    $manana
));

// Nonce REST para llamadas API
$rest_nonce = wp_create_nonce('wp_rest');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AN Studio · Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,600&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0 }
        :root {
            --gold: #BFA37C; --gold-l: #FFEBAD; --dark: #0f0a07; --surface: #1a0d06;
            --border: rgba(191,163,124,.18); --text: #f5ede6; --muted: #7c685b;
            --green: #22c55e; --yellow: #f59e0b; --red: #ef4444; --blue: #3b82f6;
        }
        body { font-family:'Poppins',sans-serif; background:#f8f9fa; color:#1f2937; min-height:100vh; }

        /* ── Navbar ── */
        .an-nav {
            background:linear-gradient(135deg,#1a0d06,#2d1609);
            padding:0 24px;
            display:flex; align-items:center; justify-content:space-between;
            height:58px; position:sticky; top:0; z-index:100;
            box-shadow:0 2px 20px rgba(0,0,0,.3);
        }
        .an-nav-brand { font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-style:italic; background:linear-gradient(90deg,#BFA37C,#FFEBAD); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .an-nav-user { display:flex; align-items:center; gap:12px; }
        .an-nav-name { font-size:12px; color:#BFA37C; font-weight:500; }
        .an-nav-logout { font-size:11px; color:#7c685b; text-decoration:none; padding:5px 12px; border:1px solid rgba(191,163,124,.2); border-radius:999px; transition:all .2s; }
        .an-nav-logout:hover { color:#BFA37C; border-color:#BFA37C; }

        /* ── Layout ── */
        .an-main { max-width:1100px; margin:0 auto; padding:28px 20px; }

        /* ── Greeting ── */
        .an-greeting { margin-bottom:24px; }
        .an-greeting h1 { font-family:'Cormorant Garamond',serif; font-size:1.8rem; font-style:italic; color:#1a0d06; }
        .an-greeting p { font-size:12px; color:#9ca3af; margin-top:2px; }

        /* ── Stats ── */
        .an-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:28px; }
        .an-stat { background:#fff; border-radius:12px; padding:18px; border-left:4px solid; box-shadow:0 1px 8px rgba(0,0,0,.06); }
        .an-stat-val { font-size:22px; font-weight:700; margin-bottom:2px; }
        .an-stat-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; }

        /* ── Sections ── */
        .an-section { background:#fff; border-radius:14px; box-shadow:0 1px 8px rgba(0,0,0,.06); margin-bottom:24px; overflow:hidden; }
        .an-section-hd { padding:14px 20px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .an-section-hd h2 { font-size:14px; font-weight:700; color:#1f2937; }
        .an-badge { background:#6366f1; color:#fff; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700; margin-left:6px; }
        .an-badge-hoy { background:#22c55e; }

        /* ── Tabla ── */
        .an-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        .an-tbl th { padding:9px 14px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; background:#f9fafb; border-bottom:2px solid #f3f4f6; }
        .an-tbl td { padding:9px 14px; border-bottom:1px solid #f8f8f8; vertical-align:middle; }
        .an-tbl tr:last-child td { border-bottom:none; }
        .an-tbl tr:hover td { background:#fafafa; }
        .an-day-sep td { background:#f8fafc; color:#6366f1; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; padding:6px 14px; }
        .an-empty td { text-align:center; padding:28px; color:#9ca3af; font-style:italic; }

        /* ── Pills ── */
        .pill { display:inline-block; padding:3px 9px; border-radius:999px; font-size:10px; font-weight:700; white-space:nowrap; }
        .pill-pagado    { background:#dcfce7; color:#15803d; }
        .pill-pendiente { background:#fef9c3; color:#854d0e; }
        .pill-cancelado { background:#fee2e2; color:#991b1b; }

        /* ── Actions ── */
        .an-act { display:inline-flex; align-items:center; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:600; text-decoration:none; border:none; cursor:pointer; font-family:'Poppins',sans-serif; transition:opacity .15s; }
        .an-act:hover { opacity:.8; }
        .an-act-wa  { background:#dcfce7; color:#15803d; }
        .an-act-cancel { background:#fee2e2; color:#991b1b; }
        .an-act-pay { background:#dbeafe; color:#1d4ed8; }
        .an-act-ai  { background:#ede9fe; color:#6d28d9; cursor:help; }

        /* ── Modal nueva reserva ── */
        .an-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; display:none; align-items:center; justify-content:center; padding:20px; }
        .an-modal-bg.open { display:flex; }
        .an-modal { background:#fff; border-radius:16px; padding:28px; width:100%; max-width:480px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
        .an-modal h3 { font-size:16px; font-weight:700; margin-bottom:20px; color:#1a0d06; }
        .an-mfield { margin-bottom:14px; }
        .an-mfield label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#BFA37C; margin-bottom:5px; }
        .an-mfield input, .an-mfield select, .an-mfield textarea { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:10px 12px; font-size:13px; font-family:'Poppins',sans-serif; color:#1f2937; outline:none; transition:border-color .2s; }
        .an-mfield input:focus, .an-mfield select:focus { border-color:#BFA37C; }
        .an-modal-actions { display:flex; gap:10px; margin-top:20px; }
        .an-btn-gold { background:linear-gradient(135deg,#BFA37C,#FFEBAD,#C89B6D); color:#1a0d06; border:none; border-radius:8px; padding:11px 20px; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; font-family:'Poppins',sans-serif; transition:opacity .2s; }
        .an-btn-gold:hover { opacity:.9; }
        .an-btn-ghost { background:none; border:1px solid #d1d5db; color:#6b7280; border-radius:8px; padding:10px 16px; font-size:12px; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; }
        .an-btn-add { background:linear-gradient(135deg,#BFA37C,#FFEBAD); color:#1a0d06; border:none; border-radius:8px; padding:7px 16px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; font-family:'Poppins',sans-serif; }

        /* ── Toast ── */
        .an-toast { position:fixed; bottom:20px; right:20px; padding:12px 20px; border-radius:10px; font-size:13px; font-weight:600; z-index:300; opacity:0; transform:translateY(10px); transition:all .3s; pointer-events:none; }
        .an-toast.show { opacity:1; transform:translateY(0); }
        .an-toast-ok  { background:#1a0d06; color:#BFA37C; }
        .an-toast-err { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        /* ── Live dot ── */
        .an-live { display:flex; align-items:center; gap:6px; font-size:11px; color:#166534; }
        .an-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; animation:an-blink 2s ease-in-out infinite; }
        @keyframes an-blink { 0%,100%{opacity:1} 50%{opacity:.2} }

        /* ── Responsive ── */
        @media(max-width:640px) {
            .an-stats { grid-template-columns:1fr 1fr; }
            .an-tbl th:nth-child(n+5), .an-tbl td:nth-child(n+5) { display:none; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="an-nav">
    <div class="an-nav-brand">AN Studio</div>
    <div class="an-nav-user">
        <span class="an-nav-name">👤 <?php echo esc_html($user->display_name ?: $user->user_login); ?></span>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/admin/'))); ?>" class="an-nav-logout">Cerrar sesión</a>
    </div>
</nav>

<!-- Main -->
<main class="an-main">

    <!-- Greeting -->
    <div class="an-greeting">
        <h1>Buen <?php echo an_saludo(); ?> 👋</h1>
        <p><?php echo esc_html(date_i18n('l d \d\e F Y', strtotime($hoy))); ?> ·
            <?php echo $is_full ? 'Todas las sucursales' : implode(', ', array_column(array_filter($all_locs, fn($l) => in_array($l->id, $loc_ids)), 'name')); ?>
        </p>
    </div>

    <!-- Stats -->
    <div class="an-stats">
        <?php foreach ([
            ['Total reservas',  (int)($stats->total??0),      '#6366f1', 'stat-total'],
            ['Pagadas',         (int)($stats->pagadas??0),     '#22c55e', 'stat-pagadas'],
            ['Pendientes',      (int)($stats->pendientes??0),  '#f59e0b', 'stat-pendientes'],
            ['Recaudado total', '$'.number_format((float)($stats->recaudado??0),0,',','.'), '#ec4899', 'stat-recaudado'],
            ['Recaudado hoy',   '$'.number_format((float)($stats->recaudado_hoy??0),0,',','.'), '#BFA37C', 'stat-hoy'],
        ] as [$lbl, $val, $color, $id]): ?>
            <div class="an-stat" style="border-color:<?php echo $color; ?>;">
                <div id="<?php echo $id; ?>" class="an-stat-val" style="color:<?php echo $color; ?>;"><?php echo $val; ?></div>
                <div class="an-stat-lbl"><?php echo $lbl; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Turnos de hoy -->
    <div class="an-section">
        <div class="an-section-hd">
            <div>
                <h2>📅 Hoy — <?php echo date_i18n('d/m/Y', strtotime($hoy)); ?>
                    <span class="an-badge an-badge-hoy" id="badge-hoy"><?php echo count($turnos_hoy); ?></span>
                </h2>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="an-live"><div class="an-dot"></div><span id="an-live-label">En vivo</span></div>
                <button class="an-btn-add" onclick="openModal()">+ Nueva reserva</button>
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table class="an-tbl">
                <thead>
                    <tr>
                        <th>Hora</th><th>Clienta</th><th>WhatsApp</th>
                        <th>Servicio</th><th>Profesional</th><th>Total</th>
                        <th>Estado</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="tbody-hoy">
                    <?php if (empty($turnos_hoy)): ?>
                        <tr class="an-empty"><td colspan="8">No hay turnos para hoy.</td></tr>
                    <?php else: foreach ($turnos_hoy as $r): ?>
                        <tr id="row-<?php echo $r->id; ?>">
                            <td style="font-weight:700;"><?php echo date('H:i', strtotime($r->fecha_turno)); ?>hs</td>
                            <td style="font-weight:600;"><?php echo esc_html($r->nombre_cliente); ?></td>
                            <td><a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $r->whatsapp); ?>" target="_blank" style="color:#22c55e;font-size:11px;text-decoration:none;"><?php echo esc_html($r->whatsapp); ?></a></td>
                            <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->servicio); ?></td>
                            <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->staff_name ?: '—'); ?></td>
                            <td style="font-weight:700;">$<?php echo number_format($r->precio, 0, ',', '.'); ?></td>
                            <td><span class="pill pill-<?php echo esc_attr($r->estado); ?>"><?php echo an_pill_text($r->estado); ?></span></td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $r->whatsapp); ?>" target="_blank" class="an-act an-act-wa">💬</a>
                                    <?php if ($r->estado !== 'cancelado'): ?>
                                        <button class="an-act an-act-cancel" onclick="anCancel(<?php echo $r->id; ?>)">✕</button>
                                    <?php endif; ?>
                                    <?php if ($r->estado === 'pendiente'): ?>
                                        <button class="an-act an-act-pay" onclick="anConfirmar(<?php echo $r->id; ?>)">✅</button>
                                    <?php endif; ?>
                                    <?php if (!empty($r->guia_ia)): ?>
                                        <span class="an-act an-act-ai" title="<?php echo esc_attr($r->guia_ia); ?>">✨</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Próximos turnos -->
    <div class="an-section">
        <div class="an-section-hd">
            <h2>🗓️ Próximos turnos <span class="an-badge" id="badge-proximos"><?php echo count($proximos); ?></span></h2>
        </div>
        <div style="overflow-x:auto;">
            <table class="an-tbl">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Hora</th><th>Clienta</th><th>WhatsApp</th>
                        <th>Servicio</th><th>Profesional</th><th>Total</th>
                        <th>Estado</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="tbody-proximos">
                    <?php if (empty($proximos)): ?>
                        <tr class="an-empty"><td colspan="9">Sin turnos próximos.</td></tr>
                    <?php else:
                        $last = '';
                        foreach ($proximos as $r):
                            $dia = date('Y-m-d', strtotime($r->fecha_turno));
                            if ($dia !== $last): $last = $dia; ?>
                                <tr class="an-day-sep"><td colspan="9">📅 <?php echo esc_html(date_i18n('D d/m', strtotime($dia))); ?></td></tr>
                            <?php endif; ?>
                            <tr id="row-<?php echo $r->id; ?>">
                                <td style="font-size:11px;color:#9ca3af;"><?php echo date_i18n('d/m', strtotime($r->fecha_turno)); ?></td>
                                <td style="font-weight:700;"><?php echo date('H:i', strtotime($r->fecha_turno)); ?>hs</td>
                                <td style="font-weight:600;"><?php echo esc_html($r->nombre_cliente); ?></td>
                                <td><a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $r->whatsapp); ?>" target="_blank" style="color:#22c55e;font-size:11px;text-decoration:none;"><?php echo esc_html($r->whatsapp); ?></a></td>
                                <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->servicio); ?></td>
                                <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->staff_name ?: '—'); ?></td>
                                <td style="font-weight:700;">$<?php echo number_format($r->precio, 0, ',', '.'); ?></td>
                                <td><span class="pill pill-<?php echo esc_attr($r->estado); ?>"><?php echo an_pill_text($r->estado); ?></span></td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $r->whatsapp); ?>" target="_blank" class="an-act an-act-wa">💬</a>
                                        <?php if ($r->estado !== 'cancelado'): ?>
                                            <button class="an-act an-act-cancel" onclick="anCancel(<?php echo $r->id; ?>)">✕</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- Modal nueva reserva manual -->
<div class="an-modal-bg" id="an-modal-bg">
    <div class="an-modal">
        <h3>✨ Nueva reserva manual</h3>

        <div class="an-mfield">
            <label>Nombre completo *</label>
            <input type="text" id="m-nombre" placeholder="Ana García">
        </div>
        <div class="an-mfield">
            <label>WhatsApp *</label>
            <input type="tel" id="m-wa" placeholder="+54 9 11 1234-5678">
        </div>
        <div class="an-mfield">
            <label>Email</label>
            <input type="email" id="m-email" placeholder="ana@gmail.com">
        </div>
        <div class="an-mfield">
            <label>Servicio *</label>
            <input type="text" id="m-servicio" placeholder="Manicura permanente">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="an-mfield">
                <label>Precio *</label>
                <input type="number" id="m-precio" placeholder="8500" min="0">
            </div>
            <div class="an-mfield">
                <label>Estado</label>
                <select id="m-estado">
                    <option value="pagado">✅ Pagado</option>
                    <option value="pendiente">⏳ Pendiente</option>
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="an-mfield">
                <label>Fecha *</label>
                <input type="date" id="m-fecha">
            </div>
            <div class="an-mfield">
                <label>Hora *</label>
                <select id="m-hora">
                    <?php foreach (['11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00'] as $h): ?>
                        <option value="<?php echo $h; ?>"><?php echo $h; ?>hs</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if (count($all_staff) > 0): ?>
            <div class="an-mfield">
                <label>Profesional</label>
                <select id="m-staff">
                    <option value="0">Sin preferencia</option>
                    <?php foreach ($all_staff as $s): ?>
                        <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if (count($all_locs) > 1): ?>
            <div class="an-mfield">
                <label>Sucursal</label>
                <select id="m-location">
                    <option value="0">Sin especificar</option>
                    <?php foreach ($all_locs as $l): ?>
                        <?php if ($is_full || in_array($l->id, $loc_ids)): ?>
                            <option value="<?php echo $l->id; ?>"><?php echo esc_html($l->name); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div id="m-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>

        <div class="an-modal-actions">
            <button class="an-btn-gold" onclick="guardarReserva()">Guardar reserva</button>
            <button class="an-btn-ghost" onclick="closeModal()">Cancelar</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="an-toast" id="an-toast"></div>

<script>
(function () {
    'use strict';

    var API_BASE = '<?php echo esc_js(rest_url('an-luxury/v4/dashboard')); ?>';
    var NONCE    = '<?php echo esc_js($rest_nonce); ?>';
    var REFRESH_MS = 20000;

    function apiCall(method, endpoint, body) {
        return fetch(API_BASE + endpoint, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE,
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) { return r.json(); });
    }

    /* ── Toast ── */
    function toast(msg, ok) {
        var t = document.getElementById('an-toast');
        t.textContent = msg;
        t.className = 'an-toast show ' + (ok !== false ? 'an-toast-ok' : 'an-toast-err');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('show'); }, 3500);
    }

    /* ── Cancelar reserva ── */
    window.anCancel = function (id) {
        if (!confirm('¿Cancelar esta reserva?')) return;
        apiCall('PUT', '/reserva/' + id, { estado: 'cancelado' })
            .then(function (d) {
                if (d.success || d.reserva) {
                    updatePill(id, 'cancelado');
                    document.querySelectorAll('#row-' + id + ' .an-act-cancel').forEach(function (b) { b.remove(); });
                    document.querySelectorAll('#row-' + id + ' .an-act-pay').forEach(function (b) { b.remove(); });
                    toast('Reserva cancelada.');
                } else {
                    toast('Error al cancelar.', false);
                }
            }).catch(function () { toast('Error de red.', false); });
    };

    /* ── Confirmar como pagada ── */
    window.anConfirmar = function (id) {
        if (!confirm('¿Confirmar como pagada esta reserva?')) return;
        apiCall('PUT', '/reserva/' + id, { estado: 'pagado' })
            .then(function (d) {
                if (d.success || d.reserva) {
                    updatePill(id, 'pagado');
                    document.querySelectorAll('#row-' + id + ' .an-act-pay').forEach(function (b) { b.remove(); });
                    toast('✅ Reserva confirmada como pagada.');
                } else {
                    toast('Error al confirmar.', false);
                }
            }).catch(function () { toast('Error de red.', false); });
    };

    function updatePill(id, estado) {
        var pills = document.querySelectorAll('#row-' + id + ' .pill');
        var txt = { pagado: '✅ Pagado', pendiente: '⏳ Pendiente', cancelado: '❌ Cancelado' }[estado] || estado;
        pills.forEach(function (p) {
            p.className = 'pill pill-' + estado;
            p.textContent = txt;
        });
    }

    /* ── Modal nueva reserva ── */
    window.openModal = function () {
        // Fecha por defecto = hoy
        var d = new Date(); d.setDate(d.getDate()); // puede ser hoy en manual
        document.getElementById('m-fecha').value = d.toISOString().split('T')[0];
        document.getElementById('an-modal-bg').classList.add('open');
    };
    window.closeModal = function () {
        document.getElementById('an-modal-bg').classList.remove('open');
        document.getElementById('m-error').style.display = 'none';
    };

    window.guardarReserva = function () {
        var nombre  = (document.getElementById('m-nombre').value   || '').trim();
        var wa      = (document.getElementById('m-wa').value        || '').trim();
        var email   = (document.getElementById('m-email').value     || '').trim();
        var servicio= (document.getElementById('m-servicio').value  || '').trim();
        var precio  = parseFloat(document.getElementById('m-precio').value || 0);
        var estado  = document.getElementById('m-estado').value;
        var fecha   = document.getElementById('m-fecha').value;
        var hora    = document.getElementById('m-hora').value;
        var staffEl = document.getElementById('m-staff');
        var locEl   = document.getElementById('m-location');

        if (!nombre || !wa || !servicio || !precio || !fecha || !hora) {
            var errEl = document.getElementById('m-error');
            errEl.textContent = 'Completá los campos obligatorios: nombre, WhatsApp, servicio, precio, fecha y hora.';
            errEl.style.display = 'block';
            return;
        }

        var body = {
            nombre: nombre, whatsapp: wa, email: email,
            servicio: servicio, precio: precio, estado: estado,
            fecha_turno: fecha + 'T' + hora + ':00',
            staff_id:    staffEl ? parseInt(staffEl.value||0) : 0,
            location_id: locEl   ? parseInt(locEl.value||0)   : 0,
        };

        apiCall('POST', '/reserva', body)
            .then(function (d) {
                if (d.success) {
                    toast('✅ Reserva creada correctamente.');
                    closeModal();
                    // Refresh suave después de 1s
                    setTimeout(doRefresh, 1000);
                } else {
                    var errEl = document.getElementById('m-error');
                    errEl.textContent = d.message || d.code || 'Error al guardar.';
                    errEl.style.display = 'block';
                }
            }).catch(function () {
                document.getElementById('m-error').textContent = 'Error de red.';
                document.getElementById('m-error').style.display = 'block';
            });
    };

    /* ── Auto-refresh de datos ── */
    function doRefresh() {
        document.getElementById('an-live-label').textContent = 'Actualizando...';

        Promise.all([
            apiCall('GET', '/stats?' + Date.now()),
            apiCall('GET', '/hoy?' + Date.now()),
        ]).then(function (results) {
            var stats = results[0];
            var hoyData = results[1];

            // Actualizar stats
            if (stats && stats.total !== undefined) {
                setVal('stat-total',     stats.pagadas + stats.pendientes + stats.canceladas);
                setVal('stat-pagadas',   stats.pagadas);
                setVal('stat-pendientes', stats.pendientes);
                setVal('stat-recaudado', '$' + Number(stats.recaudado||0).toLocaleString('es-AR', {maximumFractionDigits:0}));
                setVal('stat-hoy',       '$' + Number(stats.recaudado_hoy||0).toLocaleString('es-AR', {maximumFractionDigits:0}));
                document.getElementById('badge-hoy').textContent = stats.turnos_hoy || 0;
            }

            document.getElementById('an-live-label').textContent = 'En vivo · ' + getHMS();
        }).catch(function () {
            document.getElementById('an-live-label').textContent = 'En vivo';
        });
    }

    function setVal(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        var s = String(val);
        if (el.textContent !== s) {
            el.textContent = s;
            el.style.opacity = '.5';
            setTimeout(function () { el.style.opacity = '1'; el.style.transition = 'opacity .4s'; }, 50);
        }
    }

    function getHMS() {
        var d = new Date();
        return d.getHours().toString().padStart(2,'0')+':'+d.getMinutes().toString().padStart(2,'0')+':'+d.getSeconds().toString().padStart(2,'0');
    }

    /* Cerrar modal al click fuera */
    document.getElementById('an-modal-bg').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    setInterval(doRefresh, REFRESH_MS);

})();
</script>

</body>
</html>
<?php

// ═══════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════
function an_saludo(): string
{
    $h = (int) (new DateTime('now', new DateTimeZone(AN_TIMEZONE)))->format('G');
    if ($h < 12) return 'día';
    if ($h < 19) return 'tarde';
    return 'noche';
}

function an_pill_text(string $estado): string
{
    return ['pagado' => '✅ Pagado', 'pendiente' => '⏳ Pendiente', 'cancelado' => '❌ Cancelado'][$estado] ?? $estado;
}
