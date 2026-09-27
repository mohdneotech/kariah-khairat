<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================= export */

/** Neutralise spreadsheet formula injection (=, +, -, @, tab, CR, pipe, full-width variants). */
function pk_csv_cell( $v ) {
	if ( ! is_string( $v ) || '' === $v || is_numeric( $v ) ) { return $v; }
	return preg_match( '/^[\s\x{00A0}]*[=+\-@\t\r|%\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $v ) ? "'" . $v : $v;
}

function pk_csv_out( $name, array $head, iterable $rows ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $name . '-' . gmdate( 'Ymd' ) . '.csv"' );
	$o = fopen( 'php://output', 'w' );
	fwrite( $o, "\xEF\xBB\xBF" ); // Excel UTF-8 BOM
	fputcsv( $o, $head );
	foreach ( $rows as $r ) {
		// guard against CSV formula injection
		fputcsv( $o, array_map( 'pk_csv_cell', $r ) );
	}
	fclose( $o );
	exit;
}

add_action( 'admin_post_pk_export_ahli', function () {
	if ( ! current_user_can( PK_CAP ) ) { wp_die(); }
	check_admin_referer( 'pk_export' );
	$args = [ 'status' => sanitize_key( pk_gs( 'status', '' ) ), 'bayar' => sanitize_key( pk_gs( 'bayar', '' ) ), 'kawasan' => sanitize_text_field( wp_unslash( pk_gs( 'kawasan', '' ) ) ), 's' => sanitize_text_field( wp_unslash( pk_gs( 's', '' ) ) ), 'sumber' => sanitize_key( pk_gs( 'sumber', '' ) ) ];
	[ , $rows ] = pk_ahli_query( $args, 0 );
	$yr = pk_year();
	$out = [];
	foreach ( $rows as $r ) {
		$tg = pk_tanggungan_get( $r['id'] );
		$out[] = [ $r['no_ahli'], $r['nama'], pk_fmt_kp( $r['no_kp'] ), $r['no_hp'], $r['email'], $r['alamat'], $r['kawasan'], $r['pekerjaan'], $r['sektor'], $r['majikan'],
			$r['p_nama'], pk_fmt_kp( $r['p_kp'] ), $r['p_hp'], count( $tg ), implode( '; ', array_map( fn( $t ) => $t['nama'] . ( $t['hubungan'] ? ' (' . $t['hubungan'] . ')' : '' ), $tg ) ),
			pk_label( 'status_ahli', $r['status'] ), $r['paid'] ? 'Ya' : 'Tidak', number_format( (float) $r['baki'], 2, '.', '' ), pk_label( 'sumber', $r['sumber'] ), $r['tarikh_daftar'], $r['created_at'], $r['catatan'] ];
	}
	pk_csv_out( 'ahli-khairat', [ 'No. Ahli', 'Nama', 'No. KP', 'No. HP', 'E-mel', 'Alamat', 'Kawasan', 'Pekerjaan', 'Sektor', 'Majikan', 'Pasangan', 'KP Pasangan', 'HP Pasangan', 'Bil. Tanggungan', 'Tanggungan', 'Status', "Yuran $yr dijelaskan", 'Baki (RM)', 'Sumber', 'Tarikh Daftar', 'Direkod', 'Catatan' ], $out );
} );

