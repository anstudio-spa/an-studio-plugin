<?php
/**
 * AN STUDIO — an-dashboard/auth.php
 * ═══════════════════════════════════════════════════════
 * Autenticación del dashboard de locales (/admin/)
 * Requiere: an-config.php + an-database.php activos
 *
 * Flujo:
 *   1. Usuario accede a /admin/
 *   2. Si no está logueado → redirige a wp-login.php (o Google OAuth)
 *   3. Si está logueado pero NO tiene registro en an_dashboard_users → muestra "pendiente de aprobación"
 *   4. Si tiene registro con active=0 → "tu cuenta está pendiente de aprobación"
 *   5. Si tiene registro con active=1 → acceso concedido, continuar
 *
 * Gestión de usuarios desde WP Admin:
 *   - Menú: AN Studio → Accesos Dashboard
 *   - Ahí se aprueba, se asigna sucursal y se revoca acceso
 */


// ═══════════════════════════════════════════════════════════════════
// PÁGINA /admin/ — registrar rewrite + template
// ═══════════════════════════════════════════════════════════════════
add_action('init', function () {
    add_rewrite_rule('^admin/?$', 'index.php?an_dashboard=1', 'top');
    add_rewrite_rule('^admin/login/?$', 'index.php?an_dashboard_login=1', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'an_dashboard';
    $vars[] = 'an_dashboard_login';
    return $vars;
});

add_action('template_redirect', 'an_dashboard_router');
function an_dashboard_router()
{
    $is_dash = get_query_var('an_dashboard');
    $is_login = get_query_var('an_dashboard_login');

    if (!$is_dash && !$is_login)
        return;

    // Manejar registro desde el formulario
    if ($is_login && $_SERVER['REQUEST_METHOD'] === 'POST') {
        an_handle_dashboard_register();
    }

    // Manejar login WP desde el formulario del dashboard
    if ($is_dash && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['an_dash_login'])) {
        an_handle_dashboard_wp_login();
    }

    // Mostrar página
    if ($is_login) {
        an_render_dashboard_login_page();
        exit;
    }

    if ($is_dash) {
        an_render_dashboard_gate();
        exit;
    }
}


// ═══════════════════════════════════════════════════════════════════
// VERIFICAR ACCESO — retorna el registro del usuario o false
// ═══════════════════════════════════════════════════════════════════
function an_get_dashboard_user(): object|false
{
    if (!is_user_logged_in())
        return false;

    global $wpdb;
    $wp_user_id = get_current_user_id();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}an_dashboard_users WHERE wp_user_id = %d",
        $wp_user_id
    )) ?: false;
}

function an_dashboard_can_access(): bool
{
    $du = an_get_dashboard_user();
    return $du && (int) $du->active === 1;
}


// ═══════════════════════════════════════════════════════════════════
// GATE — decide qué mostrar en /admin/
// ═══════════════════════════════════════════════════════════════════
function an_render_dashboard_gate()
{
    // No logueado → mostrar login
    if (!is_user_logged_in()) {
        an_render_login_form();
        return;
    }

    $du = an_get_dashboard_user();

    // Logueado pero sin registro → mostrar formulario de solicitud
    if (!$du) {
        an_render_request_access_form();
        return;
    }

    // Registro existe pero pendiente de aprobación
    if ((int) $du->active === 0) {
        an_render_pending_page();
        return;
    }

    // Acceso concedido → el dashboard se encarga (index.php lo llama)
    // Incluir el dashboard principal
    require_once __DIR__ . '/index.php';
}


