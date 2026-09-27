<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================= query */

function pk_ahli_query( $args, $limit = 25, $offset = 0 ) {
	global $wpdb;
	$a = pk_t( 'ahli' ); $p = pk_t( 'pelan' ); $b = pk_t( 'bayaran' ); $tg = pk_t( 'tanggungan' );
	$yr = (int) ( $args['tahun'] ?? pk_year() );
	$w  = [ '1=1' ]; $v = [];
	if ( ! empty( $args['status'] ) ) { $w[] = 'a.status=%s'; $v[] = $args['status']; }
	if ( ! empty( $args['kawasan'] ) ) { $w[] = 'a.kawasan=%s'; $v[] = $args['kawasan']; }
	if ( ! empty( $args['sumber'] ) ) { $w[] = 'a.sumber=%s'; $v[] = $args['sumber']; }
	if ( ! empty( $args['s'] ) ) {
		$like = '%' . $wpdb->esc_like( $args['s'] ) . '%'; $dig = pk_digits( $args['s'] );
		$w[]  = '(a.nama LIKE %s OR a.no_ahli LIKE %s OR a.p_nama LIKE %s OR a.alamat LIKE %s' . ( strlen( $dig ) >= 4 ? ' OR a.no_kp LIKE %s OR a.no_hp LIKE %s' : '' ) . ')';
		array_push( $v, $like, $like, $like, $like );
		if ( strlen( $dig ) >= 4 ) { array_push( $v, "%$dig%", "%$dig%" ); }
	}
	$paid = "EXISTS(SELECT 1 FROM $p pp WHERE pp.jenis='khairat' AND pp.ref_id=a.id AND pp.status='selesai' AND FIND_IN_SET($yr, pp.liputan))";
	if ( ( $args['bayar'] ?? '' ) === 'berbayar' ) { $w[] = $paid; }
	if ( ( $args['bayar'] ?? '' ) === 'belum' ) { $w[] = "NOT $paid"; }
	if ( ( $args['bayar'] ?? '' ) === 'ansuran' ) { $w[] = "EXISTS(SELECT 1 FROM $p pa WHERE pa.jenis='khairat' AND pa.ref_id=a.id AND pa.status='aktif' AND pa.cara='ansuran')"; }
	$where = implode( ' AND ', $w );
	$order = in_array( $args['orderby'] ?? '', [ 'nama', 'no_ahli', 'created_at', 'kawasan' ], true ) ? $args['orderby'] : 'created_at';
	$dir   = ( $args['order'] ?? '' ) === 'asc' ? 'ASC' : 'DESC';
	$sql_n = "SELECT COUNT(*) FROM $a a WHERE $where";
	$sql   = "SELECT a.*, (SELECT COUNT(*) FROM $tg t WHERE t.ahli_id=a.id) AS tg_n, $paid AS paid,
		(SELECT COALESCE(SUM(p2.jumlah - COALESCE((SELECT SUM(b2.jumlah) FROM $b b2 WHERE b2.pelan_id=p2.id AND b2.status='sah'),0)),0) FROM $p p2 WHERE p2.jenis='khairat' AND p2.ref_id=a.id AND p2.status='aktif') AS baki,
		(SELECT COUNT(*) FROM $p p3 WHERE p3.jenis='khairat' AND p3.ref_id=a.id AND p3.status='aktif' AND p3.cara='ansuran') AS ansuran_n
		FROM $a a WHERE $where ORDER BY a.$order $dir";
	if ( $v ) { $sql_n = $wpdb->prepare( $sql_n, $v ); $sql = $wpdb->prepare( $sql, $v ); }
	if ( $limit ) { $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset ); }
	return [ (int) $wpdb->get_var( $sql_n ), $wpdb->get_results( $sql, ARRAY_A ) ];
}

/* ================================================================= list page */

