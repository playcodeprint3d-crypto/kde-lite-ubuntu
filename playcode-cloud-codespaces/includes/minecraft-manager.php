<?php
/**
 * Play Code — Minecraft Server Manager for MasterStudy LMS
 * Módulo para gestionar servidores privados de Minecraft sobre GitHub Codespaces y Google Cloud FRP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLAYCODE_MC_REPO', 'playcodeprint3d-crypto/playcode-minecraft-server' );
define( 'PLAYCODE_MC_DEFAULT_RELAY', '35.209.249.255' );
define( 'PLAYCODE_MC_BASE_PORT', 20000 );

/* ==========================================================================
   1. MASTERSTUDY LMS INTEGRATION (RUTAS Y MENÚ)
   ========================================================================== */

// 1.1. Registrar ruta personalizada en MasterStudy LMS
add_filter( 'stm_lms_custom_routes_config', 'playcode_mc_register_routes', 1000 );
function playcode_mc_register_routes( $routes ) {
	if ( isset( $routes['user_url']['sub_pages'] ) ) {
		$routes['user_url']['sub_pages']['minecraft_custom'] = array(
			'template'  => 'account/minecraft-custom',
			'protected' => true,
			'url'       => 'minecraft',
		);
	}
	return $routes;
}

// 1.2. Registrar etiqueta de la sección "Mis Servidores"
add_filter( 'masterstudy_account_menu_section_labels', 'playcode_mc_section_labels', 10000 );
function playcode_mc_section_labels( $labels ) {
	if ( ! is_array( $labels ) ) {
		$labels = array();
	}
	$labels['servers'] = 'Mis Servidores';
	return $labels;
}

// 1.3. Orden de secciones en la barra lateral
add_filter( 'masterstudy_account_menu_section_order', 'playcode_mc_section_order', 10000 );
function playcode_mc_section_order( $order ) {
	if ( ! is_array( $order ) ) {
		$order = array( 'main', 'communication', 'codespace', 'servers', 'progress', 'finance' );
	}
	if ( ! in_array( 'servers', $order, true ) ) {
		// Insertar antes de 'account' si existe
		$pos = array_search( 'account', $order, true );
		if ( false !== $pos ) {
			array_splice( $order, $pos, 0, array( 'servers' ) );
		} else {
			$order[] = 'servers';
		}
	}
	return $order;
}

// 1.4. Registrar elemento de menú "🎮 Minecraft"
add_filter( 'stm_lms_menu_items', 'playcode_mc_add_menu_item', 10000 );
add_filter( 'stm_lms_sorted_menu', 'playcode_mc_add_menu_item', 10000 );
function playcode_mc_add_menu_item( $items ) {
	if ( empty( $items ) || ! is_array( $items ) ) {
		return $items;
	}

	foreach ( $items as $it ) {
		if ( isset( $it['slug'] ) && 'minecraft' === $it['slug'] ) {
			return $items;
		}
	}

	$user_url = playcode_cs_get_account_base_url();

	$items[] = array(
		'id'           => 'minecraft_custom',
		'slug'         => 'minecraft',
		'menu_title'   => '🎮 Minecraft',
		'title'        => '🎮 Minecraft',
		'menu_icon'    => 'fa-cubes',
		'icon'         => 'fa-cubes',
		'badge'        => '',
		'menu_url'     => $user_url . 'minecraft/',
		'menu_place'   => 'learning',
		'section'      => 'servers',
		'order'        => 35,
		'user_profile' => true,
	);

	return $items;
}

// 1.5. Override de plantilla
add_filter( 'stm_lms_template_file', 'playcode_mc_override_template_file', 9999, 2 );
function playcode_mc_override_template_file( $path, $template_name ) {
	if ( false !== strpos( $template_name, 'minecraft-custom' ) ) {
		return PLAYCODE_CODESPACES_PATH . 'templates_override';
	}
	return $path;
}

