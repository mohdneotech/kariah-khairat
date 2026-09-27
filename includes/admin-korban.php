<?php
defined( 'ABSPATH' ) || exit;

function pk_korban_musims() {
	global $wpdb;
	$m = $wpdb->get_col( 'SELECT DISTINCT musim FROM ' . pk_t( 'korban' ) . " WHERE musim<>'' ORDER BY musim DESC" );
	$cur = pk_opt( 'musim_korban' );
	if ( ! in_array( $cur, $m, true ) ) { array_unshift( $m, $cur ); }
	return $m;
}

function pk_korban_rows( $musim, $status = '', $s = '' ) {
	global $wpdb;
	$k = pk_t( 'korban' ); $ps = pk_t( 'korban_peserta' ); $p = pk_t( 'pelan' ); $b = pk_t( 'bayaran' );
	$w = [ 'k.musim=%s' ]; $v = [ $musim ];
	if ( $status ) { $w[] = 'k.status=%s'; $v[] = $status; }
	if ( $s ) {
		$like = '%' . $wpdb->esc_like( $s ) . '%';
		$w[] = "(k.nama LIKE %s OR k.no_hp LIKE %s OR k.no_kp LIKE %s OR EXISTS(SELECT 1 FROM $ps x WHERE x.korban_id=k.id AND x.nama LIKE %s))";
		array_push( $v, $like, $like, $like, $like );
	}
	$sql = "SELECT k.*, (SELECT COALESCE(SUM(bahagian),0) FROM $ps WHERE korban_id=k.id) AS bah,
		(SELECT COALESCE(SUM(jumlah),0) FROM $p WHERE jenis='korban' AND ref_id=k.id AND status<>'batal') AS jumlah,
		(SELECT COALESCE(SUM(bb.jumlah),0) FROM $b bb JOIN $p pp ON pp.id=bb.pelan_id WHERE pp.jenis='korban' AND pp.ref_id=k.id AND pp.status<>'batal' AND bb.status='sah') AS dibayar,
		(SELECT GROUP_CONCAT(DISTINCT cara) FROM $p WHERE jenis='korban' AND ref_id=k.id AND status<>'batal') AS cara
		FROM $k k WHERE " . implode( ' AND ', $w ) . ' ORDER BY k.created_at DESC';
	return $wpdb->get_results( $wpdb->prepare( $sql, $v ), ARRAY_A );
}

