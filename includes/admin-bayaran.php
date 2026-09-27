<?php
defined( 'ABSPATH' ) || exit;

function pk_bayaran_query( $f, $limit = 50, $offset = 0 ) {
	global $wpdb;
	$b = pk_t( 'bayaran' ); $p = pk_t( 'pelan' ); $a = pk_t( 'ahli' ); $k = pk_t( 'korban' );
	$w = [ '1=1' ]; $v = [];
	if ( ! empty( $f['status'] ) ) { $w[] = 'b.status=%s'; $v[] = $f['status']; }
	if ( ! empty( $f['jenis'] ) ) { $w[] = 'p.jenis=%s'; $v[] = $f['jenis']; }
	if ( ! empty( $f['kaedah'] ) ) { $w[] = 'b.kaedah=%s'; $v[] = $f['kaedah']; }
	if ( ! empty( $f['cara'] ) ) { $w[] = 'p.cara=%s'; $v[] = $f['cara']; }
	if ( ! empty( $f['dari'] ) ) { $w[] = 'b.tarikh>=%s'; $v[] = $f['dari']; }
	if ( ! empty( $f['hingga'] ) ) { $w[] = 'b.tarikh<=%s'; $v[] = $f['hingga']; }
	if ( ! empty( $f['s'] ) ) { $like = '%' . $wpdb->esc_like( $f['s'] ) . '%'; $w[] = '(COALESCE(a.nama,k.nama) LIKE %s OR b.no_resit LIKE %s)'; array_push( $v, $like, $like ); }
	$from = "FROM $b b JOIN $p p ON p.id=b.pelan_id LEFT JOIN $a a ON p.jenis='khairat' AND a.id=p.ref_id LEFT JOIN $k k ON p.jenis='korban' AND k.id=p.ref_id WHERE " . implode( ' AND ', $w );
	$sum  = "SELECT COUNT(*) n, COALESCE(SUM(b.jumlah),0) t $from";
	$sql  = "SELECT b.*, p.jenis, p.ref_id, p.tajuk, p.cara, COALESCE(a.nama,k.nama) AS nama, COALESCE(a.no_ahli,'') AS no_ahli $from ORDER BY (b.status='menunggu') DESC, b.tarikh DESC, b.id DESC";
	if ( $v ) { $sum = $wpdb->prepare( $sum, $v ); $sql = $wpdb->prepare( $sql, $v ); }
	if ( $limit ) { $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset ); }
	return [ $wpdb->get_row( $sum, ARRAY_A ), $wpdb->get_results( $sql, ARRAY_A ) ];
}

function pk_bayaran_filters_from_get() {
	return [
		'status' => sanitize_key( pk_gs( 'status', '' ) ), 'jenis' => sanitize_key( pk_gs( 'jenis', '' ) ), 'kaedah' => sanitize_key( pk_gs( 'kaedah', '' ) ),
		'cara' => sanitize_key( pk_gs( 'cara', '' ) ), 'dari' => pk_date_in( pk_gs( 'dari' ) ), 'hingga' => pk_date_in( pk_gs( 'hingga' ) ),
		's' => sanitize_text_field( wp_unslash( pk_gs( 's', '' ) ) ),
	];
}

