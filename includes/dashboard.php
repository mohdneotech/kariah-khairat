<?php
defined( 'ABSPATH' ) || exit;

function pk_dashboard_data() {
	global $wpdb;
	$A = pk_t( 'ahli' ); $T = pk_t( 'tanggungan' ); $P = pk_t( 'pelan' ); $B = pk_t( 'bayaran' ); $K = pk_t( 'korban' ); $KP = pk_t( 'korban_peserta' );
	$yr    = pk_year();
	$musim = pk_opt( 'musim_korban' );

	$status = array_map( fn( $r ) => (int) $r->n, $wpdb->get_results( "SELECT status, COUNT(*) n FROM $A GROUP BY status", OBJECT_K ) );
	$aktif  = (int) ( $status['aktif'] ?? 0 );
	$jiwa   = $aktif
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM $A WHERE status='aktif' AND p_nama<>''" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM $T t JOIN $A a ON a.id=t.ahli_id WHERE a.status='aktif'" );
	$paid   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A a WHERE a.status='aktif' AND EXISTS(SELECT 1 FROM $P p WHERE p.jenis='khairat' AND p.ref_id=a.id AND p.status='selesai' AND FIND_IN_SET(%d,p.liputan))", $yr ) );
	$ansur  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A a WHERE a.status='aktif' AND NOT EXISTS(SELECT 1 FROM $P p WHERE p.jenis='khairat' AND p.ref_id=a.id AND p.status='selesai' AND FIND_IN_SET(%d,p.liputan)) AND EXISTS(SELECT 1 FROM $P p WHERE p.jenis='khairat' AND p.ref_id=a.id AND p.status='aktif' AND FIND_IN_SET(%d,p.liputan))", $yr, $yr ) );

	$kutip  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(jumlah),0) FROM $B WHERE status='sah' AND YEAR(tarikh)=%d", $yr ) );
	$baki   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(p.jumlah - COALESCE((SELECT SUM(b.jumlah) FROM $B b WHERE b.pelan_id=p.id AND b.status='sah'),0)),0) FROM $P p WHERE p.status='aktif'" );
	$pend_b = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $B WHERE status='menunggu'" );
	$pend_a = (int) ( $status['menunggu'] ?? 0 );
	$pend_k = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $K WHERE status='menunggu'" );
	$pend_u = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . pk_t( 'kemaskini' ) . " WHERE status='menunggu'" );
	$portal = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $A WHERE status='aktif' AND user_id>0" );
	$bah    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(ps.bahagian),0) FROM $KP ps JOIN $K k ON k.id=ps.korban_id WHERE k.musim=%s AND k.status<>'batal'", $musim ) );
	$kpes   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $K WHERE musim=%s AND status<>'batal'", $musim ) );

	// 12-month series
	$months = []; $labels = [];
	for ( $i = 11; $i >= 0; $i-- ) {
		$ts = strtotime( gmdate( 'Y-m-01', current_time( 'timestamp' ) ) . " -$i months" );
		$months[] = gmdate( 'Y-m', $ts ); $labels[] = date_i18n( 'M y', $ts );
	}
	$from = $months[0] . '-01';
	$reg  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') m, sumber, COUNT(*) n FROM $A WHERE created_at>=%s AND status<>'ditolak' GROUP BY m, sumber", $from ), ARRAY_A );
	$col  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(b.tarikh,'%%Y-%%m') m, p.jenis, SUM(b.jumlah) t FROM $B b JOIN $P p ON p.id=b.pelan_id WHERE b.status='sah' AND b.tarikh>=%s GROUP BY m, p.jenis", $from ), ARRAY_A );
	$s_reg = [ 'online' => array_fill( 0, 12, 0 ), 'manual' => array_fill( 0, 12, 0 ) ];
	foreach ( $reg as $r ) { $i = array_search( $r['m'], $months, true ); if ( false !== $i ) { $s_reg[ 'online' === $r['sumber'] ? 'online' : 'manual' ][ $i ] += (int) $r['n']; } }
	$s_col = [ 'khairat' => array_fill( 0, 12, 0 ), 'korban' => array_fill( 0, 12, 0 ) ];
	foreach ( $col as $r ) { $i = array_search( $r['m'], $months, true ); if ( false !== $i ) { $s_col[ $r['jenis'] ][ $i ] = round( (float) $r['t'], 2 ); } }

	// demographics of active members (from MyKad)
	$bands = [ 'Bawah 30' => [ 0, 29 ], '30–39' => [ 30, 39 ], '40–49' => [ 40, 49 ], '50–59' => [ 50, 59 ], '60–69' => [ 60, 69 ], '70+' => [ 70, 200 ] ];
	$age   = [ 'L' => array_fill_keys( array_keys( $bands ), 0 ), 'P' => array_fill_keys( array_keys( $bands ), 0 ) ];
	$unk   = 0;
	foreach ( $wpdb->get_col( "SELECT no_kp FROM $A WHERE status='aktif'" ) as $kp ) {
		[ $dob, $sex ] = pk_kp_info( $kp );
		$ag = pk_age( $dob );
		if ( null === $ag || ! $sex ) { $unk++; continue; }
		foreach ( $bands as $l => [ $lo, $hi ] ) { if ( $ag >= $lo && $ag <= $hi ) { $age[ $sex ][ $l ]++; break; } }
	}
	$kaw = $wpdb->get_results( "SELECT IF(kawasan='','(Tidak dinyatakan)',kawasan) k, COUNT(*) n FROM $A WHERE status='aktif' GROUP BY k ORDER BY n DESC", ARRAY_A );
	if ( count( $kaw ) > 8 ) { $rest = array_sum( array_column( array_slice( $kaw, 7 ), 'n' ) ); $kaw = array_slice( $kaw, 0, 7 ); $kaw[] = [ 'k' => 'Lain-lain', 'n' => $rest ]; }
	$sek = $wpdb->get_results( "SELECT IF(sektor='','(Tidak dinyatakan)',sektor) k, COUNT(*) n FROM $A WHERE status='aktif' GROUP BY k ORDER BY n DESC", ARRAY_A );
	$hh  = $wpdb->get_results( "SELECT sz, COUNT(*) n FROM (SELECT a.id, 1 + (a.p_nama<>'') + (SELECT COUNT(*) FROM $T t WHERE t.ahli_id=a.id) sz FROM $A a WHERE a.status='aktif') x GROUP BY sz ORDER BY sz", ARRAY_A );
	$hhb = [ '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7+' => 0 ];
	foreach ( $hh as $r ) { $hhb[ (int) $r['sz'] >= 7 ? '7+' : (string) (int) $r['sz'] ] += (int) $r['n']; }
	$hub = $wpdb->get_results( "SELECT IF(t.hubungan='','Lain-lain',t.hubungan) k, COUNT(*) n FROM $T t JOIN $A a ON a.id=t.ahli_id WHERE a.status='aktif' GROUP BY k ORDER BY n DESC", ARRAY_A );

	$mode = $wpdb->get_results( "SELECT p.jenis, p.cara, COUNT(*) n, SUM(p.jumlah) t FROM $P p WHERE p.status<>'batal' GROUP BY p.jenis, p.cara", ARRAY_A );
	$mm   = [ 'khairat' => [ 'sekaligus' => 0, 'ansuran' => 0 ], 'korban' => [ 'sekaligus' => 0, 'ansuran' => 0 ] ];
	foreach ( $mode as $r ) { $mm[ $r['jenis'] ][ $r['cara'] ] = (int) $r['n']; }

	return compact( 'yr', 'musim', 'status', 'aktif', 'jiwa', 'paid', 'ansur', 'kutip', 'baki', 'pend_b', 'pend_a', 'pend_k', 'pend_u', 'portal', 'bah', 'kpes', 'labels', 's_reg', 's_col', 'age', 'unk', 'kaw', 'sek', 'hhb', 'hub', 'mm' );
}