/* ==========================================================================
   2. HELPER FUNCTIONS
   ========================================================================== */

if ( ! function_exists( 'playcode_cs_get_oauth_login_url' ) ) {
	function playcode_cs_get_oauth_login_url() {
		$client_id = get_option( 'playcode_cs_client_id', '' );
		if ( empty( $client_id ) ) {
			return '#';
		}
		return add_query_arg( array(
			'client_id' => $client_id,
			'scope'     => 'codespace,read:user',
			'state'     => wp_create_nonce( 'playcode_gh_oauth' ),
		), 'https://github.com/login/oauth/authorize' );
	}
}

/**
 * Calcula el puerto FRP único y permanente del alumno (20000 + ID).
 */
function playcode_mc_get_student_port( $user_id ) {
	return PLAYCODE_MC_BASE_PORT + ( intval( $user_id ) % 10000 );
}

/**
 * Obtiene el host del Relay (IP de Google Cloud o dominio custom).
 */
function playcode_mc_get_relay_host() {
	return get_option( 'playcode_mc_relay_host', PLAYCODE_MC_DEFAULT_RELAY );
}

/* ==========================================================================
   3. REST API: CONFIGURACIÓN DINÁMICA PARA EL CODESPACE
   ========================================================================== */

add_action( 'rest_api_init', 'playcode_mc_register_rest_routes' );
function playcode_mc_register_rest_routes() {
	register_rest_route( 'playcode/v1', '/minecraft-config', array(
		'methods'             => 'GET',
		'callback'            => 'playcode_mc_rest_get_config',
		'permission_callback' => '__return_true',
	) );
}

function playcode_mc_rest_get_config( WP_REST_Request $request ) {
	global $wpdb;
	$user_id        = intval( $request->get_param( 'user_id' ) );
	$login          = sanitize_text_field( $request->get_param( 'login' ) );
	$codespace_name = sanitize_text_field( $request->get_param( 'codespace' ) );

	if ( ! $user_id && ! empty( $codespace_name ) ) {
		$matched_cs = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'playcode_mc_codespace_name' AND meta_value = %s LIMIT 1",
			$codespace_name
		) );
		if ( $matched_cs ) {
			$user_id = intval( $matched_cs );
		}
	}

	if ( ! $user_id && ! empty( $login ) ) {
		// Buscar usuario por github login en user_meta (PHP serializado exacto)
		$matched_user = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'playcode_github_user' AND meta_value LIKE %s ORDER BY user_id DESC LIMIT 1",
			'%"login";s:' . strlen( $login ) . ':"' . $wpdb->esc_like( $login ) . '";%'
		) );
		if ( $matched_user ) {
			$user_id = intval( $matched_user );
		}
	}

	if ( ! $user_id ) {
		$user_id = 3; // Default to active student
	}

	$version = get_user_meta( $user_id, 'playcode_mc_version', true );
	if ( empty( $version ) ) {
		$version = '1.21.1';
	}

	$port = playcode_mc_get_student_port( $user_id );
	$host = playcode_mc_get_relay_host();

	return rest_ensure_response( array(
		'user_id'        => $user_id,
		'version'        => $version,
		'port'           => $port,
		'relay_host'     => $host,
		'relay_port'     => 7000,
		'public_address' => ( $port === 25565 ) ? $host : "{$host}:{$port}",
	) );
}

/**
 * Sincroniza variables del alumno directamente como Secrets de GitHub Codespaces con Sodium.
 * Inmune a WAF, Cloudflare y OpenResty.
 */