function pk_page_bayaran() {
	global $wpdb;
	$f    = pk_bayaran_filters_from_get();
	$per  = 50; $cur = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	[ $sum, $rows ] = pk_bayaran_query( $f, $per, ( $cur - 1 ) * $per );
	$base = add_query_arg( array_filter( $f ), admin_url( 'admin.php?page=pk-bayaran' ) );
	$counts = array_map( fn( $r ) => (int) $r->n, $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . pk_t( 'bayaran' ) . ' GROUP BY status', OBJECT_K ) );
	pk_admin_header( 'Bayaran & Ansuran', ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array_filter( $f ), [ 'action' => 'pk_export_bayaran' ] ), admin_url( 'admin-post.php' ) ), 'pk_export' ) ) . '">Eksport CSV</a>' );
	echo pk_filter_links( 'status', pk_label( 'status_bayar' ), $counts, $base );
	?>
	<form method="get" class="pk-filters"><input type="hidden" name="page" value="pk-bayaran"><?php if ( $f['status'] ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $f['status'] ); ?>"><?php endif; ?>
		<?php echo pk_select( 'jenis', [ 'khairat' => 'Khairat', 'korban' => 'Korban/Aqiqah' ], $f['jenis'], '', 'Semua jenis' ); ?>
		<?php echo pk_select( 'cara', pk_label( 'mod' ), $f['cara'], '', 'Sekaligus & ansuran' ); ?>
		<?php echo pk_select( 'kaedah', pk_label( 'kaedah' ), $f['kaedah'], '', 'Semua kaedah' ); ?>
		<label>Dari <input type="date" name="dari" value="<?php echo esc_attr( $f['dari'] ); ?>"></label> <label>Hingga <input type="date" name="hingga" value="<?php echo esc_attr( $f['hingga'] ); ?>"></label>
		<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="Nama / No. resit"><button class="button">Tapis</button></form>
	<div class="tablenav top"><div class="alignleft pk-count"><b><?php echo (int) $sum['n']; ?></b> transaksi · Jumlah <b><?php echo esc_html( pk_rm( $sum['t'] ) ); ?></b></div><?php echo pk_pager( (int) $sum['n'], $per, $cur ); ?></div>
	<table class="wp-list-table widefat fixed striped pk-list">
		<thead><tr><th style="width:8%">Tarikh</th><th style="width:15%">Nama</th><th style="width:16%">Perkara</th><th style="width:8%">Jumlah</th><th style="width:10%">Kaedah</th><th style="width:8%">No. Resit</th><th style="width:13%">Bukti &amp; Resit</th><th style="width:10%">Status</th><th style="width:12%">Tindakan</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="9">Tiada rekod.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $r ) :
			$url = 'khairat' === $r['jenis'] ? admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $r['ref_id'] ) : admin_url( 'admin.php?page=pk-korban&action=edit&id=' . $r['ref_id'] ); ?>
			<tr class="<?php echo 'menunggu' === $r['status'] ? 'pk-hl' : ''; ?>"><td><?php echo esc_html( pk_date( $r['tarikh'] ) ); ?></td>
				<td><a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $r['nama'] ?: '(dipadam)' ); ?></strong></a><div class="description"><?php echo esc_html( ( 'khairat' === $r['jenis'] ? 'Khairat' : 'Korban/Aqiqah' ) . ( $r['no_ahli'] ? ' · ' . $r['no_ahli'] : '' ) ); ?></div></td>
				<td><?php echo esc_html( $r['tajuk'] ); ?> <?php echo 'ansuran' === $r['cara'] ? '<span class="pk-chip">Ansuran</span>' : ''; ?><?php echo $r['catatan'] ? '<div class="description">' . esc_html( $r['catatan'] ) . '</div>' : ''; ?></td>
				<td><b><?php echo esc_html( pk_rm( $r['jumlah'] ) ); ?></b></td>
				<td><?php echo esc_html( pk_label( 'kaedah', $r['kaedah'] ) ); ?><div class="description"><?php echo esc_html( pk_label( 'sumber', $r['sumber'] ) ); ?></div></td>
				<td><?php echo esc_html( $r['no_resit'] ?: '—' ); ?></td>
				<td><?php echo pk_admin_files_cell( $r ); ?></td>
				<td><?php echo pk_badge( 'status_bayar', $r['status'] ); ?></td>
				<td class="pk-actions"><?php
					if ( pk_self_review_blocked( pk_is_own_pelan( [ 'jenis' => $r['jenis'], 'ref_id' => $r['ref_id'] ] ) ) ) { echo '<span class="pk-chip pk-chip-alt" title="Perlu disahkan oleh AJK lain">Rekod anda — AJK lain sahkan</span>'; }
					else {
						if ( 'sah' !== $r['status'] ) { echo pk_post_btn( 'pk_bayaran_status', [ 'id' => $r['id'], 'status' => 'sah' ], 'Sahkan', 'button-primary' ); }
						if ( 'menunggu' === $r['status'] ) { echo pk_post_btn( 'pk_bayaran_status', [ 'id' => $r['id'], 'status' => 'ditolak' ], 'Tolak' ); }
					}
				?></td></tr>
		<?php endforeach; ?></tbody></table>
	<div class="tablenav bottom"><?php echo pk_pager( (int) $sum['n'], $per, $cur ); ?></div>
	<p class="description">Mengesahkan bayaran pertama permohonan khairat yang masih "Menunggu" akan mengaktifkan ahli tersebut dan memberikan No. Ahli secara automatik.</p>
	</div>
	<?php
}
