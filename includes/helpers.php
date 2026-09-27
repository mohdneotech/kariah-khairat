<?php
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ settings */

function pk_default_settings() {
	$y = (int) current_time( 'Y' ) + ( (int) current_time( 'n' ) >= 6 ? 1 : 0 ); // next Aidiladha (approx.)
	return [
		// identity
		'nama_masjid'      => get_bloginfo( 'name' ),
		'alamat_masjid'    => '',
		// fees
		'yuran_daftar'     => 30,
		'yuran_tahunan'    => 36,
		'harga_bahagian'   => 950,
		'musim_korban'     => pk_hijri_year( $y ) . 'H',
		'tahun_korban_m'   => (string) $y,
		'prefix_ahli'      => 'KK',
		'max_ansuran'      => 12,
		// payment
		'bank'             => '',
		'qr_url'           => '',
		'label_qr'         => 'DuitNow QR',
		// workflow
		'emel_notis'       => get_option( 'admin_email' ),
		'buka_khairat'     => 1,
		'buka_korban'      => 1,
		'kawasan'          => '',
		'nota_khairat'     => 'Resit rasmi boleh diambil di pejabat masjid selepas bayaran disahkan. Sila maklumkan AJK jika ada bayaran tunggakan tahun sebelum.',
		'nota_korban'      => 'Sembelihan akan diadakan selepas solat sunat Aidiladha. Satu ekor lembu = 7 bahagian.',
		'terma_khairat'    => implode( "\n", [
			'Keahlian terbuka kepada ahli kariah {masjid} seperti yang ditetapkan oleh peraturan masjid dan pihak berkuasa agama negeri.',
			'Ahli/Pencarum diwajibkan menjelaskan Yuran Keahlian {yuran_daftar} (sekali bayaran sahaja) dan Yuran Tahunan {yuran_tahunan} (setiap tahun).',
			'Yuran pembaharuan sebanyak {yuran_tahunan} perlu dijelaskan sebaik sahaja berakhir 31 Disember setiap tahun.',
			'Keahlian akan terbatal sekiranya yuran tahunan gagal dijelaskan dalam tempoh setahun tanpa alasan munasabah, dan pendaftaran baharu perlu dikemukakan semula.',
			'Tanggungan terhad kepada suami, isteri, anak-anak yang belum berkahwin dan tinggal bersama, serta ibu bapa dan ibu bapa mertua yang tinggal bersama sahaja, tidak termasuk anak berusia 18 tahun ke atas yang sudah bekerja.',
			'Ahli bertanggungjawab mengemas kini maklumat kerana hanya maklumat dalam rekod Badan Khairat Kematian dianggap sah.',
			'Permohonan dianggap tidak lengkap jika maklumat yang diperlukan tidak diisi dengan lengkap. Terma dan syarat tertakluk kepada pindaan dari masa ke semasa.',
		] ),
		'teks_wakalah'     => 'Saya dengan ini bersetuju mewakilkan kepada Jawatankuasa {masjid} untuk membeli, meniatkan, menyembelih, mengagihkan daging korban/aqiqah dan semua perkara yang berkaitan dengan ibadah ini untuk saya sebagaimana butiran di bawah.',
		'fail_borang'      => [],
		// look & integration
		'warna_utama'      => '#527c3a',
		'warna_aksen'      => '#8dc642',
		'fontawesome'      => 'auto',   // auto | cdn | off
		'ip_sumber'        => 'remote', // remote | cloudflare | xff
		'padam_data'       => 0,        // delete tables & files when the plugin is deleted
	];
}

/** Approximate Hijri year in which Dhul Hijjah falls for a given Gregorian year (good enough for a default label). */
function pk_hijri_year( $gy ) { return (int) floor( ( $gy - 621.5643 ) * 1.030684 ); }

/** Replace {masjid} {yuran_daftar} {yuran_tahunan} {harga_bahagian} in admin-editable text. */
function pk_fill( $text ) {
	return strtr( (string) $text, [
		'{masjid}'         => pk_nama(),
		'{yuran_daftar}'   => pk_rm( pk_opt( 'yuran_daftar' ) ),
		'{yuran_tahunan}'  => pk_rm( pk_opt( 'yuran_tahunan' ) ),
		'{harga_bahagian}' => pk_rm( pk_opt( 'harga_bahagian' ) ),
	] );
}

