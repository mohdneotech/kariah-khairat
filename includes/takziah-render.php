<?php
/**
 * Takziah poster renderer (GD only — works on any host with ext-gd + FreeType).
 * Static artwork (damask background, Arabic calligraphy) is pre-rendered in assets/takziah/;
 * names, dates, prayer text and the footer are drawn here with bundled OFL fonts.
 */
defined( 'ABSPATH' ) || exit;

const PK_TZ_W = 1500;
const PK_TZ_H = 2250;

function pk_tz_font( $f ) { return PK_DIR . 'assets/takziah/fonts/' . $f; }

/** GD point size that gives the requested cap height in pixels (independent of GD's DPI build setting). */
function pk_tz_size_for_cap( $font, $cap_px ) {
	static $cache = [];
	if ( ! isset( $cache[ $font ] ) ) {
		$b = imagettfbbox( 100, 0, $font, 'H' );
		$cache[ $font ] = max( 1, abs( $b[7] - $b[1] ) ) / 100;
	}
	return $cap_px / $cache[ $font ];
}

function pk_tz_width( $size, $font, $text, $track = 0 ) {
	if ( $track ) {
		$w = 0;
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) { $b = imagettfbbox( $size, 0, $font, $ch ); $w += ( $b[2] - $b[0] ) + $track; }
		return $w - $track;
	}
	$b = imagettfbbox( $size, 0, $font, $text );
	return $b[2] - $b[0];
}

/** Draw centred text at a baseline, with a soft shadow. Returns the width drawn. */
function pk_tz_text( $im, $text, $font, $size, $y, $rgb = [ 255, 255, 255 ], $track = 0, $shadow = 70 ) {
	$w  = pk_tz_width( $size, $font, $text, $track );
	$b  = imagettfbbox( $size, 0, $font, $text );
	$x  = (int) round( ( PK_TZ_W - $w ) / 2 - ( $track ? 0 : $b[0] ) );
	$fg = imagecolorallocatealpha( $im, $rgb[0], $rgb[1], $rgb[2], 0 );
	$sh = imagecolorallocatealpha( $im, 0, 0, 0, $shadow );
	foreach ( $shadow < 127 ? [ [ $sh, 3, 5 ], [ $fg, 0, 0 ] ] : [ [ $fg, 0, 0 ] ] as [ $col, $dx, $dy ] ) {
		if ( $track ) {
			$cx = $x;
			foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				$cb = imagettfbbox( $size, 0, $font, $ch );
				imagettftext( $im, $size, 0, $cx + $dx - $cb[0], $y + $dy, $col, $font, $ch );
				$cx += ( $cb[2] - $cb[0] ) + $track;
			}
		} else {
			imagettftext( $im, $size, 0, $x + $dx, $y + $dy, $col, $font, $text );
		}
	}
	return $w;
}

/** Largest size (by cap height, from $cap down to $min) at which $text fits $maxw; null if it never fits. */
function pk_tz_fit( $font, $text, $cap, $min, $maxw, $track = 0 ) {
	for ( $c = $cap; $c >= $min; $c -= 2 ) {
		$s = pk_tz_size_for_cap( $font, $c );
		if ( pk_tz_width( $s, $font, $text, $track ) <= $maxw ) { return $s; }
	}
	return null;
}

/** Split a name into two balanced lines. */
function pk_tz_split2( $text ) {
	$w = preg_split( '/\s+/u', trim( $text ) );
	if ( count( $w ) < 2 ) { return [ $text ]; }
	$best = null; $bd = PHP_INT_MAX; $len = mb_strlen( $text );
	for ( $i = 1; $i < count( $w ); $i++ ) {
		$a = implode( ' ', array_slice( $w, 0, $i ) ); $d = abs( mb_strlen( $a ) - ( $len - mb_strlen( $a ) ) );
		if ( $d < $bd ) { $bd = $d; $best = [ $a, implode( ' ', array_slice( $w, $i ) ) ]; }
	}
	return $best;
}

/** Load any supported image file into a truecolor GD image, honouring EXIF orientation for JPEGs. */
function pk_tz_load_photo( $path ) {
	if ( ! $path || ! is_file( $path ) ) { return null; }
	$im = @imagecreatefromstring( (string) file_get_contents( $path ) );
	if ( ! $im ) { return null; }
	if ( function_exists( 'exif_read_data' ) && preg_match( '/\.jpe?g$/i', $path ) ) {
		$o = (int) ( @exif_read_data( $path )['Orientation'] ?? 1 );
		$r = [ 3 => 180, 6 => -90, 8 => 90 ][ $o ] ?? 0;
		if ( $r ) { $im = imagerotate( $im, $r, 0 ); }
	}
	return $im;
}