function playcode_mc_sync_github_secrets( $token, $repo_id, $port, $version ) {
	if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
		return false;
	}

	$pk_res = playcode_cs_api_request( '/user/codespaces/secrets/public-key', $token );
	if ( empty( $pk_res['key_id'] ) || empty( $pk_res['key'] ) ) {
		return false;
	}

	$key_id  = $pk_res['key_id'];
	$pub_key = base64_decode( $pk_res['key'] );

	$secrets_to_set = array(
		'PLAYCODE_PORT'    => (string) $port,
		'PLAYCODE_VERSION' => (string) $version,
	);

	foreach ( $secrets_to_set as $sec_name => $sec_val ) {
		$encrypted = sodium_crypto_box_seal( $sec_val, $pub_key );
		$payload   = array(
			'encrypted_value'         => base64_encode( $encrypted ),
			'key_id'                  => $key_id,
			'selected_repository_ids' => array( intval( $repo_id ) ),
		);
		playcode_cs_api_request( "/user/codespaces/secrets/{$sec_name}", $token, 'PUT', $payload );
	}

	return true;
}

/* ==========================================================================
   4. AJAX HANDLERS (CONTROL DESDE EL PANEL DE MASTERSTUDY)
   ========================================================================== */

// 4.1. Consultar estado del servidor de Minecraft
add_action( 'wp_ajax_playcode_mc_status', 'playcode_mc_ajax_status' );
function playcode_mc_ajax_status() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'No autorizado' );
	}

	$token = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_success( array(
			'authorized' => false,
			'state'      => 'unauthorized',
		) );
	}

	// Buscar codespaces del usuario
	$cs_response = playcode_cs_api_request( '/user/codespaces', $token );
	$codespaces  = isset( $cs_response['codespaces'] ) ? $cs_response['codespaces'] : array();

	$mc_codespace = null;
	foreach ( $codespaces as $cs ) {
		if ( isset( $cs['repository']['full_name'] ) &&
		     strtolower( $cs['repository']['full_name'] ) === strtolower( PLAYCODE_MC_REPO ) ) {
			$mc_codespace = $cs;
			break;
		}
	}

	if ( $mc_codespace && ! empty( $mc_codespace['name'] ) ) {
		update_user_meta( $user_id, 'playcode_mc_codespace_name', $mc_codespace['name'] );
	}

	$port        = playcode_mc_get_student_port( $user_id );
	$host        = playcode_mc_get_relay_host();
	$public_addr = ( $port === 25565 ) ? $host : "{$host}:{$port}";
	$version     = get_user_meta( $user_id, 'playcode_mc_version', true );
	if ( empty( $version ) ) {
		$version = '1.20.4';
	}

	wp_send_json_success( array(
		'authorized'     => true,
		'codespace'      => $mc_codespace,
		'state'          => $mc_codespace ? $mc_codespace['state'] : 'not_created',
		'version'        => $version,
		'port'           => $port,
		'public_address' => $public_addr,
	) );
}

// 4.2. Crear servidor de Minecraft
add_action( 'wp_ajax_playcode_mc_create', 'playcode_mc_ajax_create' );
function playcode_mc_ajax_create() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = get_user_meta( $user_id, 'playcode_github_token', true );
	if ( empty( $token ) ) {
		wp_send_json_error( 'No autorizado' );
	}

	$version = isset( $_POST['version'] ) ? sanitize_text_field( $_POST['version'] ) : '1.20.4';
	update_user_meta( $user_id, 'playcode_mc_version', $version );

	$repo_info = playcode_cs_api_request( '/repos/' . PLAYCODE_MC_REPO, $token );
	if ( empty( $repo_info['id'] ) ) {
		wp_send_json_error( 'No se pudo acceder al repositorio de Minecraft en GitHub.' );
	}

	$branch = ! empty( $repo_info['default_branch'] ) ? $repo_info['default_branch'] : 'main';

	$port = playcode_mc_get_student_port( $user_id );
	$host = playcode_mc_get_relay_host();

	// Inyectar puerto y versión como GitHub Codespaces Secrets usando Sodium (100% inmune a WAF)
	playcode_mc_sync_github_secrets( $token, $repo_info['id'], $port, $version );

	$create_res = playcode_cs_api_request( '/user/codespaces', $token, 'POST', array(
		'repository_id' => intval( $repo_info['id'] ),
		'ref'           => $branch,
	) );

	if ( isset( $create_res['error'] ) ) {
		wp_send_json_error( $create_res['error'] );
	}

	if ( ! empty( $create_res['name'] ) ) {
		update_user_meta( $user_id, 'playcode_mc_codespace_name', sanitize_text_field( $create_res['name'] ) );
	}

	wp_send_json_success( $create_res );
}