function pk_nama() { return (string) ( pk_opt( 'nama_masjid' ) ?: get_bloginfo( 'name' ) ); }

function pk_settings() {
	static $s = null;
	if ( null === $s ) { $s = wp_parse_args( (array) get_option( 'pk_settings', [] ), pk_default_settings() ); }
	return $s;
}
function pk_opt( $k ) { $s = pk_settings(); return $s[ $k ] ?? null; }

function pk_qr_url() { return (string) pk_opt( 'qr_url' ); }

/* ------------------------------------------------------------------ labels */

function pk_label( $group, $key = null ) {
	static $L = null;
	if ( null === $L ) { $L = pk_labels(); }
	if ( null === $key ) { return $L[ $group ] ?? []; }
	return $L[ $group ][ $key ] ?? $key;
}

/** All option lists. Filter 'pk_labels' to rename or add options (e.g. extra relationships or payment methods). */
function pk_labels() {
	$L = [
		'status_ahli'   => [ 'menunggu' => 'Menunggu Pengesahan', 'aktif' => 'Aktif', 'tidak_aktif' => 'Tidak Aktif / Luput', 'meninggal' => 'Meninggal Dunia', 'ditolak' => 'Ditolak' ],
		'status_korban' => [ 'menunggu' => 'Menunggu Pengesahan', 'disahkan' => 'Disahkan', 'selesai' => 'Selesai', 'batal' => 'Batal' ],
		'status_bayar'  => [ 'menunggu' => 'Menunggu Semakan', 'sah' => 'Disahkan', 'ditolak' => 'Ditolak' ],
		'status_pelan'  => [ 'aktif' => 'Belum Selesai', 'selesai' => 'Selesai', 'batal' => 'Batal' ],
		'mod'           => [ 'sekaligus' => 'Sekaligus (one-off)', 'ansuran' => 'Ansuran / Menabung' ],
		'kaedah'        => [ 'qr' => 'QR', 'transfer' => 'Pindahan Bank', 'tunai' => 'Tunai', 'lain' => 'Lain-lain' ],
		'kategori'      => [ 'daftar' => 'Pendaftaran + Tahunan', 'tahunan' => 'Yuran Tahunan', 'tunggakan' => 'Tunggakan', 'korban' => 'Korban', 'aqiqah' => 'Aqiqah', 'lain' => 'Lain-lain' ],
		'sektor'        => [ 'Kerajaan' => 'Kerajaan', 'Swasta' => 'Swasta', 'Sendiri' => 'Bekerja Sendiri', 'Pesara' => 'Pesara', 'Tidak Bekerja' => 'Tidak Bekerja / Suri Rumah', 'Pelajar' => 'Pelajar', 'Lain-lain' => 'Lain-lain' ],
		'hubungan'      => [ 'Suami' => 'Suami', 'Isteri' => 'Isteri', 'Anak' => 'Anak', 'Bapa' => 'Bapa', 'Ibu' => 'Ibu', 'Bapa Mertua' => 'Bapa Mertua', 'Ibu Mertua' => 'Ibu Mertua', 'Lain-lain' => 'Lain-lain' ],
		'status_t'      => [ '' => '—', 'S' => 'Sekolah', 'K' => 'Kerja', 'P' => 'Pencen', 'T' => 'Tidak Bekerja / Bawah Umur' ],
		'jenis_korban'  => [ 'korban' => 'Korban', 'aqiqah' => 'Aqiqah' ],
		'pilihan'       => [
			'ambil_sedekah' => 'Mengambil bahagian daging dan baki disedekahkan/dihadiahkan',
			'tertentu'      => 'Mahu bahagian tertentu sahaja',
			'daging_lain'   => 'Mahu bahagian daging dan bahagian lain',
			'sedekah_semua' => 'Sedekah/hadiah semua bahagian korban',
		],
		'bahagian_lain' => [ 'kepala' => 'Kepala', 'ekor' => 'Ekor', 'kaki' => 'Kaki', 'organ' => 'Organ' ],
		'sumber'        => [ 'online' => 'Dalam Talian', 'manual' => 'Manual (AJK)', 'import' => 'Import' ],
	];
	$L['kaedah']['qr'] = pk_opt( 'label_qr' ) ?: 'QR';
	return apply_filters( 'pk_labels', $L );
}