/**
 * Draw the photo inside an anti-aliased oval with a white ring.
 * Pixel loop over the ring's bounding box only (~0.5 M px) — fast enough for an admin save.
 */
function pk_tz_oval( $im, $photo, $cx, $cy, $rx, $ry, $stroke ) {
	$half = $stroke / 2;
	$ox = (int) ceil( $rx + $half + 2 ); $oy = (int) ceil( $ry + $half + 2 );
	$ph = null;
	if ( $photo ) {
		// cover-crop the photo into the oval's box, biased slightly to the top (faces)
		$pw = imagesx( $photo ); $phh = imagesy( $photo );
		$bw = (int) ( 2 * $rx ); $bh = (int) ( 2 * $ry );
		$sc = max( $bw / $pw, $bh / $phh );
		$sw = $bw / $sc; $sh = $bh / $sc;
		$sx = ( $pw - $sw ) / 2; $sy = max( 0, ( $phh - $sh ) * 0.35 );
		$ph = imagecreatetruecolor( $bw, $bh );
		imagecopyresampled( $ph, $photo, 0, 0, (int) $sx, (int) $sy, $bw, $bh, (int) $sw, (int) $sh );
		// memorial look: black & white with a touch of contrast
		imagefilter( $ph, IMG_FILTER_GRAYSCALE );
		imagefilter( $ph, IMG_FILTER_CONTRAST, -8 );
	}
	for ( $y = -$oy; $y <= $oy; $y++ ) {
		$py = $cy + $y;
		if ( $py < 0 || $py >= PK_TZ_H ) { continue; }
		for ( $x = -$ox; $x <= $ox; $x++ ) {
			$px = $cx + $x;
			$k  = sqrt( ( $x / $rx ) ** 2 + ( $y / $ry ) ** 2 );
			if ( $k < 1e-6 ) { $d = -min( $rx, $ry ); } else { $d = ( $k - 1 ) * sqrt( $x * $x + $y * $y ) / $k; } // ≈ signed distance (px)
			if ( $d > $half + 1 ) { continue; }
			$base = imagecolorat( $im, $px, $py );
			$br = ( $base >> 16 ) & 255; $bg = ( $base >> 8 ) & 255; $bb = $base & 255;
			// inner fill (photo, or slightly darkened background when there is no photo)
			if ( $ph ) {
				$c = imagecolorat( $ph, max( 0, min( imagesx( $ph ) - 1, (int) ( $x + $rx ) ) ), max( 0, min( imagesy( $ph ) - 1, (int) ( $y + $ry ) ) ) );
				$ir = ( $c >> 16 ) & 255; $ig = ( $c >> 8 ) & 255; $ib = $c & 255;
			} else {
				$ir = (int) ( $br * 0.75 ); $ig = (int) ( $bg * 0.75 ); $ib = (int) ( $bb * 0.75 );
			}
			$fin = max( 0, min( 1, -$d - $half + 0.5 ) );           // inside the ring
			$rin = max( 0, min( 1, $half + 0.5 - abs( $d ) ) );     // on the ring
			$out = max( 0, 1 - $fin - $rin );
			$r = $ir * $fin + 255 * $rin + $br * $out;
			$g = $ig * $fin + 255 * $rin + $bg * $out;
			$b = $ib * $fin + 255 * $rin + $bb * $out;
			imagesetpixel( $im, $px, $py, ( (int) $r << 16 ) | ( (int) $g << 8 ) | (int) $b );
		}
	}
	if ( $ph ) { imagedestroy( $ph ); }
}

function pk_tz_date( $d ) { return $d ? gmdate( 'd.m.Y', strtotime( $d ) ) : ''; }

/**
 * Render a poster.
 * @param array $t  keys: nama, tarikh_lahir (Y-m-d|null), tarikh_meninggal (Y-m-d), photo_path (abs|null)
 * @param array $o  keys: doa (multi-line), kaki (footer)
 * @return GdImage|WP_Error
 */
