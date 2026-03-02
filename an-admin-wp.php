<?php
/**
 * AN STUDIO — an-admin-wp.php
 * ═══════════════════════════════════════════════════════
 * Panel de administración en WordPress (/wp-admin)
 * Requiere: an-config.php + an-database.php + an-confirmar.php activos
 *
 * Contiene:
 *   - Menú admin + CSS + JS (con diff de tabla sin destello)
 *   - AJAX: an_reprocesar_pago     → busca en MP y confirma reserva
 *   - AJAX: an_bulk_action         → eliminar / cambiar estado múltiple
 *   - AJAX: an_dashboard_refresh   → datos en vivo (Hoy + Próximos)
 *   - an_admin_page()              → HTML del panel completo
 *   - an_estado_pill()             → helper de pills de estado
 *
 * El cron está en an-cron.php — no se duplica acá.
 * La confirmación de reservas está en an-confirmar.php
 */


// NOTA: El cron está en an-cron.php — no duplicar acá.

// 12. PANEL ADMIN — MENÚ
// ═══════════════════════════════════════════════════════════════════
add_action('admin_menu', function () {
    add_menu_page(
        'AN Studio',
        '💅 AN Studio',
        'manage_options',
        'an-studio-reservas',
        'an_admin_page',
        'dashicons-calendar-alt',
        30
    );
});


