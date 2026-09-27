<?php
defined( 'ABSPATH' ) || exit;

$GLOBALS['pk_errors'] = [];

const PK_FA_URL = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css';
const PK_FA_SRI = 'sha384-PPIZEGYM1v8zp5Py7UjFb79S58UeqCL9pYVnVPURKEqvioPROaVAJKKLzvH2rDnI';
const PK_SHORTCODES = [ 'pk_borang_khairat', 'pk_borang_korban', 'pk_semak', 'pk_portal', 'pk_muat_turun' ];

/** Register front-end assets. Safe to call early: block themes render shortcodes before wp_enqueue_scripts fires. */
function pk_register_public() {
	if ( wp_script_is( 'pk-public', 'registered' ) ) { return; }
	wp_register_style( 'pk-public', PK_URL . 'assets/pk-public.css', [], PK_VER );
	wp_register_script( 'pk-public', PK_URL . 'assets/pk-public.js', [], PK_VER, true );
	wp_register_style( 'pk-fontawesome', PK_FA_URL, [], null );
}
add_action( 'wp_enqueue_scripts', 'pk_register_public' );

add_filter( 'style_loader_tag', function ( $tag, $handle ) {
	return 'pk-fontawesome' === $handle ? str_replace( ' href=', ' integrity="' . PK_FA_SRI . '" crossorigin="anonymous" href=', $tag ) : $tag;
}, 10, 2 );

/** Does the theme (or another plugin) already load Font Awesome? */
function pk_theme_has_fa() {
	$st = wp_styles();
	foreach ( array_merge( $st->queue, $st->done ) as $h ) {
		if ( 'pk-fontawesome' === $h || empty( $st->registered[ $h ] ) ) { continue; }
		if ( preg_match( '/font-?awesome/i', $h . ' ' . (string) $st->registered[ $h ]->src ) ) { return true; }
	}
	return false;
}

/** Every plugin shortcode is wrapped in .pk-ui (scoped styles) with the colours from Tetapan. */
add_filter( 'do_shortcode_tag', function ( $out, $tag ) {
	if ( ! in_array( $tag, PK_SHORTCODES, true ) ) { return $out; }
	$g = sanitize_hex_color( pk_opt( 'warna_utama' ) ) ?: '#527c3a';
	$l = sanitize_hex_color( pk_opt( 'warna_aksen' ) ) ?: '#8dc642';
	return '<div class="pk-ui" style="--pk-g:' . esc_attr( $g ) . ';--pk-leaf:' . esc_attr( $l ) . ';">' . $out . '</div>';
}, 10, 2 );

function pk_enqueue_public() {
	pk_register_public();
	wp_enqueue_style( 'pk-public' );
	wp_enqueue_script( 'pk-public' );
	wp_localize_script( 'pk-public', 'PK', [
		'daftar'  => (float) pk_opt( 'yuran_daftar' ),
		'tahunan' => (float) pk_opt( 'yuran_tahunan' ),
		'harga'   => (float) pk_opt( 'harga_bahagian' ),
	] );
	// Font Awesome: decide once the theme's own styles are queued.
	if ( did_action( 'wp_enqueue_scripts' ) ) { pk_maybe_fa(); }
	elseif ( ! has_action( 'wp_enqueue_scripts', 'pk_maybe_fa' ) ) { add_action( 'wp_enqueue_scripts', 'pk_maybe_fa', 100 ); }
}

function pk_maybe_fa() {
	$fa = pk_opt( 'fontawesome' );
	if ( 'cdn' === $fa || ( 'auto' === $fa && ! pk_theme_has_fa() ) ) { wp_enqueue_style( 'pk-fontawesome' ); }
}

function pk_ref( $type, $id ) { return ( 'khairat' === $type ? 'KHR-' : 'KRB-' ) . str_pad( (string) $id, 5, '0', STR_PAD_LEFT ); }

/* ================================================================= POST handling (PRG) */

add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['pk_action'] ) ) { return; }
	$act = is_string( $_POST['pk_action'] ) ? sanitize_key( $_POST['pk_action'] ) : '';
	if ( ! in_array( $act, [ 'khairat', 'korban', 'bayar' ], true ) ) { return; }

	$err = &$GLOBALS['pk_errors'];
	if ( ! wp_verify_nonce( $_POST['pk_nonce'] ?? '', 'pk_' . $act ) ) { $err[] = 'Sesi borang telah tamat. Sila muat semula halaman dan cuba lagi.'; return; }
	if ( ! empty( $_POST['pk_web'] ) ) { $err[] = 'Penghantaran ditolak.'; return; } // honeypot
	if ( in_array( $act, [ 'khairat', 'korban' ], true ) && ( time() - (int) ( $_POST['pk_ts'] ?? 0 ) ) < 8 ) { $err[] = 'Borang dihantar terlalu cepat. Sila semak maklumat dan hantar semula.'; return; }
	if ( ! pk_throttle( 'submit', 12, HOUR_IN_SECONDS ) ) { $err[] = 'Terlalu banyak penghantaran dari rangkaian anda. Sila cuba sebentar lagi.'; return; }

	$fn  = 'pk_handle_' . $act;
	$res = $fn();
	if ( is_wp_error( $res ) ) { $err = array_merge( $err, $res->get_error_messages() ); return; }
	wp_safe_redirect( add_query_arg( [ 'pk_ok' => $act, 'ref' => $res ], strtok( wp_get_referer() ?: home_url( add_query_arg( [] ) ), '?' ) ) . '#pk-top' );
	exit;
} );

function pk_payment_from_post( $total ) {
	$cara    = pk_p( 'cara' ) === 'ansuran' ? 'ansuran' : 'sekaligus';
	$bil     = 'ansuran' === $cara ? max( 2, min( (int) pk_opt( 'max_ansuran' ), (int) pk_p( 'bil_ansuran', 2 ) ) ) : 1;
	$kaedah  = array_key_exists( pk_p( 'kaedah' ), pk_label( 'kaedah' ) ) ? pk_p( 'kaedah' ) : 'qr';
	$bayar   = pk_money( pk_p( 'jumlah_bayar' ) );
	if ( $bayar > $total ) { $bayar = $total; }
	return compact( 'cara', 'bil', 'kaedah', 'bayar' );
}

/* ---------------------------------------------------------------- khairat */

