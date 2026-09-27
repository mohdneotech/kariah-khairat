<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================= menu & assets */

add_action( 'admin_menu', function () {
	global $wpdb;
	$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . pk_t( 'bayaran' ) . " WHERE status='menunggu'" )
		+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . pk_t( 'ahli' ) . " WHERE status='menunggu'" )
		+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . pk_t( 'korban' ) . " WHERE status='menunggu'" )
		+ ( $km = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . pk_t( 'kemaskini' ) . " WHERE status='menunggu'" ) );
	$bubble = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';
	add_menu_page( 'Kariah & Khairat', 'Kariah & Khairat' . $bubble, PK_CAP, 'pk-dashboard', 'pk_page_dashboard', 'dashicons-groups', 26 );
	add_submenu_page( 'pk-dashboard', 'Papan Pemuka', 'Papan Pemuka', PK_CAP, 'pk-dashboard', 'pk_page_dashboard' );
	add_submenu_page( 'pk-dashboard', 'Ahli Khairat', 'Ahli Khairat', PK_CAP, 'pk-ahli', 'pk_page_ahli' );
	add_submenu_page( 'pk-dashboard', 'Daftar Ahli (Manual)', '+ Daftar Ahli', PK_CAP, 'pk-ahli-baru', 'pk_page_ahli_baru' );
	add_submenu_page( 'pk-dashboard', 'Korban & Aqiqah', 'Korban & Aqiqah', PK_CAP, 'pk-korban', 'pk_page_korban' );
	add_submenu_page( 'pk-dashboard', 'Daftar Korban (Manual)', '+ Daftar Korban', PK_CAP, 'pk-korban-baru', 'pk_page_korban_baru' );
	add_submenu_page( 'pk-dashboard', 'Bayaran & Ansuran', 'Bayaran & Ansuran', PK_CAP, 'pk-bayaran', 'pk_page_bayaran' );
	add_submenu_page( 'pk-dashboard', 'Permohonan Kemas Kini', 'Kemas Kini Ahli' . ( $km ? ' <span class="awaiting-mod">' . $km . '</span>' : '' ), PK_CAP, 'pk-kemaskini', 'pk_page_kemaskini' );
	add_submenu_page( 'pk-dashboard', 'Import / Eksport', 'Import / Eksport', PK_CAP, 'pk-import', 'pk_page_import' );
	add_submenu_page( 'pk-dashboard', 'Tetapan', 'Tetapan', 'manage_options', 'pk-tetapan', 'pk_page_tetapan' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) ( $_GET['page'] ?? '' ), 'pk-' ) ) { return; }
	wp_enqueue_style( 'pk-admin', PK_URL . 'assets/pk-admin.css', [], PK_VER );
	wp_enqueue_script( 'pk-admin', PK_URL . 'assets/pk-admin.js', [], PK_VER, true );
	if ( 'pk-tetapan' === ( $_GET['page'] ?? '' ) ) { wp_enqueue_media(); }
	if ( 'pk-dashboard' === ( $_GET['page'] ?? '' ) ) {
		wp_enqueue_script( 'pk-chartjs', PK_URL . 'assets/vendor/chart.umd.min.js', [], '4.4.4', true );
	}
} );

/** Non-AJK users can't reach wp-admin dashboard clutter; send AJK role straight to our dashboard. */
add_filter( 'login_redirect', function ( $to, $req, $user ) {
	return ( $user instanceof WP_User && in_array( 'pk_ajk', (array) $user->roles, true ) ) ? admin_url( 'admin.php?page=pk-dashboard' ) : $to;
}, 10, 3 );

function pk_guard( $nonce ) {
	if ( ! current_user_can( PK_CAP ) ) { wp_die( 'Tiada kebenaran.' ); }
	check_admin_referer( $nonce );
}

function pk_back( $msg = '', $url = '' ) {
	$url = $url ?: ( wp_get_referer() ?: admin_url( 'admin.php?page=pk-dashboard' ) );
	$url = remove_query_arg( [ 'pk_msg', 'pk_err' ], $url );
	wp_safe_redirect( $msg ? add_query_arg( 'pk_msg', pk_flash_set( $msg ), $url ) : $url );
	exit;
}

add_action( 'admin_notices', function () {
	if ( empty( $_GET['pk_msg'] ) || false === strpos( (string) ( $_GET['page'] ?? '' ), 'pk-' ) ) { return; }
	$m = pk_flash_get();
	if ( '' !== $m ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $m ) . '</p></div>'; }
} );

function pk_admin_header( $title, $actions = '' ) {
	echo '<div class="wrap pk-wrap"><div class="pk-top"><h1 class="wp-heading-inline"><span class="dashicons dashicons-groups"></span> ' . esc_html( $title ) . '</h1>' . $actions . '</div><hr class="wp-header-end">';
}

