<?php
/**
 * Member profile photos — stored privately (uploads/pk-private), visible only to the member and AJK.
 * Uploads are re-encoded (square crop, EXIF stripped incl. GPS) so the original file never stays on disk.
 */
defined( 'ABSPATH' ) || exit;

const PK_FOTO_PX = 600;

/**
 * Validate an uploaded image and store a re-encoded copy in the private folder.
 * @param string $field   $_FILES key
 * @param int    $px      output size; square crop when $square, otherwise longest side
 * @return string|WP_Error|null  relative path, error, or null when no file was chosen
 */
function pk_store_foto( $field, $px = PK_FOTO_PX, $square = true ) {
	if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) { return null; }
	$f = $_FILES[ $field ];
	if ( UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'foto', 'Muat naik gambar gagal. Sila cuba lagi.' ); }
	if ( $f['size'] > 10 * MB_IN_BYTES ) { return new WP_Error( 'foto', 'Saiz gambar melebihi 10 MB.' ); }
	$chk = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ] );
	$info = @getimagesize( $f['tmp_name'] );
	if ( empty( $chk['ext'] ) || ! $info || ! in_array( $info[2], [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ], true ) ) {
		return new WP_Error( 'foto', 'Sila pilih gambar JPG, PNG atau WEBP.' );
	}
	if ( $info[0] < 120 || $info[1] < 120 ) { return new WP_Error( 'foto', 'Gambar terlalu kecil. Sila guna gambar sekurang-kurangnya 300 × 300 piksel.' ); }
	if ( $info[0] * $info[1] > 40000000 ) { return new WP_Error( 'foto', 'Resolusi gambar terlalu besar.' ); }
	$head = (string) @file_get_contents( $f['tmp_name'], false, null, 0, 1024 );
	if ( preg_match( '/<\?php|<script|<html|<svg/i', $head ) ) { return new WP_Error( 'foto', 'Fail tidak dibenarkan.' ); }
	return pk_foto_reencode( $f['tmp_name'], $px, $square );
}

/** Re-encode an image file into the private folder as JPEG. @return string|WP_Error relative path */
function pk_foto_reencode( $src, $px = PK_FOTO_PX, $square = true ) {
	$ed = wp_get_image_editor( $src );
	if ( is_wp_error( $ed ) ) { return new WP_Error( 'foto', 'Gambar tidak dapat dibaca. Sila cuba gambar lain.' ); }
	if ( method_exists( $ed, 'maybe_exif_rotate' ) ) { $ed->maybe_exif_rotate(); }
	if ( $square ) {
		$s = $ed->get_size(); $side = min( $s['width'], $s['height'] );
		// crop square, biased to the upper part of portraits where the face usually is
		$ed->crop( (int) ( ( $s['width'] - $side ) / 2 ), (int) ( ( $s['height'] - $side ) * 0.3 ), $side, $side, min( $px, $side ), min( $px, $side ) );
	} else {
		$ed->resize( $px, $px, false );
	}
	$ed->set_quality( 86 );
	$sub = gmdate( 'Y/m' );
	$dir = pk_private_dir() . '/' . $sub;
	wp_mkdir_p( $dir );
	$name = 'foto-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 10, false ) . '.jpg';
	$res  = $ed->save( "$dir/$name", 'image/jpeg' );
	if ( is_wp_error( $res ) ) { return new WP_Error( 'foto', 'Gambar tidak dapat disimpan.' ); }
	@chmod( "$dir/$name", 0640 );
	return "$sub/$name";
}

/** Absolute path of a private file, or '' when the reference is invalid / missing. */
function pk_private_path( $rel ) {
	if ( ! is_string( $rel ) || ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9\-]+\.(jpe?g|png|webp|heic|pdf)$#', $rel ) ) { return ''; }
	$base = realpath( pk_private_dir() );
	$path = realpath( $base . '/' . $rel );
	return ( $path && 0 === strpos( $path, $base . DIRECTORY_SEPARATOR ) && is_file( $path ) ) ? $path : '';
}

function pk_private_delete( $rel ) {
	if ( $p = pk_private_path( $rel ) ) { @unlink( $p ); }
}

/** Copy a private photo to a fresh private file (so two records never share one file). */
function pk_foto_copy( $rel ) {
	$p = pk_private_path( $rel );
	return $p ? pk_foto_reencode( $p, 1200, false ) : new WP_Error( 'foto', 'Gambar asal tidak ditemui.' );
}

