<?php
/** AJK review of member change requests, portal account management, audit trail. */
defined( 'ABSPATH' ) || exit;

function pk_page_kemaskini() {
	global $wpdb;
	$st   = sanitize_key( pk_gs( 'status', 'menunggu' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT k.*, a.nama, a.no_ahli FROM ' . pk_t( 'kemaskini' ) . ' k JOIN ' . pk_t( 'ahli' ) . ' a ON a.id=k.ahli_id WHERE k.status=%s ORDER BY k.created_at ' . ( 'menunggu' === $st ? 'ASC' : 'DESC' ) . ' LIMIT 100', $st ), ARRAY_A );
	$counts = array_map( fn( $r ) => (int) $r->n, $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . pk_t( 'kemaskini' ) . ' GROUP BY status', OBJECT_K ) );
	pk_admin_header( 'Permohonan Kemas Kini Ahli' );
	echo '<ul class="subsubsub">';
	$i = 0;
	foreach ( [ 'menunggu' => 'Menunggu', 'lulus' => 'Diluluskan', 'ditolak' => 'Ditolak', 'batal' => 'Dibatalkan ahli' ] as $k => $l ) {
		echo ( $i++ ? '<li> | ' : '<li>' ) . '<a class="' . ( $st === $k ? 'current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=pk-kemaskini&status=' . $k ) ) . '">' . esc_html( $l ) . ' <span class="count">(' . (int) ( $counts[ $k ] ?? 0 ) . ')</span></a></li>';
	}
	echo '</ul><div class="clear"></div>';
	echo '<p class="description">Ahli mengemas kini maklumat hubungan secara terus melalui Portal Ahli. Perubahan <b>pasangan dan tanggungan</b> memerlukan kelulusan di sini.</p>';
	if ( ! $rows ) { echo '<div class="pk-box">Tiada permohonan.</div></div>'; return; }
	foreach ( $rows as $r ) {
		$a = pk_get_ahli( $r['ahli_id'] );
		echo '<div class="pk-box"><h2><a href="' . esc_url( admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $r['ahli_id'] ) ) . '">' . esc_html( $r['nama'] ) . '</a> <span class="description">' . esc_html( $r['no_ahli'] ) . ' · dihantar ' . esc_html( pk_date( substr( $r['created_at'], 0, 10 ) ) ) . '</span></h2>';
		echo pk_kemaskini_diff( $a, json_decode( $r['data'], true ) ?: [] );
		if ( $r['nota_ahli'] ) { echo '<p><b>Nota ahli:</b> ' . esc_html( $r['nota_ahli'] ) . '</p>'; }
		if ( 'menunggu' === $st && (int) $r['ahli_id'] === pk_my_ahli_id() && pk_self_review_blocked( true ) ) {
			echo '<p><span class="pk-chip pk-chip-alt">Permohonan anda sendiri</span> Perlu disemak oleh AJK lain.</p>';
		} elseif ( 'menunggu' === $st ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pk-inline">' . wp_nonce_field( 'pk_kemaskini_semak', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="pk_kemaskini_semak"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
				. '<label>Nota AJK (dipaparkan kepada ahli jika ditolak)<br><input type="text" name="nota_ajk" class="regular-text"></label>'
				. '<button class="button button-primary" name="keputusan" value="lulus">Luluskan &amp; kemas kini rekod</button> <button class="button pk-link-del" name="keputusan" value="ditolak">Tolak</button></form>';
		} elseif ( $r['nota_ajk'] ) { echo '<p class="description">Nota AJK: ' . esc_html( $r['nota_ajk'] ) . '</p>'; }
		echo '</div>';
	}
	echo '</div>';
}

