<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lms_current_user = (array) STM_LMS_User::get_current_user( '', true, true );

do_action( 'stm_lms_template_main' );
do_action( 'masterstudy_before_account', $lms_current_user );

wp_enqueue_style( 'masterstudy-account-main' );
wp_enqueue_style( 'masterstudy-account-menu' );
?>

<?php STM_LMS_Templates::show_lms_template( 'modals/preloader' ); ?>

<div class="masterstudy-account">
	<?php do_action( 'stm_lms_admin_after_wrapper_start', $lms_current_user ); ?>
	<div class="masterstudy-account-sidebar">
		<div class="masterstudy-account-sidebar__wrapper">
			<?php do_action( 'masterstudy_account_sidebar', $lms_current_user ); ?>
		</div>
	</div>
	<div class="masterstudy-account-container">
		<?php
		if ( function_exists( 'playcode_codespaces_render_build_dashboard' ) ) {
			echo playcode_codespaces_render_build_dashboard();
		} else {
			echo '<div style="background:#FFF; border:2px solid #001F4A; padding:20px; font-weight:800; color:#001F4A;">Antigravity 2.0 Build</div>';
		}
		?>
	</div>
</div>

<?php
do_action( 'masterstudy_after_account', $lms_current_user );

