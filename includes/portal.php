<?php
/**
 * Member portal: registered kariah members log in (No. KP + password), view their record,
 * dependants, fees & receipts, update contact details, and request family changes (AJK approval).
 */
defined( 'ABSPATH' ) || exit;

const PK_PROFIL_FIELDS    = [ 'no_hp', 'email', 'alamat', 'kawasan', 'pekerjaan', 'sektor', 'majikan', 'tel_p', 'tel_r' ];
const PK_KELUARGA_FIELDS  = [ 'p_nama', 'p_kp', 'p_hp', 'p_pekerjaan', 'p_sektor', 'p_majikan', 'p_tel_p' ];

function pk_is_member_only( $user = null ) {
	$user = $user ?: wp_get_current_user();
	return $user && $user->exists() && in_array( 'pk_ahli', (array) $user->roles, true ) && ! user_can( $user, PK_CAP ) && ! user_can( $user, 'edit_posts' );
}

/* ---------------------------------------------------------------- keep members out of wp-admin */

add_action( 'admin_init', function () {
	global $pagenow;
	if ( wp_doing_ajax() || 'admin-post.php' === $pagenow ) { return; }
	if ( pk_is_member_only() ) { wp_safe_redirect( pk_page_url( 'portal' ) ); exit; }
}, 1 );
add_filter( 'show_admin_bar', fn( $show ) => pk_is_member_only() ? false : $show );
add_filter( 'login_redirect', function ( $to, $req, $user ) {
	return ( $user instanceof WP_User && pk_is_member_only( $user ) ) ? pk_page_url( 'portal' ) : $to;
}, 20, 3 );

/* ---------------------------------------------------------------- member file access (own receipts only) */

add_action( 'admin_post_pk_fail', function () {
	check_admin_referer( 'pk_fail' );
	$rel = (string) wp_unslash( pk_gs( 'f' ) );
	$a   = pk_ahli_for_user();
	if ( ! current_user_can( PK_CAP ) && ( ! $a || ! pk_file_belongs_to_ahli( $rel, $a ) ) ) { wp_die( 'Tiada kebenaran.', 403 ); }
	pk_stream_private( $rel );
} );
function pk_fail_url( $rel ) { return wp_nonce_url( admin_url( 'admin-post.php?action=pk_fail&f=' . rawurlencode( $rel ) ), 'pk_fail' ); }

/* ================================================================= POST handling */

add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['pk_portal'] ) ) { return; }
	$act = is_string( $_POST['pk_portal'] ) ? sanitize_key( $_POST['pk_portal'] ) : '';
	$err = &$GLOBALS['pk_errors'];
	if ( ! wp_verify_nonce( $_POST['pk_nonce'] ?? '', 'pk_portal_' . $act ) ) { $err[] = 'Sesi tamat. Sila muat semula halaman dan cuba lagi.'; return; }
	$map = [ 'login' => 'pk_portal_login', 'aktif' => 'pk_portal_aktif', 'pautkan' => 'pk_portal_pautkan', 'profil' => 'pk_portal_profil', 'keluarga' => 'pk_portal_keluarga', 'bayar' => 'pk_portal_bayar', 'katalaluan' => 'pk_portal_katalaluan', 'batal' => 'pk_portal_batal' ];
	if ( ! isset( $map[ $act ] ) ) { return; }
	if ( 'pautkan' === $act && ! is_user_logged_in() ) { $err[] = 'Sila log masuk semula.'; return; }
	if ( ! in_array( $act, [ 'login', 'aktif', 'pautkan' ], true ) && ! pk_ahli_for_user() ) { $err[] = 'Sila log masuk semula.'; return; }
	if ( 'katalaluan' === $act && pk_is_staff() ) { $err[] = 'Kata laluan akaun AJK/pentadbir ditukar melalui profil WordPress.'; return; }
	$res = $map[ $act ]();
	if ( is_wp_error( $res ) ) { $err = array_merge( $err, $res->get_error_messages() ); return; }
	[ $msg, $tab ] = $res;
	wp_safe_redirect( add_query_arg( 'pk_msg', pk_flash_set( $msg ), pk_page_url( 'portal' ) ) . ( $tab ? '#' . $tab : '' ) );
	exit;
} );

function pk_portal_login() {
	if ( ! pk_throttle( 'login', 10, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'x', 'Terlalu banyak cubaan. Sila cuba lagi selepas 15 minit.' ); }
	$kp = pk_digits( pk_p( 'kp' ) );
	if ( 12 !== strlen( $kp ) ) { return new WP_Error( 'x', 'No. Kad Pengenalan atau kata laluan tidak tepat.' ); }
	$cand = get_user_by( 'login', $kp );
	if ( $cand && pk_is_staff( $cand ) ) { return new WP_Error( 'x', 'Akaun AJK/pentadbir perlu log masuk melalui halaman log masuk pentadbir (wp-login), kemudian buka semula Portal Ahli.' ); }
	$u = wp_signon( [ 'user_login' => $kp, 'user_password' => substr( (string) wp_unslash( $_POST['pass'] ?? '' ), 0, 128 ), 'remember' => ! empty( $_POST['ingat'] ) ], is_ssl() );
	if ( is_wp_error( $u ) ) { return new WP_Error( 'x', 'No. Kad Pengenalan atau kata laluan tidak tepat.' ); }
	wp_set_current_user( $u->ID );
	if ( ! pk_ahli_for_user( $u->ID ) ) { wp_logout(); return new WP_Error( 'x', 'Akaun ini tidak dipautkan kepada mana-mana rekod ahli. Sila hubungi AJK.' ); }
	return [ 'Selamat datang, ' . $u->display_name . '.', '' ];
}