// ═══════════════════════════════════════════════════════════════════
// MANEJAR LOGIN WP DESDE EL DASHBOARD
// ═══════════════════════════════════════════════════════════════════
function an_handle_dashboard_wp_login()
{
    if (!isset($_POST['an_dash_login_nonce']) || !wp_verify_nonce($_POST['an_dash_login_nonce'], 'an_dash_login')) {
        return;
    }

    $user = wp_signon([
        'user_login' => sanitize_text_field($_POST['log'] ?? ''),
        'user_password' => $_POST['pwd'] ?? '',
        'remember' => true,
    ], is_ssl());

    if (is_wp_error($user)) {
        // Guardar error en sesión y redirigir
        set_transient('an_dash_login_error_' . session_id(), $user->get_error_message(), 60);
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    wp_redirect(home_url('/admin/'));
    exit;
}


// ═══════════════════════════════════════════════════════════════════
// MANEJAR SOLICITUD DE ACCESO
// ═══════════════════════════════════════════════════════════════════
function an_handle_dashboard_register()
{
    if (!isset($_POST['an_register_nonce']) || !wp_verify_nonce($_POST['an_register_nonce'], 'an_dashboard_register')) {
        wp_redirect(home_url('/admin/login/?error=nonce'));
        exit;
    }

    $username = sanitize_user($_POST['reg_username'] ?? '');
    $email = sanitize_email($_POST['reg_email'] ?? '');
    $password = $_POST['reg_password'] ?? '';
    $name = sanitize_text_field($_POST['reg_name'] ?? '');

    if (!$username || !$email || !$password || !$name) {
        wp_redirect(home_url('/admin/login/?error=empty'));
        exit;
    }

    if (!is_email($email)) {
        wp_redirect(home_url('/admin/login/?error=email'));
        exit;
    }

    if (username_exists($username) || email_exists($email)) {
        wp_redirect(home_url('/admin/login/?error=exists'));
        exit;
    }

    // Crear usuario WP con rol mínimo
    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        wp_redirect(home_url('/admin/login/?error=wp'));
        exit;
    }

    wp_update_user(['ID' => $user_id, 'display_name' => $name, 'role' => 'subscriber']);

    // Insertar en an_dashboard_users como pendiente (active=0)
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'an_dashboard_users',
        ['wp_user_id' => $user_id, 'location_ids' => '[]', 'role' => 'viewer', 'active' => 0],
        ['%d', '%s', '%s', '%d']
    );

    // Notificar al admin
    wp_mail(
        AN_ADMIN_EMAIL,
        '🔔 Nueva solicitud de acceso al dashboard — AN Studio',
        "El usuario {$name} ({$email}) solicitó acceso al dashboard de locales.\n\n"
        . "Para aprobar o rechazar, ingresá a:\n"
        . admin_url('admin.php?page=an-studio-accesos') . "\n\n"
        . "ID de usuario WordPress: {$user_id}",
        ['Content-Type: text/plain; charset=UTF-8']
    );

    // Loguear al nuevo usuario automáticamente
    wp_set_auth_cookie($user_id, true);

    wp_redirect(home_url('/admin/?registered=1'));
    exit;
}


