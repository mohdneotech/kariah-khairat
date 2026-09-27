<?php
/**
 * First-run setup: create the public pages, and check that the private receipts folder
 * is not reachable over the web.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'pk-dashboard', 'Persediaan', 'Persediaan', 'manage_options', 'pk-persediaan', 'pk_page_persediaan' );
}, 20 );

/** Which logical pages have no published page yet. */
function pk_missing_pages() {
	$ids  = (array) get_option( 'pk_pages', [] );
	$miss = [];
	foreach ( pk_page_map() as $k => $v ) {
		$ok = ( ! empty( $ids[ $k ] ) && 'publish' === get_post_status( $ids[ $k ] ) ) || pk_find_page( $k );
		if ( ! $ok ) { $miss[ $k ] = $v; }
	}
	return $miss;
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || 'pk-persediaan' === ( $_GET['page'] ?? '' ) ) { return; }
	if ( ! get_transient( 'pk_setup_notice' ) && get_option( 'pk_pages' ) ) { return; }
	$miss = pk_missing_pages();
	if ( ! $miss ) { delete_transient( 'pk_setup_notice' ); return; }
	echo '<div class="notice notice-info"><p><strong>Kariah &amp; Khairat Masjid:</strong> lengkapkan persediaan — isi nama masjid, yuran &amp; akaun bank, dan cipta halaman borang. '
		. '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=pk-persediaan' ) ) . '">Mula persediaan</a></p></div>';
} );

function pk_page_persediaan() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$miss = pk_missing_pages();
	pk_admin_header( 'Persediaan Kariah & Khairat' );
	pk_private_dir_warning( true );
	?>
	<div class="pk-box">
		<h2>1. Tetapan asas</h2>
		<p>Isi nama masjid, alamat, yuran khairat, harga bahagian korban, akaun bank dan kod QR.</p>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pk-tetapan' ) ); ?>">Buka Tetapan</a>
		<?php echo pk_opt( 'bank' ) ? ' <span class="pk-badge pk-b-ok">Akaun bank ditetapkan</span>' : ' <span class="pk-badge pk-b-warn">Akaun bank belum ditetapkan</span>'; ?></p>
	</div>
	<div class="pk-box">
		<h2>2. Halaman awam</h2>
		<table class="widefat striped" style="max-width:760px"><thead><tr><th>Halaman</th><th>Shortcode</th><th>Status</th></tr></thead><tbody>
		<?php foreach ( pk_page_map() as $k => [ $sc, $title ] ) :
			$url = isset( $miss[ $k ] ) ? '' : pk_page_url( $k ); ?>
			<tr><td><?php echo esc_html( $title ); ?></td><td><code>[<?php echo esc_html( $sc ); ?>]</code></td>
			<td><?php echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank">Ada ↗</a>' : '<span class="pk-badge pk-b-warn">Belum ada</span>'; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php if ( $miss ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
				<?php wp_nonce_field( 'pk_cipta_halaman' ); ?><input type="hidden" name="action" value="pk_cipta_halaman">
				<button class="button button-primary">Cipta <?php echo count( $miss ); ?> halaman yang belum ada</button>
			</form>
		<?php endif; ?>
		<p class="description">Selepas itu tambah halaman ke menu di <a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">Penampilan → Menu</a>. Pautan "Portal Ahli" dan "Semak Status" berguna di menu utama.</p>
	</div>
	<div class="pk-box">
		<h2>3. Pengguna AJK</h2>
		<p>Cipta akaun untuk AJK dengan peranan <strong>AJK Kariah</strong> (akses menu Kariah &amp; Khairat sahaja) di <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Pengguna → Tambah Baharu</a>. Administrator dan Editor juga boleh mengurus rekod.</p>
		<p class="description">Disyorkan: pasang plugin keselamatan dengan pengesahan dua langkah (2FA) untuk akaun AJK kerana sistem ini menyimpan No. KP dan alamat ahli.</p>
	</div>
	<div class="pk-box">
		<h2>4. E-mel</h2>
		<p>Notifikasi kepada AJK dihantar ke <code><?php echo esc_html( pk_opt( 'emel_notis' ) ?: '(belum ditetapkan)' ); ?></code>. Pastikan laman web boleh menghantar e-mel (contohnya melalui plugin SMTP).</p>
	</div>
	</div>
	<?php
}