function pk_portal_aktif() {
	if ( ! pk_throttle( 'aktif', 8, HOUR_IN_SECONDS ) ) { return new WP_Error( 'x', 'Terlalu banyak cubaan. Sila cuba lagi kemudian.' ); }
	global $wpdb;
	$kp = pk_digits( pk_p( 'kp' ) ); $hp = pk_p( 'hp' );
	$p1 = (string) wp_unslash( $_POST['pass'] ?? '' ); $p2 = (string) wp_unslash( $_POST['pass2'] ?? '' );
	$email = sanitize_email( pk_p( 'email' ) );
	if ( 12 !== strlen( $kp ) ) { return new WP_Error( 'x', 'No. Kad Pengenalan mestilah 12 digit.' ); }
	if ( $p1 !== $p2 ) { return new WP_Error( 'x', 'Pengesahan kata laluan tidak sama.' ); }
	if ( $bad = pk_password_problem( $p1, $kp, $hp ) ) { return new WP_Error( 'x', $bad ); }
	$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . " WHERE no_kp=%s AND status NOT IN ('ditolak','meninggal') ORDER BY id DESC LIMIT 1", $kp ), ARRAY_A );
	if ( ! $a || ! pk_hp_match( $a['no_hp'], $hp ) ) {
		return new WP_Error( 'x', 'Maklumat tidak sepadan dengan rekod ahli. Pastikan No. KP dan No. Telefon sama seperti semasa pendaftaran, atau hubungi AJK.' );
	}
	if ( $a['user_id'] && get_userdata( $a['user_id'] ) ) { return new WP_Error( 'x', 'Akaun portal untuk ahli ini telah diaktifkan. Sila log masuk, atau hubungi AJK untuk set semula kata laluan.' ); }
	if ( username_exists( $kp ) ) { return new WP_Error( 'x', 'Akaun dengan No. KP ini sudah wujud. Sila hubungi AJK.' ); }
	if ( $email && email_exists( $email ) ) { $email = ''; }
	$uid = wp_insert_user( [ 'user_login' => $kp, 'user_nicename' => pk_member_slug(), 'user_pass' => $p1, 'user_email' => $email, 'display_name' => $a['nama'], 'first_name' => $a['nama'], 'role' => 'pk_ahli' ] );
	if ( is_wp_error( $uid ) ) { return new WP_Error( 'x', 'Akaun tidak dapat dicipta: ' . $uid->get_error_message() ); }
	$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => $uid, 'updated_at' => pk_now() ], [ 'id' => $a['id'] ] );
	wp_set_current_user( $uid );
	wp_set_auth_cookie( $uid, true, is_ssl() );
	pk_log( $a['id'], 'akaun_aktif', 'Akaun portal diaktifkan oleh ahli (IP ' . pk_client_ip() . ')' );
	pk_notify( 'Akaun Portal Ahli diaktifkan: ' . $a['nama'], 'Jika pengaktifan ini tidak dibuat oleh ahli sendiri, nyahaktifkan akaun di: ' . admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $a['id'] ) );
	return [ 'Akaun anda telah diaktifkan. Selamat datang!', '' ];
}

/** A logged-in user (typically AJK/admin) links their own WP account to an existing member record. */
function pk_portal_pautkan() {
	global $wpdb;
	$uid = get_current_user_id();
	if ( ! pk_throttle( 'pautkan_' . $uid, 6, HOUR_IN_SECONDS ) ) { return new WP_Error( 'x', 'Terlalu banyak cubaan. Sila cuba lagi kemudian.' ); }
	if ( pk_ahli_for_user( $uid ) ) { return new WP_Error( 'x', 'Akaun anda telah dipautkan kepada rekod ahli.' ); }
	$kp = pk_digits( pk_p( 'kp' ) ); $hp = pk_p( 'hp' );
	if ( 12 !== strlen( $kp ) ) { return new WP_Error( 'x', 'No. Kad Pengenalan mestilah 12 digit.' ); }
	$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . " WHERE no_kp=%s AND status NOT IN ('ditolak','meninggal') ORDER BY id DESC LIMIT 1", $kp ), ARRAY_A );
	if ( ! $a || ! pk_hp_match( $a['no_hp'], $hp ) ) {
		return new WP_Error( 'x', 'Maklumat tidak sepadan dengan mana-mana rekod ahli. Pastikan No. KP dan No. Telefon sama seperti dalam rekod, atau daftar sebagai ahli baharu.' );
	}
	if ( $a['user_id'] && (int) $a['user_id'] !== $uid && get_userdata( $a['user_id'] ) ) {
		return new WP_Error( 'x', 'Rekod ini sudah dipautkan kepada akaun lain. Minta AJK lain menyahpaut akaun tersebut di halaman rekod ahli dahulu.' );
	}
	$wpdb->update( pk_t( 'ahli' ), [ 'user_id' => $uid, 'updated_at' => pk_now() ], [ 'id' => $a['id'] ] );
	pk_log( $a['id'], 'akaun_pautkan', 'Rekod dipautkan kepada akaun ' . wp_get_current_user()->user_login . ' (IP ' . pk_client_ip() . ')' );
	pk_notify( 'Rekod ahli dipautkan kepada akaun pengguna: ' . $a['nama'], 'Akaun: ' . wp_get_current_user()->display_name . "\nSemak: " . admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $a['id'] ) );
	return [ 'Rekod ahli anda telah dipautkan. Anda kini boleh mengurus maklumat keahlian anda di sini.', '' ];
}