function pk_kemaskini_diff( $a, array $d ) {
	$lab = [ 'p_nama' => 'Nama pasangan', 'p_kp' => 'No. KP pasangan', 'p_hp' => 'No. HP pasangan', 'p_pekerjaan' => 'Pekerjaan pasangan', 'p_sektor' => 'Sektor pasangan', 'p_majikan' => 'Majikan pasangan', 'p_tel_p' => 'Tel. pejabat pasangan' ];
	$h   = '<table class="widefat striped pk-mini pk-diff"><thead><tr><th style="width:22%">Perkara</th><th>Rekod semasa</th><th>Dimohon</th></tr></thead><tbody>';
	$chg = 0;
	foreach ( $lab as $k => $l ) {
		$o = (string) ( $a[ $k ] ?? '' ); $n = (string) ( $d[ $k ] ?? '' );
		if ( $o === $n ) { continue; }
		$chg++;
		$h .= '<tr><td>' . esc_html( $l ) . '</td><td><del>' . esc_html( $o ?: '—' ) . '</del></td><td><ins>' . esc_html( $n ?: '(kosongkan)' ) . '</ins></td></tr>';
	}
	$fmt = fn( $t ) => $t['nama'] . ( $t['hubungan'] ? ' (' . $t['hubungan'] . ')' : '' ) . ( ! empty( $t['tarikh_lahir'] ) ? ' · ' . pk_date( $t['tarikh_lahir'] ) : '' ) . ( ( $t['tinggal'] ?? 'Y' ) === 'T' ? ' · tidak tinggal bersama' : '' );
	$old = array_map( $fmt, pk_tanggungan_get( $a['id'] ) );
	$new = array_map( $fmt, $d['tanggungan'] ?? [] );
	if ( $old !== $new ) {
		$chg++;
		$h .= '<tr><td>Tanggungan</td><td>' . ( $old ? '<ol>' . implode( '', array_map( fn( $x ) => '<li' . ( in_array( $x, $new, true ) ? '' : ' class="pk-del"' ) . '>' . esc_html( $x ) . '</li>', $old ) ) . '</ol>' : '—' ) . '</td>'
			. '<td>' . ( $new ? '<ol>' . implode( '', array_map( fn( $x ) => '<li' . ( in_array( $x, $old, true ) ? '' : ' class="pk-add"' ) . '>' . esc_html( $x ) . '</li>', $new ) ) . '</ol>' : '(tiada tanggungan)' ) . '</td></tr>';
	}
	if ( ! $chg ) { $h .= '<tr><td colspan="3">Tiada perbezaan daripada rekod semasa.</td></tr>'; }
	return $h . '</tbody></table>';
}

add_action( 'admin_post_pk_kemaskini_semak', function () {
	pk_guard( 'pk_kemaskini_semak' );
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'kemaskini' ) . " WHERE id=%d AND status='menunggu'", (int) pk_p( 'id' ) ), ARRAY_A );
	if ( ! $r ) { pk_back( 'Permohonan tidak ditemui atau telah disemak.' ); }
	$own = (int) $r['ahli_id'] === pk_my_ahli_id();
	if ( pk_self_review_blocked( $own ) ) { pk_back( 'Permohonan kemas kini anda sendiri perlu disemak oleh AJK lain.' ); }
	$ok = 'lulus' === pk_p( 'keputusan' );
	if ( $own ) { pk_log( $r['ahli_id'], 'semak_sendiri', 'Pentadbir menyemak permohonan kemas kini sendiri (' . ( $ok ? 'lulus' : 'tolak' ) . ')' ); }
	if ( $ok ) {
		$d   = json_decode( $r['data'], true ) ?: [];
		$upd = array_intersect_key( $d, array_flip( PK_KELUARGA_FIELDS ) );
		$upd['updated_at'] = pk_now();
		$wpdb->update( pk_t( 'ahli' ), $upd, [ 'id' => $r['ahli_id'] ] );
		pk_tanggungan_replace( $r['ahli_id'], $d['tanggungan'] ?? [] );
	}
	$wpdb->update( pk_t( 'kemaskini' ), [ 'status' => $ok ? 'lulus' : 'ditolak', 'nota_ajk' => pk_p( 'nota_ajk' ), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => pk_now() ], [ 'id' => $r['id'] ] );
	pk_log( $r['ahli_id'], $ok ? 'kemaskini_lulus' : 'kemaskini_tolak', $ok ? 'Kemas kini pasangan/tanggungan diluluskan' : 'Permohonan kemas kini ditolak: ' . pk_p( 'nota_ajk' ) );
	pk_back( $ok ? 'Permohonan diluluskan dan rekod ahli dikemas kini.' : 'Permohonan ditolak.' );
} );

/* ================================================================= portal account (on member edit screen) */