/* ================================================================= private proof download */

add_action( 'admin_post_pk_bukti', function () {
	if ( ! current_user_can( PK_CAP ) ) { wp_die( 'Tiada kebenaran.', 403 ); }
	check_admin_referer( 'pk_bukti' );
	pk_stream_private( (string) wp_unslash( pk_gs( 'f' ) ) );
} );

/* ================================================================= shared: plans & payments panel */

function pk_admin_pelan_panel( $jenis, $ref_id, $suggest = [] ) {
	$pl = pk_pelan_list( $jenis, $ref_id );
	$yr = pk_year();
	echo '<div class="pk-box"><h2><span class="dashicons dashicons-money-alt"></span> Yuran, Pelan Bayaran &amp; Ansuran</h2>';
	if ( ! $pl ) { echo '<p class="description">Belum ada pelan bayaran. Tambah di bawah.</p>'; }
	foreach ( $pl as $p ) {
		$baki = max( 0, $p['jumlah'] - $p['dibayar'] );
		$pct  = $p['jumlah'] > 0 ? min( 100, round( $p['dibayar'] / $p['jumlah'] * 100 ) ) : 0;
		echo '<div class="pk-pelan pk-pelan-' . esc_attr( $p['status'] ) . '">';
		echo '<div class="pk-pelan-head"><div><strong>' . esc_html( $p['tajuk'] ) . '</strong> ' . pk_badge( 'status_pelan', $p['status'] )
			. '<div class="description">' . esc_html( pk_label( 'kategori', $p['kategori'] ) ) . ( $p['liputan'] ? ' · liputan tahun ' . esc_html( str_replace( ',', ', ', $p['liputan'] ) ) : '' ) . ' · '
			. ( 'ansuran' === $p['cara'] ? '<span class="pk-chip">Ansuran ' . (int) $p['bil_ansuran'] . ' × ' . esc_html( pk_rm( $p['amaun_ansuran'] ) ) . '</span>' : '<span class="pk-chip pk-chip-alt">Sekaligus</span>' ) . '</div></div>';
		echo '<div class="pk-pelan-sum"><span>Jumlah <b>' . pk_rm( $p['jumlah'] ) . '</b></span><span>Dibayar <b>' . pk_rm( $p['dibayar'] ) . '</b></span><span>Baki <b class="' . ( $baki > 0 ? 'pk-red' : '' ) . '">' . pk_rm( $baki ) . '</b></span></div></div>';
		echo '<div class="pk-bar"><i style="width:' . $pct . '%"></i></div>';
		$bs = pk_bayaran_list( $p['id'] );
		if ( $bs ) {
			echo '<table class="widefat striped pk-mini"><thead><tr><th>Tarikh</th><th>Jumlah</th><th>Kaedah</th><th>No. Resit</th><th>Bukti &amp; Resit</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $bs as $b ) {
				echo '<tr><td>' . esc_html( pk_date( $b['tarikh'] ) ) . '</td><td><b>' . pk_rm( $b['jumlah'] ) . '</b></td><td>' . esc_html( pk_label( 'kaedah', $b['kaedah'] ) ) . '<div class="description">' . esc_html( pk_label( 'sumber', $b['sumber'] ) ) . '</div></td><td>' . esc_html( $b['no_resit'] ?: '—' ) . '</td><td>'
					. pk_admin_files_cell( $b ) . '</td><td>' . pk_badge( 'status_bayar', $b['status'] ) . '</td><td class="pk-actions">';
				if ( pk_self_review_blocked( pk_is_own_pelan( $p ) ) ) { echo '<span class="pk-chip pk-chip-alt" title="Bayaran anda sendiri perlu disahkan oleh AJK lain">Rekod anda — AJK lain sahkan</span> '; }
				else {
					if ( 'sah' !== $b['status'] ) { echo pk_post_btn( 'pk_bayaran_status', [ 'id' => $b['id'], 'status' => 'sah' ], 'Sahkan', 'button-primary' ); }
					if ( 'menunggu' === $b['status'] ) { echo pk_post_btn( 'pk_bayaran_status', [ 'id' => $b['id'], 'status' => 'ditolak' ], 'Tolak' ); }
				}
				echo pk_post_btn( 'pk_del_bayaran', [ 'id' => $b['id'] ], 'Padam', 'pk-link-del', 'Padam rekod bayaran ini?' );
				echo '</td></tr>';
				if ( $b['catatan'] ) { echo '<tr><td colspan="7" class="description">↳ ' . esc_html( $b['catatan'] ) . '</td></tr>'; }
			}
			echo '</tbody></table>';
		}
		echo '<div class="pk-pelan-foot">';
		if ( 'batal' !== $p['status'] ) { echo pk_post_btn( 'pk_pelan_status', [ 'id' => $p['id'], 'status' => 'batal' ], 'Batalkan pelan', 'pk-link-del', 'Batalkan pelan ini?' ); }
		else { echo pk_post_btn( 'pk_pelan_status', [ 'id' => $p['id'], 'status' => 'aktif' ], 'Aktifkan semula' ); }
		echo pk_post_btn( 'pk_del_pelan', [ 'id' => $p['id'] ], 'Padam pelan & semua bayaran', 'pk-link-del', 'Padam pelan ini berserta semua rekod bayarannya?' );
		echo '</div></div>';
	}

	$active = [];
	foreach ( $pl as $p ) { if ( 'aktif' === $p['status'] ) { $active[ $p['id'] ] = $p['tajuk'] . ' (baki ' . pk_rm( max( 0, $p['jumlah'] - $p['dibayar'] ) ) . ')'; } }

	// record payment
	echo '<div class="pk-grid2">';
	if ( $active ) {
		echo '<form class="pk-subform" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pk_add_bayaran', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pk_add_bayaran">';
		echo '<h3>Rekod Bayaran / Ansuran</h3><p><label>Untuk<br>' . pk_select( 'pelan_id', $active, '', '', null ) . '</label></p>';
		echo '<p class="pk-inline"><label>Jumlah (RM)<br><input type="number" step="0.01" min="0.01" name="jumlah" required></label><label>Tarikh<br><input type="date" name="tarikh" value="' . esc_attr( pk_today() ) . '"></label></p>';
		echo '<p class="pk-inline"><label>Kaedah<br>' . pk_select( 'kaedah', pk_label( 'kaedah' ), 'tunai', '', null ) . '</label><label>No. Resit<br><input type="text" name="no_resit"></label></p>';
		echo '<p class="pk-inline"><label>Bukti bayaran (pilihan)<br><input type="file" name="bukti" accept="image/*,application/pdf"></label><label>Resit rasmi masjid (pilihan)<br><input type="file" name="resit" accept="image/*,application/pdf"></label></p><p><label>Catatan<br><input type="text" name="catatan" class="widefat"></label></p>';
		echo '<p><label><input type="checkbox" name="sah" value="1" checked> Terus sahkan (bayaran diterima)</label></p><p><button class="button button-primary">Simpan Bayaran</button></p></form>';
	}

	// add plan
	$sug = wp_parse_args( $suggest, [ 'tajuk' => '', 'jumlah' => '', 'kategori' => 'lain', 'liputan' => '' ] );
	echo '<form class="pk-subform" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pk-plan>' . wp_nonce_field( 'pk_add_pelan', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pk_add_pelan"><input type="hidden" name="jenis" value="' . esc_attr( $jenis ) . '"><input type="hidden" name="ref_id" value="' . (int) $ref_id . '">';
	echo '<h3>Tambah Pelan Bayaran</h3>';
	if ( 'khairat' === $jenis ) {
		$d = (float) pk_opt( 'yuran_daftar' ); $t = (float) pk_opt( 'yuran_tahunan' );
		echo '<p class="pk-presets">Pantas: '
			. '<button type="button" class="button button-small" data-preset=\'' . esc_attr( wp_json_encode( [ 'tajuk' => "Pendaftaran + Yuran Tahunan $yr", 'jumlah' => $d + $t, 'kategori' => 'daftar', 'liputan' => (string) $yr ] ) ) . '\'>Ahli baharu ' . pk_rm( $d + $t ) . '</button> '
			. '<button type="button" class="button button-small" data-preset=\'' . esc_attr( wp_json_encode( [ 'tajuk' => "Yuran Tahunan $yr", 'jumlah' => $t, 'kategori' => 'tahunan', 'liputan' => (string) $yr ] ) ) . '\'>Tahunan ' . $yr . ' ' . pk_rm( $t ) . '</button> '
			. '<button type="button" class="button button-small" data-preset=\'' . esc_attr( wp_json_encode( [ 'tajuk' => 'Yuran Tahunan ' . ( $yr + 1 ), 'jumlah' => $t, 'kategori' => 'tahunan', 'liputan' => (string) ( $yr + 1 ) ] ) ) . '\'>Tahunan ' . ( $yr + 1 ) . '</button> '
			. '<button type="button" class="button button-small" data-preset=\'' . esc_attr( wp_json_encode( [ 'tajuk' => 'Tunggakan ' . ( $yr - 1 ), 'jumlah' => $t, 'kategori' => 'tunggakan', 'liputan' => (string) ( $yr - 1 ) ] ) ) . '\'>Tunggakan ' . ( $yr - 1 ) . '</button></p>';
	}
	echo '<p><label>Tajuk<br><input type="text" name="tajuk" class="widefat" required value="' . esc_attr( $sug['tajuk'] ) . '"></label></p>';
	echo '<p class="pk-inline"><label>Kategori<br>' . pk_select( 'kategori', pk_label( 'kategori' ), $sug['kategori'], '', null ) . '</label><label>Jumlah (RM)<br><input type="number" step="0.01" min="0" name="jumlah" required value="' . esc_attr( $sug['jumlah'] ) . '"></label></p>';
	if ( 'khairat' === $jenis ) { echo '<p><label>Liputan tahun <small>(cth. 2025,2026)</small><br><input type="text" name="liputan" value="' . esc_attr( $sug['liputan'] ) . '"></label></p>'; }
	echo '<p class="pk-inline"><label>Cara<br>' . pk_select( 'cara', pk_label( 'mod' ), 'sekaligus', 'data-pk-cara', null ) . '</label><label class="pk-ans">Bil. ansuran<br><input type="number" name="bil_ansuran" min="2" max="36" value="3"></label><label class="pk-ans">Amaun sebulan (RM)<br><input type="number" step="0.01" name="amaun_ansuran" placeholder="auto"></label></p>';
	echo '<p><label>Catatan<br><input type="text" name="catatan" class="widefat"></label></p><p><button class="button">Tambah Pelan</button></p></form>';
	echo '</div></div>';
}