function pk_ahli_set_foto( $ahli_id, $rel ) {
	global $wpdb;
	$a = pk_get_ahli( $ahli_id );
	if ( ! $a ) { return; }
	if ( $a['gambar'] && $a['gambar'] !== $rel ) { pk_private_delete( $a['gambar'] ); }
	$wpdb->update( pk_t( 'ahli' ), [ 'gambar' => (string) $rel, 'updated_at' => pk_now() ], [ 'id' => $ahli_id ] );
}

/* ---------------------------------------------------------------- viewing (member: own photo · AJK: any) */

add_action( 'admin_post_pk_foto', function () {
	$id = (int) pk_gs( 'id' );
	if ( ! wp_verify_nonce( pk_gs( '_wpnonce' ), 'pk_foto_' . $id ) ) { wp_die( 'Pautan tamat tempoh.', 403 ); }
	$me = pk_ahli_for_user();
	if ( ! current_user_can( PK_CAP ) && ( ! $me || (int) $me['id'] !== $id ) ) { wp_die( 'Tiada kebenaran.', 403 ); }
	$a = pk_get_ahli( $id );
	if ( ! $a || ! $a['gambar'] ) { wp_die( 'Tiada gambar.', 404 ); }
	pk_stream_private( $a['gambar'] );
} );

function pk_foto_url( array $a ) {
	if ( empty( $a['gambar'] ) ) { return ''; }
	return add_query_arg( [ 'action' => 'pk_foto', 'id' => (int) $a['id'], 'v' => substr( md5( $a['gambar'] ), 0, 8 ), '_wpnonce' => wp_create_nonce( 'pk_foto_' . (int) $a['id'] ) ], admin_url( 'admin-post.php' ) );
}

/** Round avatar: photo when available, else the first letter of the name. */
function pk_avatar_html( array $a, $class = 'pk-avatar' ) {
	if ( $u = pk_foto_url( $a ) ) {
		return '<img class="' . esc_attr( $class ) . ' pk-avatar-img" src="' . esc_url( $u ) . '" alt="Gambar profil ' . esc_attr( $a['nama'] ) . '" width="104" height="104" loading="lazy">';
	}
	return '<div class="' . esc_attr( $class ) . '">' . esc_html( mb_substr( (string) $a['nama'], 0, 1 ) ) . '</div>';
}

/* ---------------------------------------------------------------- AJK: upload / remove from the member screen */

add_action( 'admin_post_pk_foto_ahli', function () {
	pk_guard( 'pk_foto_ahli' );
	$id = (int) pk_p( 'id' );
	$a  = pk_get_ahli( $id );
	if ( ! $a ) { pk_back( 'Rekod tidak ditemui.' ); }
	if ( pk_p( 'padam' ) ) {
		pk_ahli_set_foto( $id, '' );
		pk_log( $id, 'foto_padam', 'Gambar profil dibuang oleh AJK' );
		pk_back( 'Gambar profil dibuang.' );
	}
	$rel = pk_store_foto( 'foto' );
	if ( null === $rel ) { pk_back( 'Sila pilih gambar.' ); }
	if ( is_wp_error( $rel ) ) { pk_back( $rel->get_error_message() ); }
	pk_ahli_set_foto( $id, $rel );
	pk_log( $id, 'foto_kemaskini', 'Gambar profil dimuat naik oleh AJK' );
	pk_back( 'Gambar profil dikemas kini.' );
} );

function pk_admin_foto_box( array $a ) {
	echo '<div class="pk-box pk-foto-box"><h2><span class="dashicons dashicons-format-image"></span> Gambar Profil</h2><div class="pk-foto-row">' . pk_avatar_html( $a, 'pk-avatar pk-avatar-lg' ) . '<div>';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pk_foto_ahli', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="pk_foto_ahli"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
		. '<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required> <button class="button">' . ( $a['gambar'] ? 'Tukar' : 'Muat naik' ) . '</button></form>';
	if ( $a['gambar'] ) { echo pk_post_btn( 'pk_foto_ahli', [ 'id' => $a['id'], 'padam' => 1 ], 'Buang gambar', 'pk-link-del', 'Buang gambar profil ahli ini?' ); }
	echo '<p class="description">Peribadi — hanya ahli &amp; AJK boleh melihatnya.</p></div></div>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=pk-takziah&action=baru&ahli=' . (int) $a['id'] ) ) . '"><span class="dashicons dashicons-heart" style="vertical-align:text-bottom"></span> Makluman Takziah</a></p></div>';
}
