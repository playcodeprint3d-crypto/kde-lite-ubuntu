<?php
/**
 * Plugin Name: Play Code - Cloud Codespaces & Linux Desktop
 * Description: Conector oficial de GitHub Codespaces para MasterStudy LMS. Permite a los alumnos conectar su cuenta de GitHub, encender su máquina virtual y acceder a su escritorio Linux KDE en la nube.
 * Version: 1.2.2
 * Author: Play Code / Antigravity
 * Text Domain: playcode-codespaces
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLAYCODE_CODESPACES_VERSION', '1.2.2' );
define( 'PLAYCODE_CODESPACES_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLAYCODE_CODESPACES_URL', plugin_dir_url( __FILE__ ) );

/* ==========================================================================
   0. HELPER: Obtener URL base del panel de usuario (dinámico, multi-idioma)
   ========================================================================== */

/**
 * Returns the base URL of the MasterStudy LMS student account page,
 * respecting whatever slug the site uses (e.g. /cuenta-del-usuario/ or /user-account/).
 *
 * @return string Trailing-slashed URL.
 */
function playcode_cs_get_account_base_url() {
	if ( class_exists( 'STM_LMS_User' ) && method_exists( 'STM_LMS_User', 'login_page_url' ) ) {
		return trailingslashit( STM_LMS_User::login_page_url() );
	}
	if ( function_exists( 'stm_lms_get_account_url' ) ) {
		return trailingslashit( stm_lms_get_account_url() );
	}
	return trailingslashit( home_url( '/cuenta-del-usuario/' ) );
}

/* ==========================================================================
   1. MASTERSTUDY LMS INTEGRATION (ROUTES, MENU & TEMPLATES)
   ========================================================================== */

// 1.1. Register custom routes in MasterStudy LMS
add_filter( 'stm_lms_custom_routes_config', 'playcode_cs_register_custom_routes', 999 );
function playcode_cs_register_custom_routes( $routes ) {
	if ( isset( $routes['user_url']['sub_pages'] ) ) {
		$routes['user_url']['sub_pages']['codespace_custom'] = array(
			'template'  => 'account/codespace-custom',
			'protected' => true,
			'url'       => 'codespace',
		);
		$routes['user_url']['sub_pages']['build_custom'] = array(
			'template'  => 'account/build-custom',
			'protected' => true,
			'url'       => 'build',
		);
	}
	return $routes;
}

// 1.2. Register Section Labels in Student Dashboard
add_filter( 'masterstudy_account_menu_section_labels', 'playcode_cs_section_labels', 9999 );
function playcode_cs_section_labels( $labels ) {
	if ( ! is_array( $labels ) ) {
		$labels = array();
	}
	$labels['codespace'] = 'Laboratorio';
	unset( $labels['build'] );
	return $labels;
}

// 1.2b. Reorder Menu Sections: move 'Cuenta y ajustes' (account) strictly to the very bottom of the sidebar
add_filter( 'masterstudy_account_menu_section_order', 'playcode_cs_section_order', 9999 );
function playcode_cs_section_order( $order ) {
	if ( ! is_array( $order ) ) {
		$order = array( 'main', 'communication', 'progress', 'finance' );
	}

	// Registered sections that should precede 'account'
	$known_sections = array( 'main', 'communication', 'codespace', 'recursos', 'progress', 'finance' );

	// Merge existing order with known sections, preserving unique keys
	$merged = array_unique( array_merge( $order, $known_sections ) );

	// Remove 'account' from wherever it was
	$merged = array_values( array_diff( $merged, array( 'account' ) ) );

	// Append 'account' (Cuenta y ajustes) at the absolute bottom
	$merged[] = 'account';

	return $merged;
}

// 1.3. Add Sidebar Menu Items in Student Dashboard
add_filter( 'stm_lms_menu_items', 'playcode_cs_add_menu_items', 9999 );
add_filter( 'stm_lms_sorted_menu', 'playcode_cs_add_menu_items', 9999 );
function playcode_cs_add_menu_items( $items ) {
	if ( empty( $items ) || ! is_array( $items ) ) {
		return $items;
	}

	$user_url = playcode_cs_get_account_base_url();

	$has_codespace = false;
	$has_build     = false;

	foreach ( $items as &$item ) {
		if ( isset( $item['slug'] ) && 'codespace' === $item['slug'] ) {
			$item['menu_title'] = 'Máquina Virtual Linux';
			$item['title']      = 'Máquina Virtual Linux';
			$item['section']    = 'codespace';
			$item['order']      = 10;
			$has_codespace      = true;
		}
		if ( isset( $item['slug'] ) && 'build' === $item['slug'] ) {
			$item['menu_title'] = 'Build (Antigravity AI)';
			$item['title']      = 'Build (Antigravity AI)';
			$item['section']    = 'codespace';
			$item['order']      = 15;
			$has_build          = true;
		}
	}
	unset( $item );

	// 1. Máquina Virtual Linux item
	if ( ! $has_codespace ) {
		$items[] = array(
			'id'           => 'codespace_custom',
			'slug'         => 'codespace',
			'menu_title'   => 'Máquina Virtual Linux',
			'title'        => 'Máquina Virtual Linux',
			'menu_icon'    => 'fa-laptop-code',
			'icon'         => 'fa-laptop-code',
			'badge'        => '',
			'menu_url'     => $user_url . 'codespace/',
			'menu_place'   => 'learning',
			'section'      => 'codespace',
			'order'        => 10,
			'user_profile' => true,
		);
	}

	// 2. Build / Antigravity AI item (placed right below in the same 'Laboratorio' section)
	if ( ! $has_build ) {
		$items[] = array(
			'id'           => 'build_custom',
			'slug'         => 'build',
			'menu_title'   => 'Build (Antigravity AI)',
			'title'        => 'Build (Antigravity AI)',
			'menu_icon'    => 'fa-rocket',
			'icon'         => 'fa-rocket',
			'badge'        => 'AI',
			'menu_url'     => $user_url . 'build/',
			'menu_place'   => 'learning',
			'section'      => 'codespace',
			'order'        => 15,
			'user_profile' => true,
		);
	}

	return $items;
}