/** Proof + official receipt viewer links, with an inline upload/replace form (AJK). */
function pk_admin_files_cell( $b ) {
	$h = '<div class="pk-files">';
	$h .= $b['bukti'] ? pk_file_link( pk_bukti_url( $b['bukti'] ), $b['bukti'], 'Bukti' ) : '<span class="pk-nofile">Tiada bukti</span>';
	$h .= $b['resit'] ? pk_file_link( pk_bukti_url( $b['resit'] ), $b['resit'], 'Resit rasmi', 'pk-file-resit' ) : '';
	$h .= '</div><details class="pk-upl"><summary>' . ( $b['resit'] || $b['bukti'] ? 'Tukar / tambah fail' : 'Muat naik fail' ) . '</summary>'
		. '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pk_bayaran_fail', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="pk_bayaran_fail"><input type="hidden" name="id" value="' . (int) $b['id'] . '">'
		. '<label>Resit rasmi masjid<input type="file" name="resit" accept="image/*,application/pdf"></label>'
		. '<label>Bukti bayaran<input type="file" name="bukti" accept="image/*,application/pdf"></label>'
		. '<label>No. Resit<input type="text" name="no_resit" value="' . esc_attr( $b['no_resit'] ) . '"></label>'
		. '<button class="button button-small button-primary">Simpan</button></form></details>';
	return $h;
}