// ═══════════════════════════════════════════════════════════════════
// RENDERS DE PÁGINAS
// ═══════════════════════════════════════════════════════════════════
function an_dashboard_html_open(string $title = 'AN Studio')
{
    ?><!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>
            <?php echo esc_html($title); ?> · AN Studio
        </title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link
            href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,600&family=Poppins:wght@300;400;500;600&display=swap"
            rel="stylesheet">
        <style>
            *,
            *::before,
            *::after {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'Poppins', sans-serif;
                background: #0f0a07;
                color: #f5ede6;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px
            }

            .an-auth-card {
                background: #1a0d06;
                border: 1px solid rgba(191, 163, 124, .2);
                border-radius: 16px;
                padding: 40px 36px;
                width: 100%;
                max-width: 420px
            }

            .an-auth-logo {
                font-family: 'Cormorant Garamond', serif;
                font-size: 2rem;
                font-weight: 600;
                letter-spacing: .3em;
                background: linear-gradient(90deg, #BFA37C, #FFEBAD, #C89B6D);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
                text-align: center;
                margin-bottom: 6px
            }

            .an-auth-sub {
                font-size: 10px;
                letter-spacing: .3em;
                text-transform: uppercase;
                color: #7c685b;
                text-align: center;
                margin-bottom: 32px
            }

            .an-auth-divider {
                height: 1px;
                background: rgba(191, 163, 124, .15);
                margin: 24px 0
            }

            .an-field {
                margin-bottom: 16px
            }

            .an-field label {
                display: block;
                font-size: 10px;
                font-weight: 600;
                letter-spacing: .15em;
                text-transform: uppercase;
                color: #BFA37C;
                margin-bottom: 6px
            }

            .an-field input {
                width: 100%;
                background: #261409;
                border: 1px solid rgba(191, 163, 124, .2);
                color: #f5ede6;
                border-radius: 8px;
                padding: 12px 14px;
                font-size: 14px;
                font-family: 'Poppins', sans-serif;
                outline: none;
                transition: border-color .2s
            }

            .an-field input:focus {
                border-color: #BFA37C
            }

            .an-btn-gold {
                width: 100%;
                background: linear-gradient(135deg, #BFA37C, #FFEBAD, #C89B6D);
                color: #1a0d06;
                font-weight: 700;
                font-size: 11px;
                letter-spacing: .18em;
                text-transform: uppercase;
                border: none;
                border-radius: 8px;
                padding: 14px;
                cursor: pointer;
                font-family: 'Poppins', sans-serif;
                margin-top: 8px;
                transition: opacity .2s
            }

            .an-btn-gold:hover {
                opacity: .9
            }

            .an-btn-ghost {
                width: 100%;
                background: none;
                border: 1px solid rgba(191, 163, 124, .25);
                color: #BFA37C;
                font-weight: 600;
                font-size: 11px;
                letter-spacing: .15em;
                text-transform: uppercase;
                border-radius: 8px;
                padding: 13px;
                cursor: pointer;
                font-family: 'Poppins', sans-serif;
                transition: all .2s;
                text-decoration: none;
                display: block;
                text-align: center
            }

            .an-btn-ghost:hover {
                background: rgba(191, 163, 124, .08);
                border-color: #BFA37C
            }

            .an-btn-google {
                width: 100%;
                background: #fff;
                color: #3c3c3c;
                font-weight: 600;
                font-size: 13px;
                border: none;
                border-radius: 8px;
                padding: 13px;
                cursor: pointer;
                font-family: 'Poppins', sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                transition: opacity .2s;
                text-decoration: none
            }

            .an-btn-google:hover {
                opacity: .92
            }

            .an-error {
                background: rgba(255, 80, 50, .08);
                border: 1px solid rgba(255, 80, 50, .2);
                color: #ff8a7a;
                border-radius: 8px;
                padding: 10px 14px;
                font-size: 12px;
                margin-bottom: 16px
            }

            .an-info {
                background: rgba(191, 163, 124, .07);
                border: 1px solid rgba(191, 163, 124, .2);
                color: #BFA37C;
                border-radius: 8px;
                padding: 12px 16px;
                font-size: 12px;
                line-height: 1.6;
                margin-bottom: 16px
            }

            .an-tabs {
                display: flex;
                gap: 0;
                margin-bottom: 28px;
                border: 1px solid rgba(191, 163, 124, .2);
                border-radius: 8px;
                overflow: hidden
            }

            .an-tab {
                flex: 1;
                padding: 10px;
                font-size: 11px;
                font-weight: 600;
                letter-spacing: .1em;
                text-transform: uppercase;
                background: none;
                border: none;
                color: #7c685b;
                cursor: pointer;
                font-family: 'Poppins', sans-serif;
                transition: all .2s
            }

            .an-tab.active {
                background: rgba(191, 163, 124, .12);
                color: #BFA37C
            }
        </style>
    </head>

    <body>
        <?php
}

function an_dashboard_html_close()
{
    echo '</body></html>';
}

/* ── Pantalla de login / registro ── */
function an_render_login_form(string $error = '')
{
    $errors = [
        'empty' => 'Completá todos los campos.',
        'email' => 'El email no es válido.',
        'exists' => 'El usuario o email ya existe. ¿Ya tenés cuenta? Iniciá sesión.',
        'wp' => 'Error al crear la cuenta. Contactá al administrador.',
        'nonce' => 'Error de seguridad. Intentá de nuevo.',
    ];
    $url_error = sanitize_text_field($_GET['error'] ?? '');
    $login_error = sanitize_text_field($_GET['login_error'] ?? '');
    $error_msg = $errors[$url_error] ?? ($login_error ? 'Usuario o contraseña incorrectos.' : $error);

    an_dashboard_html_open('Acceso');
    ?>
        <div class="an-auth-card">
            <div class="an-auth-logo">AN STUDIO</div>
            <div class="an-auth-sub">Dashboard · Acceso</div>

            <?php if ($error_msg): ?>
                <div class="an-error">
                    <?php echo esc_html($error_msg); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['registered'])): ?>
                <div class="an-info">✅ Cuenta creada. Tu solicitud está pendiente de aprobación. Te avisamos por email cuando
                    esté lista.</div>
            <?php endif; ?>

            <div class="an-tabs">
                <button class="an-tab active" id="tab-login" onclick="showTab('login')">Iniciar sesión</button>
                <button class="an-tab" id="tab-reg" onclick="showTab('reg')">Registrarme</button>
            </div>

            <!-- LOGIN -->
            <div id="panel-login">
                <?php
                // Botón Google OAuth si está disponible
                $google_login_url = an_get_google_login_url();
                if ($google_login_url): ?>
                    <a href="<?php echo esc_url($google_login_url); ?>" class="an-btn-google">
                        <svg width="18" height="18" viewBox="0 0 18 18">
                            <path fill="#4285F4"
                                d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" />
                            <path fill="#34A853"
                                d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" />
                            <path fill="#FBBC05"
                                d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" />
                            <path fill="#EA4335"
                                d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" />
                        </svg>
                        Continuar con Google
                    </a>
                    <div style="display:flex;align-items:center;gap:10px;margin:16px 0;">
                        <div style="flex:1;height:1px;background:rgba(191,163,124,.15)"></div><span
                            style="font-size:10px;color:#7c685b;letter-spacing:.1em">O</span>
                        <div style="flex:1;height:1px;background:rgba(191,163,124,.15)"></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(home_url('/admin/')); ?>">
                    <?php wp_nonce_field('an_dash_login', 'an_dash_login_nonce'); ?>
                    <input type="hidden" name="an_dash_login" value="1">
                    <div class="an-field">
                        <label>Usuario o Email</label>
                        <input type="text" name="log" autocomplete="username" required>
                    </div>
                    <div class="an-field">
                        <label>Contraseña</label>
                        <input type="password" name="pwd" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="an-btn-gold">Ingresar →</button>
                </form>
            </div>

            <!-- REGISTRO -->
            <div id="panel-reg" style="display:none;">
                <div class="an-info" style="margin-bottom:20px;">
                    📋 Completá el formulario. Tu cuenta quedará pendiente de aprobación hasta que el administrador te dé
                    acceso.
                </div>

                <?php $google_login_url = an_get_google_login_url();
                if ($google_login_url): ?>
                    <a href="<?php echo esc_url($google_login_url); ?>" class="an-btn-google" style="margin-bottom:16px;">
                        <svg width="18" height="18" viewBox="0 0 18 18">
                            <path fill="#4285F4"
                                d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" />
                            <path fill="#34A853"
                                d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" />
                            <path fill="#FBBC05"
                                d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" />
                            <path fill="#EA4335"
                                d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" />
                        </svg>
                        Registrarme con Google
                    </a>
                    <div style="display:flex;align-items:center;gap:10px;margin:0 0 16px;">
                        <div style="flex:1;height:1px;background:rgba(191,163,124,.15)"></div><span
                            style="font-size:10px;color:#7c685b;letter-spacing:.1em">O</span>
                        <div style="flex:1;height:1px;background:rgba(191,163,124,.15)"></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(home_url('/admin/login/')); ?>">
                    <?php wp_nonce_field('an_dashboard_register', 'an_register_nonce'); ?>
                    <div class="an-field">
                        <label>Nombre completo</label>
                        <input type="text" name="reg_name" required>
                    </div>
                    <div class="an-field">
                        <label>Email</label>
                        <input type="email" name="reg_email" required>
                    </div>
                    <div class="an-field">
                        <label>Usuario</label>
                        <input type="text" name="reg_username" required autocomplete="off">
                    </div>
                    <div class="an-field">
                        <label>Contraseña</label>
                        <input type="password" name="reg_password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="an-btn-gold">Solicitar acceso →</button>
                </form>
            </div>
        </div>
        <script>
            function showTab(tab) {
                document.getElementById('panel-login').style.display = tab === 'login' ? '' : 'none';
                document.getElementById('panel-reg').style.display = tab === 'reg' ? '' : 'none';
                document.getElementById('tab-login').className = 'an-tab' + (tab === 'login' ? ' active' : '');
                document.getElementById('tab-reg').className = 'an-tab' + (tab === 'reg' ? ' active' : '');
            }
    <?php if (isset($_GET['error'])): ?> showTab('reg');<?php endif; ?>
        </script>
        <?php
        an_dashboard_html_close();
}