function pk_handle_khairat() {
	if ( ! pk_opt( 'buka_khairat' ) ) { return new WP_Error( 'x', 'Pendaftaran dalam talian ditutup buat masa ini.' ); }
	$e    = new WP_Error();
	$nama = pk_p( 'nama', '', 150 ); $kp = pk_digits( pk_p( 'no_kp', '', 20 ) ); $hp = pk_p( 'no_hp', '', 20 ); $alamat = pk_pt( 'alamat', 500 );
	if ( mb_strlen( $nama ) < 3 ) { $e->add( 'x', 'Sila isi Nama Penuh.' ); }
	if ( 12 !== strlen( $kp ) ) { $e->add( 'x', 'No. Kad Pengenalan mestilah 12 digit.' ); }
	if ( strlen( pk_digits( $hp ) ) < 9 ) { $e->add( 'x', 'Sila isi No. Telefon bimbit yang sah.' ); }
	if ( mb_strlen( $alamat ) < 8 ) { $e->add( 'x', 'Sila isi Alamat Rumah.' ); }
	if ( empty( $_POST['perakuan'] ) ) { $e->add( 'x', 'Sila tandakan perakuan bersetuju dengan terma dan syarat.' ); }
	$email = sanitize_email( pk_p( 'email' ) );

	$yr     = pk_year();
	$jenis  = pk_p( 'jenis_mohon' ) === 'baharui' ? 'baharui' : 'baru';
	$tunggak = array_values( array_unique( array_filter( array_map( 'intval', array_filter( (array) ( $_POST['tunggakan'] ?? [] ), 'is_scalar' ) ), fn( $y ) => $y >= $yr - 5 && $y < $yr ) ) );
	if ( 'baru' === $jenis ) { $tunggak = []; }
	$total = ( 'baru' === $jenis ? (float) pk_opt( 'yuran_daftar' ) : 0 ) + (float) pk_opt( 'yuran_tahunan' ) * ( 1 + count( $tunggak ) );
	$pay   = pk_payment_from_post( $total );

	$exist = pk_find_ahli_by_kp( $kp );
	if ( $exist && 'baru' === $jenis && in_array( $exist['status'], [ 'aktif', 'menunggu' ], true ) ) {
		$e->add( 'x', 'Permohonan tidak dapat diproses kerana rekod dengan No. Kad Pengenalan ini sudah wujud. Sila pilih "Pembaharuan Yuran Tahunan", semak di halaman Semak Status & Bayar Ansuran, atau hubungi AJK.' );
	}
	if ( $pay['bayar'] > 0 && 'tunai' !== $pay['kaedah'] && empty( $_FILES['bukti']['tmp_name'] ) ) {
		$e->add( 'x', 'Sila muat naik bukti pembayaran (resit / tangkap layar), atau set "Jumlah dibayar sekarang" kepada 0 jika belum membuat bayaran.' );
	}
	if ( $e->has_errors() ) { return $e; }

	$bukti = pk_store_upload( 'bukti' );
	if ( is_wp_error( $bukti ) ) { return $bukti; }

	$data = [
		'nama' => $nama, 'no_kp' => $kp, 'no_hp' => $hp, 'email' => $email, 'alamat' => $alamat, 'kawasan' => pk_p( 'kawasan' ),
		'pekerjaan' => pk_p( 'pekerjaan' ), 'sektor' => pk_p( 'sektor' ), 'majikan' => pk_p( 'majikan' ), 'tel_p' => pk_p( 'tel_p' ), 'tel_r' => pk_p( 'tel_r' ),
		'p_nama' => pk_p( 'p_nama' ), 'p_kp' => pk_p( 'p_kp' ), 'p_hp' => pk_p( 'p_hp' ), 'p_pekerjaan' => pk_p( 'p_pekerjaan' ), 'p_sektor' => pk_p( 'p_sektor' ), 'p_majikan' => pk_p( 'p_majikan' ), 'p_tel_p' => pk_p( 'p_tel_p' ),
	];

	if ( $exist && 'baharui' === $jenis ) {
		$id = (int) $exist['id'];
		global $wpdb;
		$note = trim( ( $exist['catatan'] ?? '' ) . "\n[" . pk_now() . '] Pembaharuan dalam talian. Maklumat dihantar: HP ' . $hp . ', alamat: ' . preg_replace( '/\s+/', ' ', $alamat ) );
		$wpdb->update( pk_t( 'ahli' ), [ 'catatan' => $note, 'updated_at' => pk_now() ], [ 'id' => $id ] );
	} else {
		$data['status']  = 'menunggu';
		$data['sumber']  = 'online';
		$data['catatan'] = 'baharui' === $jenis ? 'Memohon sebagai ahli sedia ada (pembaharuan) — sila semak rekod lama.' : '';
		$id = pk_save_ahli_data( $data );
		pk_tanggungan_replace( $id, pk_tanggungan_from_post() );
		pk_log( $id, 'daftar', 'Pendaftaran dalam talian' );
		// logged-in user (e.g. AJK/admin registering themselves) without a linked record: link it to their account
		if ( is_user_logged_in() && ! pk_ahli_for_user() ) {
			global $wpdb;
			$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => get_current_user_id() ], [ 'id' => $id ] );
			pk_log( $id, 'akaun_pautkan', 'Didaftarkan sendiri oleh pengguna ' . wp_get_current_user()->user_login );
		}
	}

	$liputan = array_merge( $tunggak, [ $yr ] );
	sort( $liputan );
	$tajuk = ( 'baru' === $jenis ? 'Pendaftaran + Yuran Tahunan ' : 'Yuran Tahunan ' ) . implode( ', ', $liputan );
	$pid   = pk_pelan_create( [
		'jenis' => 'khairat', 'ref_id' => $id, 'kategori' => 'baru' === $jenis ? 'daftar' : ( $tunggak ? 'tunggakan' : 'tahunan' ),
		'liputan' => $liputan, 'tajuk' => $tajuk, 'jumlah' => $total, 'cara' => $pay['cara'], 'bil_ansuran' => $pay['bil'],
	] );
	if ( $pay['bayar'] > 0 ) {
		pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $pay['bayar'], 'kaedah' => $pay['kaedah'], 'bukti' => (string) $bukti, 'status' => 'menunggu', 'sumber' => 'online' ] );
	}
	pk_notify( 'Pendaftaran khairat baharu: ' . mb_strtoupper( $nama ), "Nama: $nama\nNo. HP: $hp\nJumlah: " . pk_rm( $total ) . ' (' . pk_label( 'mod', $pay['cara'] ) . ")\nDibayar sekarang: " . pk_rm( $pay['bayar'] ) . "\n\nSemak: " . admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $id ) );
	return pk_ref( 'khairat', $id );
}

/* ---------------------------------------------------------------- korban */