function pk_file_link( $url, $rel, $label, $cls = '' ) {
	$pdf = str_ends_with( strtolower( $rel ), '.pdf' );
	return '<a href="' . esc_url( $url ) . '" target="_blank" class="pk-file ' . esc_attr( $cls ) . '" data-pk-view="' . ( $pdf ? 'pdf' : 'img' ) . '">'
		. ( $pdf ? '<span class="dashicons dashicons-media-document"></span>' : '<img src="' . esc_url( $url ) . '" alt="" loading="lazy">' )
		. '<span>' . esc_html( $label ) . '</span></a>';
}

add_action( 'admin_post_pk_bayaran_fail', function () {
	pk_guard( 'pk_bayaran_fail' );
	global $wpdb;
	$id = (int) pk_p( 'id' );
	$b  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'bayaran' ) . ' WHERE id=%d', $id ), ARRAY_A );
	if ( ! $b ) { pk_back( 'Rekod bayaran tidak ditemui.' ); }
	$upd = [ 'no_resit' => pk_p( 'no_resit' ) ];
	foreach ( [ 'resit', 'bukti' ] as $f ) {
		$r = pk_store_upload( $f );
		if ( is_wp_error( $r ) ) { pk_back( $r->get_error_message() ); }
		if ( $r ) { $upd[ $f ] = $r; }
	}
	$wpdb->update( pk_t( 'bayaran' ), $upd, [ 'id' => $id ] );
	$p = pk_get_pelan( $b['pelan_id'] );
	if ( $p && 'khairat' === $p['jenis'] ) { pk_log( $p['ref_id'], 'fail_bayaran', 'Fail/resit dikemas kini untuk bayaran ' . pk_rm( $b['jumlah'] ) . ' (' . $b['tarikh'] . ')' ); }
	pk_back( 'Fail bayaran disimpan.' );
} );

