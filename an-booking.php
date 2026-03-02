<?php
/**
 * AN STUDIO — an-booking.php
 * ═══════════════════════════════════════════════════════
 * Snippet independiente: lógica de reservas + Mercado Pago
 * Requiere: an-config.php + an-database.php + an-gcal.php activos
 *
 * Contiene:
 *   - AJAX: an_check_google_calendar  → slots disponibles
 *   - AJAX: an_final_pago             → crear reserva + preferencia MP
 *   - GTM purchase event en /pago-exitoso
 */


// ═══════════════════════════════════════════════════════════════════
// AJAX: DISPONIBILIDAD DE HORARIOS
// ═══════════════════════════════════════════════════════════════════
add_action('wp_ajax_an_check_google_calendar', 'an_check_calendar_handler');
add_action('wp_ajax_nopriv_an_check_google_calendar', 'an_check_calendar_handler');

function an_check_calendar_handler()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['nonce'] ?? ''), 'an_studio_nonce')) {
        wp_send_json([]);
    }

    $fecha = sanitize_text_field($_POST['fecha'] ?? '');
    $staff_id = intval($_POST['staff_id'] ?? 0);
    $location_id = intval($_POST['location_id'] ?? 0);
    if (!$fecha)
        wp_send_json([]);

    $slots = ['11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'];
    global $wpdb;

    if ($staff_id === 0 && $location_id > 0) {
        // Sin preferencia de profesional → calcular disponibilidad cruzada de todos
        $staff_list = $wpdb->get_results($wpdb->prepare(
            "SELECT id, calendar_id FROM {$wpdb->prefix}an_staff
             WHERE active = 1 AND (location_id = %d OR location_id = 0)",
            $location_id
        ));

        if (empty($staff_list)) {
            $ocupados = an_occupied_slots_for($fecha, 0, '', $slots);
        } else {
            $per_staff = [];
            foreach ($staff_list as $s) {
                $per_staff[] = an_occupied_slots_for($fecha, (int) $s->id, $s->calendar_id ?? '', $slots);
            }
            // Solo bloquear un slot si TODOS los profesionales están ocupados en ese horario
            $ocupados = count($per_staff) === 1
                ? $per_staff[0]
                : array_values(call_user_func_array('array_intersect', $per_staff));
        }
    } else {
        $cal_id = '';
        if ($staff_id > 0) {
            $s = $wpdb->get_row($wpdb->prepare(
                "SELECT calendar_id FROM {$wpdb->prefix}an_staff WHERE id = %d",
                $staff_id
            ));
            $cal_id = $s->calendar_id ?? '';
        }
        $ocupados = an_occupied_slots_for($fecha, $staff_id, $cal_id, $slots);
    }

    wp_send_json(array_values(array_unique($ocupados)));
}


// ═══════════════════════════════════════════════════════════════════
// AJAX: CREAR RESERVA + PREFERENCIA MERCADO PAGO
// ═══════════════════════════════════════════════════════════════════
add_action('wp_ajax_an_final_pago', 'an_final_pago_handler');
add_action('wp_ajax_nopriv_an_final_pago', 'an_final_pago_handler');

