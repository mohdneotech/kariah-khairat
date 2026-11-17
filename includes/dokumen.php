<?php
/**
 * Dokumen sokongan Ahli Kariah — salinan Kad Pengenalan and surat pengesahan alamat (JPKK / penghulu / pengerusi taman).
 * Stored privately in uploads/pk-private (same rules as payment proofs); visible only to the member and AJK.
 */
defined( 'ABSPATH' ) || exit;

function pk_dok_label( $k ) { return 'dok_alamat' === $k ? 'Surat pengesahan alamat' : 'Salinan Kad Pengenalan'; }

/** Replace one document on a member record (old file deleted). */
function pk_ahli_set_dok( $ahli_id, $field, $rel ) {
	global $wpdb;
	if ( ! in_array( $field, [ 'dok_ic', 'dok_alamat' ], true ) ) { return; }
	$a = pk_get_ahli( $ahli_id );
	if ( ! $a ) { return; }
	if ( $a[ $field ] && $a[ $field ] !== $rel ) { pk_private_delete( $a[ $field ] ); }
	$wpdb->update( pk_t( 'ahli' ), [ $field => (string) $rel, 'updated_at' => pk_now() ], [ 'id' => $ahli_id ] );
}

/** Store dok_ic / dok_alamat uploads from the current request onto a record. @return true|WP_Error */
function pk_dok_from_request( $ahli_id, $who ) {
	$n = 0;
	foreach ( [ 'dok_ic', 'dok_alamat' ] as $f ) {
		$rel = pk_store_upload( $f );
		if ( is_wp_error( $rel ) ) { return $rel; }
		if ( $rel ) { pk_ahli_set_dok( $ahli_id, $f, $rel ); pk_log( $ahli_id, 'dok_kemaskini', pk_dok_label( $f ) . ' dimuat naik oleh ' . $who ); $n++; }
	}
	return $n ? true : new WP_Error( 'x', 'Sila pilih fail untuk dimuat naik.' );
}

/* ---------------------------------------------------------------- AJK: member screen box */

add_action( 'admin_post_pk_dok_ahli', function () {
	pk_guard( 'pk_dok_ahli' );
	$id = (int) pk_p( 'id' );
	if ( ! pk_get_ahli( $id ) ) { pk_back( 'Rekod tidak ditemui.' ); }
	$buang = pk_p( 'buang' );
	if ( in_array( $buang, [ 'dok_ic', 'dok_alamat' ], true ) ) {
		pk_ahli_set_dok( $id, $buang, '' );
		pk_log( $id, 'dok_padam', pk_dok_label( $buang ) . ' dibuang oleh AJK' );
		pk_back( pk_dok_label( $buang ) . ' dibuang.' );
	}
	$r = pk_dok_from_request( $id, 'AJK ' . wp_get_current_user()->user_login );
	pk_back( is_wp_error( $r ) ? $r->get_error_message() : 'Dokumen sokongan disimpan.' );
} );

function pk_admin_dok_box( array $a ) {
	echo '<div class="pk-box"><h2><span class="dashicons dashicons-media-document"></span> Dokumen Sokongan</h2>';
	foreach ( [ 'dok_ic', 'dok_alamat' ] as $f ) {
		echo '<p><b>' . esc_html( pk_dok_label( $f ) ) . ':</b> ';
		if ( $a[ $f ] ) {
			echo '</p><div class="pk-files">' . pk_file_link( pk_bukti_url( $a[ $f ] ), $a[ $f ], pk_dok_label( $f ) ) . '</div>' . pk_post_btn( 'pk_dok_ahli', [ 'id' => $a['id'], 'buang' => $f ], 'Buang', 'pk-link-del', 'Buang dokumen ini?' );
		} else {
			echo 'dok_ic' === $f ? '<span class="pk-badge pk-b-warn">Tiada</span></p>' : '<span class="description">tiada (perlu hanya jika alamat IC berbeza)</span></p>';
		}
	}
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pk_dok_ahli', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="pk_dok_ahli"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
		. '<p><label>Salinan IC<br><input type="file" name="dok_ic" accept="image/*,application/pdf"></label></p>'
		. '<p><label>Surat pengesahan alamat<br><input type="file" name="dok_alamat" accept="image/*,application/pdf"></label></p>'
		. '<p><button class="button">Muat naik</button></p></form>'
		. '<p class="description">Peribadi (PDPA) — hanya ahli &amp; AJK boleh melihatnya. Semak alamat pada IC berada dalam kariah sebelum meluluskan.</p></div>';
}

/* ---------------------------------------------------------------- Portal Ahli */

function pk_portal_dok() {
	$a = pk_ahli_for_user();
	$r = pk_dok_from_request( (int) $a['id'], 'ahli (Portal)' );
	if ( is_wp_error( $r ) ) { return $r; }
	pk_notify( 'Dokumen sokongan dimuat naik: ' . $a['nama'], 'Semak: ' . admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $a['id'] ), 'portal' );
	return [ 'Dokumen sokongan anda telah dimuat naik.', 'profil' ];
}

function pk_portal_dok_card( array $a ) {
	$h = '<div class="pk-card"><h3 class="pk-h mb-1"><i class="fa-solid fa-file-shield text-mp"></i> Dokumen Sokongan</h3>'
		. '<p class="small text-secondary mb-2">Salinan Kad Pengenalan yang menunjukkan alamat dalam kariah. Jika alamat pada IC berbeza dengan tempat tinggal semasa, sertakan surat pengesahan alamat daripada JPKK / penghulu / pengerusi taman. Hanya anda dan AJK boleh melihat dokumen ini.</p><ul class="list-unstyled small mb-2">';
	foreach ( [ 'dok_ic', 'dok_alamat' ] as $f ) {
		$h .= '<li class="mb-1">' . ( $a[ $f ] ? '<i class="fa-solid fa-circle-check text-success"></i> ' . esc_html( pk_dok_label( $f ) ) . ' — <a href="' . esc_url( pk_fail_url( $a[ $f ] ) ) . '" target="_blank">lihat</a>'
			: '<i class="fa-regular fa-circle text-secondary"></i> ' . esc_html( pk_dok_label( $f ) ) . ' — belum dimuat naik' ) . '</li>';
	}
	return $h . '</ul><form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">' . pk_pf( 'dok' )
		. '<div class="col-md-5"><label class="form-label small">Salinan IC</label><input class="form-control form-control-sm" type="file" name="dok_ic" accept="image/*,application/pdf"></div>'
		. '<div class="col-md-5"><label class="form-label small">Surat pengesahan alamat</label><input class="form-control form-control-sm" type="file" name="dok_alamat" accept="image/*,application/pdf"></div>'
		. '<div class="col-md-2"><button class="btn btn-sm btn-mp w-100"><i class="fa-solid fa-upload"></i> Hantar</button></div></form></div>';
}