function pk_post_btn( $action, $fields, $label, $class = '', $confirm = '' ) {
	$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pk-inline-form"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	foreach ( $fields as $k => $v ) { $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">'; }
	return $h . '<button class="button button-small ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

add_action( 'admin_post_pk_add_pelan', function () {
	pk_guard( 'pk_add_pelan' );
	$jenis = 'korban' === pk_p( 'jenis' ) ? 'korban' : 'khairat';
	$liputan = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', pk_p( 'liputan' ) ) ) );
	pk_pelan_create( [
		'jenis' => $jenis, 'ref_id' => (int) pk_p( 'ref_id' ), 'kategori' => array_key_exists( pk_p( 'kategori' ), pk_label( 'kategori' ) ) ? pk_p( 'kategori' ) : 'lain',
		'liputan' => $liputan, 'tajuk' => pk_p( 'tajuk' ), 'jumlah' => pk_p( 'jumlah' ), 'cara' => pk_p( 'cara' ),
		'bil_ansuran' => pk_p( 'bil_ansuran' ), 'amaun_ansuran' => pk_p( 'amaun_ansuran' ), 'catatan' => pk_p( 'catatan' ),
	] );
	pk_back( 'Pelan bayaran ditambah.' );
} );

add_action( 'admin_post_pk_add_bayaran', function () {
	pk_guard( 'pk_add_bayaran' );
	$pid = (int) pk_p( 'pelan_id' );
	if ( ! pk_get_pelan( $pid ) ) { pk_back( 'Pelan tidak ditemui.' ); }
	$bukti = pk_store_upload( 'bukti' );
	if ( is_wp_error( $bukti ) ) { pk_back( $bukti->get_error_message() ); }
	$resit = pk_store_upload( 'resit' );
	if ( is_wp_error( $resit ) ) { pk_back( $resit->get_error_message() ); }
	if ( pk_self_review_blocked( pk_is_own_pelan( pk_get_pelan( $pid ) ) ) ) { $_POST['sah'] = ''; } // own record: stays 'menunggu' for another AJK
	pk_bayaran_create( [ 'resit' => (string) $resit,
		'pelan_id' => $pid, 'jumlah' => pk_p( 'jumlah' ), 'tarikh' => pk_date_in( pk_p( 'tarikh' ) ) ?: pk_today(), 'kaedah' => pk_p( 'kaedah' ),
		'no_resit' => pk_p( 'no_resit' ), 'bukti' => (string) $bukti, 'status' => pk_p( 'sah' ) ? 'sah' : 'menunggu', 'sumber' => 'manual', 'catatan' => pk_p( 'catatan' ),
	] );
	pk_back( 'Bayaran direkodkan.' );
} );

add_action( 'admin_post_pk_bayaran_status', function () {
	pk_guard( 'pk_bayaran_status' );
	global $wpdb;
	$id = (int) pk_p( 'id' ); $st = pk_p( 'status' );
	if ( ! in_array( $st, [ 'sah', 'ditolak', 'menunggu' ], true ) ) { pk_back(); }
	$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'bayaran' ) . ' WHERE id=%d', $id ), ARRAY_A );
	if ( $b ) {
		$pp  = pk_get_pelan( $b['pelan_id'] );
		$own = pk_is_own_pelan( $pp );
		if ( pk_self_review_blocked( $own ) ) { pk_back( 'Bayaran untuk rekod anda sendiri perlu disemak dan disahkan oleh AJK lain.' ); }
		if ( $own && 'khairat' === $pp['jenis'] ) { pk_log( $pp['ref_id'], 'semak_sendiri', 'Pentadbir menukar status bayaran sendiri kepada ' . $st . ' (' . pk_rm( $b['jumlah'] ) . ')' ); }
		$wpdb->update( pk_t( 'bayaran' ), [ 'status' => $st, 'direkod_oleh' => get_current_user_id() ], [ 'id' => $id ] );
		pk_pelan_refresh( (int) $b['pelan_id'] );
		// verifying the first payment of a pending khairat application activates it
		$p = pk_get_pelan( $b['pelan_id'] );
		if ( 'sah' === $st && $p && 'khairat' === $p['jenis'] && pk_p( 'aktifkan', '1' ) ) {
			$a = pk_get_ahli( $p['ref_id'] );
			if ( $a && 'menunggu' === $a['status'] ) { pk_save_ahli_data( [ 'status' => 'aktif' ], (int) $a['id'] ); }
		}
		if ( 'sah' === $st && $p && 'korban' === $p['jenis'] ) {
			$k = pk_get_korban( $p['ref_id'] );
			if ( $k && 'menunggu' === $k['status'] ) { pk_save_korban_data( [ 'status' => 'disahkan' ], (int) $k['id'] ); }
		}
	}
	pk_back( 'sah' === $st ? 'Bayaran disahkan.' : 'Status bayaran dikemas kini.' );
} );