function pk_page_korban() {
	$action = sanitize_key( pk_gs( 'action', '' ) );
	if ( 'edit' === $action ) { pk_korban_edit_screen( (int) ( $_GET['id'] ?? 0 ) ); return; }
	$musims = pk_korban_musims();
	$musim  = sanitize_text_field( wp_unslash( pk_gs( 'musim', $musims[0] ) ) );
	$status = sanitize_key( pk_gs( 'status', '' ) );
	$s      = sanitize_text_field( wp_unslash( pk_gs( 's', '' ) ) );
	$all    = pk_korban_rows( $musim );
	$rows   = ( $status || $s ) ? pk_korban_rows( $musim, $status, $s ) : $all;
	$live   = array_filter( $all, fn( $r ) => 'batal' !== $r['status'] );
	$bah    = array_sum( array_column( $live, 'bah' ) );
	$jum    = array_sum( array_column( $live, 'jumlah' ) );
	$dib    = array_sum( array_column( $live, 'dibayar' ) );
	$counts = array_count_values( array_column( $all, 'status' ) );
	$base   = admin_url( 'admin.php?page=pk-korban&musim=' . rawurlencode( $musim ) );

	pk_admin_header( 'Korban & Aqiqah ' . $musim, ' <a href="' . esc_url( admin_url( 'admin.php?page=pk-korban-baru' ) ) . '" class="page-title-action">+ Daftar Korban</a> <a href="' . esc_url( admin_url( 'admin.php?page=pk-korban&action=senarai&musim=' . rawurlencode( $musim ) ) ) . '" target="_blank" class="page-title-action">Cetak Senarai Lembu</a> <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pk_export_korban&musim=' . rawurlencode( $musim ) ), 'pk_export' ) ) . '" class="page-title-action">Eksport CSV</a>' );
	echo '<div class="pk-kpis pk-kpis-sm">'
		. '<div><span>Pendaftaran</span><b>' . count( $live ) . '</b></div>'
		. '<div><span>Jumlah bahagian</span><b>' . (int) $bah . '</b></div>'
		. '<div><span>Anggaran lembu</span><b>' . (int) ceil( $bah / 7 ) . '</b><small>' . ( $bah % 7 ? ( 7 - $bah % 7 ) . ' bahagian lagi untuk genap' : 'genap' ) . '</small></div>'
		. '<div><span>Kutipan disahkan</span><b>' . esc_html( pk_rm( $dib ) ) . '</b><small>daripada ' . esc_html( pk_rm( $jum ) ) . '</small></div>'
		. '<div><span>Baki belum dibayar</span><b class="pk-red">' . esc_html( pk_rm( max( 0, $jum - $dib ) ) ) . '</b></div></div>';
	echo pk_filter_links( 'status', pk_label( 'status_korban' ), $counts, $base );
	?>
	<form method="get" class="pk-filters"><input type="hidden" name="page" value="pk-korban">
		<?php echo pk_select( 'musim', array_combine( $musims, $musims ), $musim, '', null ); ?>
		<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="Cari nama / HP / peserta"><button class="button">Tapis</button></form>
	<table class="wp-list-table widefat fixed striped pk-list">
		<thead><tr><th style="width:8%">Rujukan</th><th style="width:22%">Nama</th><th>Jenis</th><th>Bahagian</th><th>Jumlah</th><th>Dibayar</th><th>Baki</th><th>Cara</th><th>Status</th><th>Tarikh</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="10">Tiada rekod.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $r ) : $url = admin_url( 'admin.php?page=pk-korban&action=edit&id=' . $r['id'] ); $baki = max( 0, $r['jumlah'] - $r['dibayar'] ); ?>
			<tr><td><?php echo esc_html( pk_ref( 'korban', $r['id'] ) ); ?></td>
				<td><strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $r['nama'] ); ?></a></strong><div class="description"><?php echo esc_html( $r['no_hp'] ); ?></div></td>
				<td><?php echo esc_html( pk_label( 'jenis_korban', $r['jenis'] ) ); ?></td><td><b><?php echo (int) $r['bah']; ?></b></td>
				<td><?php echo esc_html( pk_rm( $r['jumlah'] ) ); ?></td><td><?php echo esc_html( pk_rm( $r['dibayar'] ) ); ?></td>
				<td><?php echo $baki > 0 ? '<b class="pk-red">' . esc_html( pk_rm( $baki ) ) . '</b>' : '<span class="pk-badge pk-b-ok">Lunas</span>'; ?></td>
				<td><?php echo false !== strpos( (string) $r['cara'], 'ansuran' ) ? '<span class="pk-chip">Ansuran</span>' : '<span class="pk-chip pk-chip-alt">Sekaligus</span>'; ?></td>
				<td><?php echo pk_badge( 'status_korban', $r['status'] ); ?></td><td><?php echo esc_html( pk_date( substr( $r['created_at'], 0, 10 ) ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
	<?php
}

function pk_page_korban_baru() { pk_korban_edit_screen( 0 ); }

function pk_korban_edit_screen( $id ) {
	$k = $id ? pk_get_korban( $id ) : null;
	if ( $id && ! $k ) { echo '<div class="wrap"><p>Rekod tidak ditemui.</p></div>'; return; }
	$k  = $k ?: array_merge( array_fill_keys( PK_KORBAN_FIELDS, '' ), [ 'musim' => pk_opt( 'musim_korban' ), 'jenis' => 'korban', 'status' => 'disahkan', 'sumber' => 'manual' ] );
	$ps = $id ? pk_peserta_get( $id ) : [];
	if ( ! $ps ) { $ps = [ [] ]; }
	$f  = fn( $x ) => esc_attr( $k[ $x ] ?? '' );
	pk_admin_header( $id ? $k['nama'] . ' — ' . pk_ref( 'korban', $id ) : 'Daftar Korban / Aqiqah (Manual)', $id ? ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=pk-korban' ) ) . '">← Senarai</a>' : '' );
	$lain = array_filter( explode( ',', (string) $k['bahagian_lain'] ) );
	?>
	<div class="pk-cols">
	<form class="pk-main" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pk_save_korban' ); ?><input type="hidden" name="action" value="pk_save_korban"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<div class="pk-box"><h2>A — Butiran Peserta</h2>
			<div class="pk-fgrid">
				<label>Jenis<?php echo pk_select( 'jenis', pk_label( 'jenis_korban' ), $k['jenis'], '', null ); ?></label>
				<label>Musim<input type="text" name="musim" value="<?php echo $f( 'musim' ); ?>"></label>
				<label class="pk-span2">Nama *<input type="text" name="nama" required value="<?php echo $f( 'nama' ); ?>"></label>
				<label>No. KP<input type="text" name="no_kp" value="<?php echo esc_attr( pk_fmt_kp( $k['no_kp'] ) ); ?>"></label>
				<label>No. HP<input type="text" name="no_hp" value="<?php echo $f( 'no_hp' ); ?>"></label>
				<label class="pk-span2">Alamat<textarea name="alamat" rows="2"><?php echo esc_textarea( $k['alamat'] ?? '' ); ?></textarea></label>
				<label>Tel. Rumah<input type="text" name="tel_r" value="<?php echo $f( 'tel_r' ); ?>"></label>
				<label>E-mel<input type="email" name="email" value="<?php echo $f( 'email' ); ?>"></label>
			</div></div>
		<div class="pk-box"><h2>C — Peserta (<?php echo esc_html( pk_rm( pk_opt( 'harga_bahagian' ) ) ); ?> sebahagian)</h2>
			<table class="widefat pk-tg"><thead><tr><th>Nama peserta</th><th style="width:110px">Bahagian</th><th style="width:40px"></th></tr></thead>
				<tbody data-pk-rows="ps"><?php foreach ( $ps as $i => $r ) { echo pk_admin_ps_row( $i, $r ); } ?></tbody></table>
			<template data-pk-tpl="ps"><?php echo pk_admin_ps_row( '__i__', [] ); ?></template>
			<p><button type="button" class="button" data-pk-add="ps">+ Tambah peserta</button></p>
			<?php if ( $id ) : ?><p><label><input type="checkbox" name="sync_pelan" value="1" checked> Kemas kini jumlah pelan korban mengikut bilangan bahagian</label></p><?php endif; ?>
		</div>
		<div class="pk-box"><h2>D — Agihan Daging</h2>
			<?php foreach ( pk_label( 'pilihan' ) as $kk => $v ) : ?><label class="pk-block"><input type="radio" name="pilihan" value="<?php echo esc_attr( $kk ); ?>" <?php checked( $k['pilihan'], $kk ); ?>> <?php echo esc_html( $v ); ?></label><?php endforeach; ?>
			<p>Bahagian lain: <?php foreach ( pk_label( 'bahagian_lain' ) as $kk => $v ) : ?><label style="margin-right:1rem"><input type="checkbox" name="bahagian_lain[]" value="<?php echo esc_attr( $kk ); ?>" <?php checked( in_array( $kk, $lain, true ) ); ?>> <?php echo esc_html( $v ); ?></label><?php endforeach; ?></p>
		</div>
		<div class="pk-box"><h2>Urusan Pejabat</h2>
			<div class="pk-fgrid">
				<label>Status<?php echo pk_select( 'status', pk_label( 'status_korban' ), $k['status'], '', null ); ?></label>
				<label>Sumber<?php echo pk_select( 'sumber', pk_label( 'sumber' ), $k['sumber'], '', null ); ?></label>
				<label class="pk-span2">Catatan<textarea name="catatan" rows="2"><?php echo esc_textarea( $k['catatan'] ?? '' ); ?></textarea></label>
			</div></div>
		<?php if ( ! $id ) : ?>
		<div class="pk-box"><h2>Bayaran</h2>
			<div class="pk-fgrid">
				<label>Cara<?php echo pk_select( 'cara', pk_label( 'mod' ), 'sekaligus', 'data-pk-cara', null ); ?></label>
				<label class="pk-ans">Bil. ansuran<input type="number" name="bil_ansuran" min="2" max="36" value="6"></label>
				<label>Bayaran diterima sekarang (RM)<input type="number" step="0.01" min="0" name="bayar_sekarang" placeholder="0"></label>
				<label>Kaedah<?php echo pk_select( 'kaedah', pk_label( 'kaedah' ), 'tunai', '', null ); ?></label>
				<label>No. Resit<input type="text" name="no_resit"></label>
			</div></div>
		<?php endif; ?>
		<p class="pk-sticky"><button class="button button-primary button-large"><?php echo $id ? 'Simpan Perubahan' : 'Daftar'; ?></button>
		<?php if ( $id && current_user_can( 'manage_options' ) ) : ?><a href="#" class="pk-link-del" style="margin-left:1rem" onclick="if(confirm('Padam kekal rekod ini berserta bayaran?')){document.getElementById('pk-del-k').submit()}return false">Padam rekod</a><?php endif; ?></p>
	</form>
	<?php if ( $id ) : ?>
	<form id="pk-del-k" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'pk_del_korban' ); ?><input type="hidden" name="action" value="pk_del_korban"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"></form>
	<div class="pk-side">
		<div class="pk-box pk-summary"><div class="pk-sum-top"><?php echo pk_badge( 'status_korban', $k['status'] ); ?> <span class="pk-noahli"><?php echo (int) pk_korban_bahagian( $id ); ?> bahagian</span></div>
			<dl><dt>Didaftar</dt><dd><?php echo esc_html( pk_date( substr( $k['created_at'], 0, 10 ) ) . ' · ' . pk_label( 'sumber', $k['sumber'] ) ); ?></dd></dl></div>
		<?php pk_admin_pelan_panel( 'korban', $id, [ 'tajuk' => 'Tambahan ' . pk_label( 'jenis_korban', $k['jenis'] ), 'kategori' => $k['jenis'] ] ); ?>
	</div>
	<?php endif; ?>
	</div></div>
	<?php
}

function pk_admin_ps_row( $i, $r ) {
	$n = 'ps[' . $i . ']'; $b = [];
	for ( $x = 1; $x <= 7; $x++ ) { $b[ $x ] = $x; }
	return '<tr class="pk-row"><td><input type="text" class="widefat" name="' . $n . '[nama]" value="' . esc_attr( $r['nama'] ?? '' ) . '"></td><td>' . pk_select( $n . '[bahagian]', $b, $r['bahagian'] ?? 1, '', null ) . '</td><td><button type="button" class="button-link pk-link-del" data-pk-del>✕</button></td></tr>';
}

add_action( 'admin_post_pk_save_korban', function () {
	pk_guard( 'pk_save_korban' );
	$id = (int) pk_p( 'id' );
	$d  = [];
	foreach ( PK_KORBAN_FIELDS as $x ) { if ( isset( $_POST[ $x ] ) ) { $d[ $x ] = in_array( $x, [ 'alamat', 'catatan' ], true ) ? pk_pt( $x ) : pk_p( $x ); } }
	if ( '' === trim( $d['nama'] ?? '' ) ) { pk_back( 'Nama diperlukan.' ); }
	$d['jenis'] = 'aqiqah' === ( $d['jenis'] ?? '' ) ? 'aqiqah' : 'korban';
	$d['bahagian_lain'] = implode( ',', array_intersect( array_map( 'sanitize_key', array_filter( (array) ( $_POST['bahagian_lain'] ?? [] ), 'is_scalar' ) ), array_keys( pk_label( 'bahagian_lain' ) ) ) );
	$new = ! $id;
	$id  = pk_save_korban_data( $d, $id );
	$bah = pk_peserta_replace( $id, pk_peserta_from_post() );
	$amt = $bah * (float) pk_opt( 'harga_bahagian' );
	$tajuk = ucfirst( $d['jenis'] ) . ' ' . ( $d['musim'] ?? '' ) . " — $bah bahagian";
	if ( $new && $bah ) {
		$pid = pk_pelan_create( [ 'jenis' => 'korban', 'ref_id' => $id, 'kategori' => $d['jenis'], 'tajuk' => $tajuk, 'jumlah' => $amt, 'cara' => pk_p( 'cara' ), 'bil_ansuran' => pk_p( 'bil_ansuran' ) ] );
		$now = pk_money( pk_p( 'bayar_sekarang' ) );
		if ( $now > 0 ) { pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $now, 'kaedah' => pk_p( 'kaedah' ), 'no_resit' => pk_p( 'no_resit' ), 'status' => 'sah', 'sumber' => 'manual' ] ); }
	} elseif ( ! $new && pk_p( 'sync_pelan' ) ) {
		global $wpdb;
		$pl = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'pelan' ) . " WHERE jenis='korban' AND ref_id=%d AND kategori IN ('korban','aqiqah') AND status<>'batal' ORDER BY id LIMIT 1", $id ), ARRAY_A );
		if ( $pl && abs( $pl['jumlah'] - $amt ) > 0.001 ) {
			$upd = [ 'jumlah' => $amt, 'tajuk' => $tajuk ];
			if ( 'ansuran' === $pl['cara'] ) { $upd['amaun_ansuran'] = round( $amt / max( 1, $pl['bil_ansuran'] ), 2 ); }
			$wpdb->update( pk_t( 'pelan' ), $upd, [ 'id' => $pl['id'] ] );
			$wpdb->update( pk_t( 'pelan' ), [ 'status' => 'aktif' ], [ 'id' => $pl['id'] ] );
			pk_pelan_refresh( (int) $pl['id'] );
		}
	}
	pk_back( $new ? 'Pendaftaran korban disimpan.' : 'Rekod dikemas kini.', admin_url( 'admin.php?page=pk-korban&action=edit&id=' . $id ) );
} );