// ═══════════════════════════════════════════════════════════════════
// 12b. ESTILOS + JS DEL ADMIN
// ═══════════════════════════════════════════════════════════════════
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'toplevel_page_an-studio-reservas')
        return;
    $nonce_refresh = wp_create_nonce('an_dashboard_refresh');
    $nonce_bulk = wp_create_nonce('an_bulk');
    $nonce_repro = wp_create_nonce('an_reprocesar');
    ?>
    <style>
        /* ── Layout general ── */
        .an-admin-wrap {
            font-family: 'Segoe UI', sans-serif;
        }

        .an-section-box {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .an-section-header {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .an-section-header h3 {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .an-badge-count {
            background: #6366f1;
            color: #fff;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 8px;
        }

        .an-badge-hoy {
            background: #22c55e;
        }

        /* ── Tabla ── */
        .an-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .an-table thead tr {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }

        .an-table th {
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .an-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
        }

        .an-table tbody tr:hover {
            background: #fafafa;
        }

        .an-table tbody tr.selected-row {
            background: #eff6ff !important;
        }

        .an-table td {
            padding: 9px 12px;
            vertical-align: middle;
        }

        .an-table th.cb-col,
        .an-table td.cb-col {
            width: 26px;
            padding-right: 4px;
        }

        .an-table input[type=checkbox] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #6366f1;
        }

        /* ── Pills de estado ── */
        .an-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .an-pill-pagado {
            background: #dcfce7;
            color: #15803d;
        }

        .an-pill-pendiente {
            background: #fef9c3;
            color: #854d0e;
        }

        .an-pill-cancelado {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ── Botones de acción ── */
        .an-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            background: none;
            transition: opacity .15s;
        }

        .an-action-btn:hover {
            opacity: .8;
        }

        .an-btn-wa {
            background: #dcfce7;
            color: #15803d;
        }

        .an-btn-cancel {
            background: #fee2e2;
            color: #991b1b;
        }

        .an-btn-ai {
            background: #ede9fe;
            color: #6d28d9;
            cursor: help;
        }

        .an-btn-repro {
            background: #dbeafe;
            color: #1d4ed8;
        }

        /* ── Stats grid ── */
        .an-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .an-stat-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px 18px;
            border-top: 3px solid;
        }

        .an-stat-val {
            font-size: 24px;
            font-weight: 700;
            transition: color .4s;
        }

        /* ── Filtros rápidos ── */
        .an-quick-filters {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .an-qf-btn {
            padding: 6px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            background: #fff;
            color: #6b7280;
            text-decoration: none;
            transition: all .15s;
        }

        .an-qf-btn:hover,
        .an-qf-btn.active {
            background: #6366f1;
            color: #fff;
            border-color: #6366f1;
        }

        /* ── Bulk bar ── */
        .an-bulk-bar {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            background: #eff6ff;
            border-bottom: 1px solid #bfdbfe;
            flex-wrap: wrap;
        }

        .an-bulk-bar.visible {
            display: flex;
        }

        .an-bulk-count {
            font-size: 13px;
            font-weight: 700;
            color: #1d4ed8;
        }

        .an-bulk-select {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .an-bulk-apply {
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .an-bulk-apply:hover {
            background: #4f46e5;
        }

        .an-bulk-cancel-sel {
            color: #6b7280;
            font-size: 12px;
            cursor: pointer;
            background: none;
            border: none;
            text-decoration: underline;
        }

        .an-bulk-msg {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 6px;
            display: none;
        }

        /* ── Barra de refresh ── */
        .an-refresh-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            font-size: 11px;
            color: #166534;
            margin-bottom: 16px;
        }

        .an-refresh-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
            flex-shrink: 0;
        }

        .an-refresh-dot.idle {
            animation: an-blink 3s ease-in-out infinite;
        }

        .an-refresh-dot.syncing {
            background: #f59e0b;
            animation: an-blink .6s ease-in-out infinite;
        }

        @keyframes an-blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .25
            }
        }

        /* ── Separador de día ── */
        .an-day-sep td {
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            color: #6366f1;
            text-transform: uppercase;
            letter-spacing: .06em;
            background: #f8fafc;
        }

        /* ── Resultado de reprocesar ── */
        .an-repro-result {
            margin-top: 6px;
            font-size: 11px;
            padding: 6px 10px;
            border-radius: 6px;
            display: none;
            white-space: pre-wrap;
            word-break: break-all;
            max-width: 260px;
        }

        .an-repro-ok {
            background: #dcfce7;
            color: #15803d;
        }

        .an-repro-err {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ── Fila vacía ── */
        .an-empty-row td {
            padding: 28px;
            text-align: center;
            color: #9ca3af;
            font-style: italic;
        }
    </style>

    <script>
        /* ══════════════════════════════════════════════════════════════════
           AN STUDIO ADMIN JS v15.2
           Sin destello: el refresh hace DIFF por id+estado, solo actualiza
           las celdas que realmente cambiaron. No re-renderiza el tbody.
        ══════════════════════════════════════════════════════════════════ */
        (function () {
            'use strict';

            var AJAX = '<?php echo admin_url('admin-ajax.php'); ?>';
            var NR = '<?php echo $nonce_refresh; ?>';  // dashboard refresh
            var NB = '<?php echo $nonce_bulk; ?>';      // bulk
            var NP = '<?php echo $nonce_repro; ?>';     // reprocesar

            var refreshTimer = null;
            var REFRESH_MS = 15000;
            var countdown = REFRESH_MS / 1000;

            /* ── Utilidades ──────────────────────────────────────────────────── */
            function esc(s) {
                return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            function fmt(n) {
                return '$' + Number(n || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 });
            }
            function fmtHora(dt) {
                return dt ? String(dt).substring(11, 16) : '';
            }

            /* ── Reprocesar pago ─────────────────────────────────────────────── */
            window.anReprocesar = function (id) {
                var btn = document.getElementById('repro-btn-' + id);
                var res = document.getElementById('repro-res-' + id);
                if (!btn) return;
                btn.textContent = '⏳'; btn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'an_reprocesar_pago'); fd.append('nonce', NP); fd.append('reserva_id', id);
                fetch(AJAX, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!res) return;
                        res.style.display = 'block';
                        if (d.success) {
                            res.className = 'an-repro-result an-repro-ok';
                            res.textContent = d.data.msg + '\nPayment: ' + d.data.payment_id + ' | Status MP: ' + d.data.status_mp;
                            btn.textContent = '✅';
                            /* Actualizar pill inline sin recargar */
                            var pill = document.querySelector('#row-' + id + ' .an-estado-pill');
                            if (pill) { pill.className = 'an-pill an-pill-pagado an-estado-pill'; pill.textContent = '✅ Pagado'; }
                            btn.disabled = false;
                            /* Trigger diff refresh suave */
                            setTimeout(anDoRefresh, 1000);
                        } else {
                            res.className = 'an-repro-result an-repro-err';
                            res.textContent = (d.data.msg || 'Error') +
                                (d.data.diagnostico ? '\n\nDiag:\n' + d.data.diagnostico : '') +
                                (d.data.tip ? '\n\n💡 ' + d.data.tip : '');
                            btn.textContent = '🔄'; btn.disabled = false;
                        }
                    })
                    .catch(function (e) {
                        if (res) { res.style.display = 'block'; res.className = 'an-repro-result an-repro-err'; res.textContent = 'Error: ' + e.message; }
                        btn.textContent = '🔄'; btn.disabled = false;
                    });
            };

            /* ── Cancelar rápido (desde tabla dinámica) ──────────────────────── */
            window.anQuickCancel = function (id) {
                if (!confirm('¿Cancelar esta reserva?')) return;
                anBulkDo('marcar_cancelado', [id], null, function () {
                    var pill = document.querySelector('#row-' + id + ' .an-estado-pill');
                    if (pill) { pill.className = 'an-pill an-pill-cancelado an-estado-pill'; pill.textContent = '❌ Cancelado'; }
                });
            };

            /* ── Checkboxes ──────────────────────────────────────────────────── */
            window.anToggleAll = function (masterCb, sectionId) {
                var sec = document.getElementById(sectionId);
                if (!sec) return;
                sec.querySelectorAll('input.an-cb-row').forEach(function (cb) {
                    cb.checked = masterCb.checked;
                    var row = document.getElementById('row-' + cb.value);
                    if (row) row.classList.toggle('selected-row', masterCb.checked);
                });
                updateBulkBar(sectionId);
            };

            window.anToggleRow = function (cb, sectionId) {
                var row = document.getElementById('row-' + cb.value);
                if (row) row.classList.toggle('selected-row', cb.checked);
                var sec = document.getElementById(sectionId);
                var master = document.getElementById('cb-master-' + sectionId);
                if (sec && master) {
                    var all = sec.querySelectorAll('input.an-cb-row').length;
                    var checked = sec.querySelectorAll('input.an-cb-row:checked').length;
                    master.indeterminate = checked > 0 && checked < all;
                    master.checked = all > 0 && checked === all;
                }
                updateBulkBar(sectionId);
            };

            function getChecked(sectionId) {
                var sec = document.getElementById(sectionId);
                if (!sec) return [];
                return Array.from(sec.querySelectorAll('input.an-cb-row:checked')).map(function (cb) { return cb.value; });
            }

            function updateBulkBar(sectionId) {
                var bar = document.getElementById('bulk-bar-' + sectionId);
                var count = document.getElementById('bulk-count-' + sectionId);
                if (!bar || !count) return;
                var ids = getChecked(sectionId);
                bar.classList.toggle('visible', ids.length > 0);
                count.textContent = ids.length + ' seleccionada' + (ids.length !== 1 ? 's' : '');
            }

            /* ── Bulk apply ──────────────────────────────────────────────────── */
            window.anBulkApply = function (sectionId) {
                var sel = document.getElementById('bulk-action-select-' + sectionId);
                var accion = sel ? sel.value : '';
                if (!accion) { alert('Seleccioná una acción'); return; }
                var ids = getChecked(sectionId);
                if (!ids.length) { alert('Seleccioná al menos una reserva'); return; }
                var msg = accion === 'eliminar'
                    ? '⚠️ ¿Eliminar ' + ids.length + ' reserva(s)? No se puede deshacer.'
                    : '¿Cambiar estado de ' + ids.length + ' reserva(s)?';
                if (!confirm(msg)) return;
                var applyBtn = document.getElementById('bulk-apply-' + sectionId);
                if (applyBtn) { applyBtn.textContent = '⏳'; applyBtn.disabled = true; }

                anBulkDo(accion, ids, sectionId, function (d) {
                    if (accion === 'eliminar') {
                        (d.deleted || ids).forEach(function (id) {
                            var row = document.getElementById('row-' + id);
                            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .25s'; setTimeout(function () { row.remove(); }, 260); }
                        });
                    } else {
                        var pillCls = { pagado: 'an-pill-pagado', pendiente: 'an-pill-pendiente', cancelado: 'an-pill-cancelado' }[d.estado] || '';
                        var pillTxt = { pagado: '✅ Pagado', pendiente: '⏳ Pendiente', cancelado: '❌ Cancelado' }[d.estado] || d.estado;
                        (d.updated || ids).forEach(function (id) {
                            var pill = document.querySelector('#row-' + id + ' .an-estado-pill');
                            if (pill) { pill.className = 'an-pill ' + pillCls + ' an-estado-pill'; pill.textContent = pillTxt; }
                            var cb = document.querySelector('#row-' + id + ' input.an-cb-row');
                            if (cb) cb.checked = false;
                            var row = document.getElementById('row-' + id);
                            if (row) row.classList.remove('selected-row');
                        });
                    }
                    /* Reset bulk bar y master cb */
                    var bar = document.getElementById('bulk-bar-' + sectionId);
                    if (bar) bar.classList.remove('visible');
                    var master = document.getElementById('cb-master-' + sectionId);
                    if (master) { master.checked = false; master.indeterminate = false; }
                    showBulkMsg(sectionId, '✅ ' + d.msg, true);
                    if (applyBtn) { applyBtn.textContent = 'Aplicar'; applyBtn.disabled = false; }
                    setTimeout(anDoRefresh, 600);
                }, function (errMsg) {
                    showBulkMsg(sectionId, '❌ ' + errMsg, false);
                    if (applyBtn) { applyBtn.textContent = 'Aplicar'; applyBtn.disabled = false; }
                });
            };

            function anBulkDo(accion, ids, sectionId, onSuccess, onError) {
                var fd = new FormData();
                fd.append('action', 'an_bulk_action'); fd.append('nonce', NB); fd.append('bulk_action', accion);
                ids.forEach(function (id) { fd.append('ids[]', id); });
                fetch(AJAX, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) { if (onSuccess) onSuccess(d.data); }
                        else { if (onError) onError(d.data ? d.data.msg : 'Error'); }
                    })
                    .catch(function (e) { if (onError) onError('Error de red: ' + e.message); });
            }

            function showBulkMsg(sectionId, msg, ok) {
                var el = document.getElementById('bulk-msg-' + sectionId);
                if (!el) return;
                el.textContent = msg;
                el.style.background = ok ? '#dcfce7' : '#fee2e2';
                el.style.color = ok ? '#15803d' : '#991b1b';
                el.style.display = 'block';
                setTimeout(function () { el.style.display = 'none'; }, 4000);
            }

            /* ═══════════════════════════════════════════════════════════════════
               AUTO-REFRESH — SIN DESTELLO
               Estrategia: fetch datos nuevos → diff con lo que hay en el DOM →
               solo actualizar las celdas (pill de estado) que realmente cambiaron.
               Las filas nuevas se insertan, las eliminadas se remueven.
               El tbody NO se reemplaza completo → cero destello.
            ═══════════════════════════════════════════════════════════════════ */
            window.anDoRefresh = function () {
                setDot('syncing');
                var fd = new FormData();
                fd.append('action', 'an_dashboard_refresh'); fd.append('nonce', NR);
                fetch(AJAX, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) { setDot('idle'); return; }
                        var data = d.data;
                        diffStats(data.stats);
                        diffTable('section-hoy', data.turnos_hoy, false);
                        diffTable('section-proximos', data.proximos, true);
                        setDot('idle', data.timestamp);
                        countdown = REFRESH_MS / 1000;
                    })
                    .catch(function () { setDot('idle'); });
            };

            /* Actualizar stats: solo si el valor cambió */
            function diffStats(stats) {
                if (!stats) return;
                var vals = {
                    'stat-total': stats.total || 0,
                    'stat-pagadas': stats.pagadas || 0,
                    'stat-pendientes': stats.pendientes || 0,
                    'stat-recaudado': fmt(stats.recaudado),
                };
                Object.keys(vals).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    var nuevo = String(vals[id]);
                    if (el.textContent !== nuevo) {
                        el.textContent = nuevo;
                        /* Flash suave solo en el número, no en toda la tarjeta */
                        el.style.color = '#6366f1';
                        setTimeout(function () { el.style.color = ''; }, 600);
                    }
                });
            }

            /* Diff de tabla: agrega/actualiza/elimina FILAS, no reemplaza tbody */
            function diffTable(sectionId, rows, hasDateSep) {
                var tbody = document.getElementById('tbody-' + sectionId);
                if (!tbody) return;

                /* Construir mapa de nuevos datos: id → row */
                var newMap = {};
                (rows || []).forEach(function (r) { newMap[String(r.id)] = r; });

                /* IDs actuales en el DOM */
                var domRows = tbody.querySelectorAll('tr[id^="row-"]');
                var domIds = Array.from(domRows).map(function (tr) { return tr.id.replace('row-', ''); });

                /* 1. Eliminar filas que ya no existen */
                domIds.forEach(function (id) {
                    if (!newMap[id]) {
                        var tr = document.getElementById('row-' + id);
                        if (tr) { tr.style.opacity = '0'; tr.style.transition = 'opacity .25s'; setTimeout(function () { tr.remove(); }, 260); }
                    }
                });

                /* 2. Actualizar filas existentes (solo la pill de estado y el botón repro) */
                domIds.forEach(function (id) {
                    var r = newMap[id];
                    if (!r) return;
                    var tr = document.getElementById('row-' + id);
                    if (!tr) return;

                    /* Actualizar estado pill */
                    var pill = tr.querySelector('.an-estado-pill');
                    var pillCls = { pagado: 'an-pill-pagado', pendiente: 'an-pill-pendiente', cancelado: 'an-pill-cancelado' }[r.estado] || '';
                    var pillTxt = { pagado: '✅ Pagado', pendiente: '⏳ Pendiente', cancelado: '❌ Cancelado' }[r.estado] || r.estado;
                    if (pill && pill.textContent !== pillTxt) {
                        pill.className = 'an-pill ' + pillCls + ' an-estado-pill';
                        pill.textContent = pillTxt;
                    }

                    /* Si pasó a pagado, quitar botón repro */
                    if (r.estado === 'pagado') {
                        var reproBtn = document.getElementById('repro-btn-' + id);
                        if (reproBtn) reproBtn.remove();
                    }
                });

                /* 3. Agregar filas nuevas que no están en el DOM */
                /* Reconstruir el orden completo respetando los separadores de fecha */
                var existingIds = Object.fromEntries
                    ? Object.fromEntries(domIds.map(function (id) { return [id, true]; }))
                    : domIds.reduce(function (acc, id) { acc[id] = true; return acc; }, {});

                var lastDate = '';
                (rows || []).forEach(function (r) {
                    var rowId = String(r.id);
                    if (existingIds[rowId]) return; /* ya existe, no agregar */

                    /* Determinar posición: insertar al final (o antes del primer row con fecha mayor) */
                    var newTr = buildRow(r, sectionId);

                    /* Separador de fecha si corresponde */
                    if (hasDateSep) {
                        var fDia = r.fecha_turno ? r.fecha_turno.substring(0, 10) : '';
                        if (fDia && fDia !== lastDate) {
                            lastDate = fDia;
                            /* Verificar si ya existe un separador para esa fecha */
                            var sepId = 'sep-' + sectionId + '-' + fDia.replace(/-/g, '');
                            if (!document.getElementById(sepId)) {
                                var sepTr = document.createElement('tr');
                                sepTr.className = 'an-day-sep';
                                sepTr.id = sepId;
                                var d = new Date(fDia + 'T12:00:00');
                                var dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
                                var fmtDate = dias[d.getDay()] + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
                                sepTr.innerHTML = '<td colspan="10">📅 ' + fmtDate + '</td>';
                                tbody.appendChild(sepTr);
                            }
                        }
                    }

                    newTr.style.opacity = '0';
                    tbody.appendChild(newTr);
                    /* Fade in suave */
                    setTimeout(function () { newTr.style.transition = 'opacity .35s'; newTr.style.opacity = '1'; }, 30);
                });

                /* Actualizar badge count */
                var badge = document.getElementById('badge-' + sectionId);
                if (badge) badge.textContent = rows ? rows.length : 0;
                updateBulkBar(sectionId);
            }

            /* Construir una <tr> para una reserva */
            function buildRow(r, sectionId) {
                var waUrl = 'https://wa.me/' + (r.whatsapp || '').replace(/[^0-9]/g, '');
                var pillCls = { pagado: 'an-pill-pagado', pendiente: 'an-pill-pendiente', cancelado: 'an-pill-cancelado' }[r.estado] || '';
                var pillTxt = { pagado: '✅ Pagado', pendiente: '⏳ Pendiente', cancelado: '❌ Cancelado' }[r.estado] || r.estado;
                var hora = fmtHora(r.fecha_turno);

                var reproBtn = r.estado === 'pendiente'
                    ? '<button id="repro-btn-' + r.id + '" class="an-action-btn an-btn-repro" onclick="anReprocesar(' + r.id + ')" title="Buscar pago en MP">🔄</button>'
                    : '';
                var cancelBtn = r.estado !== 'cancelado'
                    ? '<button class="an-action-btn an-btn-cancel" onclick="anQuickCancel(' + r.id + ')">✕</button>'
                    : '';
                var aiBtn = r.guia_ia
                    ? '<span class="an-action-btn an-btn-ai" title="' + esc(r.guia_ia) + '">✨</span>'
                    : '';

                var tr = document.createElement('tr');
                tr.id = 'row-' + r.id;
                tr.innerHTML =
                    '<td class="cb-col"><input type="checkbox" class="an-cb-row" value="' + r.id + '" onchange="anToggleRow(this,\'' + sectionId + '\')" /></td>'
                    + '<td style="font-weight:700;">' + hora + 'hs</td>'
                    + '<td style="font-weight:700;">' + esc(r.nombre_cliente) + '</td>'
                    + '<td><a href="' + waUrl + '" target="_blank" style="color:#22c55e;text-decoration:none;font-size:11px;">' + esc(r.whatsapp || '') + '</a>'
                    + (r.email ? '<br><span style="color:#6366f1;font-size:10px;">' + esc(r.email) + '</span>' : '') + '</td>'
                    + '<td style="font-size:11px;color:#6b7280;">' + esc(r.loc_name || '—') + '</td>'
                    + '<td style="font-size:11px;color:#6b7280;">' + esc(r.staff_name || '—') + '</td>'
                    + '<td style="font-size:11px;max-width:130px;">' + esc(r.servicio || '') + '</td>'
                    + '<td style="font-weight:700;">' + fmt(r.precio) + '</td>'
                    + '<td><span class="an-pill ' + pillCls + ' an-estado-pill">' + pillTxt + '</span></td>'
                    + '<td><div style="display:flex;gap:3px;flex-wrap:wrap;">'
                    + '<a href="' + waUrl + '" target="_blank" class="an-action-btn an-btn-wa">💬</a>'
                    + reproBtn + cancelBtn + aiBtn
                    + '</div><div id="repro-res-' + r.id + '" class="an-repro-result"></div></td>';
                return tr;
            }

            /* ── Indicador de estado del refresh ─────────────────────────────── */
            function setDot(state, timestamp) {
                var dot = document.getElementById('an-refresh-dot');
                var label = document.getElementById('an-refresh-label');
                if (!dot) return;
                dot.className = 'an-refresh-dot ' + state;
                if (label) {
                    label.textContent = state === 'syncing'
                        ? 'Sincronizando...'
                        : 'En vivo · ' + (timestamp || getHMS());
                }
            }

            function getHMS() {
                var d = new Date();
                return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0') + ':' + d.getSeconds().toString().padStart(2, '0');
            }

            /* ── Inicializar ─────────────────────────────────────────────────── */
            document.addEventListener('DOMContentLoaded', function () {
                if (!document.getElementById('an-refresh-dot')) return;

                /* Primer refresh inmediato */
                anDoRefresh();

                /* Timer */
                refreshTimer = setInterval(anDoRefresh, REFRESH_MS);

                /* Countdown display */
                setInterval(function () {
                    countdown = Math.max(0, countdown - 1);
                    var el = document.getElementById('an-refresh-next');
                    if (el) el.textContent = countdown + 's';
                    if (countdown <= 0) countdown = REFRESH_MS / 1000;
                }, 1000);
            });

        })();
    </script>
    <?php
});