/* ── Pantalla pendiente de aprobación ── */
function an_render_pending_page()
{
    $user = wp_get_current_user();
    an_dashboard_html_open('Cuenta pendiente');
    ?>
        <div class="an-auth-card" style="text-align:center;">
            <div class="an-auth-logo">AN STUDIO</div>
            <div class="an-auth-sub">Dashboard · Acceso</div>
            <div style="font-size:2.5rem;margin:20px 0;">⏳</div>
            <h2
                style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-style:italic;color:#FFEBAD;margin-bottom:12px;">
                Solicitud pendiente</h2>
            <p style="font-size:13px;color:#a79c95;line-height:1.7;margin-bottom:24px;">
                Hola
                <?php echo esc_html($user->display_name); ?>, tu cuenta está esperando aprobación.<br>
                Te avisaremos a <strong style="color:#BFA37C;">
                    <?php echo esc_html($user->user_email); ?>
                </strong> cuando esté lista.
            </p>
            <a href="<?php echo esc_url(wp_logout_url(home_url('/admin/'))); ?>" class="an-btn-ghost">
                Cerrar sesión
            </a>
        </div>
        <?php
        an_dashboard_html_close();
}

/* ── Formulario de solicitud de acceso (usuario WP ya logueado sin registro) ── */
function an_render_request_access_form()
{
    $user = wp_get_current_user();
    an_dashboard_html_open('Solicitar acceso');
    ?>
        <div class="an-auth-card">
            <div class="an-auth-logo">AN STUDIO</div>
            <div class="an-auth-sub">Dashboard · Solicitar acceso</div>
            <div class="an-info">
                Hola <strong>
                    <?php echo esc_html($user->display_name ?: $user->user_login); ?>
                </strong>, tu cuenta de WordPress no tiene acceso al dashboard de locales todavía.<br><br>
                Hacé clic para enviar tu solicitud al administrador.
            </div>
            <form method="post" action="<?php echo esc_url(home_url('/admin/login/')); ?>">
                <?php wp_nonce_field('an_dashboard_register', 'an_register_nonce'); ?>
                <input type="hidden" name="reg_name"
                    value="<?php echo esc_attr($user->display_name ?: $user->user_login); ?>">
                <input type="hidden" name="reg_email" value="<?php echo esc_attr($user->user_email); ?>">
                <input type="hidden" name="reg_username" value="<?php echo esc_attr($user->user_login); ?>_existing">
                <input type="hidden" name="reg_password" value="<?php echo wp_generate_password(24); ?>">
                <input type="hidden" name="an_existing_user" value="<?php echo $user->ID; ?>">
                <button type="submit" class="an-btn-gold">Solicitar acceso al dashboard →</button>
            </form>
            <div style="margin-top:16px;">
                <a href="<?php echo esc_url(wp_logout_url(home_url('/admin/'))); ?>" class="an-btn-ghost">
                    Cerrar sesión
                </a>
            </div>
        </div>
        <?php
        an_dashboard_html_close();
}