// 4.3. Iniciar servidor
add_action( 'wp_ajax_playcode_mc_start', 'playcode_mc_ajax_start' );
function playcode_mc_ajax_start() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = get_user_meta( $user_id, 'playcode_github_token', true );
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

	if ( empty( $token ) || empty( $name ) ) {
		wp_send_json_error( 'Parámetros inválidos' );
	}

	$response = playcode_cs_api_request( "/user/codespaces/{$name}/start", $token, 'POST' );
	if ( isset( $response['error'] ) ) {
		wp_send_json_error( $response['error'] );
	}

	wp_send_json_success( $response );
}

// 4.4. Detener servidor
add_action( 'wp_ajax_playcode_mc_stop', 'playcode_mc_ajax_stop' );
function playcode_mc_ajax_stop() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = get_user_meta( $user_id, 'playcode_github_token', true );
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

	if ( empty( $token ) || empty( $name ) ) {
		wp_send_json_error( 'Parámetros inválidos' );
	}

	$response = playcode_cs_api_request( "/user/codespaces/{$name}/stop", $token, 'POST' );
	if ( isset( $response['error'] ) ) {
		wp_send_json_error( $response['error'] );
	}

	wp_send_json_success( $response );
}

// 4.5. Eliminar servidor (para recrear o cambiar versión)
add_action( 'wp_ajax_playcode_mc_delete', 'playcode_mc_ajax_delete' );
function playcode_mc_ajax_delete() {
	check_ajax_referer( 'playcode_cs_nonce', 'nonce' );

	$user_id = get_current_user_id();
	$token   = get_user_meta( $user_id, 'playcode_github_token', true );
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

	if ( empty( $token ) || empty( $name ) ) {
		wp_send_json_error( 'Parámetros inválidos' );
	}

	$response = playcode_cs_api_request( "/user/codespaces/{$name}", $token, 'DELETE' );
	if ( isset( $response['error'] ) ) {
		wp_send_json_error( $response['error'] );
	}

	wp_send_json_success( $response );
}

/* ==========================================================================
   5. RENDER DEL PANEL DE CONTROL DE MINECRAFT
   ========================================================================== */