// ═══════════════════════════════════════════════════════════════════
// 13. AJAX: RE-PROCESAR PAGO PENDIENTE
// Busca en MP por external_reference sin filtrar por estado,
// acepta pagos de cuentas de prueba (TEST) y cualquier estado activo.
// ═══════════════════════════════════════════════════════════════════
add_action('wp_ajax_an_reprocesar_pago', 'an_reprocesar_pago_handler');
function an_reprocesar_pago_handler()
{
    if (!current_user_can('manage_options'))
        wp_die('Sin permisos');
    if (!check_ajax_referer('an_reprocesar', 'nonce', false))
        wp_die('Nonce inválido');

    $rid = intval($_POST['reserva_id'] ?? 0);
    if (!$rid)
        wp_send_json_error(['msg' => 'ID inválido']);

    global $wpdb;
    $reserva = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_reservas_v4 WHERE id=%d LIMIT 1",
        $rid
    ));
    if (!$reserva)
        wp_send_json_error(['msg' => 'Reserva no encontrada']);
    if ($reserva->estado === 'pagado')
        wp_send_json_error(['msg' => 'Ya está marcada como pagada']);

    $headers = ['Authorization' => 'Bearer ' . AN_MP_TOKEN];
    $pago_found = null;
    $diagnostico = [];

    /* Estrategia 1: buscar por external_reference, sin filtrar estado */
    $url1 = 'https://api.mercadopago.com/v1/payments/search?' . http_build_query([
        'external_reference' => (string) $rid,
        'limit' => 20,
        'sort' => 'date_created',
        'criteria' => 'desc',
    ]);
    $res1 = wp_remote_get($url1, ['timeout' => 15, 'headers' => $headers]);
    if (!is_wp_error($res1)) {
        $code1 = wp_remote_retrieve_response_code($res1);
        $body1 = json_decode(wp_remote_retrieve_body($res1), true);
        $lista1 = $body1['results'] ?? [];
        $diagnostico[] = "Búsqueda ref=$rid → HTTP $code1 → " . count($lista1) . " resultado(s)";
        foreach ($lista1 as $p) {
            $diagnostico[] = "  #" . $p['id'] . " status=" . $p['status'] . " amount=" . $p['transaction_amount'];
            if (!$pago_found && in_array($p['status'], ['approved', 'authorized'], true)) {
                $pago_found = $p;
            }
        }
        /* Si hay alguno no cancelado, usarlo igual (puede ser TEST/pending) */
        if (!$pago_found && !empty($lista1)) {
            foreach ($lista1 as $p) {
                if (!in_array($p['status'], ['cancelled', 'refunded', 'charged_back'], true)) {
                    $pago_found = $p;
                    break;
                }
            }
        }
    } else {
        $diagnostico[] = 'Error búsqueda 1: ' . $res1->get_error_message();
    }

    /* Estrategia 2: buscar por email del pagador */
    if (!$pago_found && !empty($reserva->email)) {
        $url2 = 'https://api.mercadopago.com/v1/payments/search?' . http_build_query([
            'payer.email' => $reserva->email,
            'limit' => 20,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);
        $res2 = wp_remote_get($url2, ['timeout' => 15, 'headers' => $headers]);
        if (!is_wp_error($res2)) {
            $body2 = json_decode(wp_remote_retrieve_body($res2), true);
            $lista2 = $body2['results'] ?? [];
            $diagnostico[] = "Búsqueda email={$reserva->email} → " . count($lista2) . " resultado(s)";
            foreach ($lista2 as $p) {
                $diff = abs((float) ($p['transaction_amount'] ?? 0) - (float) $reserva->precio);
                if ($diff < 1 && !in_array($p['status'], ['cancelled', 'refunded'], true)) {
                    $pago_found = $p;
                    $diagnostico[] = "  Match por email+monto: #" . $p['id'] . " status=" . $p['status'];
                    break;
                }
            }
        }
    }

    error_log('AN Studio Reprocesar #' . $rid . ': ' . implode(' | ', $diagnostico));

    if (!$pago_found) {
        wp_send_json_error([
            'msg' => '⚠️ No se encontró ningún pago en MP para esta reserva.',
            'diagnostico' => implode("\n", $diagnostico),
            'tip' => 'Con cuentas TEST de MP, completá el flujo hasta la pantalla de "¡Pago aprobado!". Si usaste la tarjeta de prueba, el pago queda en estado "approved" en TEST.',
        ]);
    }

    an_confirmar_reserva($reserva);

    wp_send_json_success([
        'msg' => '✅ Reserva confirmada como pagada.',
        'payment_id' => $pago_found['id'] ?? 'N/A',
        'status_mp' => $pago_found['status'] ?? 'N/A',
        'amount' => $pago_found['transaction_amount'] ?? 0,
        'diagnostico' => implode("\n", $diagnostico),
    ]);
}