// 1.4. Override Template Files for custom tabs
add_filter( 'stm_lms_template_file', 'playcode_cs_override_template_file', 9999, 2 );
function playcode_cs_override_template_file( $path, $template_name ) {
	if ( false !== strpos( $template_name, 'codespace-custom' ) ||
	     false !== strpos( $template_name, 'build-custom' ) ) {
		return PLAYCODE_CODESPACES_PATH . 'templates_override';
	}
	return $path;
}

// 1.5. Custom SVG Icon Styling for sidebar icons
add_action( 'wp_head', 'playcode_cs_icon_styles', 999 );
function playcode_cs_icon_styles() {
	?>
	<style type="text/css">
		/* --- fa-laptop-code (Escritorio Linux) --- */
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item i.fa-laptop-code {
			font-size: 0 !important;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23001F4A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect width='18' height='12' x='3' y='4' rx='2'/%3E%3Cline x1='2' x2='22' y1='20' y2='20'/%3E%3Cpolyline points='8 9 10 11 8 13'/%3E%3Cline x1='12' x2='15' y1='13' y2='13'/%3E%3C/svg%3E") !important;
			width: 18px !important;
			height: 18px !important;
			display: inline-block !important;
			background-size: contain !important;
			background-repeat: no-repeat !important;
			background-position: center !important;
			vertical-align: middle !important;
		}
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item:hover i.fa-laptop-code,
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item_active i.fa-laptop-code,
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item.masterstudy-account-menu__list-item_active i.fa-laptop-code {
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect width='18' height='12' x='3' y='4' rx='2'/%3E%3Cline x1='2' x2='22' y1='20' y2='20'/%3E%3Cpolyline points='8 9 10 11 8 13'/%3E%3Cline x1='12' x2='15' y1='13' y2='13'/%3E%3C/svg%3E") !important;
		}

		/* --- fa-rocket (Build / Antigravity AI) --- */
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item i.fa-rocket {
			font-size: 0 !important;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23001F4A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z'/%3E%3Cpath d='m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z'/%3E%3Cpath d='M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0'/%3E%3Cpath d='M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5'/%3E%3C/svg%3E") !important;
			width: 18px !important;
			height: 18px !important;
			display: inline-block !important;
			background-size: contain !important;
			background-repeat: no-repeat !important;
			background-position: center !important;
			vertical-align: middle !important;
		}
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item:hover i.fa-rocket,
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item_active i.fa-rocket,
		.masterstudy-account-menu__list a.masterstudy-account-menu__list-item.masterstudy-account-menu__list-item_active i.fa-rocket {
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z'/%3E%3Cpath d='m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z'/%3E%3Cpath d='M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0'/%3E%3Cpath d='M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5'/%3E%3C/svg%3E") !important;
		}
	</style>
	<?php
}

// 1.6. Enqueue Assets (CSS & JS)
add_action( 'wp_enqueue_scripts', 'playcode_cs_enqueue_scripts' );
function playcode_cs_enqueue_scripts() {
	wp_register_style(
		'playcode-cs-dashboard-css',
		PLAYCODE_CODESPACES_URL . 'assets/codespace-dashboard.css',
		array(),
		PLAYCODE_CODESPACES_VERSION
	);

	wp_register_script(
		'playcode-cs-dashboard-js',
		PLAYCODE_CODESPACES_URL . 'assets/codespace-dashboard.js',
		array(),
		PLAYCODE_CODESPACES_VERSION,
		true
	);

	wp_localize_script( 'playcode-cs-dashboard-js', 'PlayCodeCS', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'playcode_cs_nonce' ),
	) );
}

/* ==========================================================================
   2. GITHUB OAUTH 2.0 FLOW & CALLBACK
   ========================================================================== */

add_action( 'rest_api_init', 'playcode_cs_register_rest_routes' );
function playcode_cs_register_rest_routes() {
	register_rest_route( 'playcode-codespaces/v1', '/oauth/callback', array(
		'methods'             => 'GET',
		'callback'            => 'playcode_cs_rest_oauth_callback',
		'permission_callback' => '__return_true',
	) );
}

function playcode_cs_rest_oauth_callback( $request ) {
	$code = $request->get_param( 'code' );
	if ( empty( $code ) ) {
		wp_die( 'Código de autorización no recibido de GitHub.' );
	}
	wp_redirect( home_url( '/?playcode_gh_callback=1&code=' . urlencode( $code ) ) );
	exit;
}