function pk_admin_portal_box( array $a ) {
	$u = $a['user_id'] ? get_userdata( $a['user_id'] ) : null;
	echo '<div class="pk-box"><h2><span class="dashicons dashicons-id"></span> Akaun Portal Ahli</h2>';
	$flash = get_transient( 'pk_pw_' . get_current_user_id() );
	if ( $flash && (int) $flash['ahli'] === (int) $a['id'] ) {
		delete_transient( 'pk_pw_' . get_current_user_id() );
		echo '<div class="pk-flash">Kata laluan sementara (paparan sekali sahaja — serahkan kepada ahli):<br><code class="pk-pw">' . esc_html( $flash['pw'] ) . '</code><br><span class="description">ID log masuk: ' . esc_html( $flash['login'] ) . ' · Ahli boleh menukarnya di Portal Ahli › Kata Laluan.</span></div>';
	}
	if ( $u && pk_is_staff( $u ) ) {
		echo '<p>Dipautkan kepada akaun <b>AJK/pentadbir</b>: ' . esc_html( $u->display_name ) . ' (<code>' . esc_html( $u->user_login ) . '</code>). Ahli ini log masuk melalui halaman pentadbir (2FA); kata laluan diurus melalui profil WordPress sendiri.</p>';
		echo pk_post_btn( 'pk_akaun', [ 'id' => $a['id'], 'op' => 'buang' ], 'Nyahpaut akaun', 'pk-link-del', 'Nyahpaut akaun pengguna ini daripada rekod ahli? Akaun AJK tidak dipadam.' );
	} elseif ( $u ) {
		echo '<p>Status: <span class="pk-badge pk-b-ok">Aktif</span> · ID log masuk <code>' . esc_html( $u->user_login ) . '</code>' . ( $u->user_email ? ' · ' . esc_html( $u->user_email ) : '' ) . '</p>';
		echo pk_post_btn( 'pk_akaun', [ 'id' => $a['id'], 'op' => 'reset' ], 'Set semula kata laluan', '', 'Jana kata laluan sementara baharu untuk ahli ini?' );
		echo pk_post_btn( 'pk_akaun', [ 'id' => $a['id'], 'op' => 'buang' ], 'Nyahaktif akaun', 'pk-link-del', 'Padam akaun log masuk ahli ini? Rekod ahli kekal.' );
	} else {
		echo '<p class="description">Belum diaktifkan. Ahli boleh mengaktifkan sendiri di halaman <a href="' . esc_url( pk_page_url( 'portal' ) ) . '" target="_blank">Portal Ahli</a> menggunakan No. KP + No. Telefon berdaftar, atau AJK boleh mencipta akaun sekarang.</p>';
		if ( 12 === strlen( $a['no_kp'] ) ) { echo pk_post_btn( 'pk_akaun', [ 'id' => $a['id'], 'op' => 'cipta' ], 'Cipta akaun & jana kata laluan', 'button-primary' ); }
		else { echo '<p class="description">No. KP 12 digit diperlukan untuk mencipta akaun.</p>'; }
		// link to an existing staff account (AJK / pentadbir who is also a kariah member)
		global $wpdb;
		$taken = array_map( 'intval', $wpdb->get_col( 'SELECT user_id FROM ' . pk_t( 'ahli' ) . ' WHERE user_id>0' ) );
		$opts  = [];
		foreach ( get_users( [ 'capability__in' => [ PK_CAP, 'edit_posts' ], 'fields' => [ 'ID', 'display_name', 'user_login' ], 'number' => 200 ] ) as $su ) {
			if ( ! in_array( (int) $su->ID, $taken, true ) ) { $opts[ $su->ID ] = $su->display_name . ' (' . $su->user_login . ')'; }
		}
		if ( $opts ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pk-inline" style="margin-top:10px">' . wp_nonce_field( 'pk_akaun', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="pk_akaun"><input type="hidden" name="op" value="pautkan"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
				. '<label>Atau pautkan kepada akaun AJK/pentadbir sedia ada<br>' . pk_select( 'user_id', $opts, '', 'required', '— Pilih akaun —' ) . '</label> <button class="button">Pautkan</button></form>';
		}
	}
	echo '</div>';
}

