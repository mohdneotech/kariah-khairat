<?php
/**
 * WordPress Dashboard (wp-admin/index.php) widgets: "Ahli Kariah" and "Khairat & Bayaran".
 * Reuses pk_dashboard_data() from dashboard.php; cached for 5 minutes, cleared when the
 * kariah tables change (any pk_* admin-post action or a public submission).
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( PK_CAP ) ) { return; }
	wp_add_dashboard_widget( 'pk_dw_kariah', 'Ahli Kariah', 'pk_dw_kariah' );
	wp_add_dashboard_widget( 'pk_dw_khairat', 'Khairat & Bayaran ' . pk_year(), 'pk_dw_khairat' );
} );

/** Cached copy of the main dashboard numbers + a few extra counts for the widgets. */
function pk_dw_data() {
	$d = get_transient( 'pk_dw_data' );
	if ( is_array( $d ) && ( $d['_v'] ?? 0 ) === 1 ) { return $d; }
	global $wpdb;
	$d = pk_dashboard_data();
	$A = pk_t( 'ahli' );
	$d['jumlah']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $A" );
	$d['baru_bln']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A WHERE created_at >= %s AND status<>'ditolak'", current_time( 'Y-m-01 00:00:00' ) ) );
	$d['baru_thn']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A WHERE created_at >= %s AND status<>'ditolak'", current_time( 'Y-01-01 00:00:00' ) ) );
	$d['tanggungan'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . pk_t( 'tanggungan' ) . " t JOIN $A a ON a.id=t.ahli_id WHERE a.status='aktif'" );
	$d['kutip_bln'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(jumlah),0) FROM ' . pk_t( 'bayaran' ) . " WHERE status='sah' AND tarikh >= %s", current_time( 'Y-m-01' ) ) );
	$d['_v']        = 1;
	unset( $d['age'] ); // not used here; keeps the transient small
	set_transient( 'pk_dw_data', $d, 5 * MINUTE_IN_SECONDS );
	return $d;
}
function pk_dw_flush() { delete_transient( 'pk_dw_data' ); }
add_action( 'admin_init', function () {
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_REQUEST['action'] ) && 0 === strpos( (string) $_REQUEST['action'], 'pk_' ) ) { pk_dw_flush(); }
} );
add_action( 'init', function () {
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! is_admin() ) {
		foreach ( array_keys( $_POST ) as $k ) { if ( 0 === strpos( (string) $k, 'pk_' ) ) { pk_dw_flush(); break; } }
	}
} );

function pk_dw_url( $page, $args = [] ) { return add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ); }

/** Horizontal stacked bar + legend. $parts = [ [label, n, colour-class, url?], … ] */
function pk_dw_stack( $parts ) {
	$tot = array_sum( array_column( $parts, 1 ) );
	$h   = '<div class="pkdw-stack" role="img" aria-label="' . esc_attr( implode( ', ', array_map( fn( $p ) => $p[0] . ' ' . $p[1], $parts ) ) ) . '">';
	foreach ( $parts as $p ) { if ( $tot && $p[1] ) { $h .= '<i class="pkdw-c-' . esc_attr( $p[2] ) . '" style="width:' . esc_attr( round( $p[1] / $tot * 100, 2 ) ) . '%"></i>'; } }
	$h .= '</div><ul class="pkdw-legend">';
	foreach ( $parts as $p ) {
		$lbl = '<span class="pkdw-dot pkdw-c-' . esc_attr( $p[2] ) . '"></span>' . esc_html( $p[0] ) . ' <b>' . number_format_i18n( $p[1] ) . '</b>';
		$h  .= '<li>' . ( ! empty( $p[3] ) ? '<a href="' . esc_url( $p[3] ) . '">' . $lbl . '</a>' : $lbl ) . '</li>';
	}
	return $h . '</ul>';
}

function pk_dw_tile( $label, $value, $sub = '', $url = '', $cls = '' ) {
	$in = '<span>' . esc_html( $label ) . '</span><b>' . $value . '</b>' . ( $sub ? '<small>' . $sub . '</small>' : '' );
	return $url ? '<a class="pkdw-tile ' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . $in . '</a>' : '<div class="pkdw-tile ' . esc_attr( $cls ) . '">' . $in . '</div>';
}

