<?php
/**
 * AN STUDIO — an-confirmar.php
 * ═══════════════════════════════════════════════════════
 * Snippet independiente: confirmación de reserva + webhook MP
 * Requiere: an-config.php + an-database.php + an-gcal.php activos
 *
 * Contiene:
 *   - an_confirmar_reserva()  ← función central con fix de idempotencia
 *   - Webhook REST de Mercado Pago  /wp-json/an-luxury/v4/pago
 *   - Página de éxito /pago-exitoso (fallback cuando el webhook no llega)
 *
 * FIX BUG CRÍTICO v16:
 *   Antes: an_confirmar_reserva() no verificaba si ya estaba pagada →
 *   condición de carrera entre webhook + botón manual → email/gcal doble,
 *   estado que volvía a pendiente.
 *
 *   Ahora: UPDATE atómico con WHERE estado!='pagado'.
 *   Si 0 filas afectadas → ya fue procesado por otro hilo → return.
 *   Solo UN proceso puede ganar la carrera → email/gcal se ejecutan 1 sola vez.
 */


// ═══════════════════════════════════════════════════════════════════
// FUNCIÓN CENTRAL — CONFIRMAR RESERVA
// ═══════════════════════════════════════════════════════════════════
function an_confirmar_reserva($reserva)
{
    global $wpdb;

    // ── IDEMPOTENCIA ATÓMICA ─────────────────────────────────────────
    // UPDATE con WHERE estado!='pagado' — si otra llamada (webhook, botón, éxito)
    // ya lo procesó, este UPDATE afecta 0 filas y salimos inmediatamente.
    // Esto evita: email doble, evento GCal doble, race condition.
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}an_reservas_v4
         SET estado = 'pagado'
         WHERE id = %d AND estado != 'pagado'",
        $reserva->id
    ));

    if ($updated === 0) {
        error_log('AN Studio confirmar_reserva: #' . $reserva->id . ' ya procesada — ignorando.');
        return;
    }

    // ── A partir de acá solo llega 1 proceso ────────────────────────
    $staff_name = $location_name = '';

    if ($reserva->staff_id > 0) {
        $s = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}an_staff WHERE id = %d",
            $reserva->staff_id
        ));
        if ($s)
            $staff_name = $s->name;
    }

    if ($reserva->location_id > 0) {
        $l = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}an_locations WHERE id = %d",
            $reserva->location_id
        ));
        if ($l)
            $location_name = $l->name;
    }

    // ── Consejo IA con Gemini ────────────────────────────────────────
    $tip = 'Te esperamos con todo listo para que disfrutes tu experiencia en AN Studio. ✨';
    $prof = $staff_name ? " con {$staff_name}" : '';
    $prompt = "Sos una experta en estética de lujo. La clienta {$reserva->nombre_cliente} reservó \"{$reserva->servicio}\"{$prof} en AN Studio. Escribí un único consejo breve (máximo 2 oraciones). Tono cálido, sofisticado, español rioplatense.";

    $gem = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . AN_GEMINI_KEY,
        [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 150],
            ]),
        ]
    );
    if (!is_wp_error($gem) && wp_remote_retrieve_response_code($gem) === 200) {
        $gb = json_decode(wp_remote_retrieve_body($gem), true);
        if (!empty($gb['candidates'][0]['content']['parts'][0]['text'])) {
            $tip = sanitize_textarea_field(trim($gb['candidates'][0]['content']['parts'][0]['text']));
        }
    }

    // ── Google Calendar ──────────────────────────────────────────────
    $gcal_id = '';
    if (AN_GCAL_REFRESH_TOKEN) {
        $gcal_id = an_create_gcal_event_full($reserva, $staff_name, $location_name) ?? '';
    }

    // ── Guardar tip + gcal_event_id (estado ya fue seteado arriba) ───
    $wpdb->update(
        $wpdb->prefix . 'an_reservas_v4',
        ['guia_ia' => $tip, 'gcal_event_id' => $gcal_id],
        ['id' => $reserva->id],
        ['%s', '%s'],
        ['%d']
    );

    // ── Email al admin ───────────────────────────────────────────────
    $precio = '$' . number_format($reserva->precio, 0, ',', '.');
    $body = "✨ TURNO CONFIRMADO — AN STUDIO ✨\n\n━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "👤 Clienta:    {$reserva->nombre_cliente}\n"
        . "📧 Email:      {$reserva->email}\n"
        . "📱 WA:         {$reserva->whatsapp}\n"
        . "💅 Servicio:   {$reserva->servicio}\n"
        . "📅 Fecha:      {$reserva->fecha_turno}\n"
        . ($staff_name ? "👩 Profesional: {$staff_name}\n" : '')
        . ($location_name ? "📍 Sucursal:    {$location_name}\n" : '')
        . "💰 Total:      {$precio}\n"
        . ($gcal_id ? "📆 Google Cal:  ✓ creado\n" : '')
        . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n💡 Consejo IA:\n\"{$tip}\"\n";

    wp_mail(
        AN_ADMIN_EMAIL,
        "✨ Nuevo Turno: {$reserva->nombre_cliente}",
        $body,
        ['Content-Type: text/plain; charset=UTF-8']
    );

    // ── Email a la clienta ───────────────────────────────────────────
    if (!empty($reserva->email)) {
        $fecha_l = date('d/m/Y', strtotime($reserva->fecha_turno));
        $hora_l = date('H:i', strtotime($reserva->fecha_turno));
        $cu = "Hola {$reserva->nombre_cliente},\n\n"
            . "Tu reserva fue confirmada con éxito. ¡Te esperamos!\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "📅 Fecha:    {$fecha_l}\n"
            . "🕐 Hora:     {$hora_l}hs\n"
            . "💅 Servicio: {$reserva->servicio}\n"
            . ($staff_name ? "👩 Con:      {$staff_name}\n" : '')
            . ($location_name ? "📍 Sucursal: {$location_name}\n" : '')
            . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        if ($tip && $tip !== 'Te esperamos con todo listo para que disfrutes tu experiencia en AN Studio. ✨') {
            $cu .= "💡 Un consejo especial:\n\"{$tip}\"\n\n";
        }
        $cu .= "Si necesitás reprogramar, respondé este email.\n\n¡Hasta pronto!\nEl equipo de AN Studio ✨\n";

        wp_mail(
            $reserva->email,
            '✨ Tu turno en AN Studio está confirmado',
            $cu,
            [
                'Content-Type: text/plain; charset=UTF-8',
                'From: AN Studio <' . AN_ADMIN_EMAIL . '>',
                'Reply-To: ' . AN_ADMIN_EMAIL,
            ]
        );
    }
}