function pk_portal_profil() {
	global $wpdb;
	$a = pk_ahli_for_user();
	$new = [];
	foreach ( PK_PROFIL_FIELDS as $k ) { $new[ $k ] = 'alamat' === $k ? pk_pt( $k ) : pk_p( $k ); }
	$new['email'] = sanitize_email( $new['email'] );
	if ( strlen( pk_digits( $new['no_hp'] ) ) < 9 ) { return new WP_Error( 'x', 'Sila isi No. Telefon bimbit yang sah.' ); }
	if ( mb_strlen( $new['alamat'] ) < 8 ) { return new WP_Error( 'x', 'Sila isi alamat rumah.' ); }
	if ( $new['sektor'] && ! array_key_exists( $new['sektor'], pk_label( 'sektor' ) ) ) { $new['sektor'] = ''; }
	$diff = [];
	foreach ( $new as $k => $v ) { if ( (string) $a[ $k ] !== (string) $v ) { $diff[ $k ] = [ $a[ $k ], $v ]; } }
	if ( ! $diff ) { return [ 'Tiada perubahan.', 'profil' ]; }
	$wpdb->update( pk_t( 'ahli' ), array_merge( $new, [ 'updated_at' => pk_now() ] ), [ 'id' => $a['id'] ] );
	if ( isset( $diff['email'] ) && $new['email'] && ! email_exists( $new['email'] ) ) { wp_update_user( [ 'ID' => get_current_user_id(), 'user_email' => $new['email'] ] ); }
	pk_log( $a['id'], 'kemaskini_profil', $diff );
	return [ 'Maklumat anda telah dikemas kini.', 'profil' ];
}

function pk_portal_keluarga() {
	global $wpdb;
	$a = pk_ahli_for_user();
	$data = [];
	foreach ( PK_KELUARGA_FIELDS as $k ) { $data[ $k ] = pk_p( $k ); }
	$data['p_kp'] = pk_digits( $data['p_kp'] );
	$data['p_nama'] = mb_strtoupper( trim( $data['p_nama'] ) );
	$tg = [];
	foreach ( pk_tanggungan_from_post() as $r ) {
		if ( '' === trim( $r['nama'] ?? '' ) ) { continue; }
		$tg[] = [ 'nama' => mb_strtoupper( trim( $r['nama'] ) ), 'tarikh_lahir' => pk_date_in( $r['tarikh_lahir'] ?? '' ), 'hubungan' => $r['hubungan'] ?? '', 'status_t' => substr( $r['status_t'] ?? '', 0, 1 ), 'tinggal' => ( $r['tinggal'] ?? 'Y' ) === 'T' ? 'T' : 'Y' ];
	}
	$data['tanggungan'] = $tg;
	$row = [ 'ahli_id' => $a['id'], 'data' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), 'status' => 'menunggu', 'nota_ahli' => pk_pt( 'nota' ), 'created_at' => pk_now() ];
	if ( $pend = pk_kemaskini_pending( $a['id'] ) ) { $wpdb->update( pk_t( 'kemaskini' ), $row, [ 'id' => $pend['id'] ] ); }
	else { $wpdb->insert( pk_t( 'kemaskini' ), $row ); }
	pk_log( $a['id'], 'mohon_kemaskini', 'Permohonan kemas kini pasangan/tanggungan dihantar' );
	pk_notify( 'Permohonan kemas kini ahli: ' . $a['nama'], 'Semak: ' . admin_url( 'admin.php?page=pk-kemaskini' ) );
	return [ 'Permohonan kemas kini telah dihantar dan akan disemak oleh AJK.', 'keluarga' ];
}

function pk_portal_batal() {
	global $wpdb;
	$a = pk_ahli_for_user();
	if ( $pend = pk_kemaskini_pending( $a['id'] ) ) { $wpdb->update( pk_t( 'kemaskini' ), [ 'status' => 'batal', 'reviewed_at' => pk_now() ], [ 'id' => $pend['id'] ] ); }
	return [ 'Permohonan kemas kini dibatalkan.', 'keluarga' ];
}

function pk_portal_bayar() {
	$a   = pk_ahli_for_user();
	$pid = (int) pk_p( 'pelan_id' );
	$p   = pk_get_pelan( $pid );
	$own = $p && 'aktif' === $p['status'] && ( ( 'khairat' === $p['jenis'] && (int) $p['ref_id'] === (int) $a['id'] ) || ( 'korban' === $p['jenis'] && ( $k = pk_get_korban( $p['ref_id'] ) ) && $k['no_kp'] && $k['no_kp'] === $a['no_kp'] ) );
	if ( ! $own ) { return new WP_Error( 'x', 'Rekod tidak ditemui atau telah selesai dibayar.' ); }
	$amt = pk_money( pk_p( 'jumlah_bayar' ) );
	if ( $amt <= 0 ) { return new WP_Error( 'x', 'Sila isi jumlah bayaran.' ); }
	if ( empty( $_FILES['bukti']['tmp_name'] ) ) { return new WP_Error( 'x', 'Sila muat naik bukti pembayaran.' ); }
	$bukti = pk_store_upload( 'bukti' );
	if ( is_wp_error( $bukti ) ) { return $bukti; }
	$kaedah = array_key_exists( pk_p( 'kaedah' ), pk_label( 'kaedah' ) ) ? pk_p( 'kaedah' ) : 'qr';
	pk_bayaran_create( [ 'pelan_id' => $pid, 'jumlah' => $amt, 'kaedah' => $kaedah, 'bukti' => $bukti, 'status' => 'menunggu', 'sumber' => 'online', 'catatan' => 'Dihantar melalui Portal Ahli' ] );
	pk_log( $a['id'], 'hantar_bayaran', $p['tajuk'] . ' — ' . pk_rm( $amt ) );
	pk_notify( 'Bayaran dihantar (Portal Ahli): ' . $a['nama'], $p['tajuk'] . "\nJumlah: " . pk_rm( $amt ) . "\n\nSemak: " . admin_url( 'admin.php?page=pk-bayaran&status=menunggu' ) );
	return [ 'Bukti bayaran telah dihantar dan akan disemak oleh Bendahari / AJK.', 'bayaran' ];
}