function pk_badge( $group, $key ) {
	$map = [ 'menunggu' => 'warn', 'aktif' => 'ok', 'sah' => 'ok', 'selesai' => 'ok', 'disahkan' => 'ok', 'tidak_aktif' => 'mute', 'batal' => 'mute', 'meninggal' => 'mute', 'ditolak' => 'bad' ];
	$c   = $map[ $key ] ?? 'mute';
	if ( 'status_pelan' === $group && 'aktif' === $key ) { $c = 'warn'; }
	return '<span class="pk-badge pk-b-' . $c . '">' . esc_html( pk_label( $group, $key ) ) . '</span>';
}

/* ------------------------------------------------------------------ formatting */

function pk_rm( $n ) { return 'RM ' . number_format( (float) $n, 2 ); }
function pk_date( $d ) { return $d && '0000-00-00' !== $d ? date_i18n( 'j M Y', strtotime( $d ) ) : '—'; }
function pk_digits( $s ) { return preg_replace( '/\D+/', '', (string) $s ); }

function pk_fmt_kp( $kp ) {
	$d = pk_digits( $kp );
	return 12 === strlen( $d ) ? substr( $d, 0, 6 ) . '-' . substr( $d, 6, 2 ) . '-' . substr( $d, 8 ) : $kp;
}
function pk_mask_kp( $kp ) {
	$d = pk_digits( $kp );
	return 12 === strlen( $d ) ? substr( $d, 0, 6 ) . '-**-**' . substr( $d, 10 ) : ( $d ? '****' . substr( $d, -4 ) : '—' );
}

/** Malaysian MyKad → [dob Y-m-d|null, 'L'|'P'|null] */
function pk_kp_info( $kp ) {
	$d = pk_digits( $kp );
	if ( 12 !== strlen( $d ) ) { return [ null, null ]; }
	$yy = (int) substr( $d, 0, 2 ); $mm = (int) substr( $d, 2, 2 ); $dd = (int) substr( $d, 4, 2 );
	$cur = (int) gmdate( 'y' );
	$yyyy = $yy > $cur ? 1900 + $yy : 2000 + $yy;
	$dob  = checkdate( $mm, $dd, $yyyy ) ? sprintf( '%04d-%02d-%02d', $yyyy, $mm, $dd ) : null;
	$sex  = ( (int) substr( $d, -1 ) ) % 2 ? 'L' : 'P';
	return [ $dob, $sex ];
}
function pk_age( $dob ) {
	if ( ! $dob ) { return null; }
	try { return ( new DateTime( $dob ) )->diff( new DateTime( 'today' ) )->y; } catch ( Exception $e ) { return null; }
}

function pk_now() { return current_time( 'mysql' ); }
function pk_today() { return current_time( 'Y-m-d' ); }
function pk_year() { return (int) current_time( 'Y' ); }

/* ------------------------------------------------------------------ private uploads */