add_action( 'admin_post_pk_del_bayaran', function () {
	pk_guard( 'pk_del_bayaran' );
	global $wpdb;
	$id = (int) pk_p( 'id' );
	$pid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT pelan_id FROM ' . pk_t( 'bayaran' ) . ' WHERE id=%d', $id ) );
	$wpdb->delete( pk_t( 'bayaran' ), [ 'id' => $id ] );
	if ( $pid ) { pk_pelan_refresh( $pid ); }
	pk_back( 'Rekod bayaran dipadam.' );
} );

add_action( 'admin_post_pk_pelan_status', function () {
	pk_guard( 'pk_pelan_status' );
	global $wpdb;
	$id = (int) pk_p( 'id' );
	$wpdb->update( pk_t( 'pelan' ), [ 'status' => 'batal' === pk_p( 'status' ) ? 'batal' : 'aktif' ], [ 'id' => $id ] );
	pk_pelan_refresh( $id );
	pk_back( 'Pelan dikemas kini.' );
} );

add_action( 'admin_post_pk_del_pelan', function () {
	pk_guard( 'pk_del_pelan' );
	pk_delete_pelan( (int) pk_p( 'id' ) );
	pk_back( 'Pelan dipadam.' );
} );

/* ================================================================= list helpers */

function pk_pager( $total, $per, $cur ) {
	$pages = (int) ceil( $total / $per );
	if ( $pages < 2 ) { return ''; }
	return '<div class="tablenav-pages">' . paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $cur, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›' ] ) . '</div>';
}

function pk_filter_links( $param, $opts, $counts, $base ) {
	$cur = sanitize_key( pk_gs( $param ) );
	$h   = '<ul class="subsubsub">';
	$all = array_sum( $counts );
	$h  .= '<li><a href="' . esc_url( remove_query_arg( [ $param, 'paged' ], $base ) ) . '" class="' . ( '' === $cur ? 'current' : '' ) . '">Semua <span class="count">(' . $all . ')</span></a></li>';
	foreach ( $opts as $k => $l ) {
		$h .= '<li> | <a href="' . esc_url( add_query_arg( [ $param => $k, 'paged' => false ], $base ) ) . '" class="' . ( $cur === $k ? 'current' : '' ) . '">' . esc_html( $l ) . ' <span class="count">(' . (int) ( $counts[ $k ] ?? 0 ) . ')</span></a></li>';
	}
	return $h . '</ul>';
}

/* ================================================================= settings */