// ═══════════════════════════════════════════════════════════════════
// GOOGLE OAUTH — URL de login (compatible con Google Site Kit)
// ═══════════════════════════════════════════════════════════════════
function an_get_google_login_url(): string
{
    // Si tiene Google Site Kit o plugin de social login → usar su URL
    if (function_exists('googlelogin_enqueue_scripts')) {
        return ''; // Google Login for WordPress plugin
    }

    // Minilogin OAuth propio sin dependencias externas
    // Solo funciona si están definidas las constantes de GCal (mismo proyecto)
    if (!AN_GCAL_CLIENT_ID || !AN_GCAL_CLIENT_SECRET)
        return '';

    $redirect_uri = home_url('/admin/?google_callback=1');
    $params = [
        'client_id' => AN_GCAL_CLIENT_ID,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
        'state' => wp_create_nonce('an_google_oauth'),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}


// ═══════════════════════════════════════════════════════════════════
// GOOGLE OAUTH CALLBACK
// ═══════════════════════════════════════════════════════════════════
add_action('template_redirect', 'an_handle_google_callback', 5);
function an_handle_google_callback()
{
    if (!isset($_GET['google_callback']) || !isset($_GET['code']))
        return;
    if (!wp_verify_nonce($_GET['state'] ?? '', 'an_google_oauth')) {
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    $redirect_uri = home_url('/admin/?google_callback=1');

    // Intercambiar code por token
    $token_res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 15,
        'body' => [
            'code' => sanitize_text_field($_GET['code']),
            'client_id' => AN_GCAL_CLIENT_ID,
            'client_secret' => AN_GCAL_CLIENT_SECRET,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        ],
    ]);

    if (is_wp_error($token_res)) {
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    $token_data = json_decode(wp_remote_retrieve_body($token_res), true);
    $access_tok = $token_data['access_token'] ?? '';
    if (!$access_tok) {
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    // Obtener info del usuario de Google
    $userinfo_res = wp_remote_get('https://www.googleapis.com/oauth2/v3/userinfo', [
        'headers' => ['Authorization' => 'Bearer ' . $access_tok],
    ]);
    if (is_wp_error($userinfo_res)) {
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    $userinfo = json_decode(wp_remote_retrieve_body($userinfo_res), true);
    $email = sanitize_email($userinfo['email'] ?? '');
    $name = sanitize_text_field($userinfo['name'] ?? '');

    if (!$email) {
        wp_redirect(home_url('/admin/?login_error=1'));
        exit;
    }

    // Buscar o crear usuario WP por email
    $user = get_user_by('email', $email);
    if (!$user) {
        $username = sanitize_user(strtolower(explode('@', $email)[0]));
        if (username_exists($username))
            $username .= '_' . wp_rand(100, 999);
        $user_id = wp_create_user($username, wp_generate_password(24), $email);
        if (is_wp_error($user_id)) {
            wp_redirect(home_url('/admin/?login_error=1'));
            exit;
        }
        wp_update_user(['ID' => $user_id, 'display_name' => $name, 'role' => 'subscriber']);
        $user = get_user_by('id', $user_id);
    }

    // Crear registro en dashboard_users si no existe (pendiente)
    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}an_dashboard_users WHERE wp_user_id = %d",
        $user->ID
    ));
    if (!$existing) {
        $wpdb->insert(
            $wpdb->prefix . 'an_dashboard_users',
            ['wp_user_id' => $user->ID, 'location_ids' => '[]', 'role' => 'viewer', 'active' => 0],
            ['%d', '%s', '%s', '%d']
        );
        // Notificar admin
        wp_mail(
            AN_ADMIN_EMAIL,
            '🔔 Nueva solicitud de acceso (Google) — AN Studio',
            "El usuario {$name} ({$email}) solicitó acceso al dashboard via Google.\n\n"
            . "Para aprobar: " . admin_url('admin.php?page=an-studio-accesos'),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    wp_set_auth_cookie($user->ID, true);
    wp_redirect(home_url('/admin/'));
    exit;
}


// ═══════════════════════════════════════════════════════════════════
// WP ADMIN — GESTIÓN DE ACCESOS (aprobar/revocar/asignar sucursal)
// ═══════════════════════════════════════════════════════════════════
add_action('admin_menu', function () {
    add_submenu_page(
        'an-studio-reservas',
        'Accesos Dashboard',
        '🔑 Accesos',
        'manage_options',
        'an-studio-accesos',
        'an_admin_accesos_page'
    );
});

function an_admin_accesos_page()
{
    global $wpdb;

    // Aprobar usuario
    if (isset($_GET['an_approve']) && check_admin_referer('an_approve_' . intval($_GET['an_approve']))) {
        $uid = intval($_GET['an_approve']);
        $lids = sanitize_text_field($_POST['location_ids'] ?? '[]');
        $wpdb->update(
            $wpdb->prefix . 'an_dashboard_users',
            ['active' => 1, 'location_ids' => $lids],
            ['id' => $uid],
            ['%d', '%s'],
            ['%d']
        );
        // Notificar al usuario
        $du = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}an_dashboard_users WHERE id=%d", $uid));
        if ($du) {
            $wp_user = get_user_by('id', $du->wp_user_id);
            if ($wp_user) {
                wp_mail(
                    $wp_user->user_email,
                    '✅ Tu acceso al dashboard AN Studio fue aprobado',
                    "Hola {$wp_user->display_name},\n\nYa podés acceder al dashboard en:\n" . home_url('/admin/') . "\n\n¡Saludos!\nAN Studio",
                    ['Content-Type: text/plain; charset=UTF-8']
                );
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>Usuario aprobado.</p></div>';
    }

    // Revocar acceso
    if (isset($_GET['an_revoke']) && check_admin_referer('an_revoke_' . intval($_GET['an_revoke']))) {
        $wpdb->update(
            $wpdb->prefix . 'an_dashboard_users',
            ['active' => 0],
            ['id' => intval($_GET['an_revoke'])],
            ['%d'],
            ['%d']
        );
        echo '<div class="notice notice-success is-dismissible"><p>Acceso revocado.</p></div>';
    }

    // Eliminar
    if (isset($_GET['an_delete_du']) && check_admin_referer('an_delete_du_' . intval($_GET['an_delete_du']))) {
        $wpdb->delete($wpdb->prefix . 'an_dashboard_users', ['id' => intval($_GET['an_delete_du'])]);
        echo '<div class="notice notice-success is-dismissible"><p>Registro eliminado.</p></div>';
    }

    $users = $wpdb->get_results(
        "SELECT du.*, u.display_name, u.user_email, u.user_login
         FROM {$wpdb->prefix}an_dashboard_users du
         LEFT JOIN {$wpdb->users} u ON u.ID = du.wp_user_id
         ORDER BY du.active ASC, du.created_at DESC"
    );
    $locs = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}an_locations WHERE active=1 ORDER BY name ASC");
    $base = admin_url('admin.php?page=an-studio-accesos');
    ?>
        <div class="wrap" style="font-family:'Segoe UI',sans-serif;">
            <h1 style="font-size:20px;">🔑 Accesos al Dashboard de Locales</h1>
            <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">
                URL del dashboard: <code><?php echo esc_html(home_url('/admin/')); ?></code>
            </p>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Sucursales asignadas</th>
                        <th>Estado</th>
                        <th>Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#9ca3af;padding:20px;">Ningún usuario ha solicitado
                                acceso todavía.</td>
                        </tr>
                    <?php else:
                        foreach ($users as $du):
                            $loc_ids = json_decode($du->location_ids ?: '[]', true);
                            $loc_names = [];
                            foreach ($locs as $l) {
                                if (in_array($l->id, $loc_ids))
                                    $loc_names[] = $l->name;
                            }
                            $loc_label = $loc_names ? implode(', ', $loc_names) : '<em style="color:#9ca3af;">Sin asignar</em>';
                            $pill_style = $du->active ? 'background:#dcfce7;color:#15803d' : 'background:#fef9c3;color:#854d0e';
                            $pill_text = $du->active ? '✅ Activo' : '⏳ Pendiente';
                            $approve_url = wp_nonce_url($base . '&an_approve=' . $du->id, 'an_approve_' . $du->id);
                            $revoke_url = wp_nonce_url($base . '&an_revoke=' . $du->id, 'an_revoke_' . $du->id);
                            $delete_url = wp_nonce_url($base . '&an_delete_du=' . $du->id, 'an_delete_du_' . $du->id);
                            ?>
                            <tr>
                                <td style="font-weight:600;">
                                    <?php echo esc_html($du->display_name ?: $du->user_login); ?>
                                </td>
                                <td style="color:#6b7280;">
                                    <?php echo esc_html($du->user_email); ?>
                                </td>
                                <td>
                                    <?php echo $loc_label; ?>
                                </td>
                                <td><span
                                        style="<?php echo $pill_style; ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;">
                                        <?php echo $pill_text; ?>
                                    </span></td>
                                <td style="color:#9ca3af;font-size:11px;">
                                    <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($du->created_at))); ?>
                                </td>
                                <td>
                                    <?php if (!$du->active): ?>
                                        <form method="post" action="<?php echo esc_url($approve_url); ?>"
                                            style="display:inline-flex;align-items:center;gap:6px;">
                                            <select name="location_ids"
                                                style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:12px;">
                                                <option value="[]">Sin sucursal (admin ve todo)</option>
                                                <?php foreach ($locs as $l): ?>
                                                    <option value="[<?php echo $l->id; ?>]">
                                                        <?php echo esc_html($l->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit"
                                                style="background:#22c55e;color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">Aprobar</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url($revoke_url); ?>"
                                            style="background:#fef9c3;color:#854d0e;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;margin-right:4px;">Revocar</a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('¿Eliminar este acceso?')"
                                        style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">✕</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
}

// Flush rewrite rules al activar (solo una vez)
add_action('init', function () {
    if (get_option('an_dashboard_rewrite_flushed') !== '1') {
        flush_rewrite_rules();
        update_option('an_dashboard_rewrite_flushed', '1');
    }
});