function pk_handle_korban() {
	if ( ! pk_opt( 'buka_korban' ) ) { return new WP_Error( 'x', 'Pendaftaran korban/aqiqah dalam talian ditutup buat masa ini.' ); }
	$e    = new WP_Error();
	$nama = pk_p( 'nama', '', 150 ); $kp = pk_digits( pk_p( 'no_kp', '', 20 ) ); $hp = pk_p( 'no_hp', '', 20 ); $alamat = pk_pt( 'alamat', 500 );
	if ( mb_strlen( $nama ) < 3 ) { $e->add( 'x', 'Sila isi Nama.' ); }
	if ( 12 !== strlen( $kp ) ) { $e->add( 'x', 'No. Kad Pengenalan mestilah 12 digit (untuk semakan status & bayaran ansuran).' ); }
	if ( strlen( pk_digits( $hp ) ) < 9 ) { $e->add( 'x', 'Sila isi No. HP yang sah.' ); }
	if ( mb_strlen( $alamat ) < 8 ) { $e->add( 'x', 'Sila isi Alamat.' ); }
	if ( empty( $_POST['wakalah'] ) ) { $e->add( 'x', 'Sila tandakan persetujuan Akad Wakalah.' ); }
	$ps  = pk_peserta_from_post();
	$bah = 0;
	foreach ( $ps as $r ) { if ( trim( $r['nama'] ?? '' ) !== '' ) { $bah += max( 1, min( 7, (int) ( $r['bahagian'] ?? 1 ) ) ); } }
	if ( $bah < 1 ) { $e->add( 'x', 'Sila isi sekurang-kurangnya seorang nama peserta.' ); }
	$pil = array_key_exists( pk_p( 'pilihan' ), pk_label( 'pilihan' ) ) ? pk_p( 'pilihan' ) : '';
	if ( ! $pil ) { $e->add( 'x', 'Sila pilih keputusan agihan daging (Bahagian D).' ); }
	$total = $bah * (float) pk_opt( 'harga_bahagian' );
	$pay   = pk_payment_from_post( $total );
	if ( $pay['bayar'] > 0 && 'tunai' !== $pay['kaedah'] && empty( $_FILES['bukti']['tmp_name'] ) ) {
		$e->add( 'x', 'Sila muat naik bukti pembayaran, atau set "Jumlah dibayar sekarang" kepada 0 jika belum membuat bayaran.' );
	}
	if ( $e->has_errors() ) { return $e; }
	$bukti = pk_store_upload( 'bukti' );
	if ( is_wp_error( $bukti ) ) { return $bukti; }

	$jenis = pk_p( 'jenis' ) === 'aqiqah' ? 'aqiqah' : 'korban';
	$lain  = array_intersect( array_map( 'sanitize_key', array_filter( (array) ( $_POST['bahagian_lain'] ?? [] ), 'is_scalar' ) ), array_keys( pk_label( 'bahagian_lain' ) ) );
	$id    = pk_save_korban_data( [
		'musim' => pk_opt( 'musim_korban' ), 'jenis' => $jenis, 'nama' => $nama, 'no_kp' => $kp, 'no_hp' => $hp, 'tel_r' => pk_p( 'tel_r' ),
		'email' => sanitize_email( pk_p( 'email' ) ), 'alamat' => $alamat, 'pilihan' => $pil, 'bahagian_lain' => implode( ',', $lain ),
		'status' => 'menunggu', 'sumber' => 'online', 'catatan' => pk_pt( 'catatan' ),
	] );
	pk_peserta_replace( $id, $ps );
	$pid = pk_pelan_create( [
		'jenis' => 'korban', 'ref_id' => $id, 'kategori' => $jenis, 'tajuk' => ucfirst( $jenis ) . ' ' . pk_opt( 'musim_korban' ) . " — $bah bahagian",
		'jumlah' => $total, 'cara' => $pay['cara'], 'bil_ansuran' => $pay['bil'],
	] );
	if ( $pay['bayar'] > 0 ) {
		pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $pay['bayar'], 'kaedah' => $pay['kaedah'], 'bukti' => (string) $bukti, 'status' => 'menunggu', 'sumber' => 'online' ] );
	}
	pk_notify( 'Pendaftaran ' . $jenis . ' baharu: ' . mb_strtoupper( $nama ), "Nama: $nama\nNo. HP: $hp\nBahagian: $bah\nJumlah: " . pk_rm( $total ) . ' (' . pk_label( 'mod', $pay['cara'] ) . ")\n\nSemak: " . admin_url( 'admin.php?page=pk-korban&action=edit&id=' . $id ) );
	return pk_ref( 'korban', $id );
}

/* ---------------------------------------------------------------- instalment / follow-up payment */

function pk_hp_match( $a, $b ) {
	$a = substr( pk_digits( $a ), -9 ); $b = substr( pk_digits( $b ), -9 );
	return strlen( $a ) >= 8 && $a === $b;
}

/** Verify kp+hp; returns [ahli rows, korban rows] */
function pk_semak_lookup( $kp, $hp ) {
	global $wpdb;
	$kp = pk_digits( $kp );
	if ( 12 !== strlen( $kp ) ) { return [ [], [] ]; }
	$ahli   = array_values( array_filter( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . " WHERE no_kp=%s AND status<>'ditolak'", $kp ), ARRAY_A ), fn( $r ) => pk_hp_match( $r['no_hp'], $hp ) ) );
	$korban = array_values( array_filter( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'korban' ) . " WHERE no_kp=%s AND status<>'batal' ORDER BY id DESC", $kp ), ARRAY_A ), fn( $r ) => pk_hp_match( $r['no_hp'], $hp ) ) );
	return [ $ahli, $korban ];
}

function pk_handle_bayar() {
	if ( ! pk_throttle( 'semak', 15, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'x', 'Terlalu banyak cubaan. Sila cuba lagi selepas 15 minit.' ); }
	[ $ahli, $korban ] = pk_semak_lookup( pk_p( 'kp' ), pk_p( 'hp' ) );
	$pid = (int) pk_p( 'pelan_id' );
	$p   = pk_get_pelan( $pid );
	$own = false;
	if ( $p ) {
		foreach ( 'khairat' === $p['jenis'] ? $ahli : $korban as $r ) { if ( (int) $r['id'] === (int) $p['ref_id'] ) { $own = true; } }
	}
	if ( ! $own || 'aktif' !== $p['status'] ) { return new WP_Error( 'x', 'Rekod tidak ditemui atau telah selesai dibayar.' ); }
	$amt = pk_money( pk_p( 'jumlah_bayar' ) );
	if ( $amt <= 0 ) { return new WP_Error( 'x', 'Sila isi jumlah bayaran.' ); }
	if ( empty( $_FILES['bukti']['tmp_name'] ) ) { return new WP_Error( 'x', 'Sila muat naik bukti pembayaran.' ); }
	$bukti = pk_store_upload( 'bukti' );
	if ( is_wp_error( $bukti ) ) { return $bukti; }
	$kaedah = array_key_exists( pk_p( 'kaedah' ), pk_label( 'kaedah' ) ) ? pk_p( 'kaedah' ) : 'qr';
	pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $amt, 'kaedah' => $kaedah, 'bukti' => $bukti, 'status' => 'menunggu', 'sumber' => 'online', 'catatan' => pk_pt( 'catatan' ) ] );
	[ $nama ] = pk_pelan_owner( $p );
	pk_notify( 'Bayaran ansuran diterima: ' . $nama, $p['tajuk'] . "\nJumlah: " . pk_rm( $amt ) . "\n\nSemak: " . admin_url( 'admin.php?page=pk-bayaran&status=menunggu' ) );
	return 'BYR-' . $pid;
}

/* ================================================================= render helpers */

function pk_v( $k, $default = '' ) { return esc_attr( isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $default ); }

function pk_render_errors() {
	if ( empty( $GLOBALS['pk_errors'] ) ) { return ''; }
	$h = '<div class="alert alert-danger pk-alert" role="alert"><strong><i class="fa-solid fa-triangle-exclamation me-1"></i> Sila betulkan perkara berikut:</strong><ul class="mb-0 mt-2">';
	foreach ( $GLOBALS['pk_errors'] as $m ) { $h .= '<li>' . esc_html( $m ) . '</li>'; }
	return $h . '</ul></div>';
}