function pk_page_dashboard() {
	global $wpdb;
	$d  = pk_dashboard_data();
	$yr = $d['yr'];
	pk_admin_header( 'Papan Pemuka Kariah', ' <a href="' . esc_url( admin_url( 'admin.php?page=pk-ahli-baru' ) ) . '" class="page-title-action">+ Daftar Ahli</a> <a href="' . esc_url( admin_url( 'admin.php?page=pk-korban-baru' ) ) . '" class="page-title-action">+ Daftar Korban</a>' );
	if ( current_user_can( 'manage_options' ) ) { pk_private_dir_warning(); }
	echo pk_dashboard_my_record();
	$pct = $d['aktif'] ? round( $d['paid'] / $d['aktif'] * 100 ) : 0;
	$pend = $d['pend_a'] + $d['pend_k'] + $d['pend_b'] + $d['pend_u'];
	?>
	<div class="pk-kpis">
		<div><span>Ahli khairat aktif</span><b><?php echo number_format_i18n( $d['aktif'] ); ?></b><small><?php echo number_format_i18n( $d['jiwa'] ); ?> jiwa dilindungi · <?php echo (int) $d['portal']; ?> guna portal</small></div>
		<div><span>Yuran <?php echo (int) $yr; ?> dijelaskan</span><b><?php echo (int) $pct; ?>%</b><small><?php echo (int) $d['paid']; ?> / <?php echo (int) $d['aktif']; ?> ahli · <?php echo (int) $d['ansur']; ?> sedang ansuran</small><div class="pk-bar"><i style="width:<?php echo (int) $pct; ?>%"></i></div></div>
		<div><span>Kutipan disahkan <?php echo (int) $yr; ?></span><b><?php echo esc_html( pk_rm( $d['kutip'] ) ); ?></b><small>khairat + korban/aqiqah</small></div>
		<div><span>Baki belum dibayar</span><b class="pk-red"><?php echo esc_html( pk_rm( $d['baki'] ) ); ?></b><small>semua pelan aktif / ansuran</small></div>
		<div><span>Korban <?php echo esc_html( $d['musim'] ); ?></span><b><?php echo (int) $d['bah']; ?> <small class="pk-inl">bahagian</small></b><small><?php echo (int) $d['kpes']; ?> pendaftaran · ≈ <?php echo (int) ceil( $d['bah'] / 7 ); ?> ekor lembu</small></div>
		<a class="<?php echo $pend ? 'pk-kpi-alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=pk-bayaran&status=menunggu' ) ); ?>"><span>Perlu tindakan</span><b><?php echo (int) $pend; ?></b><small><?php echo (int) $d['pend_a']; ?> ahli · <?php echo (int) $d['pend_k']; ?> korban · <?php echo (int) $d['pend_b']; ?> bayaran · <?php echo (int) $d['pend_u']; ?> kemas kini</small></a>
	</div>

	<div class="pk-charts">
		<div class="pk-chart pk-w2"><h3>Pendaftaran ahli — 12 bulan</h3><div class="pk-cv"><canvas id="pkc-reg" height="220"></canvas></div></div>
		<div class="pk-chart"><h3>Status keahlian</h3><div class="pk-cv"><canvas id="pkc-status" height="220"></canvas></div></div>
		<div class="pk-chart pk-w2"><h3>Kutipan disahkan — 12 bulan (RM)</h3><div class="pk-cv"><canvas id="pkc-col" height="220"></canvas></div></div>
		<div class="pk-chart"><h3>Cara bayaran (bil. pelan)</h3><div class="pk-cv"><canvas id="pkc-mode" height="220"></canvas></div></div>
		<div class="pk-chart pk-w2"><h3>Umur &amp; jantina ahli aktif <small>(daripada No. KP<?php echo $d['unk'] ? ', ' . (int) $d['unk'] . ' tidak diketahui' : ''; ?>)</small></h3><div class="pk-cv"><canvas id="pkc-age" height="220"></canvas></div></div>
		<div class="pk-chart"><h3>Saiz isi rumah dilindungi</h3><div class="pk-cv"><canvas id="pkc-hh" height="220"></canvas></div></div>
		<div class="pk-chart"><h3>Kawasan / taman</h3><div class="pk-cv"><canvas id="pkc-kaw" height="240"></canvas></div></div>
		<div class="pk-chart"><h3>Sektor pekerjaan</h3><div class="pk-cv"><canvas id="pkc-sek" height="240"></canvas></div></div>
		<div class="pk-chart"><h3>Tanggungan mengikut hubungan</h3><div class="pk-cv"><canvas id="pkc-hub" height="240"></canvas></div></div>
	</div>

	<div class="pk-charts">
		<div class="pk-chart">
			<h3>Bayaran menunggu semakan</h3>
			<?php
			[ , $rows ] = pk_bayaran_query( [ 'status' => 'menunggu' ], 8 );
			if ( ! $rows ) { echo '<p class="description">Tiada — semua telah disemak.</p>'; }
			echo '<ul class="pk-ul">';
			foreach ( $rows as $r ) { echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=pk-' . ( 'khairat' === $r['jenis'] ? 'ahli' : 'korban' ) . '&action=edit&id=' . $r['ref_id'] ) ) . '">' . esc_html( $r['nama'] ) . '</a><span>' . esc_html( pk_rm( $r['jumlah'] ) ) . '</span><small>' . esc_html( $r['tajuk'] ) . '</small></li>'; }
			echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=pk-bayaran&status=menunggu' ) ) . '">Semua bayaran menunggu →</a></p>';
			?>
		</div>
		<div class="pk-chart">
			<h3>Ansuran — baki tertinggi</h3>
			<?php
			$P = pk_t( 'pelan' ); $B = pk_t( 'bayaran' );
			$ans = $wpdb->get_results( "SELECT p.*, p.jumlah - COALESCE((SELECT SUM(b.jumlah) FROM $B b WHERE b.pelan_id=p.id AND b.status='sah'),0) AS baki FROM $P p WHERE p.status='aktif' AND p.cara='ansuran' ORDER BY baki DESC LIMIT 8", ARRAY_A );
			if ( ! $ans ) { echo '<p class="description">Tiada pelan ansuran aktif.</p>'; }
			echo '<ul class="pk-ul">';
			foreach ( $ans as $p ) { [ $nm, $url ] = pk_pelan_owner( $p ); $pc = $p['jumlah'] > 0 ? round( ( 1 - $p['baki'] / $p['jumlah'] ) * 100 ) : 0; echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $nm ) . '</a><span>' . esc_html( pk_rm( $p['baki'] ) ) . '</span><small>' . esc_html( $p['tajuk'] ) . ' · ' . (int) $pc . '% dibayar</small><div class="pk-bar"><i style="width:' . (int) $pc . '%"></i></div></li>'; }
			echo '</ul>';
			?>
		</div>
		<div class="pk-chart">
			<h3>Pendaftaran terkini</h3>
			<?php
			$rec = $wpdb->get_results( "SELECT id, nama, status, sumber, created_at, 'khairat' j FROM " . pk_t( 'ahli' ) . " UNION ALL SELECT id, nama, status, sumber, created_at, 'korban' j FROM " . pk_t( 'korban' ) . ' ORDER BY created_at DESC LIMIT 8', ARRAY_A );
			if ( ! $rec ) { echo '<p class="description">Belum ada pendaftaran.</p>'; }
			echo '<ul class="pk-ul">';
			foreach ( $rec as $r ) { echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=pk-' . ( 'khairat' === $r['j'] ? 'ahli' : 'korban' ) . '&action=edit&id=' . $r['id'] ) ) . '">' . esc_html( $r['nama'] ) . '</a>' . pk_badge( 'khairat' === $r['j'] ? 'status_ahli' : 'status_korban', $r['status'] ) . '<small>' . esc_html( ( 'khairat' === $r['j'] ? 'Khairat' : 'Korban/Aqiqah' ) . ' · ' . pk_label( 'sumber', $r['sumber'] ) . ' · ' . human_time_diff( strtotime( $r['created_at'] ), current_time( 'timestamp' ) ) . ' lalu' ) . '</small></li>'; }
			echo '</ul>';
			?>
		</div>
	</div>
	<script type="application/json" id="pk-dash-data"><?php
	$st = [];
	foreach ( pk_label( 'status_ahli' ) as $k => $l ) { $st[] = [ 'label' => $l, 'key' => $k, 'n' => (int) ( $d['status'][ $k ] ?? 0 ) ]; }
	echo wp_json_encode( [
		'labels' => $d['labels'], 'reg' => $d['s_reg'], 'col' => $d['s_col'], 'status' => $st,
		'age' => [ 'labels' => array_keys( $d['age']['L'] ), 'L' => array_values( $d['age']['L'] ), 'P' => array_values( $d['age']['P'] ) ],
		'kaw' => $d['kaw'], 'sek' => $d['sek'], 'hub' => $d['hub'], 'hh' => [ 'labels' => array_keys( $d['hhb'] ), 'n' => array_values( $d['hhb'] ) ], 'mode' => $d['mm'],
	] );
	?></script>
	</div>
	<?php
}

/** Strip at the top of the AJK dashboard: the AJK's own member record (AJK can be kariah members too). */
function pk_dashboard_my_record() {
	$me = pk_ahli_for_user();
	$portal = pk_page_url( 'portal' );
	if ( ! $me ) {
		return '<div class="pk-me pk-me-empty"><span class="dashicons dashicons-id-alt"></span><div><b>Rekod Ahli Saya</b><br><span class="description">Anda juga ahli kariah? Pautkan rekod keahlian sedia ada atau daftar sebagai ahli — kemudian urus maklumat anda sendiri di Portal Ahli.</span></div>'
			. '<a class="button button-primary" href="' . esc_url( $portal ) . '">Pautkan / Daftar Rekod Saya</a></div>';
	}
	$paid = pk_ahli_paid_year( $me['id'], pk_year() );
	return '<div class="pk-me"><span class="dashicons dashicons-id-alt"></span><div><b>Rekod Ahli Saya:</b> ' . esc_html( $me['nama'] ) . ' · ' . esc_html( $me['no_ahli'] ?: 'tiada No. Ahli' ) . ' ' . pk_badge( 'status_ahli', $me['status'] )
		. ' · Yuran ' . pk_year() . ': ' . ( $paid ? '<span class="pk-badge pk-b-ok">Dijelaskan</span>' : '<span class="pk-badge pk-b-warn">Belum</span>' ) . '</div>'
		. '<a class="button button-primary" href="' . esc_url( $portal ) . '">Buka Portal Ahli</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $me['id'] ) ) . '">Lihat rekod</a></div>';
}