function pk_portal_katalaluan() {
	$u = wp_get_current_user();
	$cur = (string) wp_unslash( $_POST['cur'] ?? '' ); $p1 = (string) wp_unslash( $_POST['pass'] ?? '' ); $p2 = (string) wp_unslash( $_POST['pass2'] ?? '' );
	if ( ! pk_throttle( 'katalaluan_' . $u->ID, 6, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'x', 'Terlalu banyak cubaan. Sila cuba lagi selepas 15 minit.' ); }
	if ( ! wp_check_password( $cur, $u->user_pass, $u->ID ) ) { return new WP_Error( 'x', 'Kata laluan semasa tidak tepat.' ); }
	if ( $p1 !== $p2 ) { return new WP_Error( 'x', 'Pengesahan kata laluan tidak sama.' ); }
	$ah = pk_ahli_for_user();
	if ( $bad = pk_password_problem( $p1, $ah['no_kp'] ?? $u->user_login, $ah['no_hp'] ?? '' ) ) { return new WP_Error( 'x', $bad ); }
	wp_set_password( $p1, $u->ID );
	wp_set_auth_cookie( $u->ID, true, is_ssl() );
	return [ 'Kata laluan telah ditukar.', 'akaun' ];
}

/* ================================================================= [pk_portal] */

function pk_pf( $act ) {
	return wp_nonce_field( 'pk_portal_' . $act, 'pk_nonce', false, false ) . '<input type="hidden" name="pk_portal" value="' . esc_attr( $act ) . '">';
}

add_shortcode( 'pk_portal', function () {
	pk_enqueue_public();
	$out = pk_render_errors();
	$flash = pk_flash_get();
	if ( '' !== $flash && is_user_logged_in() ) { $out .= '<div class="alert alert-success pk-alert"><i class="fa-solid fa-circle-check me-1"></i> ' . esc_html( $flash ) . '</div>'; }
	if ( ! is_user_logged_in() ) { return $out . pk_portal_guest(); }
	$a = pk_ahli_for_user();
	if ( ! $a ) {
		return $out . pk_portal_unlinked();
	}
	return $out . pk_portal_member( $a );
} );