add_action( 'init', 'playcode_cs_handle_oauth_callback' );
function playcode_cs_handle_oauth_callback() {
	if ( ! isset( $_GET['playcode_gh_callback'] ) || ! isset( $_GET['code'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		wp_die( 'Debes iniciar sesión en Play Code para conectar tu cuenta de GitHub.' );
	}

	$code          = sanitize_text_field( $_GET['code'] );
	$client_id     = get_option( 'playcode_cs_client_id', '' );
	$client_secret = get_option( 'playcode_cs_client_secret', '' );

	if ( empty( $client_id ) || empty( $client_secret ) ) {
		wp_die( 'Configuración de GitHub OAuth incompleta en el servidor.' );
	}

	// Exchange authorization code for access token
	$token_url = 'https://github.com/login/oauth/access_token';
	$response  = wp_remote_post( $token_url, array(
		'headers' => array(
			'Accept'     => 'application/json',
			'User-Agent' => 'PlayCode-LMS-App',
		),
		'body'    => array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'code'          => $code,
		),
		'timeout' => 20,
	) );

	if ( is_wp_error( $response ) ) {
		wp_die( 'Error al comunicar con GitHub: ' . esc_html( $response->get_error_message() ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['access_token'] ) ) {
		$error_msg = isset( $body['error_description'] ) ? $body['error_description'] : 'No se pudo obtener el token de acceso.';
		wp_die( 'Error de autenticación con GitHub: ' . esc_html( $error_msg ) );
	}

	$access_token = sanitize_text_field( $body['access_token'] );
	$user_id      = get_current_user_id();

	// Save token
	update_user_meta( $user_id, 'playcode_github_token', $access_token );

	// Fetch user details from GitHub
	$user_profile = playcode_cs_api_request( '/user', $access_token );
	if ( ! empty( $user_profile['login'] ) ) {
		update_user_meta( $user_id, 'playcode_github_user', $user_profile );
	}

	// Redirect back to the codespace tab (dynamic URL)
	$redirect_url = playcode_cs_get_account_base_url() . 'codespace/?connected=1';
	wp_redirect( esc_url_raw( $redirect_url ) );
	exit;
}

/* ==========================================================================
   3. GITHUB API HELPER FUNCTIONS
   ========================================================================== */

function playcode_cs_api_request( $endpoint, $token, $method = 'GET', $body = null ) {
	$url  = 'https://api.github.com/' . ltrim( $endpoint, '/' );
	$args = array(
		'method'  => $method,
		'headers' => array(
			'Authorization'        => 'Bearer ' . $token,
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'PlayCode-LMS-App',
			'X-GitHub-Api-Version' => '2022-11-28',
		),
		'timeout' => 20,
	);

	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return array( 'error' => $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$raw_body    = wp_remote_retrieve_body( $response );
	$data        = json_decode( $raw_body, true );

	if ( $status_code >= 400 ) {
		$msg = isset( $data['message'] ) ? $data['message'] : 'Error en la petición a GitHub API';
		return array( 'error' => $msg, 'status_code' => $status_code );
	}

	return $data;
}

/* ==========================================================================
   4. AJAX HANDLERS
   ========================================================================== */

// 4.1. Get Codespace Status
add_action( 'wp_ajax_playcode_cs_get_status', 'playcode_cs_ajax_get_status' );
function playcode_cs_ajax_get_status() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Usuario no autenticado' );
	}

	$token = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_error( 'Cuenta de GitHub no conectada' );
	}

	// Fetch GitHub user
	$user_data = get_user_meta( $user_id, 'playcode_github_user', true );
	if ( empty( $user_data ) ) {
		$user_data = playcode_cs_api_request( '/user', $token );
		if ( ! empty( $user_data['login'] ) ) {
			update_user_meta( $user_id, 'playcode_github_user', $user_data );
		}
	}

	// Fetch list of user's codespaces
	$cs_response = playcode_cs_api_request( '/user/codespaces', $token );
	if ( isset( $cs_response['error'] ) ) {
		wp_send_json_error( $cs_response['error'] );
	}

	$codespaces   = isset( $cs_response['codespaces'] ) ? $cs_response['codespaces'] : array();
	$default_repo = get_option( 'playcode_cs_default_repo', 'portadordelsello-stack/linux-kde-lite' );

	$target_codespace = null;

	// Find the codespace matching our repository
	foreach ( $codespaces as $cs ) {
		if ( isset( $cs['repository']['full_name'] ) &&
		     strtolower( $cs['repository']['full_name'] ) === strtolower( $default_repo ) ) {
			$target_codespace = $cs;
			break;
		}
	}

	// If not found by full name, check by partial name
	if ( ! $target_codespace && ! empty( $codespaces ) ) {
		foreach ( $codespaces as $cs ) {
			if ( isset( $cs['repository']['name'] ) &&
			     false !== strpos( strtolower( $cs['repository']['name'] ), 'linux-kde' ) ) {
				$target_codespace = $cs;
				break;
			}
		}
	}

	wp_send_json_success( array(
		'user'      => $user_data,
		'codespace' => $target_codespace,
	) );
}