function pk_page_tetapan() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$s     = pk_settings();
	$pages = (array) get_option( 'pk_pages', [] );
	pk_admin_header( 'Tetapan Kariah & Khairat' );
	pk_private_dir_warning();
	$row = function ( $label, $html, $help = '' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $html . ( $help ? '<p class="description">' . $help . '</p>' : '' ) . '</td></tr>';
	};
	$in  = fn( $k, $attrs = 'class="regular-text"', $type = 'text' ) => '<input type="' . $type . '" name="s[' . $k . ']" value="' . esc_attr( is_array( $s[ $k ] ) ? implode( ',', $s[ $k ] ) : $s[ $k ] ) . '" ' . $attrs . '>';
	$ta  = fn( $k, $rows = 3 ) => '<textarea name="s[' . $k . ']" rows="' . $rows . '" class="large-text">' . esc_textarea( $s[ $k ] ) . '</textarea>';
	$sel = fn( $k, $opts ) => pk_select( 's[' . $k . ']', $opts, $s[ $k ], '', null );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-box pk-settings">
		<?php wp_nonce_field( 'pk_tetapan' ); ?><input type="hidden" name="action" value="pk_tetapan">

		<h2>Maklumat masjid</h2>
		<table class="form-table" role="presentation"><?php
			$row( 'Nama masjid / surau', $in( 'nama_masjid', 'class="regular-text" required' ), 'Dipaparkan pada borang, cetakan dan e-mel notifikasi.' );
			$row( 'Alamat', $ta( 'alamat_masjid', 2 ), 'Untuk kepala borang cetakan (pilihan).' );
		?></table>

		<h2>Yuran &amp; bayaran</h2>
		<table class="form-table" role="presentation"><?php
			$row( 'Yuran Khairat', '<span class="pk-inline"><label>Pendaftaran (RM)<br>' . $in( 'yuran_daftar', 'step="0.01" min="0"', 'number' ) . '</label><label>Tahunan (RM)<br>' . $in( 'yuran_tahunan', 'step="0.01" min="0"', 'number' ) . '</label><label>Prefix No. Ahli<br>' . $in( 'prefix_ahli', 'size="6" maxlength="10"' ) . '</label></span>', 'No. Ahli dijana sebagai <code>PREFIX-0001</code> apabila ahli diaktifkan.' );
			$row( 'Korban / Aqiqah', '<span class="pk-inline"><label>Musim (Hijrah)<br>' . $in( 'musim_korban', 'size="8"' ) . '</label><label>Tahun Masihi<br>' . $in( 'tahun_korban_m', 'size="6"' ) . '</label><label>Harga sebahagian (RM)<br>' . $in( 'harga_bahagian', 'step="0.01" min="0"', 'number' ) . '</label></span>', 'Tukar musim setiap tahun selepas Aidiladha — rekod lama kekal di bawah musim masing-masing.' );
			$row( 'Ansuran', '<label>Maksimum bilangan bulan pada borang awam ' . $in( 'max_ansuran', 'min="2" max="36" class="small-text"', 'number' ) . '</label>' );
			$row( 'Akaun bank', $in( 'bank', 'class="large-text" placeholder="cth. Bank Islam — MASJID ANDA — 1234 5678 9012"' ) );
			$row( 'Imej kod QR', '<span class="pk-inline"><label>URL imej<br>' . $in( 'qr_url', 'class="regular-text" id="pk-qr-url"', 'url' ) . '</label><label>&nbsp;<br><button type="button" class="button" data-pk-media="#pk-qr-url">Pilih dari Media</button></label><label>Label<br>' . $in( 'label_qr', 'size="14" placeholder="DuitNow QR"' ) . '</label></span>'
				. ( $s['qr_url'] ? '<p><img src="' . esc_url( $s['qr_url'] ) . '" alt="" style="max-width:120px;border:1px solid #ddd;border-radius:6px;padding:4px;background:#fff"></p>' : '' ) );
		?></table>

		<h2>Borang &amp; teks</h2>
		<table class="form-table" role="presentation"><?php
			$row( 'Borang dalam talian', '<label><input type="checkbox" name="s[buka_khairat]" value="1" ' . checked( $s['buka_khairat'], 1, false ) . '> Buka pendaftaran Khairat</label><br><label><input type="checkbox" name="s[buka_korban]" value="1" ' . checked( $s['buka_korban'], 1, false ) . '> Buka pendaftaran Korban/Aqiqah</label>' );
			$row( 'Senarai kawasan / taman', $ta( 'kawasan', 4 ), 'Satu setiap baris — dicadangkan pada borang dan digunakan dalam infografik.' );
			$row( 'Terma & syarat khairat', $ta( 'terma_khairat', 7 ), 'Satu syarat setiap baris. Boleh guna <code>{masjid}</code> <code>{yuran_daftar}</code> <code>{yuran_tahunan}</code>.' );
			$row( 'Akad wakalah korban', $ta( 'teks_wakalah', 3 ), 'Boleh guna <code>{masjid}</code>.' );
			$row( 'Nota khairat', $ta( 'nota_khairat', 2 ), 'Dipaparkan selepas pendaftaran berjaya.' );
			$row( 'Nota korban', $ta( 'nota_korban', 2 ) );
			$row( 'ID lampiran borang PDF', $in( 'fail_borang' ), 'ID Media (dipisahkan koma) untuk shortcode <code>[pk_muat_turun]</code>. ID boleh dilihat pada URL semasa membuka fail di Media.' );
		?></table>

		<h2>Halaman</h2>
		<table class="form-table" role="presentation"><?php
			foreach ( pk_page_map() as $k => [ $sc, $title ] ) {
				$cur = (int) ( $pages[ $k ] ?? 0 ) ?: pk_find_page( $k );
				$dd  = wp_dropdown_pages( [ 'name' => 'pages[' . $k . ']', 'selected' => $cur, 'show_option_none' => '— Cari automatik —', 'option_none_value' => 0, 'echo' => 0 ] );
				$row( $title, $dd . ' <code>[' . $sc . ']</code>' . ( $cur ? ' <a href="' . esc_url( get_permalink( $cur ) ) . '" target="_blank">Lihat ↗</a>' : '' ) );
			}
		?></table>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pk-persediaan' ) ); ?>">Cipta halaman yang belum ada…</a></p>

		<h2>Rupa &amp; integrasi</h2>
		<table class="form-table" role="presentation"><?php
			$row( 'Warna', '<span class="pk-inline"><label>Utama<br>' . $in( 'warna_utama', '', 'color' ) . '</label><label>Aksen<br>' . $in( 'warna_aksen', '', 'color' ) . '</label></span>', 'Digunakan pada borang, Portal Ahli dan butang.' );
			$row( 'Ikon Font Awesome', $sel( 'fontawesome', [ 'auto' => 'Automatik — muat dari CDN jika tema belum memuatkannya', 'cdn' => 'Sentiasa muat dari CDN (jsDelivr)', 'off' => 'Jangan muat (tema sudah ada / tidak mahu ikon)' ] ) );
			$row( 'Sumber alamat IP', $sel( 'ip_sumber', [ 'remote' => 'REMOTE_ADDR (lalai — kebanyakan hos)', 'cloudflare' => 'Cloudflare — CF-Connecting-IP', 'xff' => 'Proksi songsang tempatan — X-Forwarded-For' ] ), 'Digunakan untuk had cubaan (anti-spam / brute force). Pilih Cloudflare hanya jika pelayan anda menerima trafik dari Cloudflare sahaja — jika tidak, header boleh dipalsukan.' );
			$row( 'E-mel notifikasi AJK', $in( 'emel_notis', 'class="regular-text"', 'email' ), 'Memerlukan e-mel (SMTP) laman web berfungsi.' );
			$row( 'Nyahpasang', '<label><input type="checkbox" name="s[padam_data]" value="1" ' . checked( $s['padam_data'], 1, false ) . '> Padam <strong>semua</strong> rekod ahli, korban, bayaran dan fail bukti apabila plugin ini dipadam</label>', 'Biarkan tidak bertanda jika anda mungkin memasang semula plugin. Tindakan ini tidak boleh dibatalkan.' );
		?></table>

		<p><button class="button button-primary">Simpan Tetapan</button></p>
		<h2>Shortcode</h2>
		<p><code>[pk_borang_khairat]</code> · <code>[pk_borang_korban]</code> · <code>[pk_semak]</code> · <code>[pk_portal]</code> · <code>[pk_muat_turun ids="12,13"]</code></p>
	</form></div>
	<?php
}