function pk_private_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'pk-private';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
	if ( ! file_exists( "$dir/.htaccess" ) ) { @file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" ); }
	if ( ! file_exists( "$dir/index.php" ) ) { @file_put_contents( "$dir/index.php", "<?php // silence\n" ); }
	if ( ! file_exists( "$dir/web.config" ) ) { @file_put_contents( "$dir/web.config", "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); }
	return $dir;
}

/**
 * Validate & store an uploaded payment proof outside the public media library.
 * @return string|WP_Error|null relative path, error, or null if no file.
 */
function pk_store_upload( $field ) {
	if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) { return null; }
	$f = $_FILES[ $field ];
	if ( UPLOAD_ERR_OK !== (int) $f['error'] ) { return new WP_Error( 'upload', 'Muat naik fail gagal. Sila cuba lagi.' ); }
	if ( $f['size'] > 10 * MB_IN_BYTES ) { return new WP_Error( 'upload', 'Saiz fail melebihi 10 MB.' ); }
	$allowed = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'pdf' => 'application/pdf' ];
	$chk     = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], $allowed );
	if ( empty( $chk['ext'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'upload', 'Jenis fail tidak dibenarkan. Sila muat naik gambar (JPG/PNG) atau PDF.' ); }
	// content sniffing: the bytes must really be what the extension says (blocks polyglots / renamed scripts)
	$head = (string) @file_get_contents( $f['tmp_name'], false, null, 0, 1024 );
	if ( 'pdf' === $chk['ext'] ) {
		if ( 0 !== strpos( ltrim( $head ), '%PDF-' ) ) { return new WP_Error( 'upload', 'Fail PDF tidak sah.' ); }
	} elseif ( 'heic' !== $chk['ext'] && false === @getimagesize( $f['tmp_name'] ) ) {
		return new WP_Error( 'upload', 'Fail gambar tidak sah.' );
	}
	if ( preg_match( '/<\?php|<script|<html|<svg/i', $head ) ) { return new WP_Error( 'upload', 'Fail tidak dibenarkan.' ); }
	$sub = gmdate( 'Y/m' );
	$dir = pk_private_dir() . '/' . $sub;
	wp_mkdir_p( $dir );
	$name = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 10, false ) . '.' . $chk['ext'];
	if ( ! @move_uploaded_file( $f['tmp_name'], "$dir/$name" ) ) { return new WP_Error( 'upload', 'Fail tidak dapat disimpan.' ); }
	@chmod( "$dir/$name", 0640 );
	return "$sub/$name";
}

/** Stream a private proof/receipt safely (called after auth checks). */
function pk_stream_private( $rel ) {
	if ( ! is_string( $rel ) || ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9\-]+\.(jpe?g|png|webp|heic|pdf)$#', $rel ) ) { wp_die( 'Fail tidak sah.', 400 ); }
	$base = realpath( pk_private_dir() );
	$path = realpath( $base . '/' . $rel );
	if ( ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) { wp_die( 'Fail tidak ditemui.', 404 ); }
	$ft = wp_check_filetype( $path );
	nocache_headers();
	header( 'Content-Type: ' . ( $ft['type'] ?: 'application/octet-stream' ) );
	header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cross-Origin-Resource-Policy: same-origin' );
	if ( 'application/pdf' !== $ft['type'] ) { header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" ); }
	readfile( $path );
	exit;
}

function pk_bukti_url( $rel ) {
	return $rel ? wp_nonce_url( admin_url( 'admin-post.php?action=pk_bukti&f=' . rawurlencode( $rel ) ), 'pk_bukti' ) : '';
}

/* ------------------------------------------------------------------ misc */

/**
 * Real client IP, per Tetapan → "Sumber alamat IP":
 *  remote     — REMOTE_ADDR only (default; correct for most hosts).
 *  cloudflare — trust CF-Connecting-IP (use only when the origin accepts traffic from Cloudflare alone).
 *  xff        — first X-Forwarded-For entry, only when REMOTE_ADDR is a private/loopback proxy.
 */
function pk_client_ip() {
	$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	$mode   = pk_opt( 'ip_sumber' );
	if ( 'cloudflare' === $mode ) {
		$cf = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
		if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) { return $cf; }
	} elseif ( 'xff' === $mode ) {
		$private = $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		$xff     = trim( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) )[0] );
		if ( $private && filter_var( $xff, FILTER_VALIDATE_IP ) ) { return $xff; }
	}
	return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
}

/** simple per-IP throttle; returns true if allowed */
function pk_throttle( $bucket, $max, $window ) {
	$k = 'pk_t_' . $bucket . '_' . md5( pk_client_ip() );
	$n = (int) get_transient( $k );
	if ( $n >= $max ) { return false; }
	set_transient( $k, $n + 1, $window );
	return true;
}