add_action( 'admin_post_pk_cipta_halaman', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Tiada kebenaran.' ); }
	check_admin_referer( 'pk_cipta_halaman' );
	$ids = (array) get_option( 'pk_pages', [] );
	$n   = 0;
	foreach ( pk_missing_pages() as $k => [ $sc, $title ] ) {
		$content = 'muat_turun' === $k
			? "<!-- wp:paragraph --><p>Muat turun borang untuk diisi secara manual dan diserahkan kepada AJK.</p><!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->[$sc]<!-- /wp:shortcode -->"
			: "<!-- wp:shortcode -->[$sc]<!-- /wp:shortcode -->";
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content, 'comment_status' => 'closed' ] );
		if ( $id && ! is_wp_error( $id ) ) { $ids[ $k ] = (int) $id; $n++; }
		wp_cache_delete( 'pk_pg_' . $k, 'pk' );
	}
	update_option( 'pk_pages', $ids, false );
	delete_transient( 'pk_setup_notice' );
	pk_back( $n . ' halaman dicipta.', admin_url( 'admin.php?page=pk-persediaan' ) );
} );

/* ------------------------------------------------------------------ private folder exposure check */

/**
 * true = files in uploads/pk-private are downloadable by anyone (e.g. nginx ignores .htaccess),
 * false = blocked, null = could not test (loopback request failed).
 */
function pk_private_dir_exposed( $fresh = false ) {
	$c = get_transient( 'pk_priv_check' );
	if ( ! $fresh && false !== $c ) { return 'y' === $c ? true : ( 'n' === $c ? false : null ); }
	$dir   = pk_private_dir();
	$token = wp_generate_password( 24, false );
	$name  = 'pk-probe-' . strtolower( wp_generate_password( 8, false ) ) . '.txt';
	$res   = null;
	if ( @file_put_contents( "$dir/$name", $token ) ) {
		$up  = wp_upload_dir();
		$r   = wp_remote_get( trailingslashit( $up['baseurl'] ) . 'pk-private/' . $name, [ 'timeout' => 8, 'sslverify' => false, 'redirection' => 2 ] );
		if ( ! is_wp_error( $r ) ) {
			$res = 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== strpos( wp_remote_retrieve_body( $r ), $token );
		}
		@unlink( "$dir/$name" );
	}
	set_transient( 'pk_priv_check', true === $res ? 'y' : ( false === $res ? 'n' : 'u' ), true === $res ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
	return $res;
}

function pk_private_dir_warning( $fresh = false ) {
	$x = pk_private_dir_exposed( $fresh );
	if ( true !== $x ) { return; }
	$up = wp_upload_dir();
	$path = wp_parse_url( trailingslashit( $up['baseurl'] ) . 'pk-private/', PHP_URL_PATH );
	echo '<div class="notice notice-error"><p><strong>Amaran keselamatan:</strong> fail bukti bayaran dan resit dalam <code>' . esc_html( $path ) . '</code> boleh dimuat turun oleh sesiapa sahaja. '
		. 'Pelayan anda (biasanya nginx) tidak membaca fail <code>.htaccess</code>. Minta pentadbir pelayan tambah peraturan ini, kemudian muat semula halaman:</p>'
		. '<pre style="background:#f6f7f7;padding:8px 10px;max-width:760px;overflow:auto">location ^~ ' . esc_html( $path ) . " {\n    deny all;\n    return 403;\n}</pre>"
		. '<p>Caddy: <code>respond ' . esc_html( $path ) . '* 403</code> · Apache: pastikan <code>AllowOverride</code> membenarkan <code>.htaccess</code>.</p></div>';
}