function pk_tz_render( array $t, array $o ) {
	if ( ! function_exists( 'imagettftext' ) ) { return new WP_Error( 'gd', 'Pelayan tidak menyokong GD/FreeType — imej tidak dapat dijana.' ); }
	@set_time_limit( 60 );
	$serif = pk_tz_font( 'NotoSerif-Bold.ttf' ); $sans = pk_tz_font( 'OpenSans-SemiBold.ttf' );
	$bold  = pk_tz_font( 'OpenSans-Bold.ttf' );  $script = pk_tz_font( 'KaushanScript-Regular.ttf' );

	$im = @imagecreatefromjpeg( PK_DIR . 'assets/takziah/bg.jpg' );
	if ( ! $im ) { return new WP_Error( 'gd', 'Fail latar takziah tidak ditemui.' ); }
	imagealphablending( $im, true );
	imageantialias( $im, true );
	$white = imagecolorallocate( $im, 255, 255, 255 );

	// frame
	$fr = imagecolorallocatealpha( $im, 255, 255, 255, 30 );
	for ( $i = 0; $i < 4; $i++ ) { imagerectangle( $im, 30 + $i, 30 + $i, PK_TZ_W - 31 - $i, PK_TZ_H - 31 - $i, $fr ); }

	// AL FATIHAH
	pk_tz_text( $im, 'AL FATIHAH', $bold, pk_tz_size_for_cap( $bold, 56 ), 150, [ 255, 255, 255 ], 6 );

	// Arabic calligraphy (pre-rendered, transparent PNG)
	$ar = @imagecreatefrompng( PK_DIR . 'assets/takziah/arab.png' );
	if ( $ar ) {
		$aw = 1180; $ah = (int) round( imagesy( $ar ) * $aw / imagesx( $ar ) );
		imagecopyresampled( $im, $ar, (int) ( ( PK_TZ_W - $aw ) / 2 ), 182, 0, 0, $aw, $ah, imagesx( $ar ), imagesy( $ar ) );
		imagedestroy( $ar );
	}

	// name: one line if it fits, else two lines
	$nama  = trim( preg_replace( '/\s+/u', ' ', (string) $t['nama'] ) );
	$lines = [ $nama ];
	$ns    = pk_tz_fit( $serif, $nama, 64, 46, 1300 );
	if ( null === $ns ) {
		$lines = pk_tz_split2( $nama );
		$ns    = min( array_map( fn( $l ) => pk_tz_fit( $serif, $l, 58, 30, 1300 ) ?? pk_tz_size_for_cap( $serif, 30 ), $lines ) );
	}
	$two = count( $lines ) > 1;

	// photo oval (shrinks a little when the name needs two lines)
	$ry = $two ? 330 : 365; $rx = $two ? 280 : 305; $cy = $two ? 870 : 900;
	pk_tz_oval( $im, $t['photo'] ?? null, 750, $cy, $rx, $ry, 11 );

	$y = $cy + $ry + 135;
	foreach ( $lines as $i => $l ) { pk_tz_text( $im, $l, $serif, $ns, $y ); $y += $i < count( $lines ) - 1 ? 92 : 0; }

	// dates
	$mati = pk_tz_date( $t['tarikh_meninggal'] );
	$lahir = pk_tz_date( $t['tarikh_lahir'] ?? null );
	$dt = $lahir ? "$lahir - $mati" : "Kembali ke rahmatullah: $mati";
	pk_tz_text( $im, $dt, $sans, pk_tz_fit( $sans, $dt, 48, 30, 1300 ) ?? pk_tz_size_for_cap( $sans, 30 ), $y + 100 );

	// divider
	$ly = $y + 160;
	imagefilledrectangle( $im, 70, $ly, PK_TZ_W - 71, $ly + 6, $white );

	// prayer text block — centred between the divider and "Salam Takziah"
	$doa  = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', (string) ( $o['doa'] ?? '' ) ) ), 'strlen' ) );
	$doa  = array_slice( $doa, 0, 6 );
	$st_y = 2085;
	if ( $doa ) {
		$avail = $st_y - 150 - ( $ly + 30 );
		$lh    = min( 88, $avail / count( $doa ) );
		$cap   = min( 46, (int) ( $lh * 0.52 ) );
		$size  = min( array_map( fn( $l ) => pk_tz_fit( $sans, $l, $cap, 26, 1320 ) ?? pk_tz_size_for_cap( $sans, 26 ), $doa ) );
		$y0    = $ly + 30 + ( $avail - $lh * count( $doa ) ) / 2 + $lh * 0.72;
		foreach ( $doa as $i => $l ) { pk_tz_text( $im, $l, $sans, $size, (int) round( $y0 + $i * $lh ) ); }
	}

	// Salam Takziah
	pk_tz_text( $im, 'Salam Takziah', $script, pk_tz_size_for_cap( $script, 92 ), $st_y );

	// footer — where the image comes from
	$kaki = trim( (string) ( $o['kaki'] ?? '' ) );
	if ( '' !== $kaki ) {
		pk_tz_text( $im, $kaki, $sans, pk_tz_fit( $sans, $kaki, 21, 14, 1300 ) ?? pk_tz_size_for_cap( $sans, 14 ), PK_TZ_H - 68, [ 185, 185, 185 ], 0, 127 );
	}
	return $im;
}
