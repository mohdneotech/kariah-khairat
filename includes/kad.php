<?php
/**
 * Kad Ahli Kariah — generated on demand with GD (no file stored): 1012×638 px = 85.6×54 mm (ID-1 / CR80) at 300 dpi.
 * Only for active Ahli Kariah with a system-issued No. Kariah. Visible to the member (own card) and AJK.
 * Colours follow Tetapan → Warna (utama / aksen); logo = custom logo, theme assets/img/logo*.png or site icon.
 */
defined( 'ABSPATH' ) || exit;

function pk_kad_kod( array $a ) {
	$h = strtoupper( substr( hash_hmac( 'sha256', $a['id'] . '|' . $a['no_ahli'] . '|' . $a['no_kp'], wp_salt( 'auth' ) ), 0, 8 ) );
	return substr( $h, 0, 4 ) . '-' . substr( $h, 4 );
}

function pk_kad_logo_path() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id && ( $f = get_attached_file( $id ) ) && is_file( $f ) ) { return $f; }
	foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $d ) {
		foreach ( [ 'logo.png', 'logo-masjid.png', 'logo.jpg', 'logo@2x.png' ] as $n ) { if ( is_file( "$d/assets/img/$n" ) ) { return "$d/assets/img/$n"; } }
	}
	$ic = (int) get_option( 'site_icon' );
	return $ic && ( $f = get_attached_file( $ic ) ) && is_file( $f ) ? $f : '';
}

function pk_kad_img( $path ) {
	if ( ! $path || ! is_file( $path ) ) { return null; }
	$im = @imagecreatefromstring( (string) file_get_contents( $path ) );
	return $im ?: null;
}

/** Paste $src into a box, cover (crop) or contain (fit). */
function pk_kad_paste( $dst, $src, $x, $y, $w, $h, $cover = true ) {
	$sw = imagesx( $src ); $sh = imagesy( $src );
	$r  = $cover ? max( $w / $sw, $h / $sh ) : min( $w / $sw, $h / $sh );
	$cw = (int) round( $w / $r ); $ch = (int) round( $h / $r );
	if ( $cover ) {
		$sx = (int) max( 0, ( $sw - $cw ) / 2 ); $sy = (int) max( 0, ( $sh - $ch ) * 0.3 );
		imagecopyresampled( $dst, $src, $x, $y, $sx, $sy, $w, $h, min( $cw, $sw ), min( $ch, $sh ) );
	} else {
		$dw = (int) round( $sw * $r ); $dh = (int) round( $sh * $r );
		imagecopyresampled( $dst, $src, $x + (int) ( ( $w - $dw ) / 2 ), $y + (int) ( ( $h - $dh ) / 2 ), 0, 0, $dw, $dh, $sw, $sh );
	}
}

function pk_kad_text( $im, $size, $font, $x, $y, $col, $text, $maxw = 0, $min = 12 ) {
	if ( $maxw ) {
		while ( $size > $min ) { $b = imagettfbbox( $size, 0, $font, $text ); if ( ( $b[2] - $b[0] ) <= $maxw ) { break; } $size -= 1; }
	}
	imagettftext( $im, $size, 0, $x, $y, $col, $font, $text );
	return $size;
}