add_action( 'admin_post_pk_tetapan', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	check_admin_referer( 'pk_tetapan' );
	$in  = wp_unslash( (array) ( $_POST['s'] ?? [] ) );
	$def = pk_default_settings();
	$cur = pk_settings();
	$out = [];
	$str = fn( $k, $max = 190 ) => mb_substr( sanitize_text_field( is_scalar( $in[ $k ] ?? '' ) ? (string) ( $in[ $k ] ?? '' ) : '' ), 0, $max );
	foreach ( $def as $k => $v ) {
		switch ( true ) {
			case in_array( $k, [ 'buka_khairat', 'buka_korban', 'padam_data' ], true ):
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1; break;
			case 'fail_borang' === $k:
				$out[ $k ] = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $in[ $k ] ?? '' ) ) ) ) ); break;
			case in_array( $k, [ 'kawasan', 'nota_khairat', 'nota_korban', 'terma_khairat', 'teks_wakalah', 'alamat_masjid' ], true ):
				$out[ $k ] = mb_substr( sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) ), 0, 5000 ); break;
			case in_array( $k, [ 'yuran_daftar', 'yuran_tahunan', 'harga_bahagian' ], true ):
				$out[ $k ] = pk_money( $in[ $k ] ?? $v ); break;
			case 'max_ansuran' === $k:
				$out[ $k ] = max( 2, min( 36, (int) ( $in[ $k ] ?? 12 ) ) ); break;
			case 'qr_url' === $k:
				$out[ $k ] = esc_url_raw( (string) ( $in[ $k ] ?? '' ), [ 'https', 'http' ] ); break;
			case 'emel_notis' === $k:
				$out[ $k ] = sanitize_email( (string) ( $in[ $k ] ?? '' ) ); break;
			case in_array( $k, [ 'warna_utama', 'warna_aksen' ], true ):
				$out[ $k ] = sanitize_hex_color( (string) ( $in[ $k ] ?? '' ) ) ?: $v; break;
			case 'fontawesome' === $k:
				$out[ $k ] = in_array( $in[ $k ] ?? '', [ 'auto', 'cdn', 'off' ], true ) ? $in[ $k ] : 'auto'; break;
			case 'ip_sumber' === $k:
				$out[ $k ] = in_array( $in[ $k ] ?? '', [ 'remote', 'cloudflare', 'xff' ], true ) ? $in[ $k ] : 'remote'; break;
			case 'prefix_ahli' === $k:
				$out[ $k ] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $str( $k, 10 ) ) ) ?: 'KK'; break;
			case 'nama_masjid' === $k:
				$out[ $k ] = $str( $k ) ?: get_bloginfo( 'name' ); break;
			default:
				$out[ $k ] = array_key_exists( $k, $in ) ? $str( $k ) : ( $cur[ $k ] ?? $v );
		}
	}
	update_option( 'pk_settings', $out, false );
	$pg = [];
	foreach ( array_keys( pk_page_map() ) as $k ) {
		$id = (int) ( $_POST['pages'][ $k ] ?? 0 );
		if ( $id && 'page' === get_post_type( $id ) ) { $pg[ $k ] = $id; }
	}
	update_option( 'pk_pages', $pg, false );
	foreach ( array_keys( pk_page_map() ) as $k ) { wp_cache_delete( 'pk_pg_' . $k, 'pk' ); }
	pk_back( 'Tetapan disimpan.' );
} );