function pk_portal_guest() {
	$tab = ( $_POST['pk_portal'] ?? '' ) === 'aktif' ? 'aktif' : 'login';
	$staff_login = wp_login_url( pk_page_url( 'portal' ) );
	ob_start(); ?>
	<div class="pk-portal-guest">
		<div class="pk-intro"><div><i class="fa-solid fa-id-card"></i></div><div><strong>Portal Ahli Kariah</strong><br>Semak maklumat keahlian khairat, tanggungan, yuran dan resit bayaran anda. Kemas kini maklumat hubungan pada bila-bila masa.</div></div>
		<ul class="nav nav-pills pk-tabs justify-content-center mb-3" role="tablist">
			<li class="nav-item"><button class="nav-link <?php echo 'login' === $tab ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pk-login" type="button"><i class="fa-solid fa-right-to-bracket"></i> Log Masuk</button></li>
			<li class="nav-item"><button class="nav-link <?php echo 'aktif' === $tab ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pk-aktif" type="button"><i class="fa-solid fa-user-check"></i> Aktifkan Akaun (kali pertama)</button></li>
		</ul>
		<div class="tab-content">
			<div class="tab-pane fade <?php echo 'login' === $tab ? 'show active' : ''; ?>" id="pk-login">
				<form method="post" class="pk-card pk-form">
					<?php echo pk_pf( 'login' ); ?>
					<div class="mb-3"><label class="form-label">No. Kad Pengenalan</label><input class="form-control" name="kp" required inputmode="numeric" autocomplete="username" value="<?php echo 'login' === $tab ? esc_attr( pk_p( 'kp' ) ) : ''; ?>"></div>
					<div class="mb-3"><label class="form-label">Kata laluan</label><input class="form-control" type="password" name="pass" required autocomplete="current-password"></div>
					<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="ingat" value="1"> <span class="form-check-label">Ingat saya</span></label>
					<button class="btn btn-mp w-100"><i class="fa-solid fa-right-to-bracket"></i> Log Masuk</button>
					<p class="small text-secondary mt-3 mb-0">Terlupa kata laluan? Sila hubungi AJK / pejabat masjid untuk set semula.</p>
					<p class="small text-secondary mt-2 mb-0"><i class="fa-solid fa-user-shield"></i> AJK / pentadbir: <a href="<?php echo esc_url( $staff_login ); ?>">log masuk melalui halaman pentadbir</a> untuk mengurus rekod ahli anda sendiri.</p>
				</form>
			</div>
			<div class="tab-pane fade <?php echo 'aktif' === $tab ? 'show active' : ''; ?>" id="pk-aktif">
				<form method="post" class="pk-card pk-form">
					<?php echo pk_pf( 'aktif' ); ?>
					<p class="small text-secondary">Untuk ahli yang telah berdaftar dengan Badan Khairat Kematian. Masukkan No. KP dan No. Telefon <strong>seperti dalam rekod pendaftaran</strong>, kemudian cipta kata laluan.</p>
					<div class="row g-3">
						<div class="col-md-6"><label class="form-label">No. Kad Pengenalan *</label><input class="form-control" name="kp" required inputmode="numeric" value="<?php echo 'aktif' === $tab ? esc_attr( pk_p( 'kp' ) ) : ''; ?>"></div>
						<div class="col-md-6"><label class="form-label">No. Telefon berdaftar *</label><input class="form-control" name="hp" type="tel" required value="<?php echo 'aktif' === $tab ? esc_attr( pk_p( 'hp' ) ) : ''; ?>"></div>
						<div class="col-md-6"><label class="form-label">Kata laluan baharu * <small class="text-secondary">(min. 8 aksara)</small></label><input class="form-control" type="password" name="pass" required minlength="8" autocomplete="new-password"></div>
						<div class="col-md-6"><label class="form-label">Sahkan kata laluan *</label><input class="form-control" type="password" name="pass2" required minlength="8" autocomplete="new-password"></div>
						<div class="col-12"><label class="form-label">E-mel <small class="text-secondary">(pilihan)</small></label><input class="form-control" type="email" name="email" value="<?php echo 'aktif' === $tab ? esc_attr( pk_p( 'email' ) ) : ''; ?>"></div>
					</div>
					<button class="btn btn-mp mt-3"><i class="fa-solid fa-user-check"></i> Aktifkan Akaun</button>
					<p class="small text-secondary mt-3 mb-0">Belum berdaftar sebagai ahli? <a href="<?php echo esc_url( pk_page_url( 'khairat' ) ); ?>">Daftar Khairat Kematian</a>.</p>
				</form>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function pk_portal_member( array $a ) {
	$yr   = pk_year();
	$paid = pk_ahli_paid_year( $a['id'], $yr );
	$tg   = pk_tanggungan_get( $a['id'] );
	$pend = pk_kemaskini_pending( $a['id'] );
	global $wpdb;
	$korban = $a['no_kp'] ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'korban' ) . " WHERE no_kp=%s AND status<>'batal' ORDER BY id DESC", $a['no_kp'] ), ARRAY_A ) : [];
	$kaw  = array_filter( array_map( 'trim', explode( "\n", (string) pk_opt( 'kawasan' ) ) ) );
	$v    = fn( $k ) => esc_attr( isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) && ( $_POST['pk_portal'] ?? '' ) === 'profil' ? wp_unslash( $_POST[ $k ] ) : $a[ $k ] );
	$lastrej = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'kemaskini' ) . " WHERE ahli_id=%d AND status IN ('lulus','ditolak') ORDER BY id DESC LIMIT 1", $a['id'] ), ARRAY_A );
	ob_start(); ?>
	<div class="pk-portal" id="pk-top">
		<div class="pk-member-head">
			<div class="pk-avatar"><?php echo esc_html( mb_substr( $a['nama'], 0, 1 ) ); ?></div>
			<div class="flex-grow-1">
				<div class="pk-mh-name"><?php echo esc_html( $a['nama'] ); ?></div>
				<div class="pk-mh-meta">No. Ahli <strong><?php echo esc_html( $a['no_ahli'] ?: 'belum diberikan' ); ?></strong> · <?php echo pk_badge( 'status_ahli', $a['status'] ); ?> · Yuran <?php echo (int) $yr; ?>: <?php echo $paid ? '<span class="pk-badge pk-b-ok">Dijelaskan</span>' : '<span class="pk-badge pk-b-warn">Belum dijelaskan</span>'; ?></div>
			</div>
			<?php if ( current_user_can( PK_CAP ) ) : ?><a class="btn btn-sm btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=pk-dashboard' ) ); ?>"><i class="fa-solid fa-gauge"></i> Papan Pemuka AJK</a><?php endif; ?>
			<a class="btn btn-sm btn-outline-light" href="<?php echo esc_url( wp_logout_url( pk_page_url( 'portal' ) ) ); ?>"><i class="fa-solid fa-right-from-bracket"></i> Log keluar</a>
		</div>

		<ul class="nav nav-tabs pk-tabs" role="tablist">
			<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil" type="button"><i class="fa-solid fa-user"></i> Profil</button></li>
			<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#keluarga" type="button"><i class="fa-solid fa-people-roof"></i> Keluarga &amp; Tanggungan<?php echo $pend ? ' <span class="pk-dot"></span>' : ''; ?></button></li>
			<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#bayaran" type="button"><i class="fa-solid fa-receipt"></i> Yuran &amp; Resit</button></li>
			<?php if ( $korban ) : ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#korban" type="button"><i class="fa-solid fa-cow"></i> Korban</button></li><?php endif; ?>
			<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#akaun" type="button"><i class="fa-solid fa-key"></i> Kata Laluan</button></li>
		</ul>

		<div class="tab-content pk-tab-body">
			<!-- PROFIL -->
			<div class="tab-pane fade show active" id="profil">
				<form method="post" class="pk-card pk-form">
					<?php echo pk_pf( 'profil' ); ?>
					<div class="pk-kv mb-3"><div><span>Nama</span><?php echo esc_html( $a['nama'] ); ?></div><div><span>No. KP</span><?php echo esc_html( pk_fmt_kp( $a['no_kp'] ) ); ?></div><div><span>Tarikh daftar</span><?php echo esc_html( pk_date( $a['tarikh_daftar'] ) ); ?></div></div>
					<p class="small text-secondary">Nama dan No. KP hanya boleh dipinda oleh AJK. Maklumat di bawah boleh dikemas kini terus.</p>
					<div class="row g-3">
						<div class="col-md-6"><label class="form-label">No. Telefon Bimbit *</label><input class="form-control" name="no_hp" type="tel" required value="<?php echo $v( 'no_hp' ); ?>"></div>
						<div class="col-md-6"><label class="form-label">E-mel</label><input class="form-control" name="email" type="email" value="<?php echo $v( 'email' ); ?>"></div>
						<div class="col-12"><label class="form-label">Alamat Rumah *</label><textarea class="form-control" name="alamat" rows="2" required><?php echo esc_textarea( $a['alamat'] ); ?></textarea></div>
						<div class="col-md-6"><label class="form-label">Kawasan / Taman</label><input class="form-control" name="kawasan" list="pk-kaw" value="<?php echo $v( 'kawasan' ); ?>"><datalist id="pk-kaw"><?php foreach ( $kaw as $k ) { echo '<option value="' . esc_attr( $k ) . '">'; } ?></datalist></div>
						<div class="col-md-6"><label class="form-label">Pekerjaan</label><input class="form-control" name="pekerjaan" value="<?php echo $v( 'pekerjaan' ); ?>"></div>
						<div class="col-md-6"><label class="form-label">Sektor</label><?php echo pk_select( 'sektor', pk_label( 'sektor' ), $a['sektor'] ); ?></div>
						<div class="col-md-6"><label class="form-label">Majikan</label><input class="form-control" name="majikan" value="<?php echo $v( 'majikan' ); ?>"></div>
						<div class="col-md-6"><label class="form-label">Tel. Pejabat</label><input class="form-control" name="tel_p" value="<?php echo $v( 'tel_p' ); ?>"></div>
						<div class="col-md-6"><label class="form-label">Tel. Rumah</label><input class="form-control" name="tel_r" value="<?php echo $v( 'tel_r' ); ?>"></div>
					</div>
					<button class="btn btn-mp mt-3"><i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan</button>
				</form>
			</div>

			<!-- KELUARGA -->
			<div class="tab-pane fade" id="keluarga">
				<div class="pk-card">
					<h3 class="pk-h"><i class="fa-solid fa-people-roof text-mp"></i> Rekod semasa</h3>
					<div class="pk-kv"><div><span>Suami / Isteri</span><?php echo esc_html( $a['p_nama'] ?: '—' ); ?></div><div><span>No. KP pasangan</span><?php echo esc_html( $a['p_kp'] ? pk_mask_kp( $a['p_kp'] ) : '—' ); ?></div><div><span>No. HP pasangan</span><?php echo esc_html( $a['p_hp'] ?: '—' ); ?></div></div>
					<div class="table-responsive"><table class="table table-sm pk-table mb-0"><thead><tr><th>#</th><th>Nama tanggungan</th><th>Hubungan</th><th>Tarikh lahir</th><th>Umur</th><th>Status</th><th>Tinggal bersama</th></tr></thead><tbody>
					<?php if ( ! $tg ) : ?><tr><td colspan="7" class="text-secondary">Tiada tanggungan direkodkan.</td></tr><?php endif; ?>
					<?php foreach ( $tg as $i => $t ) : ?><tr><td><?php echo $i + 1; ?></td><td><?php echo esc_html( $t['nama'] ); ?></td><td><?php echo esc_html( $t['hubungan'] ?: '—' ); ?></td><td><?php echo esc_html( pk_date( $t['tarikh_lahir'] ) ); ?></td><td><?php $ag = pk_age( $t['tarikh_lahir'] ); echo null === $ag ? '—' : (int) $ag; ?></td><td><?php echo esc_html( pk_label( 'status_t', $t['status_t'] ) ); ?></td><td><?php echo 'T' === $t['tinggal'] ? 'Tidak' : 'Ya'; ?></td></tr><?php endforeach; ?>
					</tbody></table></div>
				</div>
				<?php if ( $pend ) : ?>
					<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2"><div><i class="fa-solid fa-hourglass-half me-1"></i> Permohonan kemas kini anda (<?php echo esc_html( pk_date( substr( $pend['created_at'], 0, 10 ) ) ); ?>) sedang menunggu semakan AJK. Anda boleh meminda permohonan di bawah.</div>
					<form method="post" class="m-0"><?php echo pk_pf( 'batal' ); ?><button class="btn btn-sm btn-outline-secondary">Batalkan permohonan</button></form></div>
				<?php elseif ( $lastrej && 'ditolak' === $lastrej['status'] ) : ?>
					<div class="alert alert-secondary small">Permohonan terakhir (<?php echo esc_html( pk_date( substr( $lastrej['reviewed_at'] ?? $lastrej['created_at'], 0, 10 ) ) ); ?>) tidak diluluskan<?php echo $lastrej['nota_ajk'] ? ': ' . esc_html( $lastrej['nota_ajk'] ) : '.'; ?></div>
				<?php endif; ?>
				<?php
				$src = $pend ? json_decode( $pend['data'], true ) : null;
				$cur = $src ?: array_merge( array_intersect_key( $a, array_flip( PK_KELUARGA_FIELDS ) ), [ 'tanggungan' => $tg ] );
				$rows = $cur['tanggungan'] ?: [];
				if ( count( $rows ) < 2 ) { $rows = array_merge( $rows, array_fill( 0, 2 - count( $rows ), [] ) ); }
				?>
				<details class="pk-card pk-edit"<?php echo ( $_POST['pk_portal'] ?? '' ) === 'keluarga' ? ' open' : ''; ?>>
					<summary class="pk-h mb-0"><i class="fa-solid fa-pen-to-square text-mp"></i> Mohon kemas kini pasangan / tanggungan</summary>
					<form method="post" class="pk-form mt-3" data-pk-form="keluarga">
						<?php echo pk_pf( 'keluarga' ); ?>
						<p class="small text-secondary">Perubahan pasangan dan tanggungan memberi kesan kepada perlindungan khairat, oleh itu ia akan <strong>disemak dan diluluskan oleh AJK</strong> terlebih dahulu. Tanggungan terhad kepada suami/isteri, anak belum berkahwin yang tinggal bersama, serta ibu bapa / mertua yang tinggal bersama (tidak termasuk anak 18 tahun ke atas yang bekerja).</p>
						<div class="row g-3">
							<div class="col-12"><label class="form-label">Nama suami / isteri</label><input class="form-control" name="p_nama" value="<?php echo esc_attr( $cur['p_nama'] ?? '' ); ?>"></div>
							<div class="col-md-6"><label class="form-label">No. KP pasangan</label><input class="form-control" name="p_kp" inputmode="numeric" value="<?php echo esc_attr( $cur['p_kp'] ?? '' ); ?>"></div>
							<div class="col-md-6"><label class="form-label">No. HP pasangan</label><input class="form-control" name="p_hp" type="tel" value="<?php echo esc_attr( $cur['p_hp'] ?? '' ); ?>"></div>
							<div class="col-md-6"><label class="form-label">Pekerjaan pasangan</label><input class="form-control" name="p_pekerjaan" value="<?php echo esc_attr( $cur['p_pekerjaan'] ?? '' ); ?>"></div>
							<div class="col-md-6"><label class="form-label">Sektor</label><?php echo pk_select( 'p_sektor', pk_label( 'sektor' ), $cur['p_sektor'] ?? '' ); ?></div>
							<div class="col-md-6"><label class="form-label">Majikan pasangan</label><input class="form-control" name="p_majikan" value="<?php echo esc_attr( $cur['p_majikan'] ?? '' ); ?>"></div>
							<div class="col-md-6"><label class="form-label">Tel. pejabat pasangan</label><input class="form-control" name="p_tel_p" value="<?php echo esc_attr( $cur['p_tel_p'] ?? '' ); ?>"></div>
						</div>
						<h4 class="h6 mt-4">Tanggungan</h4>
						<div class="pk-rows" data-pk-rows="tg" data-pk-max="12"><?php foreach ( $rows as $i => $r ) { echo pk_tg_row( $i, $r ); } ?></div>
						<template data-pk-tpl="tg"><?php echo pk_tg_row( '__i__', [] ); ?></template>
						<button type="button" class="btn btn-sm btn-outline-success mt-2" data-pk-add="tg"><i class="fa-solid fa-plus"></i> Tambah tanggungan</button>
						<div class="mt-3"><label class="form-label">Nota kepada AJK <small class="text-secondary">(cth. sebab perubahan: kelahiran, perkahwinan, kematian)</small></label><textarea class="form-control" name="nota" rows="2"><?php echo esc_textarea( $pend['nota_ahli'] ?? '' ); ?></textarea></div>
						<button class="btn btn-mp mt-3"><i class="fa-solid fa-paper-plane"></i> Hantar untuk kelulusan AJK</button>
					</form>
				</details>
			</div>

			<!-- BAYARAN -->
			<div class="tab-pane fade" id="bayaran">
				<?php echo pk_portal_pelan_block( 'khairat', $a['id'] ); ?>
			</div>

			<?php if ( $korban ) : ?>
			<div class="tab-pane fade" id="korban">
				<?php foreach ( $korban as $k ) : ?>
					<div class="pk-card"><h3 class="pk-h"><i class="fa-solid fa-cow text-mp"></i> <?php echo esc_html( pk_label( 'jenis_korban', $k['jenis'] ) . ' ' . $k['musim'] ); ?> <?php echo pk_badge( 'status_korban', $k['status'] ); ?></h3>
						<p class="small mb-2">Peserta: <?php echo esc_html( implode( ', ', array_map( fn( $p ) => $p['nama'] . ' (' . $p['bahagian'] . ')', pk_peserta_get( $k['id'] ) ) ) ); ?></p>
						<?php echo pk_portal_pelan_block( 'korban', $k['id'], false ); ?></div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<!-- AKAUN -->
			<div class="tab-pane fade" id="akaun">
				<?php if ( pk_is_staff() ) : ?>
				<div class="pk-card pk-narrow-form"><p class="mb-2">Anda log masuk menggunakan akaun AJK/pentadbir <strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong>.</p><p class="small text-secondary mb-3">Kata laluan (dan pengesahan dua langkah, jika diaktifkan) akaun ini diurus melalui profil WordPress.</p><a class="btn btn-mp" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><i class="fa-solid fa-user-gear"></i> Buka Profil Akaun</a></div>
				<?php else : ?>
				<form method="post" class="pk-card pk-form pk-narrow-form">
					<?php echo pk_pf( 'katalaluan' ); ?>
					<p class="small text-secondary">ID log masuk anda ialah No. KP: <strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong></p>
					<div class="mb-3"><label class="form-label">Kata laluan semasa</label><input class="form-control" type="password" name="cur" required autocomplete="current-password"></div>
					<div class="mb-3"><label class="form-label">Kata laluan baharu (min. 8 aksara)</label><input class="form-control" type="password" name="pass" required minlength="8" autocomplete="new-password"></div>
					<div class="mb-3"><label class="form-label">Sahkan kata laluan baharu</label><input class="form-control" type="password" name="pass2" required minlength="8" autocomplete="new-password"></div>
					<button class="btn btn-mp"><i class="fa-solid fa-key"></i> Tukar Kata Laluan</button>
				</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function pk_portal_pelan_block( $jenis, $ref, $card = true ) {
	$pl = array_filter( pk_pelan_list( $jenis, $ref ), fn( $p ) => 'batal' !== $p['status'] );
	$h  = $card ? '<div class="pk-card"><h3 class="pk-h"><i class="fa-solid fa-receipt text-mp"></i> Yuran, bayaran &amp; resit</h3>' : '';
	if ( ! $pl ) { return $h . '<p class="text-secondary mb-0">Tiada rekod yuran.</p>' . ( $card ? '</div>' : '' ); }
	$aktif = [];
	foreach ( $pl as $p ) {
		$baki = max( 0, $p['jumlah'] - $p['dibayar'] );
		$pct  = $p['jumlah'] > 0 ? min( 100, round( $p['dibayar'] / $p['jumlah'] * 100 ) ) : 0;
		$h   .= '<div class="pk-pl"><div class="d-flex justify-content-between flex-wrap gap-2"><div><strong>' . esc_html( $p['tajuk'] ) . '</strong> ' . pk_badge( 'status_pelan', $p['status'] )
			. '<div class="small text-secondary">' . esc_html( 'ansuran' === $p['cara'] ? 'Ansuran ' . $p['bil_ansuran'] . ' × ' . pk_rm( $p['amaun_ansuran'] ) : 'Sekaligus' ) . '</div></div>'
			. '<div class="text-end small">Jumlah <b>' . pk_rm( $p['jumlah'] ) . '</b> · Dibayar <b>' . pk_rm( $p['dibayar'] ) . '</b> · Baki <b class="' . ( $baki > 0 ? 'text-danger' : '' ) . '">' . pk_rm( $baki ) . '</b></div></div>'
			. '<div class="pk-bar"><i style="width:' . $pct . '%"></i></div>';
		$bs = pk_bayaran_list( $p['id'] );
		if ( $bs ) {
			$h .= '<div class="table-responsive"><table class="table table-sm pk-table mb-2"><thead><tr><th>Tarikh</th><th class="text-end">Jumlah</th><th>Kaedah</th><th>No. Resit</th><th>Fail</th><th>Status</th></tr></thead><tbody>';
			foreach ( $bs as $b ) {
				$files = ( $b['bukti'] ? '<a href="' . esc_url( pk_fail_url( $b['bukti'] ) ) . '" target="_blank" class="me-2"><i class="fa-regular fa-image"></i> Bukti</a>' : '' )
					. ( $b['resit'] ? '<a href="' . esc_url( pk_fail_url( $b['resit'] ) ) . '" target="_blank" class="fw-semibold"><i class="fa-solid fa-file-invoice"></i> Resit rasmi</a>' : '' );
				$h .= '<tr><td>' . esc_html( pk_date( $b['tarikh'] ) ) . '</td><td class="text-end">' . pk_rm( $b['jumlah'] ) . '</td><td>' . esc_html( pk_label( 'kaedah', $b['kaedah'] ) ) . '</td><td>' . esc_html( $b['no_resit'] ?: '—' ) . '</td><td>' . ( $files ?: '—' ) . '</td><td>' . pk_badge( 'status_bayar', $b['status'] ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		}
		$h .= '</div>';
		if ( 'aktif' === $p['status'] ) { $aktif[ $p['id'] ] = [ $p['tajuk'] . ' — baki ' . pk_rm( $baki ), $p['amaun_ansuran'] > 0 ? min( $p['amaun_ansuran'], $baki ) : $baki ]; }
	}
	if ( $aktif ) {
		$first = reset( $aktif );
		$h .= '<details class="pk-bayar mt-2"><summary class="btn btn-leaf btn-sm"><i class="fa-solid fa-upload"></i> Hantar bukti bayaran / ansuran</summary>'
			. '<form method="post" enctype="multipart/form-data" class="pk-form mt-3">' . pk_pf( 'bayar' )
			. '<div class="row g-3"><div class="col-md-6"><label class="form-label">Untuk</label>' . pk_select( 'pelan_id', array_map( fn( $x ) => $x[0], $aktif ), array_key_first( $aktif ), 'required', null ) . '</div>'
			. '<div class="col-md-3"><label class="form-label">Jumlah (RM)</label><input type="number" step="0.01" min="1" class="form-control" name="jumlah_bayar" required value="' . esc_attr( number_format( (float) $first[1], 2, '.', '' ) ) . '"></div>'
			. '<div class="col-md-3"><label class="form-label">Kaedah</label>' . pk_select( 'kaedah', array_diff_key( pk_label( 'kaedah' ), [ 'tunai' => 1 ] ), 'qr', '', null ) . '</div>'
			. '<div class="col-md-8"><label class="form-label">Bukti pembayaran</label><input type="file" class="form-control" name="bukti" accept="image/*,application/pdf" required></div>'
			. '<div class="col-md-4 d-flex align-items-end"><button class="btn btn-mp w-100"><i class="fa-solid fa-paper-plane"></i> Hantar</button></div>'
			. '<div class="col-12 small text-secondary">' . esc_html( pk_opt( 'bank' ) ) . '</div></div></form></details>';
	}
	return $h . ( $card ? '</div>' : '' );
}

/** Logged in (e.g. AJK/admin) but no member record linked yet: link an existing record, or register as a new member. */
function pk_portal_unlinked() {
	$u = wp_get_current_user();
	$staff = pk_is_staff( $u );
	ob_start(); ?>
	<div class="pk-portal-guest">
		<div class="pk-intro"><div><i class="fa-solid fa-user-plus"></i></div><div><strong>Assalamualaikum, <?php echo esc_html( $u->display_name ); ?></strong><br><?php echo $staff ? 'Anda log masuk sebagai AJK/pentadbir. Jika anda juga ahli kariah, pautkan rekod keahlian anda atau daftar sebagai ahli baharu — kemudian urus maklumat anda sendiri di sini.' : 'Akaun anda belum dipautkan kepada rekod ahli.'; ?></div></div>
		<form method="post" class="pk-card pk-form">
			<?php echo pk_pf( 'pautkan' ); ?>
			<h3 class="pk-h"><i class="fa-solid fa-link text-mp"></i> Saya sudah berdaftar — pautkan rekod saya</h3>
			<p class="small text-secondary">Masukkan No. KP dan No. Telefon <strong>seperti dalam rekod keahlian</strong>.</p>
			<div class="row g-3">
				<div class="col-md-6"><label class="form-label">No. Kad Pengenalan *</label><input class="form-control" name="kp" required inputmode="numeric" value="<?php echo esc_attr( pk_p( 'kp' ) ); ?>"></div>
				<div class="col-md-6"><label class="form-label">No. Telefon berdaftar *</label><input class="form-control" name="hp" type="tel" required value="<?php echo esc_attr( pk_p( 'hp' ) ); ?>"></div>
			</div>
			<button class="btn btn-mp mt-3"><i class="fa-solid fa-link"></i> Pautkan Rekod Saya</button>
		</form>
		<div class="pk-card text-center">
			<h3 class="pk-h justify-content-center"><i class="fa-solid fa-user-plus text-mp"></i> Belum berdaftar?</h3>
			<p class="small text-secondary">Isi borang pendaftaran khairat semasa log masuk. Rekod baharu akan dipautkan terus kepada akaun anda dan disemak oleh AJK.</p>
			<a class="btn btn-leaf" href="<?php echo esc_url( pk_page_url( 'khairat' ) ); ?>"><i class="fa-solid fa-pen-to-square"></i> Daftar Sebagai Ahli</a>
		</div>
		<p class="text-center small"><?php if ( current_user_can( PK_CAP ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=pk-dashboard' ) ); ?>">Papan Pemuka AJK</a> · <?php endif; ?><a href="<?php echo esc_url( wp_logout_url( pk_page_url( 'portal' ) ) ); ?>">Log keluar</a></p>
	</div>
	<?php
	return ob_get_clean();
}