function pk_render_success( $type ) {
	if ( ( $_GET['pk_ok'] ?? '' ) !== $type ) { return ''; }
	$ref = is_string( $_GET['ref'] ?? null ) && preg_match( '/^(KHR|KRB|BYR)-\d{1,9}$/', $_GET['ref'] ) ? $_GET['ref'] : '';
	$msg = [
		'khairat' => 'Pendaftaran Khairat Kematian anda telah diterima. AJK akan menyemak permohonan dan bayaran anda. No. Ahli akan diberikan selepas permohonan diluluskan.',
		'korban'  => 'Pendaftaran Korban/Aqiqah anda telah diterima. AJK akan menghubungi anda untuk pengesahan.',
		'bayar'   => 'Bukti bayaran anda telah dihantar dan akan disemak oleh Bendahari / AJK.',
	][ $type ];
	if ( 'khairat' === $type ) { $msg .= ' Selepas diluluskan, anda boleh mengaktifkan akaun Portal Ahli untuk menyemak rekod dan mengemas kini maklumat.'; }
	return '<div class="alert alert-success pk-alert" id="pk-top"><h5 class="alert-heading mb-1"><i class="fa-solid fa-circle-check me-1"></i> Terima kasih!</h5><p class="mb-1">' . esc_html( $msg ) . '</p>'
		. ( $ref ? '<p class="mb-0">No. rujukan: <strong>' . esc_html( $ref ) . '</strong>. Anda boleh menyemak status dan menghantar bayaran ansuran seterusnya melalui halaman <a href="' . esc_url( pk_page_url( 'semak' ) ) . '">Semak Status &amp; Bayar Ansuran</a>.</p>' : '' )
		. '<p class="small text-secondary mt-2 mb-0">' . esc_html( pk_opt( 'nota_khairat' ) ) . '</p></div>';
}

/** Shortcode behind each logical page (used for the fallback lookup and the setup screen). */
function pk_page_map() {
	return [
		'khairat'    => [ 'pk_borang_khairat', 'Daftar Khairat Kematian' ],
		'korban'     => [ 'pk_borang_korban', 'Daftar Korban & Aqiqah' ],
		'semak'      => [ 'pk_semak', 'Semak Status & Bayar Ansuran' ],
		'portal'     => [ 'pk_portal', 'Portal Ahli' ],
		'muat_turun' => [ 'pk_muat_turun', 'Muat Turun Borang' ],
	];
}

/** Published page holding a given shortcode (cached). */
function pk_find_page( $which ) {
	$map = pk_page_map();
	if ( empty( $map[ $which ] ) ) { return 0; }
	$ck = 'pk_pg_' . $which;
	$id = wp_cache_get( $ck, 'pk' );
	if ( false === $id ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s ORDER BY ID LIMIT 1",
			'%' . $wpdb->esc_like( '[' . $map[ $which ][0] ) . '%'
		) );
		wp_cache_set( $ck, $id, 'pk', HOUR_IN_SECONDS );
	}
	return (int) $id;
}

function pk_page_url( $which ) {
	$ids = (array) get_option( 'pk_pages', [] );
	$id  = ! empty( $ids[ $which ] ) && 'publish' === get_post_status( $ids[ $which ] ) ? (int) $ids[ $which ] : pk_find_page( $which );
	return $id ? get_permalink( $id ) : home_url( '/' );
}

function pk_common_fields( $act ) {
	return wp_nonce_field( 'pk_' . $act, 'pk_nonce', false, false )
		. '<input type="hidden" name="pk_action" value="' . esc_attr( $act ) . '"><input type="hidden" name="pk_ts" value="' . time() . '">'
		. '<div class="pk-hp" aria-hidden="true"><label>Laman web<input type="text" name="pk_web" tabindex="-1" autocomplete="off"></label></div>';
}

function pk_select( $name, $opts, $cur, $attrs = '', $blank = '— Pilih —' ) {
	$h = '<select class="form-select" name="' . esc_attr( $name ) . '" ' . $attrs . '>';
	if ( null !== $blank ) { $h .= '<option value="">' . esc_html( $blank ) . '</option>'; }
	foreach ( $opts as $k => $v ) { $h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $cur, (string) $k, false ) . '>' . esc_html( $v ) . '</option>'; }
	return $h . '</select>';
}

function pk_field( $label, $name, $opt = [] ) {
	$req  = ! empty( $opt['req'] );
	$type = $opt['type'] ?? 'text';
	$col  = $opt['col'] ?? 'col-md-6';
	$h    = '<div class="' . $col . '"><label class="form-label">' . esc_html( $label ) . ( $req ? ' <span class="text-danger">*</span>' : '' ) . '</label>';
	if ( 'textarea' === $type ) {
		$h .= '<textarea class="form-control" name="' . esc_attr( $name ) . '" rows="2"' . ( $req ? ' required' : '' ) . '>' . esc_textarea( is_scalar( $_POST[ $name ] ?? '' ) ? wp_unslash( $_POST[ $name ] ?? '' ) : '' ) . '</textarea>';
	} else {
		$h .= '<input type="' . esc_attr( $type ) . '" class="form-control" name="' . esc_attr( $name ) . '" value="' . pk_v( $name ) . '"' . ( $req ? ' required' : '' ) . ( $opt['attrs'] ?? '' ) . '>';
	}
	if ( ! empty( $opt['help'] ) ) { $h .= '<div class="form-text">' . esc_html( $opt['help'] ) . '</div>'; }
	return $h . '</div>';
}