function pk_dw_kariah() {
	$d  = pk_dw_data();
	$st = $d['status'];
	$n  = fn( $k ) => (int) ( $st[ $k ] ?? 0 );
	echo '<div class="pkdw">';
	echo '<div class="pkdw-tiles">';
	echo pk_dw_tile( 'Ahli aktif', number_format_i18n( $d['aktif'] ), number_format_i18n( $d['jumlah'] ) . ' rekod keseluruhan', pk_dw_url( 'pk-ahli', [ 'status' => 'aktif' ] ) );
	echo pk_dw_tile( 'Jiwa dilindungi', number_format_i18n( $d['jiwa'] ), 'ahli + pasangan + ' . number_format_i18n( $d['tanggungan'] ) . ' tanggungan' );
	echo pk_dw_tile( 'Ahli baharu', number_format_i18n( $d['baru_bln'] ), 'bulan ini · ' . number_format_i18n( $d['baru_thn'] ) . ' tahun ' . (int) $d['yr'] );
	echo pk_dw_tile( 'Guna Portal Ahli', number_format_i18n( $d['portal'] ), $d['aktif'] ? round( $d['portal'] / $d['aktif'] * 100 ) . '% ahli aktif' : '—' );
	echo '</div>';

	echo '<h4>Status keahlian</h4>';
	echo pk_dw_stack( [
		[ 'Aktif', $n( 'aktif' ), 'ok', pk_dw_url( 'pk-ahli', [ 'status' => 'aktif' ] ) ],
		[ 'Menunggu', $n( 'menunggu' ), 'warn', pk_dw_url( 'pk-ahli', [ 'status' => 'menunggu' ] ) ],
		[ 'Tidak aktif', $n( 'tidak_aktif' ), 'mute', pk_dw_url( 'pk-ahli', [ 'status' => 'tidak_aktif' ] ) ],
		[ 'Meninggal', $n( 'meninggal' ), 'dark', pk_dw_url( 'pk-ahli', [ 'status' => 'meninggal' ] ) ],
		[ 'Ditolak', $n( 'ditolak' ), 'bad', pk_dw_url( 'pk-ahli', [ 'status' => 'ditolak' ] ) ],
	] );

	$kaw = array_values( array_filter( $d['kaw'], fn( $r ) => (int) $r['n'] > 0 ) );
	if ( $kaw ) {
		$max = max( array_map( fn( $r ) => (int) $r['n'], $kaw ) );
		echo '<h4>Kawasan teratas</h4><ul class="pkdw-bars">';
		foreach ( array_slice( $kaw, 0, 4 ) as $r ) {
			echo '<li><span>' . esc_html( $r['k'] ) . '</span><i><em style="width:' . esc_attr( round( (int) $r['n'] / $max * 100 ) ) . '%"></em></i><b>' . number_format_i18n( (int) $r['n'] ) . '</b></li>';
		}
		echo '</ul>';
	}

	$todo = [];
	if ( $d['pend_a'] ) { $todo[] = '<a href="' . esc_url( pk_dw_url( 'pk-ahli', [ 'status' => 'menunggu' ] ) ) . '">' . (int) $d['pend_a'] . ' pendaftaran menunggu pengesahan</a>'; }
	if ( $d['pend_u'] ) { $todo[] = '<a href="' . esc_url( pk_dw_url( 'pk-kemaskini' ) ) . '">' . (int) $d['pend_u'] . ' permohonan kemas kini ahli</a>'; }
	echo $todo ? '<p class="pkdw-alert"><span class="dashicons dashicons-warning"></span> ' . implode( ' · ', $todo ) . '</p>' : '<p class="pkdw-okline"><span class="dashicons dashicons-yes-alt"></span> Tiada pendaftaran atau kemas kini menunggu.</p>';
	echo '<p class="pkdw-foot"><a href="' . esc_url( pk_dw_url( 'pk-dashboard' ) ) . '">Papan Pemuka Kariah →</a> <a href="' . esc_url( pk_dw_url( 'pk-ahli-baru' ) ) . '">+ Daftar Ahli</a></p>';
	echo '</div>';
}

function pk_dw_khairat() {
	$d     = pk_dw_data();
	$yr    = (int) $d['yr'];
	$belum = max( 0, $d['aktif'] - $d['paid'] - $d['ansur'] );
	$pct   = $d['aktif'] ? round( $d['paid'] / $d['aktif'] * 100 ) : 0;
	echo '<div class="pkdw">';
	echo '<div class="pkdw-ring-row"><div class="pkdw-ring" style="--p:' . (int) $pct . '"><b>' . (int) $pct . '%</b></div><div><h4>Yuran khairat ' . $yr . ' dijelaskan</h4>'
		. '<p>' . number_format_i18n( $d['paid'] ) . ' daripada ' . number_format_i18n( $d['aktif'] ) . ' ahli aktif</p></div></div>';
	echo pk_dw_stack( [
		[ 'Selesai', (int) $d['paid'], 'ok', pk_dw_url( 'pk-ahli', [ 'status' => 'aktif', 'bayar' => 'berbayar' ] ) ],
		[ 'Belum selesai bayar', (int) $d['ansur'], 'info', pk_dw_url( 'pk-ahli', [ 'status' => 'aktif', 'bayar' => 'belum' ] ) ],
		[ 'Tiada pelan yuran', $belum, 'bad', pk_dw_url( 'pk-ahli', [ 'status' => 'aktif', 'bayar' => 'belum' ] ) ],
	] );
	echo '<div class="pkdw-tiles">';
	echo pk_dw_tile( 'Kutipan disahkan ' . $yr, esc_html( pk_rm( $d['kutip'] ) ), esc_html( pk_rm( $d['kutip_bln'] ) ) . ' bulan ini' );
	echo pk_dw_tile( 'Baki belum dibayar', esc_html( pk_rm( $d['baki'] ) ), 'semua pelan aktif / ansuran', pk_dw_url( 'pk-bayaran' ), $d['baki'] > 0 ? 'pkdw-red' : '' );
	echo pk_dw_tile( 'Bayaran perlu disemak', number_format_i18n( $d['pend_b'] ), 'bukti bayaran menunggu', pk_dw_url( 'pk-bayaran', [ 'status' => 'menunggu' ] ), $d['pend_b'] ? 'pkdw-warn' : '' );
	echo pk_dw_tile( 'Korban ' . $d['musim'], number_format_i18n( $d['bah'] ) . ' <small>bahagian</small>', (int) $d['kpes'] . ' pendaftaran · ≈ ' . (int) ceil( $d['bah'] / 7 ) . ' ekor lembu' . ( $d['pend_k'] ? ' · ' . (int) $d['pend_k'] . ' menunggu' : '' ), pk_dw_url( 'pk-korban' ) );
	echo '</div>';
	echo '<p class="pkdw-foot"><a href="' . esc_url( pk_dw_url( 'pk-bayaran' ) ) . '">Bayaran &amp; Ansuran →</a> <a href="' . esc_url( pk_dw_url( 'pk-korban' ) ) . '">Korban &amp; Aqiqah →</a></p>';
	echo '</div>';
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'index.php' !== $hook || ! current_user_can( PK_CAP ) ) { return; }
	wp_add_inline_style( 'dashboard', pk_dw_css() );
} );