add_action( 'admin_post_pk_export_korban', function () {
	if ( ! current_user_can( PK_CAP ) ) { wp_die(); }
	check_admin_referer( 'pk_export' );
	$musim = sanitize_text_field( wp_unslash( pk_gs( 'musim', pk_opt( 'musim_korban' ) ) ) );
	$out   = [];
	foreach ( pk_korban_rows( $musim ) as $r ) {
		$ps = pk_peserta_get( $r['id'] );
		$out[] = [ pk_ref( 'korban', $r['id'] ), $r['musim'], pk_label( 'jenis_korban', $r['jenis'] ), $r['nama'], pk_fmt_kp( $r['no_kp'] ), $r['no_hp'], $r['alamat'],
			implode( '; ', array_map( fn( $p ) => $p['nama'] . ' x' . $p['bahagian'], $ps ) ), $r['bah'], $r['jumlah'], $r['dibayar'], max( 0, $r['jumlah'] - $r['dibayar'] ),
			false !== strpos( (string) $r['cara'], 'ansuran' ) ? 'Ansuran' : 'Sekaligus', pk_label( 'pilihan', $r['pilihan'] ), $r['bahagian_lain'], pk_label( 'status_korban', $r['status'] ), $r['created_at'] ];
	}
	pk_csv_out( 'korban-' . sanitize_file_name( $musim ), [ 'Rujukan', 'Musim', 'Jenis', 'Nama', 'No. KP', 'No. HP', 'Alamat', 'Peserta', 'Bahagian', 'Jumlah', 'Dibayar', 'Baki', 'Cara', 'Agihan', 'Bahagian lain', 'Status', 'Direkod' ], $out );
} );

add_action( 'admin_post_pk_export_bayaran', function () {
	if ( ! current_user_can( PK_CAP ) ) { wp_die(); }
	check_admin_referer( 'pk_export' );
	[ , $rows ] = pk_bayaran_query( pk_bayaran_filters_from_get(), 0 );
	$out = [];
	foreach ( $rows as $r ) { $out[] = [ $r['tarikh'], $r['nama'], $r['no_ahli'], 'khairat' === $r['jenis'] ? 'Khairat' : 'Korban/Aqiqah', $r['tajuk'], pk_label( 'mod', $r['cara'] ), $r['jumlah'], pk_label( 'kaedah', $r['kaedah'] ), $r['no_resit'], pk_label( 'status_bayar', $r['status'] ), pk_label( 'sumber', $r['sumber'] ), $r['catatan'] ]; }
	pk_csv_out( 'bayaran', [ 'Tarikh', 'Nama', 'No. Ahli', 'Jenis', 'Perkara', 'Cara', 'Jumlah (RM)', 'Kaedah', 'No. Resit', 'Status', 'Sumber', 'Catatan' ], $out );
} );

/* ================================================================= import */

function pk_page_import() {
	pk_admin_header( 'Import / Eksport' );
	$res = get_transient( 'pk_import_res_' . get_current_user_id() );
	if ( $res ) { delete_transient( 'pk_import_res_' . get_current_user_id() ); echo '<div class="notice notice-info"><p>' . wp_kses_post( $res ) . '</p></div>'; }
	?>
	<div class="pk-grid2">
	<div class="pk-box">
		<h2><span class="dashicons dashicons-upload"></span> Import Ahli Khairat (CSV)</h2>
		<p>Menerima dua format:</p>
		<ol>
			<li><b>Respons Google Form "Kutipan Khairat Kematian"</b> — muat turun dari Google Sheets (Fail → Muat turun → CSV). Lajur <i>Nama Penuh, No Kad Pengenalan, No. Telefon, Alamat Rumah, Maklumat Isi Rumah, Jumlah bayaran, Tunggakan…, Resit Pembayaran</i> dikenal pasti secara automatik.</li>
			<li><b>Templat sistem</b> — <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pk_template' ), 'pk_export' ) ); ?>">muat turun templat CSV</a> (untuk rekod buku / Excel lama).</li>
		</ol>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pk_import' ); ?><input type="hidden" name="action" value="pk_import">
			<p><input type="file" name="csv" accept=".csv,text/csv" required></p>
			<p><label>Status rekod diimport <?php echo pk_select( 'status', [ 'aktif' => 'Aktif', 'menunggu' => 'Menunggu Pengesahan' ], 'aktif', '', null ); ?></label></p>
			<p><label><input type="checkbox" name="sah" value="1" checked> Tandakan bayaran dalam fail sebagai <b>disahkan</b></label></p>
			<p><label><input type="checkbox" name="dry" value="1" checked> Cubaan sahaja (tidak simpan — lihat ringkasan dahulu)</label></p>
			<p><button class="button button-primary">Import</button></p>
		</form>
		<p class="description">Rekod dengan No. KP yang telah wujud akan dilangkau (tiada pendua).</p>
	</div>
	<div class="pk-box">
		<h2><span class="dashicons dashicons-download"></span> Eksport</h2>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pk_export_ahli' ), 'pk_export' ) ); ?>">Semua ahli khairat (CSV)</a></p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pk_export_korban&musim=' . rawurlencode( pk_opt( 'musim_korban' ) ) ), 'pk_export' ) ); ?>">Korban &amp; aqiqah <?php echo esc_html( pk_opt( 'musim_korban' ) ); ?> (CSV)</a></p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pk_export_bayaran' ), 'pk_export' ) ); ?>">Semua transaksi bayaran (CSV)</a></p>
		<p class="description">Fail CSV boleh dibuka terus dalam Excel / Google Sheets. Senarai yang ditapis boleh dieksport dari halaman senarai masing-masing.</p>
		<p class="description"><b>Peringatan PDPA:</b> fail eksport mengandungi No. KP dan alamat. Simpan dengan selamat dan jangan kongsi di kumpulan WhatsApp.</p>
	</div>
	</div></div>
	<?php
}