/** @return string|WP_Error PNG bytes */
function pk_kad_render( array $a ) {
	if ( ! function_exists( 'imagettftext' ) ) { return new WP_Error( 'gd', 'Pelayan tidak menyokong GD/FreeType — kad tidak dapat dijana.' ); }
	$W = 1012; $H = 638;
	$im = imagecreatetruecolor( $W, $H );
	imagealphablending( $im, true );
	$c = fn( $hex ) => imagecolorallocate( $im, hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
	$mix  = function ( $hex, $f ) { $hex = sanitize_hex_color( $hex ) ?: '#527c3a'; $r = []; foreach ( [ 1, 3, 5 ] as $i ) { $r[] = str_pad( dechex( (int) round( hexdec( substr( $hex, $i, 2 ) ) * $f ) ), 2, '0', STR_PAD_LEFT ); } return '#' . implode( '', $r ); };
	$deep = $c( $mix( pk_opt( 'warna_utama' ), 0.36 ) ); $green = $c( $mix( pk_opt( 'warna_utama' ), 0.6 ) ); $leaf = $c( sanitize_hex_color( pk_opt( 'warna_aksen' ) ) ?: '#8dc642' ); $cream = $c( '#f7f9f3' );
	$ink = $c( '#1d2327' ); $muted = $c( '#6b7280' ); $white = $c( '#ffffff' ); $soft = $c( '#e3edd9' );
	$bold = pk_tz_font( 'OpenSans-Bold.ttf' ); $semi = pk_tz_font( 'OpenSans-SemiBold.ttf' ); $serif = pk_tz_font( 'NotoSerif-Bold.ttf' );

	imagefilledrectangle( $im, 0, 0, $W, $H, $cream );
	// faint watermark ring
	imageantialias( $im, true );
	imagefilledellipse( $im, $W - 60, $H - 170, 546, 546, $soft );
	imagefilledellipse( $im, $W - 60, $H - 170, 494, 494, $cream );
	// header
	imagefilledrectangle( $im, 0, 0, $W, 148, $deep );
	imagefilledrectangle( $im, 0, 148, $W, 156, $leaf );
	imagefilledellipse( $im, 92, 74, 120, 120, $white );
	if ( $logo = pk_kad_img( pk_kad_logo_path() ) ) { pk_kad_paste( $im, $logo, 44, 26, 96, 96, false ); imagedestroy( $logo ); }
	$nama_masjid = function_exists( 'pk_nama' ) ? pk_nama() : get_bloginfo( 'name' );
	pk_kad_text( $im, 30, $bold, 172, 70, $white, mb_strtoupper( $nama_masjid ), $W - 200, 18 );
	pk_kad_text( $im, 21, $semi, 172, 112, $leaf, 'KAD AHLI KARIAH BERDAFTAR' );

	// photo
	$px = 48; $py = 190; $pw = 230; $ph = 288;
	imagefilledrectangle( $im, $px - 5, $py - 5, $px + $pw + 4, $py + $ph + 4, $green );
	$foto = ! empty( $a['gambar'] ) ? pk_kad_img( pk_private_path( $a['gambar'] ) ) : null;
	if ( $foto ) { pk_kad_paste( $im, $foto, $px, $py, $pw, $ph, true ); imagedestroy( $foto ); }
	else {
		imagefilledrectangle( $im, $px, $py, $px + $pw - 1, $py + $ph - 1, $soft );
		$ini = mb_strtoupper( mb_substr( (string) $a['nama'], 0, 1 ) );
		$b = imagettfbbox( 110, 0, $serif, $ini );
		imagettftext( $im, 110, 0, $px + (int) ( ( $pw - ( $b[2] - $b[0] ) ) / 2 ), $py + 190, $green, $serif, $ini );
	}

	// details
	$x = 312; $mw = $W - $x - 40;
	pk_kad_text( $im, 15, $semi, $x, 210, $muted, 'NAMA' );
	$nm = (string) $a['nama'];
	$s  = 30; $b = imagettfbbox( $s, 0, $bold, $nm );
	if ( ( $b[2] - $b[0] ) > $mw && false !== ( $sp = mb_strrpos( mb_substr( $nm, 0, (int) ( mb_strlen( $nm ) * 0.6 ) ), ' ' ) ) ) {
		$s = min( pk_kad_text( $im, 26, $bold, $x, 248, $ink, mb_substr( $nm, 0, $sp ), $mw, 16 ), 26 );
		pk_kad_text( $im, $s, $bold, $x, 284, $ink, trim( mb_substr( $nm, $sp ) ), $mw, 16 );
		$yb = 300;
	} else {
		pk_kad_text( $im, 30, $bold, $x, 252, $ink, $nm, $mw, 18 );
		$yb = 270;
	}
	pk_kad_text( $im, 15, $semi, $x, $yb + 40, $muted, 'NO. KARIAH' );
	pk_kad_text( $im, 48, $serif, $x, $yb + 100, $green, (string) $a['no_ahli'] );
	pk_kad_text( $im, 15, $semi, $x, $yb + 150, $muted, 'NO. K/P' );
	pk_kad_text( $im, 22, $semi, $x, $yb + 182, $ink, pk_mask_kp( $a['no_kp'] ) );
	pk_kad_text( $im, 15, $semi, $x + 330, $yb + 150, $muted, 'AHLI SEJAK' );
	pk_kad_text( $im, 22, $semi, $x + 330, $yb + 182, $ink, $a['tarikh_daftar'] ? date_i18n( 'd/m/Y', strtotime( $a['tarikh_daftar'] ) ) : '—' );

	// footer
	imagefilledrectangle( $im, 0, 548, $W, $H, $deep );
	pk_kad_text( $im, 17, $semi, 48, 600, $leaf, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$kod = 'Kod: ' . pk_kad_kod( $a );
	$b = imagettfbbox( 17, 0, $semi, $kod );
	pk_kad_text( $im, 17, $semi, $W - 48 - ( $b[2] - $b[0] ), 600, $white, $kod );

	ob_start(); imagepng( $im, null, 6 ); imagedestroy( $im );
	return (string) ob_get_clean();
}

function pk_kad_url( array $a, $mode = '' ) {
	return add_query_arg( [ 'action' => 'pk_kad', 'id' => (int) $a['id'], 'mode' => $mode, 'v' => substr( md5( $a['no_ahli'] . $a['nama'] . $a['gambar'] . $a['tarikh_daftar'] ), 0, 8 ), '_wpnonce' => wp_create_nonce( 'pk_kad_' . (int) $a['id'] ) ], admin_url( 'admin-post.php' ) );
}

add_action( 'admin_post_pk_kad', function () {
	$id = (int) pk_gs( 'id' );
	if ( ! wp_verify_nonce( pk_gs( '_wpnonce' ), 'pk_kad_' . $id ) ) { wp_die( 'Pautan tamat tempoh. Sila muat semula halaman.', 403 ); }
	$me = pk_ahli_for_user();
	if ( ! current_user_can( PK_CAP ) && ( ! $me || (int) $me['id'] !== $id ) ) { wp_die( 'Tiada kebenaran.', 403 ); }
	$a = pk_get_ahli( $id );
	if ( ! $a || ! pk_is_kariah_aktif( $a ) ) { wp_die( 'Kad hanya untuk Ahli Kariah aktif yang telah menerima No. Kariah.', 404 ); }
	$mode = pk_gs( 'mode' );
	if ( 'cetak' === $mode ) {
		$src = pk_kad_url( $a );
		nocache_headers();
		echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><title>Kad Ahli Kariah ' . esc_html( $a['no_ahli'] ) . '</title><style>@page{size:A4;margin:15mm}body{font:13px/1.5 Arial,sans-serif;color:#222;margin:20px}img{width:85.6mm;height:54mm;border-radius:3.2mm;outline:.2mm dashed #999;outline-offset:1mm;display:block;margin:6mm 0}.n{color:#666;max-width:150mm}.b{padding:8px 14px;margin-right:6px}@media print{.np{display:none}}</style></head><body>'
			. '<div class="np"><button class="b" onclick="window.print()">Cetak</button><a class="b" href="' . esc_url( pk_kad_url( $a, 'muat' ) ) . '">Muat turun PNG</a><p class="n">Saiz sebenar kad 85.6 × 54 mm. Semasa mencetak, pilih skala <b>100%</b> (bukan "Fit to page"), kemudian gunting di sepanjang garis putus-putus. Disyorkan dicetak pada kertas tebal atau dilaminasi.</p></div>'
			. '<img src="' . esc_url( $src ) . '" alt="Kad Ahli Kariah"></body></html>';
		exit;
	}
	$png = pk_kad_render( $a );
	if ( is_wp_error( $png ) ) { wp_die( esc_html( $png->get_error_message() ) ); }
	nocache_headers();
	header( 'Content-Type: image/png' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: ' . ( 'muat' === $mode ? 'attachment' : 'inline' ) . '; filename="Kad-Ahli-Kariah-' . sanitize_file_name( $a['no_ahli'] ) . '.png"' );
	echo $png; // phpcs:ignore
	exit;
} );

function pk_admin_kad_box( array $a ) {
	echo '<div class="pk-box"><h2><span class="dashicons dashicons-id"></span> Kad Ahli Kariah</h2>';
	if ( ! pk_is_kariah_aktif( $a ) ) { echo '<p class="description">Kad dijana selepas Ahli Kariah aktif dan No. Kariah diberikan.</p></div>'; return; }
	echo '<p><img src="' . esc_url( pk_kad_url( $a ) ) . '" alt="Kad Ahli Kariah" style="width:100%;max-width:340px;border-radius:10px;border:1px solid #dcdcde" loading="lazy"></p>'
		. '<p><a class="button" href="' . esc_url( pk_kad_url( $a, 'cetak' ) ) . '" target="_blank">Cetak</a> <a class="button" href="' . esc_url( pk_kad_url( $a, 'muat' ) ) . '">Muat turun PNG</a></p>'
		. '<p class="description">Kod pengesahan pada kad: <code>' . esc_html( pk_kad_kod( $a ) ) . '</code> — bandingkan dengan kad yang ditunjukkan untuk memastikan ia sah.</p></div>';
}

function pk_portal_kad_card( array $a ) {
	if ( ! pk_is_kariah_aktif( $a ) ) { return ''; }
	return '<div class="pk-card"><h3 class="pk-h mb-2"><i class="fa-solid fa-id-card text-mp"></i> Kad Ahli Kariah</h3>'
		. '<img src="' . esc_url( pk_kad_url( $a ) ) . '" alt="Kad Ahli Kariah ' . esc_attr( $a['no_ahli'] ) . '" style="width:100%;max-width:420px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.12)" loading="lazy">'
		. '<div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-sm btn-mp" href="' . esc_url( pk_kad_url( $a, 'muat' ) ) . '"><i class="fa-solid fa-download"></i> Muat turun</a><a class="btn btn-sm btn-outline-success" href="' . esc_url( pk_kad_url( $a, 'cetak' ) ) . '" target="_blank"><i class="fa-solid fa-print"></i> Cetak (saiz sebenar)</a></div>'
		. '<p class="small text-secondary mt-2 mb-0">Simpan dalam telefon atau cetak. Gambar profil anda dipaparkan pada kad — tukar gambar di bawah jika perlu.</p></div>';
}