/** Widget styles (dashboard screen only). */
function pk_dw_css() {
	return '
.pkdw h4{margin:14px 0 6px;font-size:13px;font-weight:600}
.pkdw-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:4px}
.pkdw-tile{display:block;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:9px 11px;color:#1d2327;text-decoration:none;min-width:0}
a.pkdw-tile:hover{border-color:#2271b1;color:#1d2327}
.pkdw-tile span{display:block;font-size:12px;color:#50575e}
.pkdw-tile b{display:block;font-size:22px;line-height:1.25;font-weight:600;color:#14532d}
.pkdw-tile b small{font-size:12px;font-weight:400;color:#50575e}
.pkdw-tile small{display:block;font-size:11.5px;color:#646970;overflow-wrap:anywhere}
.pkdw-tile.pkdw-red b{color:#b32d2e}.pkdw-tile.pkdw-warn{border-color:#dba617;background:#fcf9e8}.pkdw-tile.pkdw-warn b{color:#996800}
.pkdw-stack{display:flex;height:12px;border-radius:6px;overflow:hidden;background:#e2e4e7;margin:4px 0 6px}
.pkdw-stack i{display:block;height:100%}
.pkdw-legend{display:flex;flex-wrap:wrap;gap:4px 14px;margin:0 0 4px;font-size:12px}
.pkdw-legend li{margin:0}.pkdw-legend a{text-decoration:none;color:#1d2327}.pkdw-legend a:hover{color:#2271b1}
.pkdw-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;vertical-align:0}
.pkdw-c-ok{background:#15803d}.pkdw-c-warn{background:#dba617}.pkdw-c-info{background:#2271b1}.pkdw-c-mute{background:#a7aaad}.pkdw-c-dark{background:#3c434a}.pkdw-c-bad{background:#d63638}.pkdw-c-new{background:#72aee6}
.pkdw-bars{margin:0}.pkdw-bars li{display:grid;grid-template-columns:minmax(0,38%) 1fr auto;gap:8px;align-items:center;font-size:12px;margin:0 0 5px}
.pkdw-bars span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pkdw-bars i{display:block;height:8px;background:#e2e4e7;border-radius:4px;overflow:hidden}.pkdw-bars em{display:block;height:100%;background:#15803d}
.pkdw-alert{margin:12px 0 0;padding:7px 10px;background:#fcf9e8;border-left:4px solid #dba617}.pkdw-alert .dashicons{color:#996800}
.pkdw-okline{margin:12px 0 0;color:#50575e}.pkdw-okline .dashicons{color:#15803d}
.pkdw-foot{margin:12px -12px -12px;padding:9px 12px;border-top:1px solid #f0f0f1;background:#f6f7f7;display:flex;flex-wrap:wrap;gap:6px 16px}
.pkdw-ring-row{display:flex;align-items:center;gap:14px;margin-bottom:6px}.pkdw-ring-row h4{margin:0 0 2px}.pkdw-ring-row p{margin:0;color:#50575e}
.pkdw-ring{--p:0;flex:0 0 64px;width:64px;height:64px;border-radius:50%;background:conic-gradient(#15803d calc(var(--p)*1%),#e2e4e7 0);display:grid;place-items:center}
.pkdw-ring b{display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#fff;font-size:14px}
@media (max-width:480px){.pkdw-tiles{grid-template-columns:minmax(0,1fr)}}
';
}
