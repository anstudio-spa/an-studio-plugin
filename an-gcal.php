<?php
/**
 * AN STUDIO — an-gcal.php
 * ═══════════════════════════════════════════════════════
 * Snippet independiente: Google Calendar OAuth2 + eventos
 * Requiere: an-config.php activo
 *
 * Funciones:
 *   an_gcal_access_token()
 *   an_create_gcal_event_full($reserva, $staff_name, $location_name)
 *   an_gcal_slots($fecha, $cal_id, $tz, $slots)
 *   an_occupied_slots_for($fecha, $staff_id, $cal_id, $slots)
 */

// ═══════════════════════════════════════════════════════════════════
// TOKEN OAUTH2
// ═══════════════════════════════════════════════════════════════════
function an_gcal_access_token(): ?string
{
    $cached = get_transient('an_gcal_access_token');
    if ($cached)
        return $cached;

    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 15,
        'body' => [
            'client_id' => AN_GCAL_CLIENT_ID,
            'client_secret' => AN_GCAL_CLIENT_SECRET,
            'refresh_token' => AN_GCAL_REFRESH_TOKEN,
            'grant_type' => 'refresh_token',
        ],
    ]);

    if (is_wp_error($res)) {
        error_log('AN Studio OAuth2 error: ' . $res->get_error_message());
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($res));
    $tok = $data->access_token ?? null;

    if (!$tok) {
        error_log('AN Studio OAuth2 sin token: ' . wp_remote_retrieve_body($res));
        return null;
    }

    set_transient('an_gcal_access_token', $tok, ($data->expires_in ?? 3600) - 300);
    return $tok;
}


// ═══════════════════════════════════════════════════════════════════
// CREAR EVENTO
// ═══════════════════════════════════════════════════════════════════
function an_create_gcal_event_full($reserva, string $staff_name = '', string $location_name = ''): ?string
{
    $tok = an_gcal_access_token();
    if (!$tok)
        return null;

    $tz = new DateTimeZone(AN_TIMEZONE);
    $s = new DateTime($reserva->fecha_turno, $tz);
    $e = (clone $s)->modify('+1 hour');
    $pf = '$' . number_format($reserva->precio, 0, ',', '.');

    $title = '💅 ' . $reserva->nombre_cliente;
    if ($staff_name)
        $title .= " con {$staff_name}";
    if ($location_name)
        $title .= " — {$location_name}";

    $desc = "👤 Clienta:   {$reserva->nombre_cliente}\n"
        . "📱 WA:        {$reserva->whatsapp}\n"
        . "💅 Servicio:  {$reserva->servicio}\n"
        . "💰 Total:     {$pf}\n"
        . "🔖 Reserva:   #{$reserva->id}";
    if ($staff_name)
        $desc .= "\n👩 Profesional: {$staff_name}";
    if ($location_name)
        $desc .= "\n📍 Sucursal:    {$location_name}";

    $event = [
        'summary' => $title,
        'description' => $desc,
        'colorId' => '11',
        'start' => ['dateTime' => $s->format('c'), 'timeZone' => AN_TIMEZONE],
        'end' => ['dateTime' => $e->format('c'), 'timeZone' => AN_TIMEZONE],
    ];

    // Calendarios donde crear el evento: admin + profesional (si tiene)
    $cals = [AN_GCAL_CALENDAR_ID];
    if ($reserva->staff_id > 0) {
        global $wpdb;
        $sf = $wpdb->get_row($wpdb->prepare(
            "SELECT calendar_id FROM {$wpdb->prefix}an_staff WHERE id = %d",
            $reserva->staff_id
        ));
        if ($sf && $sf->calendar_id && $sf->calendar_id !== AN_GCAL_CALENDAR_ID) {
            $cals[] = $sf->calendar_id;
        }
    }

    $created_id = null;
    foreach ($cals as $cal) {
        $r = wp_remote_post(
            'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $tok,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($event),
            ]
        );

        if (is_wp_error($r)) {
            error_log("AN Studio GCal [{$cal}] error: " . $r->get_error_message());
            continue;
        }

        $code = wp_remote_retrieve_response_code($r);
        $data = json_decode(wp_remote_retrieve_body($r));

        if (in_array($code, [200, 201], true) && !empty($data->id)) {
            if (!$created_id)
                $created_id = $data->id;
        } else {
            error_log("AN Studio GCal [{$cal}] HTTP {$code}: " . wp_remote_retrieve_body($r));
        }
    }

    return $created_id;
}