function an_final_pago_handler()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['nonce'] ?? ''), 'an_studio_nonce')) {
        wp_send_json_error(['msg' => 'Nonce inválido']);
    }

    global $wpdb;

    $s = sanitize_text_field($_POST['s'] ?? '');
    $p = floatval($_POST['p'] ?? 0);
    $f = sanitize_text_field($_POST['f'] ?? '');
    $n = sanitize_text_field($_POST['n'] ?? '');
    $e = sanitize_email($_POST['e'] ?? '');
    $w = sanitize_text_field($_POST['w'] ?? '');
    $sid = intval($_POST['staff_id'] ?? 0);
    $lid = intval($_POST['location_id'] ?? 0);
    $sn = sanitize_text_field($_POST['staff_name'] ?? '');
    $ln = sanitize_text_field($_POST['location_name'] ?? '');

    if (!$s || !$p || !$f || !$n) {
        wp_send_json_error(['msg' => 'Datos incompletos']);
    }

    // Auto-asignar profesional si eligió "sin preferencia"
    if ($sid === 0 && $lid > 0) {
        $fecha_dia = substr($f, 0, 10);
        $auto = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id, s.name,
             (SELECT COUNT(*) FROM {$wpdb->prefix}an_reservas_v4 r
              WHERE r.staff_id = s.id
              AND DATE(r.fecha_turno) = %s
              AND r.estado IN ('pagado','pendiente')) AS cnt
             FROM {$wpdb->prefix}an_staff s
             WHERE s.active = 1 AND (s.location_id = %d OR s.location_id = 0)
             ORDER BY cnt ASC, RAND() LIMIT 1",
            $fecha_dia,
            $lid
        ));
        if ($auto) {
            $sid = (int) $auto->id;
            $sn = $auto->name;
        }
    }

    // Insertar reserva en estado pendiente
    $wpdb->insert(
        $wpdb->prefix . 'an_reservas_v4',
        [
            'fecha_turno' => $f,
            'servicio' => $s,
            'precio' => $p,
            'nombre_cliente' => $n,
            'email' => $e,
            'whatsapp' => $w,
            'estado' => 'pendiente',
            'staff_id' => $sid,
            'location_id' => $lid,
        ],
        ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d']
    );
    $rid = $wpdb->insert_id;
    if (!$rid)
        wp_send_json_error(['msg' => 'Error al guardar reserva en BD']);

    // Crear preferencia en Mercado Pago
    $title = trim(($ln ? $ln . ' — ' : '') . 'AN Studio: ' . $s);

    $mp = wp_remote_post('https://api.mercadopago.com/checkout/preferences', [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . AN_MP_TOKEN,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'items' => [
                [
                    'title' => $title,
                    'quantity' => 1,
                    'unit_price' => $p,
                    'currency_id' => 'ARS',
                ]
            ],
            'payer' => ['name' => $n],
            'external_reference' => (string) $rid,
            'back_urls' => [
                'success' => home_url('/pago-exitoso'),
                'failure' => home_url('/agenda-facil'),
                'pending' => home_url('/pago-exitoso'),
            ],
            'auto_return' => 'approved',
            'statement_descriptor' => 'AN STUDIO',
            'notification_url' => home_url('/wp-json/an-luxury/v4/pago'),
            'payment_methods' => ['installments' => 12],
        ]),
    ]);

    if (is_wp_error($mp)) {
        wp_send_json_error(['msg' => 'Error MP: ' . $mp->get_error_message()]);
    }

    $mpd = json_decode(wp_remote_retrieve_body($mp));
    if (empty($mpd->id)) {
        wp_send_json_error(['msg' => 'Sin preference_id de MP']);
    }

    wp_send_json_success([
        'reserva_id' => $rid,
        'preference_id' => $mpd->id,
        'url' => $mpd->init_point ?? '',
    ]);
}


// ═══════════════════════════════════════════════════════════════════
// GTM — PURCHASE EVENT EN /pago-exitoso
// ═══════════════════════════════════════════════════════════════════
add_action('wp_footer', function () {
    if (!is_page('pago-exitoso'))
        return;

    $payment_id = intval($_GET['payment_id'] ?? 0);
    $status = sanitize_text_field($_GET['collection_status'] ?? $_GET['status'] ?? '');
    $reserva_id = intval($_GET['external_reference'] ?? 0);

    if (!$payment_id || !$reserva_id)
        return;
    if (!in_array($status, ['approved', 'pending'], true))
        return;

    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id = %d LIMIT 1",
        $reserva_id
    ));
    if (!$r)
        return;

    // Disparar GTM solo una vez por reserva (cookie de 24h)
    $ck = 'an_gtm_fired_' . $reserva_id;
    if (isset($_COOKIE[$ck]))
        return;
    setcookie($ck, '1', time() + 86400, '/');
    ?>
    <script>
        (function () {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                event: 'an_purchase',
                ecommerce: {
                    transaction_id: '<?php echo $reserva_id; ?>',
                    value:    <?php echo (float) $r->precio; ?>,
                currency: 'ARS',
                items: [{
                    item_id: '<?php echo $reserva_id; ?>',
                    item_name: '<?php echo esc_js($r->servicio); ?>',
                    price:     <?php echo (float) $r->precio; ?>,
                        quantity: 1,
                }],
            },
        an_cliente: '<?php echo esc_js($r->nombre_cliente); ?>',
            an_reserva_id: <?php echo $reserva_id; ?>,
                an_payment_id: <?php echo $payment_id; ?>,
        });
    }) ();
    </script>
    <?php
});