// ═══════════════════════════════════════════════════════════════════
// 14. AJAX: BULK ACTIONS
// ═══════════════════════════════════════════════════════════════════
add_action('wp_ajax_an_bulk_action', 'an_bulk_action_handler');
function an_bulk_action_handler()
{
    if (!current_user_can('manage_options'))
        wp_die('Sin permisos');
    if (!check_ajax_referer('an_bulk', 'nonce', false))
        wp_die('Nonce inválido');

    $accion = sanitize_key($_POST['bulk_action'] ?? '');
    $ids_raw = $_POST['ids'] ?? [];
    if (!is_array($ids_raw) || empty($ids_raw))
        wp_send_json_error(['msg' => 'Sin reservas seleccionadas']);

    $ids = array_values(array_filter(array_map('intval', $ids_raw), fn($id) => $id > 0));
    if (empty($ids))
        wp_send_json_error(['msg' => 'IDs inválidos']);

    global $wpdb;
    $ph = implode(',', array_fill(0, count($ids), '%d'));

    switch ($accion) {
        case 'eliminar':
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}an_reservas_v4 WHERE id IN ($ph)", ...$ids));
            wp_send_json_success(['msg' => count($ids) . ' reserva(s) eliminada(s).', 'deleted' => $ids]);
            break;
        case 'marcar_pagado':
        case 'marcar_pendiente':
        case 'marcar_cancelado':
            $estado = str_replace('marcar_', '', $accion);
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}an_reservas_v4 SET estado=%s WHERE id IN ($ph)", $estado, ...$ids));
            wp_send_json_success(['msg' => count($ids) . ' reserva(s) → "' . $estado . '".', 'updated' => $ids, 'estado' => $estado]);
            break;
        default:
            wp_send_json_error(['msg' => 'Acción desconocida']);
    }
}


// ═══════════════════════════════════════════════════════════════════
// 15. AJAX: DASHBOARD REFRESH (datos para el auto-refresh)
// ═══════════════════════════════════════════════════════════════════
add_action('wp_ajax_an_dashboard_refresh', 'an_dashboard_refresh_handler');
function an_dashboard_refresh_handler()
{
    if (!current_user_can('manage_options'))
        wp_die('Sin permisos');
    if (!check_ajax_referer('an_dashboard_refresh', 'nonce', false))
        wp_die('Nonce inválido');

    global $wpdb;
    $tz_ar = new DateTimeZone(AN_TIMEZONE);
    $hoy = (new DateTime('now', $tz_ar))->format('Y-m-d');
    $manana = (new DateTime('+1 day', $tz_ar))->format('Y-m-d');

    $stats = $wpdb->get_row(
        "SELECT COUNT(*) AS total,
         SUM(CASE WHEN estado='pagado'    THEN 1 ELSE 0 END) AS pagadas,
         SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes,
         SUM(CASE WHEN estado='pagado'    THEN precio ELSE 0 END) AS recaudado
         FROM {$wpdb->prefix}an_reservas_v4"
    );

    $turnos_hoy = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.fecha_turno, r.nombre_cliente, r.whatsapp, r.email,
                r.servicio, r.precio, r.estado, r.guia_ia,
                s.name AS staff_name, l.name AS loc_name
         FROM {$wpdb->prefix}an_reservas_v4 r
         LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id=s.id
         LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id=l.id
         WHERE DATE(r.fecha_turno) = %s
         ORDER BY r.fecha_turno ASC",
        $hoy
    ));

    $proximos = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.fecha_turno, r.nombre_cliente, r.whatsapp, r.email,
                r.servicio, r.precio, r.estado, r.guia_ia,
                s.name AS staff_name, l.name AS loc_name
         FROM {$wpdb->prefix}an_reservas_v4 r
         LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id=s.id
         LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id=l.id
         WHERE DATE(r.fecha_turno) >= %s AND r.estado != 'cancelado'
         ORDER BY r.fecha_turno ASC LIMIT 50",
        $manana
    ));

    wp_send_json_success([
        'stats' => $stats,
        'turnos_hoy' => $turnos_hoy,
        'proximos' => $proximos,
        'timestamp' => (new DateTime('now', $tz_ar))->format('H:i:s'),
    ]);
}