function pk_payment_block( $total_label ) {
	$max = (int) pk_opt( 'max_ansuran' );
	$bil = [];
	for ( $i = 2; $i <= $max; $i++ ) { $bil[ $i ] = "$i bulan"; }
	$qr = pk_qr_url();
	ob_start(); ?>
	<div class="pk-card">
		<h3 class="pk-h"><span class="pk-step"><i class="fa-solid fa-wallet"></i></span> Cara Pembayaran</h3>
		<div class="pk-total">Jumlah perlu dibayar: <strong data-pk-total>RM 0.00</strong> <span class="text-secondary small"><?php echo esc_html( $total_label ); ?></span></div>
		<div class="row g-3 mt-1">
			<div class="col-md-6">
				<label class="form-label">Pilihan bayaran <span class="text-danger">*</span></label>
				<div class="pk-choice">
					<label><input type="radio" name="cara" value="sekaligus" <?php checked( pk_p( 'cara', 'sekaligus' ), 'sekaligus' ); ?>> <span><i class="fa-solid fa-money-bill-wave"></i> Sekaligus <small>bayar penuh sekali</small></span></label>
					<label><input type="radio" name="cara" value="ansuran" <?php checked( pk_p( 'cara' ), 'ansuran' ); ?>> <span><i class="fa-solid fa-piggy-bank"></i> Ansuran / Menabung <small>bayar sedikit demi sedikit</small></span></label>
				</div>
			</div>
			<div class="col-md-6 pk-ansuran-only">
				<label class="form-label">Tempoh ansuran</label>
				<?php echo pk_select( 'bil_ansuran', $bil, pk_p( 'bil_ansuran', '3' ), 'data-pk-bil', null ); ?>
				<div class="form-text">Anggaran sebulan: <strong data-pk-sebulan>RM 0.00</strong></div>
			</div>
			<div class="col-md-6">
				<label class="form-label">Jumlah dibayar sekarang (RM)</label>
				<input type="number" step="0.01" min="0" class="form-control" name="jumlah_bayar" data-pk-bayar value="<?php echo pk_v( 'jumlah_bayar' ); ?>">
				<div class="form-text">Isi 0 jika akan membayar kemudian / di masjid.</div>
			</div>
			<div class="col-md-6">
				<label class="form-label">Kaedah bayaran</label>
				<?php echo pk_select( 'kaedah', pk_label( 'kaedah' ), pk_p( 'kaedah', 'qr' ), '', null ); ?>
			</div>
			<div class="col-12">
				<div class="pk-paybox">
					<?php if ( $qr ) : ?><img src="<?php echo esc_url( $qr ); ?>" alt="<?php echo esc_attr( pk_opt( 'label_qr' ) . ' ' . pk_nama() ); ?>" loading="lazy"><?php endif; ?>
					<div>
						<div class="fw-semibold mb-1"><i class="fa-solid fa-building-columns text-mp me-1"></i> Akaun masjid</div>
						<div><?php echo pk_opt( 'bank' ) ? esc_html( pk_opt( 'bank' ) ) : '<em class="text-secondary">Butiran akaun bank belum ditetapkan — sila hubungi pejabat masjid.</em>'; ?></div>
						<div class="small text-secondary mt-2">Imbas <?php echo esc_html( pk_opt( 'label_qr' ) ?: 'kod QR' ); ?> atau buat pindahan ke akaun di atas, kemudian muat naik resit / tangkap layar sebagai bukti.</div>
						<label class="form-label mt-3">Bukti pembayaran <small class="text-secondary">(JPG, PNG atau PDF, maks. 10 MB)</small></label>
						<input type="file" class="form-control" name="bukti" accept="image/*,application/pdf">
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* ================================================================= [pk_borang_khairat] */

add_shortcode( 'pk_borang_khairat', function () {
	pk_enqueue_public();
	if ( $ok = pk_render_success( 'khairat' ) ) { return $ok; }
	if ( ! pk_opt( 'buka_khairat' ) ) { return '<div class="pk-note">Pendaftaran dalam talian ditutup buat masa ini. Sila hubungi pejabat masjid.</div>'; }
	$yr = pk_year();
	$kaw = array_filter( array_map( 'trim', explode( "\n", (string) pk_opt( 'kawasan' ) ) ) );
	$tg = isset( $_POST['tg'] ) ? pk_tanggungan_from_post() : array_fill( 0, 3, [] );
	ob_start(); ?>
	<form class="pk-form" id="pk-top" method="post" enctype="multipart/form-data" data-pk-form="khairat">
		<?php echo pk_render_errors(); echo pk_common_fields( 'khairat' ); ?>
		<div class="pk-intro">
			<div><i class="fa-solid fa-hand-holding-heart"></i></div>
			<div><strong>Badan Khairat Kematian <?php echo esc_html( pk_nama() ); ?></strong><br>
			Yuran pendaftaran <strong><?php echo esc_html( pk_rm( pk_opt( 'yuran_daftar' ) ) ); ?></strong> (sekali seumur hidup) + yuran tahunan <strong><?php echo esc_html( pk_rm( pk_opt( 'yuran_tahunan' ) ) ); ?></strong>. Bayaran boleh dibuat <strong>sekaligus</strong> atau secara <strong>ansuran</strong>.</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">1</span> Jenis Permohonan</h3>
			<div class="pk-choice">
				<label><input type="radio" name="jenis_mohon" value="baru" <?php checked( pk_p( 'jenis_mohon', 'baru' ), 'baru' ); ?>> <span><i class="fa-solid fa-user-plus"></i> Ahli Baharu <small>Pendaftaran + Tahunan <?php echo (int) $yr; ?> = <?php echo esc_html( pk_rm( pk_opt( 'yuran_daftar' ) + pk_opt( 'yuran_tahunan' ) ) ); ?></small></span></label>
				<label><input type="radio" name="jenis_mohon" value="baharui" <?php checked( pk_p( 'jenis_mohon' ), 'baharui' ); ?>> <span><i class="fa-solid fa-rotate"></i> Pembaharuan Ahli Sedia Ada <small>Yuran Tahunan <?php echo (int) $yr; ?> = <?php echo esc_html( pk_rm( pk_opt( 'yuran_tahunan' ) ) ); ?></small></span></label>
			</div>
			<div class="pk-tunggakan mt-3">
				<label class="form-label">Tunggakan tahun sebelum <small class="text-secondary">(abaikan jika tiada)</small></label>
				<div class="d-flex flex-wrap gap-3">
				<?php for ( $y = $yr - 1; $y >= $yr - 3; $y-- ) : ?>
					<label class="form-check"><input class="form-check-input" type="checkbox" name="tunggakan[]" value="<?php echo (int) $y; ?>" <?php checked( in_array( (string) $y, (array) ( $_POST['tunggakan'] ?? [] ), true ) ); ?>> <span class="form-check-label">Tahun <?php echo (int) $y; ?> (<?php echo esc_html( pk_rm( pk_opt( 'yuran_tahunan' ) ) ); ?>)</span></label>
				<?php endfor; ?>
				</div>
			</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">A</span> Maklumat Ahli</h3>
			<div class="row g-3">
				<?php
				echo pk_field( 'Nama Penuh (seperti dalam KP)', 'nama', [ 'req' => 1, 'col' => 'col-12' ] );
				echo pk_field( 'No. Kad Pengenalan', 'no_kp', [ 'req' => 1, 'attrs' => ' inputmode="numeric" placeholder="cth. 800101105123" pattern="[0-9\- ]{12,14}"' ] );
				echo pk_field( 'No. Telefon Bimbit', 'no_hp', [ 'req' => 1, 'type' => 'tel', 'attrs' => ' placeholder="cth. 012-3456789"' ] );
				echo pk_field( 'Alamat Rumah', 'alamat', [ 'req' => 1, 'type' => 'textarea', 'col' => 'col-12' ] );
				?>
				<div class="col-md-6"><label class="form-label">Kawasan / Taman</label><input class="form-control" name="kawasan" list="pk-kawasan" value="<?php echo pk_v( 'kawasan' ); ?>"><datalist id="pk-kawasan"><?php foreach ( $kaw as $k ) { echo '<option value="' . esc_attr( $k ) . '">'; } ?></datalist></div>
				<?php echo pk_field( 'E-mel', 'email', [ 'type' => 'email' ] ); ?>
				<?php echo pk_field( 'Pekerjaan', 'pekerjaan' ); ?>
				<div class="col-md-6"><label class="form-label">Sektor</label><?php echo pk_select( 'sektor', pk_label( 'sektor' ), pk_p( 'sektor' ) ); ?></div>
				<?php echo pk_field( 'Majikan', 'majikan' ); echo pk_field( 'Tel. Pejabat', 'tel_p', [ 'type' => 'tel', 'col' => 'col-md-3' ] ); echo pk_field( 'Tel. Rumah', 'tel_r', [ 'type' => 'tel', 'col' => 'col-md-3' ] ); ?>
			</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">B</span> Maklumat Suami / Isteri <small class="text-secondary fw-normal">(jika berkaitan)</small></h3>
			<div class="row g-3">
				<?php
				echo pk_field( 'Nama Penuh', 'p_nama', [ 'col' => 'col-12' ] );
				echo pk_field( 'No. Kad Pengenalan', 'p_kp', [ 'attrs' => ' inputmode="numeric"' ] );
				echo pk_field( 'No. Telefon Bimbit', 'p_hp', [ 'type' => 'tel' ] );
				echo pk_field( 'Pekerjaan', 'p_pekerjaan' );
				?>
				<div class="col-md-6"><label class="form-label">Sektor</label><?php echo pk_select( 'p_sektor', pk_label( 'sektor' ), pk_p( 'p_sektor' ) ); ?></div>
				<?php echo pk_field( 'Majikan', 'p_majikan' ); echo pk_field( 'Tel. Pejabat', 'p_tel_p', [ 'type' => 'tel' ] ); ?>
			</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">C</span> Maklumat Tanggungan</h3>
			<p class="small text-secondary">Terhad kepada suami/isteri, anak yang belum berkahwin dan tinggal bersama, serta ibu bapa / ibu bapa mertua yang tinggal bersama. Tidak termasuk anak berusia 18 tahun ke atas yang sudah bekerja.</p>
			<div class="pk-rows" data-pk-rows="tg" data-pk-max="12">
				<?php foreach ( $tg as $i => $r ) { echo pk_tg_row( $i, $r ); } ?>
			</div>
			<template data-pk-tpl="tg"><?php echo pk_tg_row( '__i__', [] ); ?></template>
			<button type="button" class="btn btn-sm btn-outline-success mt-2" data-pk-add="tg"><i class="fa-solid fa-plus"></i> Tambah tanggungan</button>
		</div>

		<?php echo pk_payment_block( '' ); ?>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">D</span> Perakuan Ahli</h3>
			<details class="pk-terms"><summary>Baca Terma &amp; Syarat</summary><?php echo pk_terma_khairat(); ?></details>
			<label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="perakuan" value="1" required <?php checked( ! empty( $_POST['perakuan'] ) ); ?>> <span class="form-check-label">Saya seperti nama di atas <strong>bersetuju</strong> dengan terma dan syarat yang telah ditetapkan, dan mengesahkan maklumat yang diberi adalah benar.</span></label>
		</div>

		<button type="submit" class="btn btn-mp btn-lg"><i class="fa-solid fa-paper-plane"></i> Hantar Pendaftaran</button>
	</form>
	<?php
	return ob_get_clean();
} );

function pk_tg_row( $i, $r ) {
	$n = 'tg[' . $i . ']';
	return '<div class="pk-row row g-2 align-items-end">'
		. '<div class="col-md-4"><label class="form-label small">Nama</label><input class="form-control form-control-sm" name="' . $n . '[nama]" value="' . esc_attr( $r['nama'] ?? '' ) . '"></div>'
		. '<div class="col-6 col-md-2"><label class="form-label small">Tarikh lahir</label><input type="date" class="form-control form-control-sm" name="' . $n . '[tarikh_lahir]" value="' . esc_attr( $r['tarikh_lahir'] ?? '' ) . '"></div>'
		. '<div class="col-6 col-md-2"><label class="form-label small">Hubungan</label>' . str_replace( 'form-select', 'form-select form-select-sm', pk_select( $n . '[hubungan]', pk_label( 'hubungan' ), $r['hubungan'] ?? '' ) ) . '</div>'
		. '<div class="col-6 col-md-2"><label class="form-label small">Status</label>' . str_replace( 'form-select', 'form-select form-select-sm', pk_select( $n . '[status_t]', pk_label( 'status_t' ), $r['status_t'] ?? '', '', null ) ) . '</div>'
		. '<div class="col-4 col-md-1"><label class="form-label small">Tinggal bersama</label>' . str_replace( 'form-select', 'form-select form-select-sm', pk_select( $n . '[tinggal]', [ 'Y' => 'Ya', 'T' => 'Tidak' ], $r['tinggal'] ?? 'Y', '', null ) ) . '</div>'
		. '<div class="col-2 col-md-1 text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-pk-del title="Buang"><i class="fa-solid fa-xmark"></i></button></div>'
		. '</div>';
}

function pk_terma_khairat() {
	$lines = array_filter( array_map( 'trim', explode( "\n", (string) pk_opt( 'terma_khairat' ) ) ) );
	if ( ! $lines ) { return '<p class="small mb-0">Tertakluk kepada peraturan Badan Khairat Kematian ' . esc_html( pk_nama() ) . '.</p>'; }
	return '<ol class="small mb-0"><li>' . implode( '</li><li>', array_map( 'esc_html', array_map( 'pk_fill', $lines ) ) ) . '</li></ol>';
}

/* ================================================================= [pk_borang_korban] */

add_shortcode( 'pk_borang_korban', function () {
	pk_enqueue_public();
	if ( $ok = pk_render_success( 'korban' ) ) { return $ok; }
	if ( ! pk_opt( 'buka_korban' ) ) { return '<div class="pk-note">Pendaftaran korban/aqiqah dalam talian ditutup buat masa ini. Sila hubungi pejabat masjid.</div>'; }
	$ps = isset( $_POST['ps'] ) ? pk_peserta_from_post() : array_fill( 0, 1, [] );
	$lain = array_map( 'sanitize_key', array_filter( (array) ( $_POST['bahagian_lain'] ?? [] ), 'is_scalar' ) );
	ob_start(); ?>
	<form class="pk-form" id="pk-top" method="post" enctype="multipart/form-data" data-pk-form="korban">
		<?php echo pk_render_errors(); echo pk_common_fields( 'korban' ); ?>
		<div class="pk-intro">
			<div><i class="fa-solid fa-cow"></i></div>
			<div><strong>Ibadah Korban <?php echo esc_html( pk_opt( 'musim_korban' ) . ' / ' . pk_opt( 'tahun_korban_m' ) . 'M' ); ?></strong><br>
			Satu bahagian lembu: <strong><?php echo esc_html( pk_rm( pk_opt( 'harga_bahagian' ) ) ); ?></strong>. Bayaran boleh dibuat <strong>sekaligus</strong> atau <strong>menabung secara ansuran</strong> sehingga cukup.</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">A</span> Butiran Peserta</h3>
			<div class="pk-choice mb-3">
				<label><input type="radio" name="jenis" value="korban" <?php checked( pk_p( 'jenis', 'korban' ), 'korban' ); ?>> <span><i class="fa-solid fa-cow"></i> Korban</span></label>
				<label><input type="radio" name="jenis" value="aqiqah" <?php checked( pk_p( 'jenis' ), 'aqiqah' ); ?>> <span><i class="fa-solid fa-baby"></i> Aqiqah</span></label>
			</div>
			<div class="row g-3">
				<?php
				echo pk_field( 'Nama', 'nama', [ 'req' => 1, 'col' => 'col-12' ] );
				echo pk_field( 'No. Kad Pengenalan', 'no_kp', [ 'req' => 1, 'attrs' => ' inputmode="numeric" pattern="[0-9\- ]{12,14}"', 'help' => 'Digunakan untuk semakan status & bayaran ansuran.' ] );
				echo pk_field( 'No. HP', 'no_hp', [ 'req' => 1, 'type' => 'tel' ] );
				echo pk_field( 'Alamat', 'alamat', [ 'req' => 1, 'type' => 'textarea', 'col' => 'col-12' ] );
				echo pk_field( 'No. Tel. Rumah', 'tel_r', [ 'type' => 'tel' ] );
				echo pk_field( 'E-mel', 'email', [ 'type' => 'email' ] );
				?>
			</div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">B</span> Akad Wakalah</h3>
			<label class="form-check"><input class="form-check-input" type="checkbox" name="wakalah" value="1" required <?php checked( ! empty( $_POST['wakalah'] ) ); ?>> <span class="form-check-label"><?php echo esc_html( pk_fill( pk_opt( 'teks_wakalah' ) ) ); ?></span></label>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">C</span> Maklumat Penyertaan <small class="text-secondary fw-normal">(<?php echo esc_html( pk_rm( pk_opt( 'harga_bahagian' ) ) ); ?> sebahagian)</small></h3>
			<div class="pk-rows" data-pk-rows="ps" data-pk-max="14">
				<?php foreach ( $ps as $i => $r ) { echo pk_ps_row( $i, $r ); } ?>
			</div>
			<template data-pk-tpl="ps"><?php echo pk_ps_row( '__i__', [] ); ?></template>
			<button type="button" class="btn btn-sm btn-outline-success mt-2" data-pk-add="ps"><i class="fa-solid fa-plus"></i> Tambah peserta</button>
			<div class="pk-total mt-3">Jumlah bahagian: <strong data-pk-bah>0</strong></div>
		</div>

		<div class="pk-card">
			<h3 class="pk-h"><span class="pk-step">D</span> Perakuan — Agihan Daging</h3>
			<?php foreach ( pk_label( 'pilihan' ) as $k => $v ) : ?>
				<label class="form-check"><input class="form-check-input" type="radio" name="pilihan" value="<?php echo esc_attr( $k ); ?>" required <?php checked( pk_p( 'pilihan' ), $k ); ?>> <span class="form-check-label"><?php echo esc_html( $v ); ?></span></label>
			<?php endforeach; ?>
			<div class="mt-2 small">Bahagian lain (jika berkaitan):
				<?php foreach ( pk_label( 'bahagian_lain' ) as $k => $v ) : ?>
					<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="bahagian_lain[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $lain, true ) ); ?>> <span class="form-check-label"><?php echo esc_html( $v ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<div class="mt-3"><label class="form-label">Catatan</label><textarea class="form-control" name="catatan" rows="2"><?php echo esc_textarea( wp_unslash( $_POST['catatan'] ?? '' ) ); ?></textarea></div>
			<p class="small text-secondary mt-2 mb-0"><?php echo esc_html( pk_opt( 'nota_korban' ) ); ?></p>
		</div>

		<?php echo pk_payment_block( '' ); ?>

		<button type="submit" class="btn btn-mp btn-lg"><i class="fa-solid fa-paper-plane"></i> Hantar Pendaftaran</button>
	</form>
	<?php
	return ob_get_clean();
} );

function pk_ps_row( $i, $r ) {
	$n = 'ps[' . $i . ']';
	$b = [];
	for ( $x = 1; $x <= 7; $x++ ) { $b[ $x ] = $x; }
	return '<div class="pk-row row g-2 align-items-end">'
		. '<div class="col-8 col-md-8"><label class="form-label small">Nama peserta</label><input class="form-control form-control-sm" name="' . $n . '[nama]" value="' . esc_attr( $r['nama'] ?? '' ) . '"></div>'
		. '<div class="col-3 col-md-3"><label class="form-label small">Bahagian</label>' . str_replace( 'form-select', 'form-select form-select-sm', pk_select( $n . '[bahagian]', $b, $r['bahagian'] ?? 1, 'data-pk-bahagian', null ) ) . '</div>'
		. '<div class="col-1 text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-pk-del title="Buang"><i class="fa-solid fa-xmark"></i></button></div>'
		. '</div>';
}

/* ================================================================= [pk_semak] */

add_shortcode( 'pk_semak', function () {
	pk_enqueue_public();
	$out = pk_render_success( 'bayar' ) . pk_render_errors();
	$kp  = pk_p( 'kp' ); $hp = pk_p( 'hp' );
	$did = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ( isset( $_POST['pk_semak'] ) || ( $_POST['pk_action'] ?? '' ) === 'bayar' );
	ob_start(); ?>
	<form class="pk-form pk-card" method="post" id="pk-top">
		<h3 class="pk-h"><i class="fa-solid fa-magnifying-glass text-mp"></i> Semak Status Keahlian / Korban</h3>
		<p class="small text-secondary">Masukkan No. Kad Pengenalan dan No. Telefon yang didaftarkan.</p>
		<?php wp_nonce_field( 'pk_semak', 'pk_semak_nonce' ); ?>
		<div class="row g-3 align-items-end">
			<div class="col-md-5"><label class="form-label">No. Kad Pengenalan</label><input class="form-control" name="kp" required inputmode="numeric" value="<?php echo esc_attr( $kp ); ?>"></div>
			<div class="col-md-4"><label class="form-label">No. Telefon</label><input class="form-control" name="hp" required type="tel" value="<?php echo esc_attr( $hp ); ?>"></div>
			<div class="col-md-3"><button class="btn btn-mp w-100" name="pk_semak" value="1"><i class="fa-solid fa-magnifying-glass"></i> Semak</button></div>
		</div>
	</form>
	<?php
	$out .= ob_get_clean();
	if ( ! $did ) { return $out; }
	// Every lookup (search form OR the instalment-upload re-render) must carry a valid nonce and counts against the throttle.
	$nonce_ok = isset( $_POST['pk_semak'] )
		? wp_verify_nonce( (string) ( $_POST['pk_semak_nonce'] ?? '' ), 'pk_semak' )
		: wp_verify_nonce( (string) ( $_POST['pk_nonce'] ?? '' ), 'pk_bayar' );
	if ( ! $nonce_ok ) { return $out . '<div class="alert alert-warning">Sesi tamat. Sila cuba lagi.</div>'; }
	if ( ! pk_throttle( 'semak', 15, 15 * MINUTE_IN_SECONDS ) ) { return $out . '<div class="alert alert-warning">Terlalu banyak cubaan. Sila cuba lagi selepas 15 minit.</div>'; }
	[ $ahli, $korban ] = pk_semak_lookup( $kp, $hp );
	if ( ! $ahli && ! $korban ) {
		return $out . '<div class="alert alert-warning">Tiada rekod ditemui untuk kombinasi No. KP dan No. Telefon ini. Jika anda ahli lama yang belum direkodkan dalam sistem, sila hubungi AJK / pejabat masjid.</div>';
	}
	$yr = pk_year();
	ob_start();
	echo '<div class="pk-result">';
	foreach ( $ahli as $a ) {
		$paid = pk_ahli_paid_year( $a['id'], $yr );
		echo '<div class="pk-card"><h3 class="pk-h"><i class="fa-solid fa-hand-holding-heart text-mp"></i> Khairat Kematian</h3>';
		echo '<div class="pk-kv"><div><span>Nama</span>' . esc_html( $a['nama'] ) . '</div><div><span>No. Ahli</span>' . esc_html( $a['no_ahli'] ?: 'Belum diberikan' ) . '</div><div><span>Status</span>' . pk_badge( 'status_ahli', $a['status'] ) . '</div><div><span>Yuran ' . (int) $yr . '</span>' . ( $paid ? '<span class="pk-badge pk-b-ok">Dijelaskan</span>' : '<span class="pk-badge pk-b-warn">Belum dijelaskan</span>' ) . '</div></div>';
		echo pk_public_pelan_table( 'khairat', $a['id'], $kp, $hp );
		echo '</div>';
	}
	foreach ( $korban as $k ) {
		echo '<div class="pk-card"><h3 class="pk-h"><i class="fa-solid fa-cow text-mp"></i> ' . esc_html( pk_label( 'jenis_korban', $k['jenis'] ) . ' ' . $k['musim'] ) . '</h3>';
		echo '<div class="pk-kv"><div><span>Nama</span>' . esc_html( $k['nama'] ) . '</div><div><span>Bahagian</span>' . (int) pk_korban_bahagian( $k['id'] ) . '</div><div><span>Status</span>' . pk_badge( 'status_korban', $k['status'] ) . '</div><div><span>Rujukan</span>' . esc_html( pk_ref( 'korban', $k['id'] ) ) . '</div></div>';
		echo pk_public_pelan_table( 'korban', $k['id'], $kp, $hp );
		echo '</div>';
	}
	echo '</div>';
	return $out . ob_get_clean();
} );

function pk_public_pelan_table( $jenis, $ref, $kp, $hp ) {
	$pl = pk_pelan_list( $jenis, $ref );
	if ( ! $pl ) { return '<p class="small text-secondary mb-0">Tiada rekod bayaran.</p>'; }
	$h = '<div class="table-responsive"><table class="table table-sm pk-table"><thead><tr><th>Perkara</th><th>Cara</th><th class="text-end">Jumlah</th><th class="text-end">Dibayar</th><th class="text-end">Baki</th><th></th></tr></thead><tbody>';
	$aktif = [];
	foreach ( $pl as $p ) {
		if ( 'batal' === $p['status'] ) { continue; }
		$baki = max( 0, $p['jumlah'] - $p['dibayar'] );
		$pct  = $p['jumlah'] > 0 ? min( 100, round( $p['dibayar'] / $p['jumlah'] * 100 ) ) : 0;
		$h   .= '<tr><td>' . esc_html( $p['tajuk'] ) . '<div class="pk-bar"><i style="width:' . $pct . '%"></i></div></td><td>' . esc_html( 'ansuran' === $p['cara'] ? 'Ansuran (' . $p['bil_ansuran'] . 'x ' . pk_rm( $p['amaun_ansuran'] ) . ')' : 'Sekaligus' ) . '</td><td class="text-end">' . pk_rm( $p['jumlah'] ) . '</td><td class="text-end">' . pk_rm( $p['dibayar'] ) . ( $p['menunggu'] > 0 ? '<div class="small text-warning">+ ' . pk_rm( $p['menunggu'] ) . ' dalam semakan</div>' : '' ) . '</td><td class="text-end fw-semibold">' . pk_rm( $baki ) . '</td><td>' . pk_badge( 'status_pelan', $p['status'] ) . '</td></tr>';
		if ( 'aktif' === $p['status'] ) { $aktif[ $p['id'] ] = $p['tajuk'] . ' — baki ' . pk_rm( $baki ); $def = $p['amaun_ansuran'] > 0 ? min( $p['amaun_ansuran'], $baki ) : $baki; }
	}
	$h .= '</tbody></table></div>';
	if ( $aktif ) {
		$h .= '<details class="pk-bayar"' . ( ( $_POST['pk_action'] ?? '' ) === 'bayar' ? ' open' : '' ) . '><summary class="btn btn-leaf btn-sm"><i class="fa-solid fa-upload"></i> Hantar bukti bayaran / ansuran seterusnya</summary>'
			. '<form method="post" enctype="multipart/form-data" class="pk-form mt-3">' . pk_common_fields( 'bayar' )
			. '<input type="hidden" name="kp" value="' . esc_attr( $kp ) . '"><input type="hidden" name="hp" value="' . esc_attr( $hp ) . '">'
			. '<div class="row g-3"><div class="col-md-6"><label class="form-label">Untuk</label>' . pk_select( 'pelan_id', $aktif, array_key_first( $aktif ), 'required', null ) . '</div>'
			. '<div class="col-md-3"><label class="form-label">Jumlah (RM)</label><input type="number" step="0.01" min="1" class="form-control" name="jumlah_bayar" required value="' . esc_attr( number_format( (float) ( $def ?? 0 ), 2, '.', '' ) ) . '"></div>'
			. '<div class="col-md-3"><label class="form-label">Kaedah</label>' . pk_select( 'kaedah', array_diff_key( pk_label( 'kaedah' ), [ 'tunai' => 1 ] ), 'qr', '', null ) . '</div>'
			. '<div class="col-md-8"><label class="form-label">Bukti pembayaran</label><input type="file" class="form-control" name="bukti" accept="image/*,application/pdf" required></div>'
			. '<div class="col-md-4 d-flex align-items-end"><button class="btn btn-mp w-100"><i class="fa-solid fa-paper-plane"></i> Hantar</button></div>'
			. '<div class="col-12 small text-secondary">' . esc_html( pk_opt( 'bank' ) ) . '</div></div></form></details>';
	}
	return $h;
}

/* ================================================================= [pk_muat_turun] */

add_shortcode( 'pk_muat_turun', function ( $atts ) {
	pk_enqueue_public();
	$atts = shortcode_atts( [ 'ids' => '' ], $atts );
	$ids  = $atts['ids'] ? array_map( 'intval', explode( ',', $atts['ids'] ) ) : (array) pk_opt( 'fail_borang' );
	$h    = '<div class="pk-downloads">';
	foreach ( array_filter( $ids ) as $id ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) { continue; }
		$f    = get_attached_file( $id );
		$size = $f && file_exists( $f ) ? size_format( filesize( $f ) ) : '';
		$h   .= '<a class="pk-dl" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" download><i class="fa-regular fa-file-pdf"></i><span><strong>' . esc_html( get_the_title( $id ) ) . '</strong><small>' . esc_html( wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) ) ?: 'PDF' ) . ( $size ? ' · ' . esc_html( $size ) : '' ) . '</small></span><i class="fa-solid fa-download ms-auto"></i></a>';
	}
	return $h . '</div>';
} );