function pk_page_ahli() {
	$action = sanitize_key( pk_gs( 'action', '' ) );
	if ( 'edit' === $action ) { pk_ahli_edit_screen( (int) ( $_GET['id'] ?? 0 ) ); return; }
	global $wpdb;
	$base = admin_url( 'admin.php?page=pk-ahli' );
	foreach ( [ 'status', 'bayar', 'kawasan', 's', 'orderby', 'order', 'sumber' ] as $k ) { if ( '' !== pk_gs( $k ) ) { $base = add_query_arg( $k, rawurlencode( wp_unslash( pk_gs( $k ) ) ), $base ); } }
	$args = [
		'status' => sanitize_key( pk_gs( 'status', '' ) ), 'bayar' => sanitize_key( pk_gs( 'bayar', '' ) ), 'kawasan' => sanitize_text_field( wp_unslash( pk_gs( 'kawasan', '' ) ) ),
		's' => sanitize_text_field( wp_unslash( pk_gs( 's', '' ) ) ), 'orderby' => sanitize_key( pk_gs( 'orderby', '' ) ), 'order' => sanitize_key( pk_gs( 'order', '' ) ), 'sumber' => sanitize_key( pk_gs( 'sumber', '' ) ),
	];
	$per  = 30; $cur = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	[ $total, $rows ] = pk_ahli_query( $args, $per, ( $cur - 1 ) * $per );
	$counts = $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . pk_t( 'ahli' ) . ' GROUP BY status', OBJECT_K );
	$counts = array_map( fn( $r ) => (int) $r->n, $counts );
	$kaw    = $wpdb->get_col( 'SELECT DISTINCT kawasan FROM ' . pk_t( 'ahli' ) . " WHERE kawasan<>'' ORDER BY kawasan" );
	$yr     = pk_year();

	pk_admin_header( 'Ahli Khairat Kematian', ' <a href="' . esc_url( admin_url( 'admin.php?page=pk-ahli-baru' ) ) . '" class="page-title-action">+ Daftar Ahli</a> <a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'pk_export_ahli', str_replace( 'admin.php?page=pk-ahli', 'admin-post.php?', $base ) ), 'pk_export' ) ) . '" class="page-title-action">Eksport CSV</a>' );
	echo pk_filter_links( 'status', pk_label( 'status_ahli' ), $counts, $base );
	?>
	<form method="get" class="pk-filters">
		<input type="hidden" name="page" value="pk-ahli">
		<?php if ( $args['status'] ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $args['status'] ); ?>"><?php endif; ?>
		<?php echo pk_select( 'bayar', [ 'berbayar' => "Yuran $yr dijelaskan", 'belum' => "Belum jelas yuran $yr", 'ansuran' => 'Sedang ansuran' ], $args['bayar'], '', 'Semua status bayaran' ); ?>
		<?php echo pk_select( 'kawasan', array_combine( $kaw, $kaw ) ?: [], $args['kawasan'], '', 'Semua kawasan' ); ?>
		<?php echo pk_select( 'sumber', pk_label( 'sumber' ), $args['sumber'], '', 'Semua sumber' ); ?>
		<input type="search" name="s" value="<?php echo esc_attr( $args['s'] ); ?>" placeholder="Cari nama / No. KP / HP / No. Ahli">
		<button class="button">Tapis</button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pk_bulk_ahli' ); ?><input type="hidden" name="action" value="pk_bulk_ahli">
		<div class="tablenav top"><div class="alignleft actions">
			<select name="bulk"><option value="">Tindakan pukal</option><option value="aktif">Luluskan / Aktifkan</option><option value="tidak_aktif">Tandakan Tidak Aktif</option><option value="meninggal">Tandakan Meninggal Dunia</option><option value="ditolak">Tolak</option><?php if ( current_user_can( 'manage_options' ) ) : ?><option value="padam">Padam kekal</option><?php endif; ?></select>
			<button class="button" onclick="return this.form.bulk.value!=='padam'||confirm('Padam kekal rekod dipilih?')">Guna</button>
		</div><?php echo pk_pager( $total, $per, $cur ); ?><div class="alignright pk-count"><?php echo (int) $total; ?> rekod</div></div>
		<table class="wp-list-table widefat fixed striped pk-list">
			<thead><tr><td class="check-column"><input type="checkbox" data-pk-checkall></td>
				<th style="width:9%"><?php echo pk_sort_link( 'No. Ahli', 'no_ahli', $base ); ?></th><th style="width:24%"><?php echo pk_sort_link( 'Nama', 'nama', $base ); ?></th><th>No. HP</th><th><?php echo pk_sort_link( 'Kawasan', 'kawasan', $base ); ?></th><th style="width:7%">Tanggungan</th><th>Yuran <?php echo (int) $yr; ?></th><th>Baki</th><th>Status</th><th><?php echo pk_sort_link( 'Didaftar', 'created_at', $base ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?><tr><td colspan="10">Tiada rekod.</td></tr><?php endif; ?>
			<?php foreach ( $rows as $r ) : $url = admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $r['id'] ); ?>
				<tr>
					<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"></th>
					<td><?php echo esc_html( $r['no_ahli'] ?: '—' ); ?></td>
					<td><strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $r['nama'] ); ?></a></strong><div class="description"><?php echo esc_html( pk_mask_kp( $r['no_kp'] ) ); ?> · <?php echo esc_html( pk_label( 'sumber', $r['sumber'] ) ); ?></div>
						<div class="row-actions"><a href="<?php echo esc_url( $url ); ?>">Lihat / Edit</a> | <a href="<?php echo esc_url( admin_url( 'admin.php?page=pk-ahli&action=print&id=' . $r['id'] ) ); ?>" target="_blank">Cetak</a></div></td>
					<td><a href="tel:<?php echo esc_attr( pk_digits( $r['no_hp'] ) ); ?>"><?php echo esc_html( $r['no_hp'] ); ?></a></td>
					<td><?php echo esc_html( $r['kawasan'] ?: '—' ); ?></td>
					<td><?php echo (int) $r['tg_n']; ?></td>
					<td><?php echo $r['paid'] ? '<span class="pk-badge pk-b-ok">Dijelaskan</span>' : '<span class="pk-badge pk-b-warn">Belum</span>'; ?><?php echo $r['ansuran_n'] ? ' <span class="pk-chip">Ansuran</span>' : ''; ?></td>
					<td><?php echo $r['baki'] > 0 ? '<b class="pk-red">' . esc_html( pk_rm( $r['baki'] ) ) . '</b>' : '—'; ?></td>
					<td><?php echo pk_badge( 'status_ahli', $r['status'] ); ?></td>
					<td><?php echo esc_html( pk_date( substr( $r['created_at'], 0, 10 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="tablenav bottom"><?php echo pk_pager( $total, $per, $cur ); ?></div>
	</form>

	<div class="pk-box pk-narrow">
		<h2><span class="dashicons dashicons-update"></span> Jana Yuran Tahunan</h2>
		<p>Cipta pelan "Yuran Tahunan" (<?php echo esc_html( pk_rm( pk_opt( 'yuran_tahunan' ) ) ); ?>) untuk semua ahli <b>Aktif</b> yang belum mempunyai pelan bagi tahun dipilih. Selamat dijalankan berulang kali (tiada pendua).</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-inline"><?php wp_nonce_field( 'pk_jana' ); ?><input type="hidden" name="action" value="pk_jana">
			<label>Tahun <input type="number" name="tahun" value="<?php echo (int) $yr; ?>" min="2020" max="2100"></label> <button class="button" onclick="return confirm('Jana yuran tahunan untuk semua ahli aktif?')">Jana</button></form>
	</div>
	</div>
	<?php
}

function pk_sort_link( $label, $col, $base ) {
	$cur = sanitize_key( pk_gs( 'orderby', '' ) ); $ord = sanitize_key( pk_gs( 'order', 'desc' ) );
	$new = ( $cur === $col && 'asc' === $ord ) ? 'desc' : 'asc';
	return '<a href="' . esc_url( add_query_arg( [ 'orderby' => $col, 'order' => $new ], $base ) ) . '">' . esc_html( $label ) . ( $cur === $col ? ( 'asc' === $ord ? ' ▲' : ' ▼' ) : '' ) . '</a>';
}

add_action( 'admin_post_pk_bulk_ahli', function () {
	pk_guard( 'pk_bulk_ahli' );
	$ids = array_slice( array_map( 'intval', array_filter( (array) ( $_POST['ids'] ?? [] ), 'is_scalar' ) ), 0, 500 );
	$op  = pk_p( 'bulk' );
	if ( ! $ids || ! $op ) { pk_back( 'Tiada rekod dipilih.' ); }
	foreach ( $ids as $id ) {
		if ( 'padam' === $op ) { if ( current_user_can( 'manage_options' ) ) { pk_delete_ahli( $id ); } continue; }
		if ( $id === pk_my_ahli_id() && pk_self_review_blocked( true ) ) { continue; } // AJK cannot change the status of their own record
		if ( array_key_exists( $op, pk_label( 'status_ahli' ) ) ) { pk_save_ahli_data( [ 'status' => $op ], $id ); }
	}
	pk_back( count( $ids ) . ' rekod dikemas kini.' );
} );

add_action( 'admin_post_pk_jana', function () {
	pk_guard( 'pk_jana' );
	global $wpdb;
	$yr  = max( 2020, min( 2100, (int) pk_p( 'tahun' ) ) );
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT a.id FROM ' . pk_t( 'ahli' ) . " a WHERE a.status='aktif' AND NOT EXISTS(SELECT 1 FROM " . pk_t( 'pelan' ) . " p WHERE p.jenis='khairat' AND p.ref_id=a.id AND p.status<>'batal' AND FIND_IN_SET(%d,p.liputan))", $yr ) );
	foreach ( $ids as $id ) {
		pk_pelan_create( [ 'jenis' => 'khairat', 'ref_id' => $id, 'kategori' => 'tahunan', 'liputan' => [ $yr ], 'tajuk' => "Yuran Tahunan $yr", 'jumlah' => pk_opt( 'yuran_tahunan' ), 'cara' => 'sekaligus' ] );
	}
	pk_back( count( $ids ) . " pelan yuran tahunan $yr dijana." );
} );

/* ================================================================= add / edit */

function pk_page_ahli_baru() { pk_ahli_edit_screen( 0 ); }

function pk_ahli_edit_screen( $id ) {
	$a = $id ? pk_get_ahli( $id ) : null;
	if ( $id && ! $a ) { echo '<div class="wrap"><p>Rekod tidak ditemui.</p></div>'; return; }
	$a   = $a ?: array_fill_keys( PK_AHLI_FIELDS, '' );
	$tg  = $id ? pk_tanggungan_get( $id ) : [];
	if ( count( $tg ) < 3 ) { $tg = array_merge( $tg, array_fill( 0, 3 - count( $tg ), [] ) ); }
	$kaw = array_filter( array_map( 'trim', explode( "\n", (string) pk_opt( 'kawasan' ) ) ) );
	$yr  = pk_year();

	$title   = $id ? $a['nama'] : 'Daftar Ahli Khairat (Manual)';
	$actions = $id ? ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=pk-ahli&action=print&id=' . $id ) ) . '" target="_blank">Cetak Borang</a> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=pk-ahli' ) ) . '">← Senarai</a>' : '';
	pk_admin_header( $title, $actions );
	if ( $id && $id === pk_my_ahli_id() ) {
		echo '<div class="notice notice-info"><p><b>Ini rekod keahlian anda sendiri.</b> '
			. ( pk_self_review_blocked( true )
				? 'Status, No. Ahli, pasangan &amp; tanggungan, dan pengesahan bayaran untuk rekod ini perlu dibuat oleh AJK lain. Kemas kini maklumat hubungan anda atau mohon perubahan keluarga melalui <a href="' . esc_url( pk_page_url( 'portal' ) ) . '">Portal Ahli</a>.'
				: 'Sebagai pentadbir anda boleh menyunting rekod ini, tetapi semua perubahan direkodkan dalam Sejarah Perubahan. Sebaik-baiknya minta AJK lain mengesahkan bayaran dan perubahan keluarga anda.' )
			. '</p></div>';
	}
	$f = fn( $k ) => esc_attr( $a[ $k ] ?? '' );
	?>
	<div class="pk-cols">
	<form class="pk-main" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pk_save_ahli' ); ?><input type="hidden" name="action" value="pk_save_ahli"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<div class="pk-box">
			<h2>A — Maklumat Ahli</h2>
			<div class="pk-fgrid">
				<label class="pk-span2">Nama Penuh *<input type="text" name="nama" required value="<?php echo $f( 'nama' ); ?>"></label>
				<label>No. Kad Pengenalan<input type="text" name="no_kp" value="<?php echo esc_attr( pk_fmt_kp( $a['no_kp'] ) ); ?>"></label>
				<label>No. HP<input type="text" name="no_hp" value="<?php echo $f( 'no_hp' ); ?>"></label>
				<label class="pk-span2">Alamat Rumah<textarea name="alamat" rows="2"><?php echo esc_textarea( $a['alamat'] ?? '' ); ?></textarea></label>
				<label>Kawasan / Taman<input type="text" name="kawasan" list="pk-kaw" value="<?php echo $f( 'kawasan' ); ?>"><datalist id="pk-kaw"><?php foreach ( $kaw as $k ) { echo '<option value="' . esc_attr( $k ) . '">'; } ?></datalist></label>
				<label>E-mel<input type="email" name="email" value="<?php echo $f( 'email' ); ?>"></label>
				<label>Pekerjaan<input type="text" name="pekerjaan" value="<?php echo $f( 'pekerjaan' ); ?>"></label>
				<label>Sektor<?php echo pk_select( 'sektor', pk_label( 'sektor' ), $a['sektor'] ); ?></label>
				<label>Majikan<input type="text" name="majikan" value="<?php echo $f( 'majikan' ); ?>"></label>
				<label>Tel. Pejabat<input type="text" name="tel_p" value="<?php echo $f( 'tel_p' ); ?>"></label>
				<label>Tel. Rumah<input type="text" name="tel_r" value="<?php echo $f( 'tel_r' ); ?>"></label>
			</div>
		</div>
		<div class="pk-box">
			<h2>B — Suami / Isteri</h2>
			<div class="pk-fgrid">
				<label class="pk-span2">Nama Penuh<input type="text" name="p_nama" value="<?php echo $f( 'p_nama' ); ?>"></label>
				<label>No. KP<input type="text" name="p_kp" value="<?php echo esc_attr( pk_fmt_kp( $a['p_kp'] ) ); ?>"></label>
				<label>No. HP<input type="text" name="p_hp" value="<?php echo $f( 'p_hp' ); ?>"></label>
				<label>Pekerjaan<input type="text" name="p_pekerjaan" value="<?php echo $f( 'p_pekerjaan' ); ?>"></label>
				<label>Sektor<?php echo pk_select( 'p_sektor', pk_label( 'sektor' ), $a['p_sektor'] ); ?></label>
				<label>Majikan<input type="text" name="p_majikan" value="<?php echo $f( 'p_majikan' ); ?>"></label>
				<label>Tel. Pejabat<input type="text" name="p_tel_p" value="<?php echo $f( 'p_tel_p' ); ?>"></label>
			</div>
		</div>
		<div class="pk-box">
			<h2>C — Tanggungan</h2>
			<table class="widefat pk-tg"><thead><tr><th>Nama</th><th>Tarikh Lahir</th><th>Hubungan</th><th>Status</th><th>Tinggal Bersama</th><th></th></tr></thead>
				<tbody data-pk-rows="tg"><?php foreach ( $tg as $i => $r ) { echo pk_admin_tg_row( $i, $r ); } ?></tbody></table>
			<template data-pk-tpl="tg"><?php echo pk_admin_tg_row( '__i__', [] ); ?></template>
			<p><button type="button" class="button" data-pk-add="tg">+ Tambah tanggungan</button></p>
		</div>
		<div class="pk-box">
			<h2>Urusan Pejabat / AJK</h2>
			<div class="pk-fgrid">
				<label>Status<?php echo pk_select( 'status', pk_label( 'status_ahli' ), $a['status'] ?: 'aktif', '', null ); ?></label>
				<label>No. Ahli <small>(auto bila Aktif)</small><input type="text" name="no_ahli" value="<?php echo $f( 'no_ahli' ); ?>"></label>
				<label>Tarikh daftar<input type="date" name="tarikh_daftar" value="<?php echo $f( 'tarikh_daftar' ) ?: esc_attr( $id ? '' : pk_today() ); ?>"></label>
				<label>Sumber<?php echo pk_select( 'sumber', pk_label( 'sumber' ), $a['sumber'] ?: 'manual', '', null ); ?></label>
				<label class="pk-span2">Catatan<textarea name="catatan" rows="3"><?php echo esc_textarea( $a['catatan'] ?? '' ); ?></textarea></label>
			</div>
		</div>
		<?php if ( ! $id && ! pk_ahli_for_user() ) : ?>
		<div class="pk-box pk-me-empty">
			<label><input type="checkbox" name="rekod_saya" value="1"> <b>Ini rekod saya sendiri</b> — pautkan rekod ini kepada akaun saya (<?php echo esc_html( wp_get_current_user()->user_login ); ?>) supaya saya boleh mengurusnya di Portal Ahli.</label>
			<?php if ( pk_self_review_blocked( true ) ) : ?><p class="description">Rekod anda akan berstatus <i>Menunggu Pengesahan</i> dan sebarang bayaran perlu disahkan oleh AJK lain.</p><?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ( ! $id ) : ?>
		<div class="pk-box">
			<h2>Yuran &amp; Bayaran</h2>
			<div class="pk-fgrid">
				<label>Pelan yuran<?php echo pk_select( 'pelan_awal', [ 'baru' => 'Ahli baharu — ' . pk_rm( pk_opt( 'yuran_daftar' ) + pk_opt( 'yuran_tahunan' ) ) . " (Daftar + $yr)", 'tahunan' => "Ahli sedia ada — Tahunan $yr " . pk_rm( pk_opt( 'yuran_tahunan' ) ), 'tiada' => 'Tiada / tambah kemudian' ], 'baru', '', null ); ?></label>
				<label>Cara<?php echo pk_select( 'cara', pk_label( 'mod' ), 'sekaligus', 'data-pk-cara', null ); ?></label>
				<label class="pk-ans">Bil. ansuran<input type="number" name="bil_ansuran" min="2" max="36" value="3"></label>
				<label>Bayaran diterima sekarang (RM)<input type="number" step="0.01" min="0" name="bayar_sekarang" placeholder="0"></label>
				<label>Kaedah<?php echo pk_select( 'kaedah', pk_label( 'kaedah' ), 'tunai', '', null ); ?></label>
				<label>No. Resit<input type="text" name="no_resit"></label>
			</div>
		</div>
		<?php endif; ?>
		<p class="pk-sticky"><button class="button button-primary button-large"><?php echo $id ? 'Simpan Perubahan' : 'Daftar Ahli'; ?></button>
		<?php if ( $id && current_user_can( 'manage_options' ) ) : ?><a href="#" class="pk-link-del" style="margin-left:1rem" onclick="if(confirm('Padam kekal ahli ini berserta semua rekod bayaran?')){document.getElementById('pk-del-ahli').submit()}return false">Padam rekod</a><?php endif; ?></p>
	</form>
	<?php if ( $id ) : ?>
	<form id="pk-del-ahli" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'pk_del_ahli' ); ?><input type="hidden" name="action" value="pk_del_ahli"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"></form>
	<div class="pk-side">
		<?php
		[ $dob, $sex ] = pk_kp_info( $a['no_kp'] );
		$age  = pk_age( $dob );
		$paid = pk_ahli_paid_year( $id, $yr );
		echo '<div class="pk-box pk-summary"><div class="pk-sum-top">' . pk_badge( 'status_ahli', $a['status'] ) . ' <span class="pk-noahli">' . esc_html( $a['no_ahli'] ?: 'Tiada No. Ahli' ) . '</span></div>';
		echo '<dl><dt>Yuran ' . $yr . '</dt><dd>' . ( $paid ? '<span class="pk-badge pk-b-ok">Dijelaskan</span>' : '<span class="pk-badge pk-b-warn">Belum dijelaskan</span>' ) . '</dd>';
		echo '<dt>Umur / Jantina</dt><dd>' . ( null !== $age ? (int) $age . ' tahun' : '—' ) . ' · ' . ( 'L' === $sex ? 'Lelaki' : ( 'P' === $sex ? 'Perempuan' : '—' ) ) . '</dd>';
		echo '<dt>Isi rumah dilindungi</dt><dd>' . ( 1 + ( $a['p_nama'] ? 1 : 0 ) + count( array_filter( $tg, fn( $r ) => ! empty( $r['nama'] ) ) ) ) . ' orang</dd>';
		echo '<dt>Didaftar</dt><dd>' . esc_html( pk_date( $a['tarikh_daftar'] ) ) . ' · ' . esc_html( pk_label( 'sumber', $a['sumber'] ) ) . '</dd></dl></div>';
		if ( $pend = pk_kemaskini_pending( $id ) ) {
			echo '<div class="pk-box pk-pending"><h2><span class="dashicons dashicons-warning"></span> Permohonan kemas kini menunggu kelulusan</h2>' . pk_kemaskini_diff( $a, json_decode( $pend['data'], true ) ?: [] )
				. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=pk-kemaskini' ) ) . '">Semak &amp; luluskan</a></p></div>';
		}
		pk_admin_pelan_panel( 'khairat', $id, [ 'tajuk' => "Yuran Tahunan $yr", 'jumlah' => pk_opt( 'yuran_tahunan' ), 'kategori' => 'tahunan', 'liputan' => (string) $yr ] );
		pk_admin_portal_box( $a );
		pk_admin_log_box( $id );
		?>
	</div>
	<?php endif; ?>
	</div></div>
	<?php
}

function pk_admin_tg_row( $i, $r ) {
	$n = 'tg[' . $i . ']';
	return '<tr class="pk-row"><td><input type="text" name="' . $n . '[nama]" value="' . esc_attr( $r['nama'] ?? '' ) . '"></td>'
		. '<td><input type="date" name="' . $n . '[tarikh_lahir]" value="' . esc_attr( $r['tarikh_lahir'] ?? '' ) . '"></td>'
		. '<td>' . pk_select( $n . '[hubungan]', pk_label( 'hubungan' ), $r['hubungan'] ?? '' ) . '</td>'
		. '<td>' . pk_select( $n . '[status_t]', pk_label( 'status_t' ), $r['status_t'] ?? '', '', null ) . '</td>'
		. '<td>' . pk_select( $n . '[tinggal]', [ 'Y' => 'Ya', 'T' => 'Tidak' ], $r['tinggal'] ?? 'Y', '', null ) . '</td>'
		. '<td><button type="button" class="button-link pk-link-del" data-pk-del>✕</button></td></tr>';
}

add_action( 'admin_post_pk_save_ahli', function () {
	pk_guard( 'pk_save_ahli' );
	$id   = (int) pk_p( 'id' );
	$data = [];
	foreach ( array_diff( PK_AHLI_FIELDS, [ 'user_id' ] ) as $k ) { if ( isset( $_POST[ $k ] ) ) { $data[ $k ] = in_array( $k, [ 'alamat', 'catatan' ], true ) ? pk_pt( $k ) : pk_p( $k ); } }
	$data['email'] = sanitize_email( $data['email'] ?? '' );
	$data['tarikh_daftar'] = pk_date_in( $data['tarikh_daftar'] ?? '' );
	if ( ! array_key_exists( $data['status'] ?? '', pk_label( 'status_ahli' ) ) ) { $data['status'] = 'aktif'; }
	if ( '' === trim( $data['nama'] ?? '' ) ) { pk_back( 'Nama diperlukan.' ); }
	// AJK editing their own record: status and No. Ahli stay as they are (another AJK must approve)
	if ( $id && $id === pk_my_ahli_id() && pk_self_review_blocked( true ) && ( $cur = pk_get_ahli( $id ) ) ) {
		$data['status'] = $cur['status']; $data['no_ahli'] = $cur['no_ahli'];
		foreach ( PK_KELUARGA_FIELDS as $k ) { $data[ $k ] = $cur[ $k ]; }
		$_POST['tg'] = pk_tanggungan_get( $id );
	}
	$new = ! $id;
	if ( $new && ! empty( $data['no_kp'] ) && ( $dup = pk_find_ahli_by_kp( $data['no_kp'] ) ) ) {
		pk_back( 'No. KP ini telah wujud dalam rekod: ' . $dup['nama'] . '.', admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $dup['id'] ) );
	}
	$before = $id ? pk_get_ahli( $id ) : null;
	$self = $new && pk_p( 'rekod_saya' ) && ! pk_ahli_for_user();
	if ( $self && pk_self_review_blocked( true ) ) { $data['status'] = 'menunggu'; $data['no_ahli'] = ''; }
	$id = pk_save_ahli_data( $data, $id );
	if ( $self ) {
		global $wpdb;
		$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => get_current_user_id() ], [ 'id' => $id ] );
		pk_log( $id, 'akaun_pautkan', 'Didaftarkan sendiri oleh AJK ' . wp_get_current_user()->user_login );
	}
	if ( $before ) {
		$diff = [];
		foreach ( $data as $k => $v ) { if ( 'catatan' !== $k && array_key_exists( $k, $before ) && (string) $before[ $k ] !== (string) ( $k === 'no_kp' || $k === 'p_kp' ? pk_digits( $v ) : ( in_array( $k, [ 'nama', 'p_nama' ], true ) ? mb_strtoupper( trim( $v ) ) : $v ) ) ) { $diff[ $k ] = [ $before[ $k ], $v ]; } }
		if ( $diff ) { pk_log( $id, 'kemaskini_ajk', $diff ); }
	} else {
		pk_log( $id, 'daftar', 'Didaftarkan oleh AJK' );
	}
	pk_tanggungan_replace( $id, pk_tanggungan_from_post() );

	if ( $new ) {
		$yr = pk_year();
		$pa = pk_p( 'pelan_awal' );
		if ( in_array( $pa, [ 'baru', 'tahunan' ], true ) ) {
			$amt = ( 'baru' === $pa ? pk_opt( 'yuran_daftar' ) : 0 ) + pk_opt( 'yuran_tahunan' );
			$pid = pk_pelan_create( [ 'jenis' => 'khairat', 'ref_id' => $id, 'kategori' => 'baru' === $pa ? 'daftar' : 'tahunan', 'liputan' => [ $yr ],
				'tajuk' => ( 'baru' === $pa ? 'Pendaftaran + Yuran Tahunan ' : 'Yuran Tahunan ' ) . $yr, 'jumlah' => $amt, 'cara' => pk_p( 'cara' ), 'bil_ansuran' => pk_p( 'bil_ansuran' ) ] );
			$now = pk_money( pk_p( 'bayar_sekarang' ) );
			if ( $now > 0 ) {
				pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $now, 'kaedah' => pk_p( 'kaedah' ), 'no_resit' => pk_p( 'no_resit' ), 'status' => ( $self && pk_self_review_blocked( true ) ) ? 'menunggu' : 'sah', 'sumber' => 'manual' ] );
			}
		}
	}
	pk_back( $new ? 'Ahli didaftarkan.' : 'Maklumat ahli disimpan.', admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $id ) );
} );