// ═══════════════════════════════════════════════════════════════════
// WEBHOOK MERCADO PAGO
// POST /wp-json/an-luxury/v4/pago
// ═══════════════════════════════════════════════════════════════════
add_action('rest_api_init', function () {
    register_rest_route('an-luxury/v4', '/pago', [
        'methods' => 'POST',
        'callback' => 'an_webhook_v4',
        'permission_callback' => '__return_true',
    ]);
});

function an_webhook_v4(WP_REST_Request $request)
{
    global $wpdb;
    $params = $request->get_params();

    error_log('AN Studio Webhook recibido: ' . wp_json_encode($params));

    if (empty($params['type']) || $params['type'] !== 'payment' || empty($params['data']['id'])) {
        error_log('AN Studio Webhook: ignorado (tipo=' . ($params['type'] ?? 'N/A') . ')');
        return new WP_REST_Response(null, 200);
    }

    $pay_id = intval($params['data']['id']);
    $pay_res = wp_remote_get(
        'https://api.mercadopago.com/v1/payments/' . $pay_id,
        ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . AN_MP_TOKEN]]
    );

    if (is_wp_error($pay_res)) {
        error_log('AN Studio Webhook: error MP — ' . $pay_res->get_error_message());
        return new WP_REST_Response(null, 200);
    }

    $code = wp_remote_retrieve_response_code($pay_res);
    $pago = json_decode(wp_remote_retrieve_body($pay_res));
    error_log("AN Studio Webhook: MP HTTP={$code} status=" . ($pago->status ?? 'N/A') . " ref=" . ($pago->external_reference ?? 'N/A'));

    if (!$pago || $pago->status !== 'approved' || empty($pago->external_reference)) {
        return new WP_REST_Response(null, 200);
    }

    $rid = intval($pago->external_reference);
    $reserva = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id = %d",
        $rid
    ));

    if (!$reserva) {
        error_log("AN Studio Webhook: reserva id={$rid} no encontrada");
        return new WP_REST_Response(null, 200);
    }

    // an_confirmar_reserva tiene el check atómico interno — seguro llamarlo
    an_confirmar_reserva($reserva);
    error_log("AN Studio Webhook: an_confirmar_reserva(#{$rid}) ejecutada");

    return new WP_REST_Response(null, 200);
}


// ═══════════════════════════════════════════════════════════════════
// PÁGINA DE ÉXITO /pago-exitoso
// Fallback para cuando el webhook no llega antes que el usuario
// ═══════════════════════════════════════════════════════════════════
add_action('template_redirect', 'an_success_page_handler');
function an_success_page_handler()
{
    if (!is_page('pago-exitoso'))
        return;

    $payment_id = intval($_GET['payment_id'] ?? 0);
    $status = sanitize_text_field($_GET['collection_status'] ?? $_GET['status'] ?? '');
    $reserva_id = intval($_GET['external_reference'] ?? 0);

    if (!$payment_id || !$reserva_id || $status !== 'approved')
        return;

    global $wpdb;
    $reserva = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id = %d LIMIT 1",
        $reserva_id
    ));
    if (!$reserva)
        return;

    // Verificar con MP antes de confirmar
    $pay_res = wp_remote_get(
        'https://api.mercadopago.com/v1/payments/' . $payment_id,
        ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . AN_MP_TOKEN]]
    );
    if (is_wp_error($pay_res))
        return;

    $pago = json_decode(wp_remote_retrieve_body($pay_res));
    if (!$pago || $pago->status !== 'approved')
        return;

    // an_confirmar_reserva maneja la idempotencia internamente
    an_confirmar_reserva($reserva);
}