<?php
/**
 * Runs when the plugin is DELETED from wp-admin (not on deactivate).
 * Member data is only removed if "Padam semua rekod … apabila plugin dipadam" was ticked in Tetapan.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$s = get_option( 'pk_settings' );

delete_site_transient( 'pk_gh_release' );
delete_transient( 'pk_setup_notice' );
delete_transient( 'pk_priv_check' );

if ( empty( $s['padam_data'] ) ) {
	return; // keep everything — reinstalling the plugin picks up where it left off
}

foreach ( [ 'ahli', 'tanggungan', 'korban', 'korban_peserta', 'pelan', 'bayaran', 'kemaskini', 'log' ] as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pk_' . $t ); // phpcs:ignore WordPress.DB.PreparedSQL
}
foreach ( [ 'pk_settings', 'pk_pages', 'pk_db_ver' ] as $o ) {
	delete_option( $o );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pk\_%' OR option_name LIKE '\_transient\_timeout\_pk\_%'" );

// Portal-only member accounts created by the plugin.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( get_users( [ 'role' => 'pk_ahli', 'fields' => 'all' ] ) as $u ) {
	if ( [ 'pk_ahli' ] === array_values( (array) $u->roles ) ) {
		wp_delete_user( $u->ID );
	}
}
foreach ( [ 'administrator', 'editor' ] as $r ) {
	if ( $role = get_role( $r ) ) { $role->remove_cap( 'pk_urus' ); }
}
remove_role( 'pk_ajk' );
remove_role( 'pk_ahli' );

// Private payment proofs & receipts.
$up  = wp_upload_dir();
$dir = trailingslashit( $up['basedir'] ) . 'pk-private';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