add_action( 'admin_post_pk_template', function () {
	if ( ! current_user_can( PK_CAP ) ) { wp_die(); }
	check_admin_referer( 'pk_export' );
	pk_csv_out( 'templat-ahli-khairat', [ 'no_ahli', 'nama', 'no_kp', 'no_hp', 'email', 'alamat', 'kawasan', 'pekerjaan', 'sektor', 'majikan', 'p_nama', 'p_kp', 'p_hp', 'tanggungan', 'tarikh_daftar', 'tahun_berbayar', 'catatan' ],
		[ [ 'KK-0001', 'AHMAD BIN ALI', '700101105123', '012-3456789', '', 'No 1, Jalan Masjid, Kampung Contoh', 'Kampung Contoh', 'Pesara', 'Pesara', '', 'AMINAH BINTI ABU', '720202105124', '', 'SITI BINTI AHMAD (Anak); ALI BIN AHMAD (Anak)', '2020-01-15', '2025,2026', '' ] ] );
} );

function pk_norm_head( $h ) { return trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( (string) $h ) ) ), '_' ); }

add_action( 'admin_post_pk_import', function () {
	pk_guard( 'pk_import' );
	if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { pk_back( 'Tiada fail.' ); }
	$fh = fopen( $_FILES['csv']['tmp_name'], 'r' );
	$first = fgets( $fh ); rewind( $fh );
	$delim = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
	$head  = fgetcsv( $fh, 0, $delim );
	if ( ! $head ) { pk_back( 'Fail CSV kosong.' ); }
	$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
	$map = [];
	foreach ( $head as $i => $h ) {
		$n = pk_norm_head( $h );
		$key = match ( true ) {
			str_starts_with( $n, 'timestamp' ) || 'cap_masa' === $n => 'ts',
			in_array( $n, [ 'email', 'email_address', 'alamat_e_mel', 'e_mel' ], true ) => 'email',
			in_array( $n, [ 'nama_penuh', 'nama' ], true ) => 'nama',
			str_starts_with( $n, 'no_kad_pengenalan' ) || 'no_kp' === $n => 'no_kp',
			str_starts_with( $n, 'no_telefon' ) || 'no_hp' === $n => 'no_hp',
			'alamat_rumah' === $n || 'alamat' === $n => 'alamat',
			str_starts_with( $n, 'berapa_orang_isi_rumah' ) => 'bil_isi',
			str_starts_with( $n, 'maklumat_isi_rumah' ) || 'tanggungan' === $n => 'tanggungan',
			str_starts_with( $n, 'jumlah_bayaran' ) => 'bayaran',
			str_starts_with( $n, 'tunggakan' ) => 'tunggakan',
			str_starts_with( $n, 'resit' ) => 'resit',
			in_array( $n, [ 'no_ahli', 'kawasan', 'pekerjaan', 'sektor', 'majikan', 'p_nama', 'p_kp', 'p_hp', 'tarikh_daftar', 'tahun_berbayar', 'catatan' ], true ) => $n,
			default => null,
		};
		if ( $key && ! isset( $map[ $key ] ) ) { $map[ $key ] = $i; }
	}
	if ( ! isset( $map['nama'] ) ) { pk_back( 'Lajur "Nama" / "Nama Penuh" tidak ditemui dalam fail.' ); }

	$dry = (bool) pk_p( 'dry' ); $sah = (bool) pk_p( 'sah' ); $st = 'menunggu' === pk_p( 'status' ) ? 'menunggu' : 'aktif';
	$yr_now = pk_year();
	$n_ok = 0; $n_dup = 0; $n_bad = 0; $n_pay = 0; $sum = 0.0; $seen = []; $dups = [];
	while ( ( $row = fgetcsv( $fh, 0, $delim ) ) !== false ) {
		$g = fn( $k ) => isset( $map[ $k ] ) ? trim( (string) ( $row[ $map[ $k ] ] ?? '' ) ) : '';
		$nama = $g( 'nama' );
		if ( '' === $nama ) { continue; }
		$kp = pk_digits( $g( 'no_kp' ) );
		if ( $kp && ( isset( $seen[ $kp ] ) || pk_find_ahli_by_kp( $kp ) ) ) { $n_dup++; $dups[] = $nama; continue; }
		if ( $kp ) { $seen[ $kp ] = 1; }
		$ts   = $g( 'ts' ) ? strtotime( $g( 'ts' ) ) : false;
		$date = $ts ? gmdate( 'Y-m-d', $ts ) : ( pk_date_in( $g( 'tarikh_daftar' ) ) ?: pk_today() );
		$yr   = (int) substr( $date, 0, 4 );

		// payments (Google Form wording: "RM 66 (Pendaftaran + Tahunan)", "RM 72 (Tahun 2024/2025)")
		$plans = [];
		$bay   = $g( 'bayaran' );
		if ( $bay && preg_match( '/RM\s*([\d.]+)/i', $bay, $m ) ) {
			$baru = stripos( $bay, 'daftar' ) !== false;
			$plans[] = [ 'kategori' => $baru ? 'daftar' : 'tahunan', 'liputan' => [ $yr ], 'tajuk' => ( $baru ? 'Pendaftaran + Yuran Tahunan ' : 'Yuran Tahunan ' ) . $yr, 'jumlah' => (float) $m[1] ];
		}
		$tg_s = $g( 'tunggakan' );
		if ( $tg_s && preg_match( '/RM\s*([\d.]+)/i', $tg_s, $m ) ) {
			preg_match_all( '/(20\d\d)/', $tg_s, $yy );
			$ys = array_map( 'intval', $yy[1] ?: [ $yr - 1 ] );
			$plans[] = [ 'kategori' => 'tunggakan', 'liputan' => $ys, 'tajuk' => 'Tunggakan ' . implode( ', ', $ys ), 'jumlah' => (float) $m[1] ];
		}
		if ( $g( 'tahun_berbayar' ) ) {
			foreach ( array_filter( array_map( 'intval', preg_split( '/[\s,;\/]+/', $g( 'tahun_berbayar' ) ) ) ) as $y ) {
				$plans[] = [ 'kategori' => 'tahunan', 'liputan' => [ $y ], 'tajuk' => "Yuran Tahunan $y (rekod lama)", 'jumlah' => (float) pk_opt( 'yuran_tahunan' ) ];
			}
		}

		// dependants: split by line / numbering / semicolon
		$tgs = [];
		$raw = $g( 'tanggungan' );
		if ( $raw && ! preg_match( '/^\s*[-–]?\s*(tiada)?\s*$/i', $raw ) ) {
			foreach ( preg_split( '/\r?\n|;|(?:^|\s)\d{1,2}[.)]\s*/', $raw ) as $part ) {
				$part = trim( $part, " \t-,." );
				if ( mb_strlen( $part ) < 3 ) { continue; }
				$hub = '';
				if ( preg_match( '/\(([^)]+)\)\s*$/', $part, $hm ) ) { $hub = ucfirst( strtolower( trim( $hm[1] ) ) ); $part = trim( preg_replace( '/\([^)]+\)\s*$/', '', $part ) ); }
				if ( ! $hub && preg_match( '/^(isteri|suami|anak|ibu|bapa)\s*[-:]\s*(.+)$/iu', $part, $hm ) ) { $hub = ucfirst( strtolower( $hm[1] ) ); $part = trim( $hm[2] ); }
				if ( ! $hub && preg_match( '/\b(isteri|suami)\b/i', $part, $hm ) ) { $hub = ucfirst( strtolower( $hm[1] ) ); $part = trim( preg_replace( '/\s*[-:]?\s*\b(isteri|suami)\b\s*[-:]?\s*/i', ' ', $part ) ); }
				$tgs[] = [ 'nama' => $part, 'hubungan' => array_key_exists( $hub, pk_label( 'hubungan' ) ) ? $hub : '' ];
			}
		}

		$n_ok++;
		foreach ( $plans as $pl ) { $n_pay++; $sum += $pl['jumlah']; }
		if ( $dry ) { continue; }

		$note = [];
		if ( $g( 'resit' ) ) { $note[] = 'Resit (Google Form): ' . $g( 'resit' ); }
		if ( $g( 'bil_isi' ) ) { $note[] = 'Bil. isi rumah dinyatakan: ' . $g( 'bil_isi' ); }
		if ( $g( 'catatan' ) ) { $note[] = $g( 'catatan' ); }
		$id = pk_save_ahli_data( [
			'no_ahli' => $g( 'no_ahli' ), 'nama' => $nama, 'no_kp' => $kp, 'no_hp' => $g( 'no_hp' ), 'email' => sanitize_email( $g( 'email' ) ), 'alamat' => $g( 'alamat' ),
			'kawasan' => $g( 'kawasan' ), 'pekerjaan' => $g( 'pekerjaan' ), 'sektor' => $g( 'sektor' ), 'majikan' => $g( 'majikan' ),
			'p_nama' => $g( 'p_nama' ), 'p_kp' => $g( 'p_kp' ), 'p_hp' => $g( 'p_hp' ), 'status' => $st, 'sumber' => 'import', 'tarikh_daftar' => $date, 'catatan' => implode( "\n", $note ),
		] );
		global $wpdb;
		$wpdb->update( pk_t( 'ahli' ), [ 'created_at' => $date . ( $ts ? gmdate( ' H:i:s', $ts ) : ' 00:00:00' ) ], [ 'id' => $id ] );
		pk_tanggungan_replace( $id, $tgs );
		foreach ( $plans as $pl ) {
			$pid = pk_pelan_create( array_merge( $pl, [ 'jenis' => 'khairat', 'ref_id' => $id, 'cara' => 'sekaligus', 'tarikh_mula' => $date ] ) );
			pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $pl['jumlah'], 'tarikh' => $date, 'kaedah' => 'qr', 'status' => $sah ? 'sah' : 'menunggu', 'sumber' => 'import', 'catatan' => $g( 'resit' ) ? 'Bukti dalam Google Form' : '' ] );
		}
	}
	fclose( $fh );
	$msg = ( $dry ? '<b>Cubaan (tiada disimpan):</b> ' : '<b>Import selesai:</b> ' ) . "$n_ok ahli" . ( $dry ? ' akan diimport' : ' diimport' ) . ", $n_pay rekod bayaran (" . pk_rm( $sum ) . "), $n_dup dilangkau kerana No. KP sudah wujud/berulang."
		. ( $dups ? '<br><small>Dilangkau: ' . esc_html( implode( ', ', array_slice( $dups, 0, 20 ) ) ) . ( count( $dups ) > 20 ? '…' : '' ) . '</small>' : '' )
		. ( $dry ? '<br>Nyahtanda "Cubaan sahaja" dan import semula untuk menyimpan.' : '' );
	set_transient( 'pk_import_res_' . get_current_user_id(), $msg, 300 );
	pk_back();
} );