add_action( 'admin_post_pk_del_ahli', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Tiada kebenaran.' ); }
	check_admin_referer( 'pk_del_ahli' );
	pk_delete_ahli( (int) pk_p( 'id' ) );
	pk_back( 'Rekod ahli dipadam.', admin_url( 'admin.php?page=pk-ahli' ) );
} );

/* ================================================================= printable record (A4) */

add_action( 'admin_init', function () {
	if ( ( $_GET['page'] ?? '' ) !== 'pk-ahli' || ( $_GET['action'] ?? '' ) !== 'print' ) { return; }
	if ( ! current_user_can( PK_CAP ) ) { wp_die( 'Tiada kebenaran.' ); }
	$a = pk_get_ahli( (int) ( $_GET['id'] ?? 0 ) );
	if ( ! $a ) { wp_die( 'Rekod tidak ditemui.' ); }
	$tg = pk_tanggungan_get( $a['id'] );
	$pl = pk_pelan_list( 'khairat', $a['id'] );
	$e  = fn( $v ) => esc_html( $v ?: '' );
	?><!doctype html><html lang="ms"><head><meta charset="utf-8"><title><?php echo $e( $a['nama'] ); ?> — Borang Khairat</title>
	<style>
	@page { size: A4; margin: 14mm; } body { font: 11pt/1.35 Arial, sans-serif; color: #111; max-width: 190mm; margin: 0 auto; }
	.hd { text-align: center; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 10px; position: relative; } .hd h1 { font-size: 14pt; margin: 0; } .hd h2 { font-size: 13pt; margin: 2px 0; } .hd p { margin: 2px 0; font-size: 8.5pt; }
	.no { position: absolute; right: 0; top: 0; border: 1px solid #000; padding: 4px 8px; font-size: 9pt; } .no b { font-size: 11pt; }
	h3 { background: #eee; font-size: 10.5pt; padding: 3px 6px; margin: 12px 0 6px; }
	table { width: 100%; border-collapse: collapse; } td, th { padding: 3px 5px; vertical-align: top; font-size: 10pt; } .kv td:nth-child(odd) { width: 18%; color: #444; } .kv td:nth-child(even) { border-bottom: 1px dotted #999; width: 32%; }
	.grid th, .grid td { border: 1px solid #555; } .grid th { background: #f3f3f3; }
	.sig { display: flex; gap: 20px; margin-top: 18px; } .sig div { flex: 1; border-top: 1px solid #000; padding-top: 3px; font-size: 9pt; margin-top: 30px; }
	.small { font-size: 8.5pt; } .pbtn { position: fixed; top: 10px; right: 10px; } @media print { .pbtn { display: none; } }
	</style></head><body>
	<button class="pbtn" onclick="window.print()">🖨 Cetak</button>
	<div class="hd"><div class="no">No. Ahli<br><b><?php echo $e( $a['no_ahli'] ?: '________' ); ?></b></div>
		<h1>BADAN KHAIRAT KEMATIAN</h1><h2><?php echo esc_html( mb_strtoupper( pk_nama() ) ); ?></h2>
		<?php if ( pk_opt( 'alamat_masjid' ) ) : ?><p><?php echo esc_html( preg_replace( '/\s*\n\s*/', ', ', trim( pk_opt( 'alamat_masjid' ) ) ) ); ?></p><?php endif; ?>
		<p><b>BORANG PENDAFTARAN KEAHLIAN KHAIRAT KEMATIAN</b></p></div>
	<h3>BAHAGIAN A : MAKLUMAT AHLI</h3>
	<table class="kv"><tr><td>Nama Penuh</td><td colspan="3"><?php echo $e( $a['nama'] ); ?></td></tr>
		<tr><td>No. KP</td><td><?php echo $e( pk_fmt_kp( $a['no_kp'] ) ); ?></td><td>No. HP</td><td><?php echo $e( $a['no_hp'] ); ?></td></tr>
		<tr><td>Alamat Rumah</td><td colspan="3"><?php echo nl2br( $e( $a['alamat'] ) ); ?></td></tr>
		<tr><td>Pekerjaan</td><td><?php echo $e( $a['pekerjaan'] ); ?></td><td>Sektor</td><td><?php echo $e( $a['sektor'] ); ?></td></tr>
		<tr><td>Majikan</td><td><?php echo $e( $a['majikan'] ); ?></td><td>E-mel</td><td><?php echo $e( $a['email'] ); ?></td></tr>
		<tr><td>Tel. (P)</td><td><?php echo $e( $a['tel_p'] ); ?></td><td>Tel. (R)</td><td><?php echo $e( $a['tel_r'] ); ?></td></tr></table>
	<h3>BAHAGIAN B : MAKLUMAT SUAMI / ISTERI</h3>
	<table class="kv"><tr><td>Nama Penuh</td><td colspan="3"><?php echo $e( $a['p_nama'] ); ?></td></tr>
		<tr><td>No. KP</td><td><?php echo $e( pk_fmt_kp( $a['p_kp'] ) ); ?></td><td>No. HP</td><td><?php echo $e( $a['p_hp'] ); ?></td></tr>
		<tr><td>Pekerjaan</td><td><?php echo $e( $a['p_pekerjaan'] ); ?></td><td>Sektor</td><td><?php echo $e( $a['p_sektor'] ); ?></td></tr>
		<tr><td>Majikan</td><td><?php echo $e( $a['p_majikan'] ); ?></td><td>Tel. (P)</td><td><?php echo $e( $a['p_tel_p'] ); ?></td></tr></table>
	<h3>BAHAGIAN C : MAKLUMAT TANGGUNGAN</h3>
	<table class="grid"><tr><th style="width:5%">Bil</th><th>Nama</th><th style="width:15%">Tarikh Lahir</th><th style="width:14%">Hubungan</th><th style="width:9%">Status**</th><th style="width:10%">Tinggal Bersama*</th></tr>
	<?php for ( $i = 0; $i < max( 8, count( $tg ) ); $i++ ) : $r = $tg[ $i ] ?? []; ?>
		<tr><td><?php echo $i + 1; ?></td><td><?php echo $e( $r['nama'] ?? '' ); ?></td><td><?php echo ! empty( $r['tarikh_lahir'] ) ? esc_html( date_i18n( 'd/m/Y', strtotime( $r['tarikh_lahir'] ) ) ) : ''; ?></td><td><?php echo $e( $r['hubungan'] ?? '' ); ?></td><td><?php echo $e( $r['status_t'] ?? '' ); ?></td><td><?php echo $e( $r['tinggal'] ?? '' ); ?></td></tr>
	<?php endfor; ?></table>
	<p class="small">**Status: S-Sekolah, K-Kerja, P-Pencen &nbsp; *Tinggal Bersama: Y-Ya, T-Tidak</p>
	<h3>REKOD YURAN</h3>
	<table class="grid"><tr><th>Perkara</th><th>Cara</th><th>Jumlah</th><th>Dibayar</th><th>Baki</th></tr>
	<?php foreach ( $pl as $p ) : if ( 'batal' === $p['status'] ) { continue; } ?><tr><td><?php echo $e( $p['tajuk'] ); ?></td><td><?php echo $e( pk_label( 'mod', $p['cara'] ) ); ?></td><td><?php echo esc_html( pk_rm( $p['jumlah'] ) ); ?></td><td><?php echo esc_html( pk_rm( $p['dibayar'] ) ); ?></td><td><?php echo esc_html( pk_rm( max( 0, $p['jumlah'] - $p['dibayar'] ) ) ); ?></td></tr><?php endforeach; ?>
	<?php if ( ! $pl ) : ?><tr><td colspan="5">—</td></tr><?php endif; ?></table>
	<h3>BAHAGIAN D : PERAKUAN AHLI</h3>
	<p class="small">Saya seperti nama di atas bersetuju dengan terma dan syarat yang telah ditetapkan.</p>
	<div class="sig"><div>Tandatangan Ahli</div><div>Tarikh</div></div>
	<h3>URUSAN PEJABAT / AJK</h3>
	<table class="kv"><tr><td>Tarikh terima</td><td><?php echo esc_html( pk_date( substr( $a['created_at'], 0, 10 ) ) ); ?></td><td>Status</td><td><?php echo esc_html( pk_label( 'status_ahli', $a['status'] ) ); ?></td></tr></table>
	<div class="sig"><div>Disahkan oleh (Tandatangan AJK)</div><div>Disemak oleh (Tandatangan Nazir)</div></div>
	</body></html><?php
	exit;
} );