/** Scalar GET param (arrays → default) — avoids TypeErrors from ?x[]= tampering. */
function pk_gs( $k, $default = '' ) { return isset( $_GET[ $k ] ) && is_scalar( $_GET[ $k ] ) ? (string) $_GET[ $k ] : $default; }

/** Single-line POST field: scalar only, sanitised, length-capped. */
function pk_p( $k, $default = '', $max = 190 ) {
	if ( ! isset( $_POST[ $k ] ) || ! is_scalar( $_POST[ $k ] ) ) { return $default; }
	return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), 0, $max );
}
/** Multi-line POST field: scalar only, sanitised, length-capped. */
function pk_pt( $k, $max = 2000 ) {
	if ( ! isset( $_POST[ $k ] ) || ! is_scalar( $_POST[ $k ] ) ) { return ''; }
	return mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ), 0, $max );
}
/** Clean a free-text value coming from a nested array (tanggungan / peserta rows). */
function pk_clean( $v, $max = 190 ) { return is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, $max ) : ''; }

/* ------------------------------------------------------------------ flash messages
 * Status messages are stored server-side and referenced by a random token, so a crafted
 * ?pk_msg=... link can never put attacker-chosen text on a masjid page (content spoofing / phishing). */
function pk_flash_set( $msg ) {
	$t = strtolower( wp_generate_password( 16, false, false ) );
	set_transient( 'pk_flash_' . $t, [ 'm' => (string) $msg, 'u' => get_current_user_id() ], 10 * MINUTE_IN_SECONDS );
	return $t;
}
function pk_flash_get() {
	$t = isset( $_GET['pk_msg'] ) && is_string( $_GET['pk_msg'] ) ? strtolower( $_GET['pk_msg'] ) : '';
	if ( ! preg_match( '/^[a-z0-9]{16}$/', $t ) ) { return ''; }
	$f = get_transient( 'pk_flash_' . $t );
	if ( ! is_array( $f ) || (int) $f['u'] !== get_current_user_id() ) { return ''; }
	return (string) $f['m'];
}

/** Random public slug for member accounts — the login is the IC number, which must never appear in URLs. */
function pk_member_slug() { return 'ahli-' . strtolower( wp_generate_password( 10, false, false ) ); }

/** Password rules for portal members. Returns error string or ''. */
function pk_password_problem( $pw, $kp = '', $hp = '' ) {
	if ( strlen( $pw ) < 8 ) { return 'Kata laluan mestilah sekurang-kurangnya 8 aksara.'; }
	if ( strlen( $pw ) > 128 ) { return 'Kata laluan terlalu panjang.'; }
	if ( ! preg_match( '/[A-Za-z]/', $pw ) || ! preg_match( '/\d/', $pw ) ) { return 'Kata laluan mesti mengandungi huruf dan nombor.'; }
	$d = pk_digits( $pw );
	foreach ( array_filter( [ pk_digits( $kp ), substr( pk_digits( $hp ), -8 ) ] ) as $bad ) {
		if ( strlen( $bad ) >= 6 && false !== strpos( $d, substr( $bad, 0, 6 ) ) ) { return 'Kata laluan tidak boleh mengandungi No. KP atau No. Telefon anda.'; }
	}
	if ( in_array( strtolower( $pw ), [ 'password', 'password1', 'abcd1234', 'qwerty123', 'masjid123', 'surau123', 'khairat123', '12345678a' ], true ) ) { return 'Kata laluan terlalu mudah diteka.'; }
	return '';
}
function pk_money( $v ) { return round( max( 0, (float) str_replace( [ ',', 'RM', ' ' ], '', (string) $v ) ), 2 ); }
function pk_date_in( $v ) {
	$v = trim( (string) $v );
	if ( ! $v ) { return null; }
	$t = strtotime( $v );
	return $t ? gmdate( 'Y-m-d', $t ) : null;
}

function pk_notify( $subject, $body ) {
	$to = pk_opt( 'emel_notis' );
	if ( $to && is_email( $to ) ) { @wp_mail( $to, '[' . pk_nama() . '] ' . $subject, $body ); }
}
