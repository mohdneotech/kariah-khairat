<?php
/**
 * Plugin Name:       Kariah & Khairat Masjid
 * Plugin URI:        https://github.com/mohdneotech/kariah-khairat
 * Description:       Sistem ahli kariah untuk masjid / surau — pendaftaran Badan Khairat Kematian dan Ibadah Korban/Aqiqah dalam talian atau manual oleh AJK, bayaran sekaligus & ansuran dengan bukti bayaran, Portal Ahli, semak status, import/eksport CSV dan papan pemuka infografik.
 * Version:           1.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mohd Nordin Hussain
 * Author URI:        https://mohdnordin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kariah-khairat
 */

defined( 'ABSPATH' ) || exit;

// Another copy (e.g. an older site-specific build) is already loaded — stand down instead of fataling.
if ( defined( 'PK_VER' ) ) {
	register_activation_hook( __FILE__, function () {
		wp_die( 'Sila nyahaktifkan plugin lama (Perepat Kariah) dahulu, kemudian aktifkan plugin ini. Semua data akan dikekalkan.', 'Plugin lama masih aktif', [ 'back_link' => true ] );
	} );
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Kariah &amp; Khairat Masjid:</strong> satu lagi plugin kariah (fungsi <code>pk_*</code>) sedang aktif. Nyahaktifkan plugin lama dahulu — semua rekod ahli, korban dan bayaran akan dikekalkan.</p></div>';
	} );
	return;
}

define( 'PK_VER', '1.4.0' );
define( 'PK_DB_VER', '2' );
define( 'PK_FILE', __FILE__ );
define( 'PK_DIR', plugin_dir_path( __FILE__ ) );
define( 'PK_URL', plugin_dir_url( __FILE__ ) );
define( 'PK_CAP', 'pk_urus' );
define( 'PK_REPO', 'mohdneotech/kariah-khairat' );

require_once PK_DIR . 'includes/helpers.php';
require_once PK_DIR . 'includes/db.php';
require_once PK_DIR . 'includes/public.php';
require_once PK_DIR . 'includes/portal.php';
require_once PK_DIR . 'includes/class-github-updater.php';
if ( is_admin() ) {
	require_once PK_DIR . 'includes/admin.php';
	require_once PK_DIR . 'includes/admin-ahli.php';
	require_once PK_DIR . 'includes/admin-korban.php';
	require_once PK_DIR . 'includes/admin-bayaran.php';
	require_once PK_DIR . 'includes/dashboard.php';
	require_once PK_DIR . 'includes/import-export.php';
	require_once PK_DIR . 'includes/admin-kemaskini.php';
	require_once PK_DIR . 'includes/setup.php';
}
if ( is_admin() || wp_doing_cron() ) {
	new PK_GitHub_Updater( __FILE__, PK_REPO, PK_VER );
}

register_activation_hook( __FILE__, 'pk_activate' ); // defined in includes/db.php

add_action( 'plugins_loaded', function () {
	if ( get_option( 'pk_db_ver' ) !== PK_DB_VER ) { pk_install_schema(); }
} );