// 4.2. Create Codespace via API
add_action( 'wp_ajax_playcode_cs_create', 'playcode_cs_ajax_create' );
function playcode_cs_ajax_create() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_error( 'No autorizado' );
	}

	$default_repo = get_option( 'playcode_cs_default_repo', 'portadordelsello-stack/linux-kde-lite' );
	$repo_info    = playcode_cs_api_request( "/repos/{$default_repo}", $token );

	if ( empty( $repo_info['id'] ) ) {
		wp_send_json_error( 'No se pudo obtener la información del repositorio en GitHub.' );
	}

	$branch     = ! empty( $repo_info['default_branch'] ) ? $repo_info['default_branch'] : 'main';
	$create_res = playcode_cs_api_request( '/user/codespaces', $token, 'POST', array(
		'repository_id' => intval( $repo_info['id'] ),
		'ref'           => $branch,
	) );

	if ( isset( $create_res['error'] ) ) {
		wp_send_json_error( $create_res['error'] );
	}

	wp_send_json_success( $create_res );
}

// 4.3. Start Codespace
add_action( 'wp_ajax_playcode_cs_start', 'playcode_cs_ajax_start' );
function playcode_cs_ajax_start() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

	if ( ! $user_id || empty( $name ) ) {
		wp_send_json_error( 'Datos inválidos' );
	}

	$token = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_error( 'No autorizado' );
	}

	$response = playcode_cs_api_request( "/user/codespaces/{$name}/start", $token, 'POST' );
	if ( isset( $response['error'] ) ) {
		wp_send_json_error( $response['error'] );
	}

	wp_send_json_success( $response );
}

// 4.4. Stop Codespace
add_action( 'wp_ajax_playcode_cs_stop', 'playcode_cs_ajax_stop' );
function playcode_cs_ajax_stop() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

	if ( ! $user_id || empty( $name ) ) {
		wp_send_json_error( 'Datos inválidos' );
	}

	$token = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_error( 'No autorizado' );
	}

	$response = playcode_cs_api_request( "/user/codespaces/{$name}/stop", $token, 'POST' );
	if ( isset( $response['error'] ) ) {
		wp_send_json_error( $response['error'] );
	}

	wp_send_json_success( $response );
}

// 4.5. Save Personal Access Token manually
add_action( 'wp_ajax_playcode_cs_save_token', 'playcode_cs_ajax_save_token' );
function playcode_cs_ajax_save_token() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';

	if ( ! $user_id || empty( $token ) ) {
		wp_send_json_error( 'Token requerido' );
	}

	// Verify token by querying /user
	$user_profile = playcode_cs_api_request( '/user', $token );
	if ( isset( $user_profile['error'] ) || empty( $user_profile['login'] ) ) {
		wp_send_json_error( 'El token es inválido o no tiene permisos de lectura de usuario.' );
	}

	update_user_meta( $user_id, 'playcode_github_token', $token );
	update_user_meta( $user_id, 'playcode_github_user', $user_profile );

	wp_send_json_success( array( 'login' => $user_profile['login'] ) );
}

// 4.6. Disconnect GitHub Account
add_action( 'wp_ajax_playcode_cs_disconnect', 'playcode_cs_ajax_disconnect' );
function playcode_cs_ajax_disconnect() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id ) {
		delete_user_meta( $user_id, 'playcode_github_token' );
		delete_user_meta( $user_id, 'playcode_github_user' );
	}

	wp_send_json_success();
}

/* ==========================================================================
   5. DASHBOARD RENDERER — "MI ESCRITORIO LINUX" TAB
   ========================================================================== */

add_shortcode( 'playcode_codespace', 'playcode_codespaces_render_dashboard' );