add_action( 'admin_post_pk_del_korban', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Tiada kebenaran.' ); }
	check_admin_referer( 'pk_del_korban' );
	pk_delete_korban( (int) pk_p( 'id' ) );
	pk_back( 'Rekod dipadam.', admin_url( 'admin.php?page=pk-korban' ) );
} );

/* ---------------------------------------------------------------- printable cow allocation (7 per lembu) */

add_action( 'admin_init', function () {
	if ( ( $_GET['page'] ?? '' ) !== 'pk-korban' || ( $_GET['action'] ?? '' ) !== 'senarai' ) { return; }
	if ( ! current_user_can( PK_CAP ) ) { wp_die(); }
	global $wpdb;
	$musim = sanitize_text_field( wp_unslash( pk_gs( 'musim', pk_opt( 'musim_korban' ) ) ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT p.nama, p.bahagian, k.nama AS pendaftar, k.no_hp, k.jenis, k.status FROM ' . pk_t( 'korban_peserta' ) . ' p JOIN ' . pk_t( 'korban' ) . " k ON k.id=p.korban_id WHERE k.musim=%s AND k.status<>'batal' ORDER BY k.jenis, k.id, p.id", $musim ), ARRAY_A );
	$slots = [];
	foreach ( $rows as $r ) { for ( $i = 0; $i < (int) $r['bahagian']; $i++ ) { $slots[] = $r; } }
	$lembu = array_chunk( $slots, 7 );
	?><!doctype html><html lang="ms"><head><meta charset="utf-8"><title>Senarai Peserta Korban <?php echo esc_html( $musim ); ?></title>
	<style>@page{size:A4;margin:12mm}body{font:10.5pt Arial,sans-serif;max-width:190mm;margin:0 auto}h1{font-size:14pt;text-align:center;margin:0 0 4px}p.c{text-align:center;margin:0 0 12px}.l{break-inside:avoid;margin-bottom:10px}.l h2{font-size:11pt;background:#eee;padding:3px 6px;margin:0}table{width:100%;border-collapse:collapse}td,th{border:1px solid #777;padding:3px 5px;font-size:9.5pt}th{background:#f6f6f6}.w{color:#a00}.pb{position:fixed;top:8px;right:8px}@media print{.pb{display:none}}</style></head><body>
	<button class="pb" onclick="print()">🖨 Cetak</button>
	<h1>SENARAI PESERTA IBADAH KORBAN &amp; AQIQAH <?php echo esc_html( $musim ); ?></h1><p class="c"><?php echo esc_html( pk_nama() ); ?> · <?php echo count( $slots ); ?> bahagian · <?php echo count( $lembu ); ?> ekor lembu (anggaran)</p>
	<?php foreach ( $lembu as $n => $grp ) : ?>
		<div class="l"><h2>Lembu <?php echo $n + 1; ?><?php echo count( $grp ) < 7 ? ' <span class="w">(' . count( $grp ) . '/7 — belum penuh)</span>' : ''; ?></h2>
		<table><tr><th style="width:5%">#</th><th>Nama Peserta (diniatkan)</th><th>Pendaftar</th><th style="width:15%">No. HP</th><th style="width:10%">Jenis</th></tr>
		<?php foreach ( $grp as $i => $r ) : ?><tr><td><?php echo $i + 1; ?></td><td><?php echo esc_html( $r['nama'] ); ?></td><td><?php echo esc_html( $r['pendaftar'] ); ?></td><td><?php echo esc_html( $r['no_hp'] ); ?></td><td><?php echo esc_html( pk_label( 'jenis_korban', $r['jenis'] ) ); ?></td></tr><?php endforeach; ?>
		</table></div>
	<?php endforeach; ?>
	<?php if ( ! $lembu ) : ?><p>Tiada peserta.</p><?php endif; ?>
	</body></html><?php
	exit;
} );