function playcode_mc_render_dashboard() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<div class="playcode-mc-alert">Debes iniciar sesión para acceder a tu servidor.</div>';
	}

	$token       = get_user_meta( $user_id, 'playcode_github_token', true );
	$port        = playcode_mc_get_student_port( $user_id );
	$host        = playcode_mc_get_relay_host();
	$public_addr = ( $port === 25565 ) ? $host : "{$host}:{$port}";
	$nonce       = wp_create_nonce( 'playcode_cs_nonce' );

	wp_enqueue_style( 'playcode-mc-style', PLAYCODE_CODESPACES_URL . 'assets/minecraft-dashboard.css', array(), PLAYCODE_CODESPACES_VERSION );
	wp_enqueue_script( 'playcode-mc-script', PLAYCODE_CODESPACES_URL . 'assets/minecraft-dashboard.js', array( 'jquery' ), PLAYCODE_CODESPACES_VERSION, true );
	wp_localize_script( 'playcode-mc-script', 'playcodeMC', array(
		'ajaxurl'    => admin_url( 'admin-ajax.php' ),
		'nonce'      => $nonce,
		'authorized' => ! empty( $token ),
		'authUrl'    => playcode_cs_get_oauth_login_url(),
		'publicAddr' => $public_addr,
	) );

	ob_start();
	?>
	<div class="playcode-mc-wrapper" id="playcode-mc-app">
		<!-- HEADER -->
		<div class="playcode-mc-header">
			<div class="playcode-mc-title-box">
				<h1 class="playcode-mc-title">🎮 Mi Servidor de Minecraft</h1>
				<p class="playcode-mc-subtitle">Tu mundo multijugador privado y seguro — Play Code Lab</p>
			</div>
			<div class="playcode-mc-header-actions">
				<span id="mc-status-badge" class="playcode-mc-badge playcode-mc-badge-gray">Consultando...</span>
			</div>
		</div>

		<!-- VISTA 1: NO AUTORIZADO CON GITHUB -->
		<div id="mc-view-unauthorized" class="playcode-mc-card" style="display: <?php echo empty( $token ) ? 'block' : 'none'; ?>;">
			<div class="playcode-mc-hero-auth">
				<div class="playcode-mc-icon-cube">🧱</div>
				<h2>Conecta tu cuenta de GitHub</h2>
				<p>Para encender tu servidor privado en la nube de Play Code, vincula tu cuenta en un solo click.</p>
				<a href="<?php echo esc_url( playcode_cs_get_oauth_login_url() ); ?>" class="playcode-mc-btn playcode-mc-btn-primary">
					⚡ Conectar con GitHub
				</a>
			</div>
		</div>

		<!-- VISTA 2: SIN SERVIDOR CREADO -->
		<div id="mc-view-no-server" class="playcode-mc-card" style="display: none;">
			<div class="playcode-mc-create-box">
				<h2>Crea tu Servidor de Minecraft</h2>
				<p>Elige tu versión preferida. Podrán entrar amigos tanto con Minecraft oficial como No-Premium (TLauncher, etc.).</p>
				
				<div class="playcode-mc-version-selector">
					<label for="mc-version-select"><strong>Selecciona la Versión:</strong></label>
					<select id="mc-version-select" class="playcode-mc-select">
						<option value="1.20.4" selected>Minecraft 1.20.4 (Recomendada — Estable y Moderna)</option>
						<option value="1.21.1">Minecraft 1.21.1 (Última Versión con Trial Chambers)</option>
						<option value="1.19.4">Minecraft 1.19.4 (The Wild Update)</option>
						<option value="1.18.2">Minecraft 1.18.2 (Caves & Cliffs)</option>
						<option value="1.16.5">Minecraft 1.16.5 (Nether Update — Ideal para Mods)</option>
						<option value="1.12.2">Minecraft 1.12.2 (Clásica de Mods)</option>
						<option value="1.8.8">Minecraft 1.8.8 (Clásica de PvP y Combate)</option>
					</select>
				</div>

				<div style="margin-top: 25px;">
					<button id="mc-btn-create" class="playcode-mc-btn playcode-mc-btn-primary">
						🎮 Crear mi Servidor de Minecraft
					</button>
				</div>
			</div>
		</div>

		<!-- VISTA 3: SERVIDOR EN CONSTRUCCIÓN / ARRANCANDO -->
		<div id="mc-view-loading" class="playcode-mc-card" style="display: none;">
			<div class="playcode-mc-loading-box">
				<div class="playcode-mc-spinner"></div>
				<h3 id="mc-loading-title">Preparando tu servidor en la nube...</h3>
				<p id="mc-loading-desc">Descargando PaperMC, configurando Java y abriendo el túnel seguro. Esto toma unos segundos.</p>
			</div>
		</div>

		<!-- VISTA 4: PANEL DE CONTROL COMPLETO (EN LÍNEA / DETENIDO) -->
		<div id="mc-view-active" style="display: none;">
			<!-- CAJA DE CONEXIÓN IP -->
			<div class="playcode-mc-card playcode-mc-ip-card">
				<div class="playcode-mc-ip-header">
					<span class="playcode-mc-label">DIRECCIÓN PARA TU LAUNCHER:</span>
					<span id="mc-conn-badge" class="playcode-mc-pill playcode-mc-pill-green">🟢 En Línea</span>
				</div>
				<div class="playcode-mc-ip-content">
					<div id="mc-public-ip-text" class="playcode-mc-ip-code"><?php echo esc_html( $public_addr ); ?></div>
					<button id="mc-btn-copy-ip" class="playcode-mc-btn playcode-mc-btn-yellow" onclick="playcodeMCCopyIp()">
						📋 Copiar IP
					</button>
				</div>
				<div class="playcode-mc-ip-footer">
					<span>🎮 Versión: <strong id="mc-active-version">PaperMC 1.20.4</strong></span>
					<span>⚡ Acceso: <strong>Libre (Cuentas Oficiales y No-Premium)</strong></span>
				</div>
			</div>

			<!-- BOTONES DE ESTADO (ENCENDER / APAGAR / ELIMINAR) -->
			<div class="playcode-mc-card">
				<div class="playcode-mc-actions-bar">
					<button id="mc-btn-start" class="playcode-mc-btn playcode-mc-btn-green" style="display: none;">
						▶️ Encender Servidor
					</button>
					<button id="mc-btn-stop" class="playcode-mc-btn playcode-mc-btn-red">
						⏹ Detener Servidor
					</button>
					<button id="mc-btn-delete" class="playcode-mc-btn playcode-mc-btn-outline-red" style="margin-left: auto;">
						🗑 Eliminar Servidor
					</button>
				</div>
			</div>

			<!-- SEGURIDAD Y LISTA BLANCA (AMIGOS) -->
			<div class="playcode-mc-card">
				<div class="playcode-mc-section-header">
					<h3>🛡️ Lista Blanca (Solo Amigos Invitados)</h3>
					<span class="playcode-mc-tag">Seguridad</span>
				</div>
				<p class="playcode-mc-card-desc">
					Si activas la lista blanca, nadie podrá entrar a tu mundo a menos que lo agregues a tu lista de amigos permitidos.
				</p>
				<div class="playcode-mc-whitelist-controls">
					<button id="mc-btn-whitelist-toggle" class="playcode-mc-btn playcode-mc-btn-blue">
						Activar Lista Blanca
					</button>
				</div>
				<div class="playcode-mc-whitelist-input-group">
					<input type="text" id="mc-input-friend" class="playcode-mc-input" placeholder="Nombre de Minecraft de tu amigo...">
					<button id="mc-btn-add-friend" class="playcode-mc-btn playcode-mc-btn-primary">+ Invitar Amigo</button>
				</div>
				<div id="mc-whitelist-chips" class="playcode-mc-chips-container">
					<!-- Amigos autorizados cargados dinámicamente -->
				</div>
			</div>

			<!-- ACCIONES RÁPIDAS DE ADMINISTRADOR -->
			<div class="playcode-mc-card">
				<div class="playcode-mc-section-header">
					<h3>⚡ Acciones Rápidas</h3>
				</div>
				<div class="playcode-mc-quick-grid">
					<button class="playcode-mc-btn playcode-mc-btn-sm" onclick="playcodeMCRunCommand('op')">👑 Hacerme Administrador (/op)</button>
					<button class="playcode-mc-btn playcode-mc-btn-sm" onclick="playcodeMCRunCommand('time set day')">☀️ Hacer de Día</button>
					<button class="playcode-mc-btn playcode-mc-btn-sm" onclick="playcodeMCRunCommand('gamemode creative @a')">🎨 Modo Creativo</button>
					<button class="playcode-mc-btn playcode-mc-btn-sm" onclick="playcodeMCRunCommand('gamemode survival @a')">⚔️ Modo Supervivencia</button>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