function playcode_codespaces_render_dashboard() {
	if ( ! is_user_logged_in() ) {
		return '<div class="playcode-cs-card" style="text-align:center; color:#EF4444; font-weight:800;">Debes iniciar sesión para acceder a tu escritorio en la nube.</div>';
	}

	wp_enqueue_style( 'playcode-cs-dashboard-css' );
	wp_enqueue_script( 'playcode-cs-dashboard-js' );

	$user_id      = get_current_user_id();
	$token        = get_user_meta( $user_id, 'playcode_github_token', true );
	$gh_user      = get_user_meta( $user_id, 'playcode_github_user', true );
	$is_connected = ! empty( $token );

	$client_id    = get_option( 'playcode_cs_client_id', '' );
	$default_repo = get_option( 'playcode_cs_default_repo', 'portadordelsello-stack/linux-kde-lite' );
	$create_url   = "https://codespaces.new/{$default_repo}";

	// GitHub OAuth URL
	$oauth_url = '#';
	if ( ! empty( $client_id ) ) {
		$oauth_url = add_query_arg( array(
			'client_id' => $client_id,
			'scope'     => 'codespace,read:user',
			'state'     => wp_create_nonce( 'playcode_gh_oauth' ),
		), 'https://github.com/login/oauth/authorize' );
	}

	ob_start();
	?>
	<div class="playcode-cs-wrapper" id="playcode-cs-app" data-connected="<?php echo $is_connected ? '1' : '0'; ?>">

		<!-- Header Card -->
		<div class="playcode-cs-card">
			<div class="playcode-cs-header">
				<h2 class="playcode-cs-title">
					🖥️ Máquina Virtual Linux en la Nube
				</h2>
				<span id="playcode-cs-state-badge" class="playcode-cs-badge <?php echo $is_connected ? 'badge-blue' : 'badge-gray'; ?>">
					<?php echo $is_connected ? '<span class="playcode-cs-dot dot-gray"></span> Verificando...' : 'Desconectado'; ?>
				</span>
			</div>

			<!-- Error Alert -->
			<div id="playcode-cs-error-alert" class="playcode-cs-notice" style="background:#FEE2E2; color:#991B1B; border-color:#EF4444; display:none;"></div>

			<!-- Transient Loading / Progress Indicator -->
			<div id="playcode-cs-loading-bar" class="playcode-cs-notice" style="display:none; background:#EFF6FF; color:#1E40AF; border-color:#3B82F6;">
				<span class="playcode-spinner"></span>
				<span class="playcode-cs-loading-text">Cargando...</span>
			</div>

			<!-- Transient Status Alert for Starting -->
			<div id="playcode-cs-transient-alert" class="playcode-cs-notice" style="display:none;"></div>

			<?php if ( ! $is_connected ) : ?>
				<!-- STATE 1: NOT CONNECTED -->
				<div style="padding: 10px 0;">
					<p style="font-size: 15px; font-weight: 600; line-height: 1.6; margin-bottom: 20px;">
						Para acceder a tu propio sistema operativo Linux (KDE Plasma) con Google Chrome y Antigravity IDE, primero debes conectar tu cuenta de GitHub.
					</p>

					<div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center; margin-bottom: 25px;">
						<?php if ( ! empty( $client_id ) ) : ?>
							<a href="<?php echo esc_url( $oauth_url ); ?>" class="playcode-btn playcode-btn-dark">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
								Conectar con GitHub Oficial
							</a>
						<?php endif; ?>

						<button type="button" id="playcode-cs-toggle-token" class="playcode-btn playcode-btn-outline">
							🔑 Conectar mediante Token (PAT)
						</button>
					</div>

					<!-- Manual Token Form (Hidden by default unless toggled) -->
					<div id="playcode-cs-manual-token-panel" style="<?php echo empty( $client_id ) ? 'display:block;' : 'display:none;'; ?> background:#F8FAFC; border:1.5px solid #001F4A; padding:20px; margin-top:15px;">
						<h4 style="margin:0 0 10px 0; font-size:14px; font-weight:800;">Conexión mediante Personal Access Token:</h4>
						<p style="font-size:12px; color:#64748B; margin:0 0 12px 0;">
							Ingresa un token de GitHub con permiso <code>codespace</code> y <code>read:user</code> generado en <a href="https://github.com/settings/tokens" target="_blank" style="color:#00A3FF; font-weight:800;">GitHub Settings &gt; Developer Settings</a>.
						</p>
						<form id="playcode-cs-token-form" style="display:flex; gap:10px; flex-wrap:wrap;">
							<input type="password" id="playcode-cs-token-input" class="playcode-cs-input" style="flex:1; min-width:250px;" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" required />
							<button type="submit" class="playcode-btn playcode-btn-primary">Validar y Conectar</button>
						</form>
					</div>
				</div>

			<?php else : ?>
				<!-- STATE 2: CONNECTED -->

				<!-- User Bar -->
				<div class="playcode-cs-user-bar">
					<div class="playcode-cs-user-info">
						<img id="playcode-cs-user-avatar" class="playcode-cs-avatar" src="<?php echo ! empty( $gh_user['avatar_url'] ) ? esc_url( $gh_user['avatar_url'] ) : 'https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png'; ?>" alt="Avatar" />
						<div>
							<h4 class="playcode-cs-user-name"><?php echo ! empty( $gh_user['name'] ) ? esc_html( $gh_user['name'] ) : 'Usuario GitHub'; ?></h4>
							<span id="playcode-cs-user-login" class="playcode-cs-user-login">@<?php echo ! empty( $gh_user['login'] ) ? esc_html( $gh_user['login'] ) : 'conectado'; ?></span>
						</div>
					</div>
					<div style="display:flex; gap:10px; align-items:center;">
						<button type="button" id="playcode-cs-btn-refresh" class="playcode-btn playcode-btn-outline playcode-btn-sm" title="Actualizar estado">
							🔄 Actualizar
						</button>
						<button type="button" id="playcode-cs-btn-disconnect" class="playcode-btn playcode-btn-danger playcode-btn-sm">
							Desconectar
						</button>
					</div>
				</div>

				<!-- Case A: No Codespace Found -->
				<div id="playcode-cs-no-codespace" style="display:none; text-align:center; padding:30px 10px;">
					<h3 style="font-size:18px; font-weight:800; margin-bottom:10px;">Aún no tienes una máquina virtual creada</h3>
					<p style="font-size:14px; color:#64748B; max-width:550px; margin:0 auto 20px auto;">
						Crea tu propia máquina virtual Linux con KDE Plasma en GitHub Codespaces vinculada a este curso:
					</p>
					<div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
						<button type="button" id="playcode-cs-btn-create" class="playcode-btn playcode-btn-primary" style="font-size:15px;">
							🚀 Crear mi Máquina Virtual Linux (1 Clic)
						</button>
						<a href="<?php echo esc_url( $create_url ); ?>" target="_blank" class="playcode-btn playcode-btn-outline" style="font-size:15px;">
							↗️ Abrir en GitHub
						</a>
					</div>
				</div>

				<!-- Case B: Codespace Details & Controls -->
				<div id="playcode-cs-details" style="display:none;">
					<!-- Specs Grid -->
					<div class="playcode-cs-grid">
						<div class="playcode-cs-grid-item">
							<span class="playcode-cs-grid-label">Nombre del Entorno</span>
							<h4 id="playcode-cs-name" class="playcode-cs-grid-value">-</h4>
						</div>
						<div class="playcode-cs-grid-item">
							<span class="playcode-cs-grid-label">Recursos Asignados</span>
							<h4 id="playcode-cs-machine" class="playcode-cs-grid-value">-</h4>
						</div>
						<div class="playcode-cs-grid-item">
							<span class="playcode-cs-grid-label">Última Sesión</span>
							<h4 id="playcode-cs-last-used" class="playcode-cs-grid-value">-</h4>
						</div>
					</div>

					<!-- Actions Buttons -->
					<div class="playcode-cs-actions">
						<!-- Start Button -->
						<button type="button" id="playcode-cs-btn-start" class="playcode-btn playcode-btn-primary" style="display:none;">
							⚡ Encender Máquina Virtual
						</button>

						<!-- Open Linux Desktop Button (Available) -->
						<a href="#" id="playcode-cs-btn-open-desktop" target="_blank" class="playcode-btn playcode-btn-success" style="display:none;">
							🌐 Abrir Máquina Virtual Linux (KDE)
						</a>

						<!-- Open VS Code Web -->
						<a href="#" id="playcode-cs-btn-open-vscode" target="_blank" class="playcode-btn playcode-btn-dark">
							📁 Abrir en VS Code Web
						</a>

						<!-- Stop Button (To conserve hours) -->
						<button type="button" id="playcode-cs-btn-stop" class="playcode-btn playcode-btn-outline" style="display:none;">
							🛑 Apagar Máquina Virtual
						</button>
					</div>

					<!-- Tip Box -->
					<div class="playcode-cs-notice">
						<span>💡</span>
						<div>
							<strong>Tip de acceso:</strong> La primera vez que abras tu máquina en una sesión, GitHub mostrará una pantalla de seguridad con el botón verde <strong>"Continue"</strong>. Haz clic en <em>Continue</em> para ingresar a tu escritorio Linux. Recuerda presionar <strong>"Apagar Máquina Virtual"</strong> al terminar tu clase para ahorrar tus horas de GitHub.
						</div>
					</div>
				</div>

			<?php endif; ?>

		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* ==========================================================================
   5.2. DASHBOARD RENDERER — "BUILD (ANTIGRAVITY 2.0)" TAB
   ========================================================================== */

add_shortcode( 'playcode_build', 'playcode_codespaces_render_build_dashboard' );

function playcode_codespaces_render_build_dashboard() {
	if ( ! is_user_logged_in() ) {
		return '<div class="playcode-cs-card" style="text-align:center; color:#EF4444; font-weight:800;">Debes iniciar sesión para acceder al entorno de Build (Antigravity AI).</div>';
	}

	wp_enqueue_style( 'playcode-cs-dashboard-css' );
	wp_enqueue_script( 'playcode-cs-dashboard-js' );

	$user_id      = get_current_user_id();
	$token        = get_user_meta( $user_id, 'playcode_github_token', true );
	$gh_user      = get_user_meta( $user_id, 'playcode_github_user', true );
	$is_connected = ! empty( $token );

	$client_id    = get_option( 'playcode_cs_client_id', '' );
	$default_repo = get_option( 'playcode_cs_default_repo', 'portadordelsello-stack/linux-kde-lite' );
	$create_url   = "https://codespaces.new/{$default_repo}";
	$codespace_url = playcode_cs_get_account_base_url() . 'codespace/';

	$oauth_url = '#';
	if ( ! empty( $client_id ) ) {
		$oauth_url = add_query_arg( array(
			'client_id' => $client_id,
			'scope'     => 'codespace,read:user',
			'state'     => wp_create_nonce( 'playcode_gh_oauth' ),
		), 'https://github.com/login/oauth/authorize' );
	}

	ob_start();
	?>
	<div class="playcode-cs-wrapper" id="playcode-cs-build-app" data-view="build" data-connected="<?php echo $is_connected ? '1' : '0'; ?>">

		<!-- Header Card -->
		<div class="playcode-cs-card">
			<div class="playcode-cs-header">
				<div>
					<h2 class="playcode-cs-title">
						🚀 Build — Antigravity 2.0 Web Client
					</h2>
					<p style="margin: 5px 0 0 0; font-size: 13px; color: #64748B; font-weight: 600;">
						Entorno oficial de Vibecoding y Asistencia IA en la Nube con Gemini (Puerto 3000)
					</p>
				</div>
				<span id="playcode-cs-build-state-badge" class="playcode-cs-badge <?php echo $is_connected ? 'badge-blue' : 'badge-gray'; ?>">
					<?php echo $is_connected ? '<span class="playcode-cs-dot dot-gray"></span> Verificando...' : 'Desconectado'; ?>
				</span>
			</div>

			<!-- Error Alert -->
			<div id="playcode-cs-build-error-alert" class="playcode-cs-notice" style="background:#FEE2E2; color:#991B1B; border-color:#EF4444; display:none;"></div>

			<!-- Transient Loading / Progress Indicator -->
			<div id="playcode-cs-build-loading-bar" class="playcode-cs-notice" style="display:none; background:#EFF6FF; color:#1E40AF; border-color:#3B82F6;">
				<span class="playcode-spinner"></span>
				<span class="playcode-cs-loading-text">Cargando entorno de Build...</span>
			</div>

			<!-- Transient Status Alert -->
			<div id="playcode-cs-build-transient-alert" class="playcode-cs-notice" style="display:none;"></div>

			<?php if ( ! $is_connected ) : ?>
				<!-- STATE 1: NOT CONNECTED -->
				<div style="padding: 10px 0;">
					<p style="font-size: 15px; font-weight: 600; line-height: 1.6; margin-bottom: 20px;">
						Para utilizar el cliente web de Antigravity 2.0 y programar con asistencia de IA en tu propia máquina virtual de GitHub, primero debes conectar tu cuenta.
					</p>

					<div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center; margin-bottom: 25px;">
						<?php if ( ! empty( $client_id ) ) : ?>
							<a href="<?php echo esc_url( $oauth_url ); ?>" class="playcode-btn playcode-btn-dark">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
								Conectar con GitHub Oficial
							</a>
						<?php endif; ?>

						<button type="button" id="playcode-cs-build-toggle-token" class="playcode-btn playcode-btn-outline">
							🔑 Conectar mediante Token (PAT)
						</button>
					</div>

					<div id="playcode-cs-build-manual-token-panel" style="<?php echo empty( $client_id ) ? 'display:block;' : 'display:none;'; ?> background:#F8FAFC; border:1.5px solid #001F4A; padding:20px; margin-top:15px;">
						<h4 style="margin:0 0 10px 0; font-size:14px; font-weight:800;">Conexión mediante Personal Access Token:</h4>
						<form id="playcode-cs-build-token-form" style="display:flex; gap:10px; flex-wrap:wrap;">
							<input type="password" id="playcode-cs-build-token-input" class="playcode-cs-input" style="flex:1; min-width:250px;" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" required />
							<button type="submit" class="playcode-btn playcode-btn-primary">Validar y Conectar</button>
						</form>
					</div>
				</div>

			<?php else : ?>
				<!-- STATE 2: CONNECTED -->
				<div class="playcode-cs-user-bar">
					<div class="playcode-cs-user-info">
						<img id="playcode-cs-build-user-avatar" class="playcode-cs-avatar" src="<?php echo ! empty( $gh_user['avatar_url'] ) ? esc_url( $gh_user['avatar_url'] ) : 'https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png'; ?>" alt="Avatar" />
						<div>
							<h4 class="playcode-cs-user-name"><?php echo ! empty( $gh_user['name'] ) ? esc_html( $gh_user['name'] ) : 'Usuario GitHub'; ?></h4>
							<span id="playcode-cs-build-user-login" class="playcode-cs-user-login">@<?php echo ! empty( $gh_user['login'] ) ? esc_html( $gh_user['login'] ) : 'conectado'; ?></span>
						</div>
					</div>
					<div style="display:flex; gap:10px; align-items:center;">
						<button type="button" id="playcode-cs-build-btn-refresh" class="playcode-btn playcode-btn-outline playcode-btn-sm" title="Actualizar estado">
							🔄 Actualizar
						</button>
						<button type="button" id="playcode-cs-build-btn-disconnect" class="playcode-btn playcode-btn-danger playcode-btn-sm">
							Desconectar
						</button>
					</div>
				</div>

				<!-- Case A: No Codespace Found -->
				<div id="playcode-cs-build-no-codespace" style="display:none; text-align:center; padding:30px 10px;">
					<h3 style="font-size:18px; font-weight:800; margin-bottom:10px;">Aún no tienes un entorno de Build creado</h3>
					<p style="font-size:14px; color:#64748B; max-width:550px; margin:0 auto 20px auto;">
						Crea tu propia máquina virtual en GitHub Codespaces (incluye Linux Desktop y Antigravity AI):
					</p>
					<div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
						<button type="button" id="playcode-cs-build-btn-create" class="playcode-btn playcode-btn-primary" style="font-size:15px;">
							🚀 Crear mi Entorno en la Nube (1 Clic)
						</button>
						<a href="<?php echo esc_url( $create_url ); ?>" target="_blank" class="playcode-btn playcode-btn-outline" style="font-size:15px;">
							↗️ Abrir en GitHub
						</a>
					</div>
				</div>

				<!-- Case B: Codespace Found — Actions Bar + Iframe -->
				<div id="playcode-cs-build-details" style="display:none;">
					<!-- Actions Bar -->
					<div class="playcode-cs-actions" style="margin-bottom:15px;">
						<!-- Start Button (if stopped) -->
						<button type="button" id="playcode-cs-build-btn-start" class="playcode-btn playcode-btn-primary" style="display:none;">
							⚡ Encender mi Entorno
						</button>

						<!-- Primary: Invoke Antigravity -->
						<button type="button" id="playcode-cs-btn-invoke-agy" class="playcode-btn playcode-btn-primary" style="font-size:15px; font-weight:900;">
							🚀 Invocar a Antigravity
						</button>

						<!-- Link to Linux Desktop tab -->
						<a href="<?php echo esc_url( $codespace_url ); ?>" class="playcode-btn playcode-btn-outline">
							🖥️ Ir a Máquina Virtual Linux
						</a>

						<!-- Stop Button -->
						<button type="button" id="playcode-cs-build-btn-stop" class="playcode-btn playcode-btn-outline" style="display:none;">
							🛑 Apagar Entorno
						</button>
					</div>

					<!-- Embedded Antigravity Container (hidden until launched) -->
					<div id="playcode-agy-container" class="playcode-agy-container" style="display:none;">
						<!-- Toolbar -->
						<div class="playcode-agy-toolbar">
							<div class="playcode-agy-toolbar-status">
								<span class="playcode-cs-dot dot-green"></span>
								<span>Antigravity 2.0 Web Client (Puerto 3000)</span>
							</div>
							<div class="playcode-agy-toolbar-actions">
								<button type="button" id="playcode-agy-btn-fullscreen" class="playcode-btn playcode-btn-outline playcode-btn-sm" title="Pantalla Completa">
									🔲 Pantalla Completa
								</button>
								<a href="#" id="playcode-agy-btn-newtab" target="_blank" class="playcode-btn playcode-btn-primary playcode-btn-sm" title="Abrir en pestaña nueva">
									↗️ Abrir en Pestaña Nueva
								</a>
								<button type="button" id="playcode-agy-btn-reload" class="playcode-btn playcode-btn-outline playcode-btn-sm" title="Recargar">
									🔄
								</button>
							</div>
						</div>

						<!-- The Interactive Iframe -->
						<iframe
							id="playcode-agy-iframe"
							class="playcode-agy-frame"
							src="about:blank"
							allow="clipboard-read; clipboard-write; fullscreen; microphone; camera; display-capture"
							sandbox="allow-forms allow-modals allow-orientation-lock allow-pointer-lock allow-popups allow-popups-to-escape-sandbox allow-presentation allow-same-origin allow-scripts allow-top-navigation-by-user-activation"
						></iframe>
					</div>

					<!-- Tip Box -->
					<div class="playcode-cs-notice" style="background:#EFF6FF; border-color:#3B82F6; color:#1E40AF;">
						<span style="font-size:18px;">💡</span>
						<div>
							<strong>Para conectar tu cuenta de Google:</strong> Debido a las políticas de seguridad de Google que restringen el login dentro de ventanas embebidas, presiona el botón azul <strong>↗️ Abrir en Pestaña Nueva</strong> en la barra superior (o hazlo desde Google Chrome en tu <strong>🖥️ Máquina Virtual Linux</strong>). Una vez autenticado, Antigravity quedará habilitado automáticamente en este panel.
						</div>
					</div>
				</div>

			<?php endif; ?>

		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* ==========================================================================
   6. ADMIN SETTINGS PAGE
   ========================================================================== */

add_action( 'admin_menu', 'playcode_cs_admin_menu' );
function playcode_cs_admin_menu() {
	add_options_page(
		'PlayCode Codespaces',
		'PlayCode Codespaces',
		'manage_options',
		'playcode-codespaces-settings',
		'playcode_cs_render_admin_settings'
	);
}

function playcode_cs_render_admin_settings() {
	if ( isset( $_POST['playcode_cs_save_settings'] ) && check_admin_referer( 'playcode_cs_settings_nonce' ) ) {
		update_option( 'playcode_cs_client_id',     sanitize_text_field( $_POST['playcode_cs_client_id'] ) );
		update_option( 'playcode_cs_client_secret', sanitize_text_field( $_POST['playcode_cs_client_secret'] ) );
		update_option( 'playcode_cs_default_repo',  sanitize_text_field( $_POST['playcode_cs_default_repo'] ) );
		echo '<div class="updated"><p>Ajustes guardados correctamente.</p></div>';
	}

	$client_id     = get_option( 'playcode_cs_client_id', '' );
	$client_secret = get_option( 'playcode_cs_client_secret', '' );
	$default_repo  = get_option( 'playcode_cs_default_repo', 'portadordelsello-stack/linux-kde-lite' );
	$callback_url  = home_url( '/?playcode_gh_callback=1' );
	?>
	<div class="wrap" style="max-width:800px;">
		<h1>⚙️ PlayCode Codespaces & Linux Desktop</h1>
		<p>Configura la integración oficial con GitHub para que los alumnos puedan encender su entorno virtual desde su panel de MasterStudy LMS.</p>

		<form method="post" action="" style="background:#FFF; padding:20px; border:1px solid #CCD0D4; margin-top:20px;">
			<?php wp_nonce_field( 'playcode_cs_settings_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="playcode_cs_client_id">GitHub Client ID</label></th>
					<td>
						<input type="text" name="playcode_cs_client_id" id="playcode_cs_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="playcode_cs_client_secret">GitHub Client Secret</label></th>
					<td>
						<input type="password" name="playcode_cs_client_secret" id="playcode_cs_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="playcode_cs_default_repo">Repositorio Predeterminado</label></th>
					<td>
						<input type="text" name="playcode_cs_default_repo" id="playcode_cs_default_repo" value="<?php echo esc_attr( $default_repo ); ?>" class="regular-text" />
						<p class="description">Repositorio que contiene la imagen de Linux (ej: <code>portadordelsello-stack/linux-kde-lite</code>).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Authorization callback URL</th>
					<td>
						<input type="text" value="<?php echo esc_url( $callback_url ); ?>" class="large-text" readonly onclick="this.select();" />
						<p class="description">Copia y pega esta URL en tu <strong>GitHub OAuth App</strong> (<a href="https://github.com/settings/developers" target="_blank">Settings &gt; Developer settings &gt; OAuth Apps</a>).</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="playcode_cs_save_settings" class="button button-primary" value="Guardar Cambios" />
			</p>
		</form>
	</div>
	<?php
}