// ═══════════════════════════════════════════════════════════════════
// 16. FUNCIÓN PRINCIPAL DEL PANEL ADMIN
// ═══════════════════════════════════════════════════════════════════
function an_admin_page()
{
    global $wpdb;
    $tab = sanitize_key($_GET['tab'] ?? 'reservas');
    $base = admin_url('admin.php?page=an-studio-reservas');

    /* ── Acciones rápidas ─────────────────────────────────────────── */
    if (isset($_GET['an_cancel']) && check_admin_referer('an_cancel_' . intval($_GET['an_cancel']))) {
        $wpdb->update($wpdb->prefix . 'an_reservas_v4', ['estado' => 'cancelado'], ['id' => intval($_GET['an_cancel'])]);
        echo '<div class="notice notice-success is-dismissible"><p>Reserva cancelada.</p></div>';
    }
    if ($tab === 'sucursales' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['an_save_location'])) {
        check_admin_referer('an_save_location');
        $id = intval($_POST['loc_id'] ?? 0);
        $dat = ['name' => sanitize_text_field($_POST['loc_name']), 'address' => sanitize_text_field($_POST['loc_address']), 'city' => sanitize_text_field($_POST['loc_city']), 'lat' => !empty($_POST['loc_lat']) ? floatval($_POST['loc_lat']) : null, 'lng' => !empty($_POST['loc_lng']) ? floatval($_POST['loc_lng']) : null, 'phone' => sanitize_text_field($_POST['loc_phone']), 'whatsapp' => sanitize_text_field($_POST['loc_whatsapp']), 'active' => isset($_POST['loc_active']) ? 1 : 0];
        $fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'];
        if ($id)
            $wpdb->update($wpdb->prefix . 'an_locations', $dat, ['id' => $id], $fmt, ['%d']);
        else
            $wpdb->insert($wpdb->prefix . 'an_locations', $dat, $fmt);
        echo '<div class="notice notice-success is-dismissible"><p>Sucursal guardada.</p></div>';
    }
    if ($tab === 'sucursales' && isset($_GET['an_del_loc']) && check_admin_referer('an_del_loc_' . intval($_GET['an_del_loc']))) {
        $wpdb->delete($wpdb->prefix . 'an_locations', ['id' => intval($_GET['an_del_loc'])]);
        echo '<div class="notice notice-success is-dismissible"><p>Sucursal eliminada.</p></div>';
    }
    if ($tab === 'profesionales' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['an_save_staff'])) {
        check_admin_referer('an_save_staff');
        $id = intval($_POST['staff_id'] ?? 0);
        $dat = ['name' => sanitize_text_field($_POST['staff_name']), 'role' => sanitize_text_field($_POST['staff_role']), 'bio' => sanitize_textarea_field($_POST['staff_bio']), 'photo_url' => esc_url_raw($_POST['staff_photo'] ?? ''), 'location_id' => intval($_POST['staff_location']), 'calendar_id' => sanitize_text_field($_POST['staff_calendar']), 'active' => isset($_POST['staff_active']) ? 1 : 0, 'sort_order' => intval($_POST['staff_order'] ?? 0)];
        $fmt = ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d'];
        if ($id)
            $wpdb->update($wpdb->prefix . 'an_staff', $dat, ['id' => $id], $fmt, ['%d']);
        else
            $wpdb->insert($wpdb->prefix . 'an_staff', $dat, $fmt);
        echo '<div class="notice notice-success is-dismissible"><p>Profesional guardado.</p></div>';
    }
    if ($tab === 'profesionales' && isset($_GET['an_del_staff']) && check_admin_referer('an_del_staff_' . intval($_GET['an_del_staff']))) {
        $wpdb->delete($wpdb->prefix . 'an_staff', ['id' => intval($_GET['an_del_staff'])]);
        echo '<div class="notice notice-success is-dismissible"><p>Profesional eliminado.</p></div>';
    }

    /* ── Nav ──────────────────────────────────────────────────────── */
    ?>
    <div class="wrap an-admin-wrap">
        <h1 style="font-size:22px;">💅 AN Studio — Panel</h1>
        <nav style="display:flex;margin:16px 0;border-bottom:2px solid #e5e7eb;">
            <?php foreach (['reservas' => '📋 Reservas', 'sucursales' => '🏢 Sucursales', 'profesionales' => '👩 Profesionales'] as $tid => $tl):
                $active = $tab === $tid; ?>
                <a href="<?php echo esc_url($base . '&tab=' . $tid); ?>"
                    style="padding:10px 22px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:<?php echo $active ? '3px solid #6366f1' : '3px solid transparent'; ?>;color:<?php echo $active ? '#6366f1' : '#6b7280'; ?>;margin-bottom:-2px;"><?php echo $tl; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php

        if ($tab === 'reservas'):
            /* ── Datos ── */
            $tz_ar = new DateTimeZone(AN_TIMEZONE);
            $hoy = (new DateTime('now', $tz_ar))->format('Y-m-d');
            $manana = (new DateTime('+1 day', $tz_ar))->format('Y-m-d');

            $total_filas = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}an_reservas_v4");
            $last_sql_err = $wpdb->last_error;

            $stats = $wpdb->get_row(
                "SELECT COUNT(*) AS total,
             SUM(CASE WHEN estado='pagado'    THEN 1 ELSE 0 END) AS pagadas,
             SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes,
             SUM(CASE WHEN estado='pagado'    THEN precio ELSE 0 END) AS recaudado
             FROM {$wpdb->prefix}an_reservas_v4"
            );

            $turnos_hoy = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, s.name AS staff_name, l.name AS loc_name
             FROM {$wpdb->prefix}an_reservas_v4 r
             LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id=s.id
             LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id=l.id
             WHERE DATE(r.fecha_turno) = %s ORDER BY r.fecha_turno ASC",
                $hoy
            ));

            $proximos = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, s.name AS staff_name, l.name AS loc_name
             FROM {$wpdb->prefix}an_reservas_v4 r
             LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id=s.id
             LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id=l.id
             WHERE DATE(r.fecha_turno) >= %s AND r.estado != 'cancelado'
             ORDER BY r.fecha_turno ASC LIMIT 50",
                $manana
            ));

            /* Historial con filtros */
            $estado_f = sanitize_text_field($_GET['estado'] ?? 'todos');
            $search = sanitize_text_field($_GET['s'] ?? '');
            $staff_f = intval($_GET['staff_id'] ?? 0);
            $loc_f = intval($_GET['location_id'] ?? 0);
            $fecha_f = sanitize_text_field($_GET['fecha'] ?? '');
            $periodo_f = sanitize_text_field($_GET['periodo'] ?? '');

            $where = "WHERE 1=1";
            if ($estado_f !== 'todos')
                $where .= $wpdb->prepare(' AND r.estado=%s', $estado_f);
            if ($staff_f)
                $where .= $wpdb->prepare(' AND r.staff_id=%d', $staff_f);
            if ($loc_f)
                $where .= $wpdb->prepare(' AND r.location_id=%d', $loc_f);
            if ($search)
                $where .= $wpdb->prepare(' AND (r.nombre_cliente LIKE %s OR r.email LIKE %s OR r.servicio LIKE %s OR r.whatsapp LIKE %s)', "%$search%", "%$search%", "%$search%", "%$search%");
            if ($fecha_f)
                $where .= $wpdb->prepare(' AND DATE(r.created_at) = %s', $fecha_f);
            elseif ($periodo_f !== '') {
                $dias = intval($periodo_f);
                if ($dias >= 1)
                    $where .= $wpdb->prepare(' AND r.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $dias);
            }

            $historico = $wpdb->get_results(
                "SELECT r.*, s.name AS staff_name, l.name AS loc_name
             FROM {$wpdb->prefix}an_reservas_v4 r
             LEFT JOIN {$wpdb->prefix}an_staff     s ON r.staff_id=s.id
             LEFT JOIN {$wpdb->prefix}an_locations l ON r.location_id=l.id
             $where ORDER BY r.created_at DESC LIMIT 500"
            );

            $all_staff = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}an_staff WHERE active=1 ORDER BY name ASC");
            $all_locs = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}an_locations WHERE active=1 ORDER BY name ASC");
            ?>

            <!-- ══ BARRA EN VIVO ══════════════════════════════════════════════ -->
            <div class="an-refresh-bar">
                <div class="an-refresh-dot idle" id="an-refresh-dot"></div>
                <span id="an-refresh-label">Iniciando actualización en vivo...</span>
                <span style="margin-left:auto;color:#6b7280;display:flex;align-items:center;gap:6px;">
                    Próximo refresh: <strong id="an-refresh-next">15</strong>s
                    <button onclick="anDoRefresh()"
                        style="background:#dcfce7;border:none;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600;color:#15803d;cursor:pointer;">↻
                        Ahora</button>
                </span>
            </div>

            <!-- ══ DEBUG ══════════════════════════════════════════════════════ -->
            <div
                style="background:#fefce8;border:1px solid #fde047;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:12px;color:#713f12;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span>🗄️ <strong>BD:</strong> <?php echo $total_filas; ?> filas en wp_an_reservas_v4</span>
                <?php if ($last_sql_err): ?><span style="color:#991b1b;">⚠️ <?php echo esc_html($last_sql_err); ?></span>
                <?php else: ?><span style="color:#166534;">✅ Sin errores SQL</span><?php endif; ?>
                <span style="font-size:10px;color:#92400e;">🔗 Webhook:
                    <code><?php echo esc_html(home_url('/wp-json/an-luxury/v4/pago')); ?></code></span>
            </div>

            <!-- ══ STATS ══════════════════════════════════════════════════════ -->
            <div class="an-stat-grid">
                <?php foreach ([
                    ['Total BD', (int) $stats->total, '#6366f1', '📋', 'stat-total'],
                    ['Pagadas', (int) $stats->pagadas, '#22c55e', '✅', 'stat-pagadas'],
                    ['Pendientes', (int) $stats->pendientes, '#f59e0b', '⏳', 'stat-pendientes'],
                    ['Recaudado', '$' . number_format((float) $stats->recaudado, 0, ',', '.'), '#ec4899', '💰', 'stat-recaudado'],
                ] as $c): ?>
                    <div class="an-stat-card" style="border-top-color:<?php echo $c[2]; ?>;">
                        <div style="font-size:22px;margin-bottom:2px;"><?php echo $c[3]; ?></div>
                        <div id="<?php echo $c[4]; ?>" class="an-stat-val" style="color:<?php echo $c[2]; ?>;"><?php echo $c[1]; ?>
                        </div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo $c[0]; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ TURNOS DE HOY ══════════════════════════════════════════════ -->
            <div class="an-section-box" id="section-hoy">
                <div class="an-section-header">
                    <h3>📅 Turnos de Hoy — <span
                            style="color:#6b7280;font-weight:400;"><?php echo date_i18n('d/m/Y', strtotime($hoy)); ?></span>
                        <span class="an-badge-count an-badge-hoy" id="badge-section-hoy"><?php echo count($turnos_hoy); ?></span>
                    </h3>
                </div>
                <div class="an-bulk-bar" id="bulk-bar-section-hoy">
                    <span class="an-bulk-count" id="bulk-count-section-hoy"></span>
                    <select class="an-bulk-select" id="bulk-action-select-section-hoy">
                        <option value="">— Acción —</option>
                        <option value="marcar_pagado">✅ Marcar pagado</option>
                        <option value="marcar_pendiente">⏳ Marcar pendiente</option>
                        <option value="marcar_cancelado">❌ Marcar cancelado</option>
                        <option value="eliminar">🗑️ Eliminar</option>
                    </select>
                    <button class="an-bulk-apply" id="bulk-apply-section-hoy"
                        onclick="anBulkApply('section-hoy')">Aplicar</button>
                    <button class="an-bulk-cancel-sel" onclick="anToggleAll({checked:false},'section-hoy')">Cancelar
                        selección</button>
                    <div id="bulk-msg-section-hoy" class="an-bulk-msg"></div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th class="cb-col"><input type="checkbox" id="cb-master-section-hoy"
                                        onchange="anToggleAll(this,'section-hoy')"></th>
                                <th>Hora</th>
                                <th>Clienta</th>
                                <th>WA / Email</th>
                                <th>Sucursal</th>
                                <th>Profesional</th>
                                <th>Servicio</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-section-hoy">
                            <?php if (empty($turnos_hoy)): ?>
                                <tr class="an-empty-row">
                                    <td colspan="10">No hay turnos para hoy.</td>
                                </tr>
                            <?php else:
                                foreach ($turnos_hoy as $r):
                                    $hora = date('H:i', strtotime($r->fecha_turno));
                                    $wa_url = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $r->whatsapp);
                                    $pill = an_estado_pill($r->estado);
                                    $can_url = wp_nonce_url($base . '&tab=reservas&an_cancel=' . $r->id, 'an_cancel_' . $r->id);
                                    ?>
                                    <tr id="row-<?php echo $r->id; ?>">
                                        <td class="cb-col"><input type="checkbox" class="an-cb-row" value="<?php echo $r->id; ?>"
                                                onchange="anToggleRow(this,'section-hoy')"></td>
                                        <td style="font-weight:700;font-size:14px;"><?php echo esc_html($hora); ?>hs</td>
                                        <td style="font-weight:700;"><?php echo esc_html($r->nombre_cliente); ?></td>
                                        <td><a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                style="color:#22c55e;text-decoration:none;font-size:11px;"><?php echo esc_html($r->whatsapp); ?></a>
                                            <?php if ($r->email): ?><br><span
                                                    style="color:#6366f1;font-size:10px;"><?php echo esc_html($r->email); ?></span><?php endif; ?>
                                        </td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->loc_name ?: '—'); ?></td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->staff_name ?: '—'); ?></td>
                                        <td style="font-size:11px;max-width:140px;"><?php echo esc_html($r->servicio); ?></td>
                                        <td style="font-weight:700;">$<?php echo number_format($r->precio, 0, ',', '.'); ?></td>
                                        <td><?php echo $pill; ?></td>
                                        <td>
                                            <div style="display:flex;gap:3px;flex-wrap:wrap;align-items:center;">
                                                <a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                    class="an-action-btn an-btn-wa">💬</a>
                                                <?php if ($r->estado === 'pendiente'): ?><button id="repro-btn-<?php echo $r->id; ?>"
                                                        class="an-action-btn an-btn-repro" onclick="anReprocesar(<?php echo $r->id; ?>)"
                                                        title="Buscar pago en MP">🔄</button><?php endif; ?>
                                                <?php if ($r->estado !== 'cancelado'): ?><a href="<?php echo esc_url($can_url); ?>"
                                                        onclick="return confirm('¿Cancelar?')"
                                                        class="an-action-btn an-btn-cancel">✕</a><?php endif; ?>
                                                <?php if (!empty($r->guia_ia)): ?><span class="an-action-btn an-btn-ai"
                                                        title="<?php echo esc_attr($r->guia_ia); ?>">✨</span><?php endif; ?>
                                            </div>
                                            <div id="repro-res-<?php echo $r->id; ?>" class="an-repro-result"></div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══ PRÓXIMOS TURNOS ════════════════════════════════════════════ -->
            <div class="an-section-box" id="section-proximos">
                <div class="an-section-header">
                    <h3>🗓️ Próximos Turnos <span style="color:#6b7280;font-weight:400;font-size:12px;">(mañana en
                            adelante)</span>
                        <span class="an-badge-count" id="badge-section-proximos"><?php echo count($proximos); ?></span>
                    </h3>
                </div>
                <div class="an-bulk-bar" id="bulk-bar-section-proximos">
                    <span class="an-bulk-count" id="bulk-count-section-proximos"></span>
                    <select class="an-bulk-select" id="bulk-action-select-section-proximos">
                        <option value="">— Acción —</option>
                        <option value="marcar_pagado">✅ Marcar pagado</option>
                        <option value="marcar_pendiente">⏳ Marcar pendiente</option>
                        <option value="marcar_cancelado">❌ Marcar cancelado</option>
                        <option value="eliminar">🗑️ Eliminar</option>
                    </select>
                    <button class="an-bulk-apply" id="bulk-apply-section-proximos"
                        onclick="anBulkApply('section-proximos')">Aplicar</button>
                    <button class="an-bulk-cancel-sel" onclick="anToggleAll({checked:false},'section-proximos')">Cancelar
                        selección</button>
                    <div id="bulk-msg-section-proximos" class="an-bulk-msg"></div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th class="cb-col"><input type="checkbox" id="cb-master-section-proximos"
                                        onchange="anToggleAll(this,'section-proximos')"></th>
                                <th>Hora</th>
                                <th>Clienta</th>
                                <th>WA / Email</th>
                                <th>Sucursal</th>
                                <th>Profesional</th>
                                <th>Servicio</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-section-proximos">
                            <?php if (empty($proximos)): ?>
                                <tr class="an-empty-row">
                                    <td colspan="10">No hay turnos futuros.</td>
                                </tr>
                            <?php else:
                                $last_f = '';
                                foreach ($proximos as $r):
                                    $f_dia = date('Y-m-d', strtotime($r->fecha_turno));
                                    $hora = date('H:i', strtotime($r->fecha_turno));
                                    $wa_url = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $r->whatsapp);
                                    $pill = an_estado_pill($r->estado);
                                    $can_url = wp_nonce_url($base . '&tab=reservas&an_cancel=' . $r->id, 'an_cancel_' . $r->id);
                                    if ($f_dia !== $last_f):
                                        $last_f = $f_dia; ?>
                                        <tr class="an-day-sep" id="sep-section-proximos-<?php echo str_replace('-', '', $f_dia); ?>">
                                            <td colspan="10">📅 <?php echo esc_html(date_i18n('D d/m', strtotime($f_dia))); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr id="row-<?php echo $r->id; ?>">
                                        <td class="cb-col"><input type="checkbox" class="an-cb-row" value="<?php echo $r->id; ?>"
                                                onchange="anToggleRow(this,'section-proximos')"></td>
                                        <td style="font-weight:700;"><?php echo esc_html($hora); ?>hs</td>
                                        <td style="font-weight:700;"><?php echo esc_html($r->nombre_cliente); ?></td>
                                        <td><a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                style="color:#22c55e;font-size:11px;text-decoration:none;"><?php echo esc_html($r->whatsapp); ?></a>
                                            <?php if ($r->email): ?><br><span
                                                    style="color:#6366f1;font-size:10px;"><?php echo esc_html($r->email); ?></span><?php endif; ?>
                                        </td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->loc_name ?: '—'); ?></td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->staff_name ?: '—'); ?></td>
                                        <td style="font-size:11px;max-width:140px;"><?php echo esc_html($r->servicio); ?></td>
                                        <td style="font-weight:700;">$<?php echo number_format($r->precio, 0, ',', '.'); ?></td>
                                        <td><?php echo $pill; ?></td>
                                        <td>
                                            <div style="display:flex;gap:3px;flex-wrap:wrap;">
                                                <a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                    class="an-action-btn an-btn-wa">💬</a>
                                                <?php if ($r->estado === 'pendiente'): ?><button id="repro-btn-<?php echo $r->id; ?>"
                                                        class="an-action-btn an-btn-repro" onclick="anReprocesar(<?php echo $r->id; ?>)"
                                                        title="Buscar pago en MP">🔄</button><?php endif; ?>
                                                <?php if ($r->estado !== 'cancelado'): ?><a href="<?php echo esc_url($can_url); ?>"
                                                        onclick="return confirm('¿Cancelar?')"
                                                        class="an-action-btn an-btn-cancel">✕</a><?php endif; ?>
                                            </div>
                                            <div id="repro-res-<?php echo $r->id; ?>" class="an-repro-result"></div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══ HISTORIAL ══════════════════════════════════════════════════ -->
            <div class="an-section-box">
                <div class="an-section-header">
                    <h3>📋 Historial <span class="an-badge-count"><?php echo count($historico); ?></span></h3>
                    <span style="font-size:11px;color:#6b7280;">El historial solo se actualiza al filtrar — no se refresca
                        automáticamente</span>
                </div>
                <div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;">
                    <div style="margin-bottom:10px;">
                        <?php $periodos = ['' => 'Todo', '1' => 'Hoy', '7' => '7 días', '15' => '15 días', '30' => '30 días'];
                        foreach ($periodos as $pv => $pl):
                            $qp = $base . '&tab=reservas&periodo=' . $pv . ($estado_f !== 'todos' ? '&estado=' . urlencode($estado_f) : '') . ($search ? '&s=' . urlencode($search) : '') . ($staff_f ? '&staff_id=' . $staff_f : '') . ($loc_f ? '&location_id=' . $loc_f : '');
                            $act = ($periodo_f === $pv && !$fecha_f);
                            ?><a href="<?php echo esc_url($qp); ?>"
                                class="an-qf-btn <?php echo $act ? 'active' : ''; ?>"><?php echo $pl; ?></a> <?php endforeach; ?>
                    </div>
                    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <input type="hidden" name="page" value="an-studio-reservas">
                        <input type="hidden" name="tab" value="reservas">
                        <input type="hidden" name="periodo" value="<?php echo esc_attr($periodo_f); ?>">
                        <div><label
                                style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#374151;margin-bottom:3px;">Estado</label>
                            <select name="estado"
                                style="border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:12px;">
                                <option value="todos" <?php selected($estado_f, 'todos'); ?>>Todos</option>
                                <option value="pagado" <?php selected($estado_f, 'pagado'); ?>>✅ Pagado</option>
                                <option value="pendiente" <?php selected($estado_f, 'pendiente'); ?>>⏳ Pendiente</option>
                                <option value="cancelado" <?php selected($estado_f, 'cancelado'); ?>>❌ Cancelado</option>
                            </select>
                        </div>
                        <div><label
                                style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#374151;margin-bottom:3px;">Fecha
                                exacta</label>
                            <input type="date" name="fecha" value="<?php echo esc_attr($fecha_f); ?>"
                                style="border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:12px;">
                        </div>
                        <?php if (!empty($all_locs)): ?>
                            <div><label
                                    style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#374151;margin-bottom:3px;">Sucursal</label>
                                <select name="location_id"
                                    style="border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:12px;">
                                    <option value="0">Todas</option>
                                    <?php foreach ($all_locs as $l): ?>
                                        <option value="<?php echo $l->id; ?>" <?php selected($loc_f, $l->id); ?>>
                                            <?php echo esc_html($l->name); ?></option><?php endforeach; ?>
                                </select>
                            </div><?php endif; ?>
                        <?php if (!empty($all_staff)): ?>
                            <div><label
                                    style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#374151;margin-bottom:3px;">Profesional</label>
                                <select name="staff_id"
                                    style="border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:12px;">
                                    <option value="0">Todos</option>
                                    <?php foreach ($all_staff as $s): ?>
                                        <option value="<?php echo $s->id; ?>" <?php selected($staff_f, $s->id); ?>>
                                            <?php echo esc_html($s->name); ?></option><?php endforeach; ?>
                                </select>
                            </div><?php endif; ?>
                        <div><label
                                style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#374151;margin-bottom:3px;">Buscar</label>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                                placeholder="Nombre, email, servicio..."
                                style="border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:12px;width:170px;">
                        </div>
                        <button type="submit"
                            style="background:#6366f1;color:#fff;border:none;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;align-self:flex-end;">Filtrar</button>
                        <a href="<?php echo esc_url($base . '&tab=reservas'); ?>"
                            style="color:#6b7280;font-size:12px;padding:7px 0;align-self:flex-end;text-decoration:none;">Limpiar</a>
                    </form>
                </div>
                <!-- Bulk bar historial -->
                <div class="an-bulk-bar" id="bulk-bar-section-hist">
                    <span class="an-bulk-count" id="bulk-count-section-hist"></span>
                    <select class="an-bulk-select" id="bulk-action-select-section-hist">
                        <option value="">— Acción —</option>
                        <option value="marcar_pagado">✅ Marcar pagado</option>
                        <option value="marcar_pendiente">⏳ Marcar pendiente</option>
                        <option value="marcar_cancelado">❌ Marcar cancelado</option>
                        <option value="eliminar">🗑️ Eliminar</option>
                    </select>
                    <button class="an-bulk-apply" id="bulk-apply-section-hist"
                        onclick="anBulkApply('section-hist')">Aplicar</button>
                    <button class="an-bulk-cancel-sel" onclick="anToggleAll({checked:false},'section-hist')">Cancelar
                        selección</button>
                    <div id="bulk-msg-section-hist" class="an-bulk-msg"></div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th class="cb-col"><input type="checkbox" id="cb-master-section-hist"
                                        onchange="anToggleAll(this,'section-hist')"></th>
                                <th>#</th>
                                <th>Creado</th>
                                <th>Turno</th>
                                <th>Clienta</th>
                                <th>WA / Email</th>
                                <th>Sucursal</th>
                                <th>Profesional</th>
                                <th>Servicio</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-section-hist">
                            <?php if (empty($historico)): ?>
                                <tr class="an-empty-row">
                                    <td colspan="12">Sin resultados.</td>
                                </tr>
                            <?php else:
                                foreach ($historico as $r):
                                    $wa_url = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $r->whatsapp);
                                    $pill = an_estado_pill($r->estado);
                                    $can_url = wp_nonce_url($base . '&tab=reservas&an_cancel=' . $r->id, 'an_cancel_' . $r->id);
                                    ?>
                                    <tr id="row-<?php echo $r->id; ?>">
                                        <td class="cb-col"><input type="checkbox" class="an-cb-row" value="<?php echo $r->id; ?>"
                                                onchange="anToggleRow(this,'section-hist')"></td>
                                        <td style="color:#9ca3af;font-size:11px;"><?php echo $r->id; ?></td>
                                        <td style="color:#9ca3af;font-size:11px;white-space:nowrap;">
                                            <?php echo esc_html(date_i18n('d/m H:i', strtotime($r->created_at))); ?></td>
                                        <td style="font-weight:600;white-space:nowrap;font-size:12px;">
                                            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($r->fecha_turno))); ?>hs</td>
                                        <td style="font-weight:700;"><?php echo esc_html($r->nombre_cliente); ?></td>
                                        <td><a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                style="color:#22c55e;text-decoration:none;font-size:11px;"><?php echo esc_html($r->whatsapp); ?></a>
                                            <?php if ($r->email): ?><br><a href="mailto:<?php echo esc_attr($r->email); ?>"
                                                    style="color:#6366f1;font-size:10px;text-decoration:none;"><?php echo esc_html($r->email); ?></a><?php endif; ?>
                                        </td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->loc_name ?: '—'); ?></td>
                                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($r->staff_name ?: '—'); ?></td>
                                        <td style="font-size:11px;max-width:150px;"><?php echo esc_html($r->servicio); ?></td>
                                        <td style="font-weight:700;">$<?php echo number_format($r->precio, 0, ',', '.'); ?></td>
                                        <td><?php echo $pill; ?></td>
                                        <td>
                                            <div style="display:flex;gap:3px;flex-wrap:wrap;">
                                                <a href="<?php echo esc_url($wa_url); ?>" target="_blank"
                                                    class="an-action-btn an-btn-wa">💬</a>
                                                <?php if ($r->estado === 'pendiente'): ?><button id="repro-btn-<?php echo $r->id; ?>"
                                                        class="an-action-btn an-btn-repro" onclick="anReprocesar(<?php echo $r->id; ?>)"
                                                        title="Buscar pago en MP">🔄</button><?php endif; ?>
                                                <?php if ($r->estado !== 'cancelado'): ?><a href="<?php echo esc_url($can_url); ?>"
                                                        onclick="return confirm('¿Cancelar?')"
                                                        class="an-action-btn an-btn-cancel">✕</a><?php endif; ?>
                                                <?php if (!empty($r->guia_ia)): ?><span class="an-action-btn an-btn-ai"
                                                        title="<?php echo esc_attr($r->guia_ia); ?>">✨</span><?php endif; ?>
                                            </div>
                                            <div id="repro-res-<?php echo $r->id; ?>" class="an-repro-result"></div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <p style="font-size:11px;color:#9ca3af;padding:10px 16px;"><?php echo count($historico); ?> reservas · AN Studio
                    v15.2</p>
            </div>

        <?php elseif ($tab === 'sucursales'):
            $locs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}an_locations ORDER BY id ASC");
            $editing = isset($_GET['edit_loc']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}an_locations WHERE id=%d", intval($_GET['edit_loc']))) : null;
            $fi = $editing ?: (object) ['id' => 0, 'name' => '', 'address' => '', 'city' => '', 'lat' => '', 'lng' => '', 'phone' => '', 'whatsapp' => '', 'active' => 1];
            ?>
            <div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">
                <div>
                    <div class="an-section-box">
                        <table class="an-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Dirección</th>
                                    <th>Coords</th>
                                    <th>Estado</th>
                                    <th style="text-align:center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locs)): ?>
                                    <tr class="an-empty-row">
                                        <td colspan="5">No hay sucursales.</td>
                                    </tr>
                                <?php else:
                                    foreach ($locs as $l): ?>
                                        <tr>
                                            <td style="font-weight:700;"><?php echo esc_html($l->name); ?></td>
                                            <td style="color:#6b7280;font-size:12px;">
                                                <?php echo esc_html($l->address . ($l->city ? ', ' . $l->city : '')); ?></td>
                                            <td style="font-size:11px;color:#9ca3af;">
                                                <?php echo $l->lat ? esc_html($l->lat . ', ' . $l->lng) : '—'; ?></td>
                                            <td><span
                                                    class="an-pill <?php echo $l->active ? 'an-pill-pagado' : 'an-pill-cancelado'; ?>"><?php echo $l->active ? '✅ Activa' : '❌ Inactiva'; ?></span>
                                            </td>
                                            <td style="text-align:center">
                                                <a href="<?php echo esc_url($base . '&tab=sucursales&edit_loc=' . $l->id); ?>"
                                                    style="background:#ede9fe;color:#6d28d9;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;margin-right:4px;">✎</a>
                                                <a href="<?php echo esc_url(wp_nonce_url($base . '&tab=sucursales&an_del_loc=' . $l->id, 'an_del_loc_' . $l->id)); ?>"
                                                    onclick="return confirm('¿Eliminar?')"
                                                    style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">✕</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;">
                        <?php echo $fi->id ? 'Editar sucursal' : 'Nueva sucursal'; ?></h3>
                    <form method="post" action="<?php echo esc_url($base . '&tab=sucursales'); ?>">
                        <?php wp_nonce_field('an_save_location');
                        if ($fi->id): ?><input type="hidden" name="loc_id"
                                value="<?php echo $fi->id; ?>"><?php endif; ?>
                        <?php foreach ([['loc_name', 'Nombre *', $fi->name, 'AN Studio Recoleta'], ['loc_address', 'Dirección', $fi->address, 'Av. Callao 123'], ['loc_city', 'Ciudad', $fi->city, 'Buenos Aires'], ['loc_lat', 'Latitud', $fi->lat, '-34.5875'], ['loc_lng', 'Longitud', $fi->lng, '-58.3972'], ['loc_phone', 'Teléfono', $fi->phone, '+54 11 1234-5678'], ['loc_whatsapp', 'WhatsApp', $fi->whatsapp, '+54 9 11 1234-5678']] as $f): ?>
                            <div style="margin-bottom:10px;"><label
                                    style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:3px;"><?php echo $f[1]; ?></label>
                                <input type="text" name="<?php echo $f[0]; ?>" value="<?php echo esc_attr($f[2]); ?>"
                                    placeholder="<?php echo esc_attr($f[3]); ?>"
                                    style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;"><input type="checkbox"
                                id="loc_active" name="loc_active" value="1" <?php checked($fi->active, 1); ?>><label
                                for="loc_active" style="font-size:13px;font-weight:600;">Activa</label></div>
                        <div style="display:flex;gap:8px;"><button type="submit" name="an_save_location"
                                style="background:#6366f1;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;">Guardar</button>
                            <?php if ($fi->id): ?><a href="<?php echo esc_url($base . '&tab=sucursales'); ?>"
                                    style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:8px 14px;font-size:13px;font-weight:600;text-decoration:none;">+
                                    Nueva</a><?php endif; ?></div>
                    </form>
                </div>
            </div>

        <?php elseif ($tab === 'profesionales'):
            $staff_list = $wpdb->get_results("SELECT s.*,l.name AS loc_name FROM {$wpdb->prefix}an_staff s LEFT JOIN {$wpdb->prefix}an_locations l ON s.location_id=l.id ORDER BY s.sort_order ASC,s.id ASC");
            $locs_form = $wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}an_locations WHERE active=1 ORDER BY name ASC");
            $editing = isset($_GET['edit_staff']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}an_staff WHERE id=%d", intval($_GET['edit_staff']))) : null;
            $fis = $editing ?: (object) ['id' => 0, 'name' => '', 'role' => '', 'bio' => '', 'photo_url' => '', 'location_id' => 0, 'calendar_id' => '', 'active' => 1, 'sort_order' => 0];
            ?>
            <div style="display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start;">
                <div>
                    <div class="an-section-box">
                        <table class="an-table">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Nombre</th>
                                    <th>Rol</th>
                                    <th>Sucursal</th>
                                    <th>Calendario</th>
                                    <th>Estado</th>
                                    <th>Orden</th>
                                    <th style="text-align:center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staff_list)): ?>
                                    <tr class="an-empty-row">
                                        <td colspan="8">No hay profesionales.</td>
                                    </tr>
                                <?php else:
                                    foreach ($staff_list as $s):
                                        $init = implode('', array_map(function ($w) {
                                            return $w[0] ?? ''; }, array_slice(explode(' ', $s->name), 0, 2))); ?>
                                        <tr>
                                            <td style="padding:8px 12px;"><?php if ($s->photo_url): ?><img
                                                        src="<?php echo esc_attr($s->photo_url); ?>"
                                                        style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                                                <?php else: ?>
                                                    <div
                                                        style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#BFA37C,#FFEBAD);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#331B0C;">
                                                        <?php echo esc_html(strtoupper($init)); ?></div><?php endif; ?>
                                            </td>
                                            <td style="font-weight:700;"><?php echo esc_html($s->name); ?></td>
                                            <td style="color:#6b7280;"><?php echo esc_html($s->role); ?></td>
                                            <td style="font-size:11px;color:#6b7280;">
                                                <?php echo $s->location_id ? esc_html($s->loc_name) : '<em style="color:#9ca3af">Todas</em>'; ?>
                                            </td>
                                            <td><?php echo $s->calendar_id ? '<span class="an-pill an-pill-pagado" style="font-size:9px;">✓ Config</span>' : '<span style="color:#d1d5db;font-size:11px;">—</span>'; ?>
                                            </td>
                                            <td><span
                                                    class="an-pill <?php echo $s->active ? 'an-pill-pagado' : 'an-pill-cancelado'; ?>"><?php echo $s->active ? '✅' : '❌'; ?></span>
                                            </td>
                                            <td style="text-align:center;color:#9ca3af;"><?php echo $s->sort_order; ?></td>
                                            <td style="text-align:center;">
                                                <a href="<?php echo esc_url($base . '&tab=profesionales&edit_staff=' . $s->id); ?>"
                                                    style="background:#ede9fe;color:#6d28d9;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;margin-right:3px;">✎</a>
                                                <a href="<?php echo esc_url(wp_nonce_url($base . '&tab=profesionales&an_del_staff=' . $s->id, 'an_del_staff_' . $s->id)); ?>"
                                                    onclick="return confirm('¿Eliminar?')"
                                                    style="background:#fee2e2;color:#991b1b;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">✕</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;">
                        <?php echo $fis->id ? 'Editar profesional' : 'Nuevo profesional'; ?></h3>
                    <form method="post" action="<?php echo esc_url($base . '&tab=profesionales'); ?>">
                        <?php wp_nonce_field('an_save_staff');
                        if ($fis->id): ?><input type="hidden" name="staff_id"
                                value="<?php echo $fis->id; ?>"><?php endif; ?>
                        <?php foreach ([['staff_name', 'Nombre *', $fis->name, 'Ana García'], ['staff_role', 'Rol', $fis->role, 'Esteticista'], ['staff_photo', 'URL foto', $fis->photo_url, 'https://...'], ['staff_calendar', 'ID Calendario Google', $fis->calendar_id, 'ana@gmail.com']] as $f): ?>
                            <div style="margin-bottom:10px;"><label
                                    style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:3px;"><?php echo $f[1]; ?></label>
                                <input type="text" name="<?php echo $f[0]; ?>" value="<?php echo esc_attr($f[2]); ?>"
                                    placeholder="<?php echo esc_attr($f[3]); ?>"
                                    style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-bottom:10px;"><label
                                style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:3px;">Bio</label>
                            <textarea name="staff_bio" rows="3"
                                style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;resize:vertical;"><?php echo esc_textarea($fis->bio); ?></textarea>
                        </div>
                        <div style="margin-bottom:10px;"><label
                                style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:3px;">Sucursal</label>
                            <select name="staff_location"
                                style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                                <option value="0">Todas</option>
                                <?php foreach ($locs_form as $l): ?>
                                    <option value="<?php echo $l->id; ?>" <?php selected($fis->location_id, $l->id); ?>>
                                        <?php echo esc_html($l->name); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:12px;align-items:flex-end;">
                            <div><label
                                    style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:3px;">Orden</label>
                                <input type="number" name="staff_order" value="<?php echo esc_attr($fis->sort_order); ?>" min="0"
                                    style="width:70px;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                            <div style="flex:1;display:flex;align-items:center;gap:7px;padding-bottom:3px;"><input
                                    type="checkbox" id="staff_active" name="staff_active" value="1" <?php checked($fis->active, 1); ?>><label for="staff_active"
                                    style="font-size:13px;font-weight:600;color:#374151;">Activo</label></div>
                        </div>
                        <div style="display:flex;gap:8px;"><button type="submit" name="an_save_staff"
                                style="background:#6366f1;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;">Guardar</button>
                            <?php if ($fis->id): ?><a href="<?php echo esc_url($base . '&tab=profesionales'); ?>"
                                    style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:8px 14px;font-size:13px;font-weight:600;text-decoration:none;">+
                                    Nuevo</a><?php endif; ?></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}


// ═══════════════════════════════════════════════════════════════════
// 17. HELPER: PILL DE ESTADO
// ═══════════════════════════════════════════════════════════════════
function an_estado_pill(string $estado): string
{
    $map = [
        'pagado' => ['an-pill-pagado', '✅ Pagado'],
        'pendiente' => ['an-pill-pendiente', '⏳ Pendiente'],
        'cancelado' => ['an-pill-cancelado', '❌ Cancelado'],
    ];
    $c = $map[$estado] ?? ['', $estado];
    return '<span class="an-pill ' . esc_attr($c[0]) . ' an-estado-pill">' . esc_html($c[1]) . '</span>';
}