add_action( 'admin_post_pk_akaun', function () {
	pk_guard( 'pk_akaun' );
	global $wpdb;
	$a  = pk_get_ahli( (int) pk_p( 'id' ) );
	if ( ! $a ) { pk_back( 'Rekod tidak ditemui.' ); }
	$op = pk_p( 'op' );
	$pw = wp_generate_password( 10, false, false );
	$u  = $a['user_id'] ? get_userdata( $a['user_id'] ) : null;
	if ( 'cipta' === $op && ! $u ) {
		$login = pk_digits( $a['no_kp'] );
		$ex    = get_user_by( 'login', $login );
		if ( $ex && ! in_array( 'pk_ahli', (array) $ex->roles, true ) ) { pk_back( 'ID log masuk ' . $login . ' telah digunakan oleh akaun lain.' ); }
		$uid = $ex ? $ex->ID : wp_insert_user( [ 'user_login' => $login, 'user_nicename' => pk_member_slug(), 'user_pass' => $pw, 'user_email' => ( $a['email'] && ! email_exists( $a['email'] ) ) ? $a['email'] : '', 'display_name' => $a['nama'], 'role' => 'pk_ahli' ] );
		if ( is_wp_error( $uid ) ) { pk_back( $uid->get_error_message() ); }
		if ( $ex ) { wp_set_password( $pw, $uid ); }
		$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => $uid ], [ 'id' => $a['id'] ] );
		set_transient( 'pk_pw_' . get_current_user_id(), [ 'ahli' => $a['id'], 'pw' => $pw, 'login' => $login ], 600 );
		pk_log( $a['id'], 'akaun_cipta', 'Akaun portal dicipta oleh AJK' );
		pk_back( 'Akaun portal dicipta.' );
	}
	if ( 'pautkan' === $op && ! $u ) {
		$su = get_userdata( (int) pk_p( 'user_id' ) );
		if ( ! $su || ! pk_is_staff( $su ) ) { pk_back( 'Akaun tidak sah.' ); }
		if ( pk_ahli_for_user( $su->ID ) ) { pk_back( 'Akaun tersebut sudah dipautkan kepada rekod ahli lain.' ); }
		$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => $su->ID ], [ 'id' => $a['id'] ] );
		pk_log( $a['id'], 'akaun_pautkan', 'Dipautkan kepada akaun AJK/pentadbir ' . $su->user_login . ' oleh ' . wp_get_current_user()->user_login );
		pk_back( 'Rekod dipautkan kepada akaun ' . $su->display_name . '.' );
	}
	if ( in_array( $op, [ 'reset', 'cipta' ], true ) && $u && pk_is_staff( $u ) ) { pk_back( 'Akaun AJK/pentadbir tidak boleh diset semula dari sini — gunakan Pengguna › Profil.' ); }
	if ( 'reset' === $op && $u ) {
		wp_set_password( $pw, $u->ID );
		set_transient( 'pk_pw_' . get_current_user_id(), [ 'ahli' => $a['id'], 'pw' => $pw, 'login' => $u->user_login ], 600 );
		pk_log( $a['id'], 'akaun_reset', 'Kata laluan portal diset semula oleh AJK' );
		pk_back( 'Kata laluan baharu dijana.' );
	}
	if ( 'buang' === $op && $u ) {
		if ( in_array( 'pk_ahli', (array) $u->roles, true ) && ! user_can( $u, 'edit_posts' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $u->ID );
		}
		$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => 0 ], [ 'id' => $a['id'] ] );
		pk_log( $a['id'], 'akaun_buang', 'Akaun portal dinyahaktifkan oleh AJK' );
		pk_back( 'Akaun portal dinyahaktifkan.' );
	}
	pk_back();
} );

/* ================================================================= history */

function pk_admin_log_box( $ahli_id ) {
	$rows = pk_log_list( $ahli_id, 30 );
	$lab  = [ 'no_hp' => 'No. HP', 'email' => 'E-mel', 'alamat' => 'Alamat', 'kawasan' => 'Kawasan', 'pekerjaan' => 'Pekerjaan', 'sektor' => 'Sektor', 'majikan' => 'Majikan', 'tel_p' => 'Tel. pejabat', 'tel_r' => 'Tel. rumah', 'status' => 'Status', 'nama' => 'Nama', 'no_kp' => 'No. KP' ];
	echo '<div class="pk-box"><h2><span class="dashicons dashicons-backup"></span> Sejarah Perubahan</h2>';
	if ( ! $rows ) { echo '<p class="description">Tiada rekod.</p></div>'; return; }
	echo '<ul class="pk-log">';
	foreach ( $rows as $r ) {
		$who = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
		$det = json_decode( (string) $r['butiran'], true );
		if ( is_array( $det ) ) {
			$det = implode( '; ', array_map( fn( $k, $v ) => ( $lab[ $k ] ?? $k ) . ': ' . ( is_array( $v ) ? ( ( $v[0] ?: '—' ) . ' → ' . ( $v[1] ?: '—' ) ) : $v ), array_keys( $det ), $det ) );
		}
		echo '<li><span>' . esc_html( mysql2date( 'j M Y, g:i a', $r['created_at'] ) ) . ' · ' . esc_html( $who ? $who->display_name : 'Sistem' ) . '</span><b>' . esc_html( str_replace( '_', ' ', $r['tindakan'] ) ) . '</b> ' . esc_html( (string) $det ) . '</li>';
	}
	echo '</ul></div>';
}