// ═══════════════════════════════════════════════════════════════════
// LEER SLOTS OCUPADOS DESDE GOOGLE CALENDAR
// ═══════════════════════════════════════════════════════════════════
function an_gcal_slots(string $fecha, string $cal_id, DateTimeZone $tz_ar, array $slots): array
{
    $ocupados = [];
    $cache_key = 'an_gcal_' . md5($cal_id) . '_' . $fecha;
    $events = get_transient($cache_key);

    if (false === $events) {
        $time_min = (new DateTime($fecha . 'T00:00:00', $tz_ar))->format('c');
        $time_max = (new DateTime($fecha . 'T23:59:59', $tz_ar))->format('c');

        $url = add_query_arg([
            'key' => AN_GOOGLE_API_KEY,
            'timeMin' => $time_min,
            'timeMax' => $time_max,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 50,
            'fields' => 'items(start,end,summary)',
        ], 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal_id) . '/events');

        $res = wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'ANStudio/1.0']);

        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $body = json_decode(wp_remote_retrieve_body($res), true);
            $events = $body['items'] ?? [];
            set_transient($cache_key, $events, 5 * MINUTE_IN_SECONDS);
        } else {
            $err = is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res);
            error_log("AN Studio GCal slots [{$cal_id}] error: {$err}");
            $events = [];
        }
    }

    foreach ($events as $event) {
        // Evento de día completo → bloquea todos los slots
        if (!empty($event['start']['date']) && empty($event['start']['dateTime'])) {
            return $slots;
        }
        if (empty($event['start']['dateTime']))
            continue;

        try {
            $ev_s = new DateTime($event['start']['dateTime']);
            $ev_s->setTimezone($tz_ar);
            $ev_e = new DateTime($event['end']['dateTime'] ?? $event['start']['dateTime']);
            $ev_e->setTimezone($tz_ar);
            if ($ev_e <= $ev_s)
                $ev_e->modify('+1 hour');
        } catch (Exception $ex) {
            continue;
        }

        foreach ($slots as $slot) {
            $sl_s = new DateTime($fecha . 'T' . $slot . ':00', $tz_ar);
            $sl_e = (clone $sl_s)->modify('+1 hour');
            if ($sl_s < $ev_e && $sl_e > $ev_s) {
                $ocupados[] = $slot;
            }
        }
    }

    return $ocupados;
}


// ═══════════════════════════════════════════════════════════════════
// SLOTS OCUPADOS COMBINADO (BD + GCal)
// ═══════════════════════════════════════════════════════════════════
function an_occupied_slots_for(string $fecha, int $staff_id, string $cal_id, array $slots): array
{
    global $wpdb;
    $ocupados = [];
    $tz_ar = new DateTimeZone(AN_TIMEZONE);

    // Reservas en BD (pagadas o pendientes recientes)
    $staff_cond = $staff_id > 0 ? $wpdb->prepare('AND staff_id = %d', $staff_id) : '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT fecha_turno FROM {$wpdb->prefix}an_reservas_v4
         WHERE DATE(fecha_turno) = %s
         $staff_cond
         AND (
             estado = 'pagado'
             OR (estado = 'pendiente' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE))
         )",
        $fecha
    ));

    foreach ($rows as $r) {
        $h = date('H:i', strtotime($r->fecha_turno));
        if (in_array($h, $slots, true))
            $ocupados[] = $h;
    }

    // Calendario admin
    $ocupados = array_merge($ocupados, an_gcal_slots($fecha, AN_GCAL_CALENDAR_ID, $tz_ar, $slots));

    // Calendario del profesional (si es diferente al admin)
    if ($cal_id && $cal_id !== AN_GCAL_CALENDAR_ID) {
        $ocupados = array_merge($ocupados, an_gcal_slots($fecha, $cal_id, $tz_ar, $slots));
    }

    return array_values(array_unique($ocupados));
}