<?php
defined( 'ABSPATH' ) || exit;

/** Activation: tables, private folder, capabilities, default settings. */
function pk_activate() {
	pk_install_schema();
	pk_private_dir();
	foreach ( [ 'administrator', 'editor' ] as $r ) {
		if ( $role = get_role( $r ) ) { $role->add_cap( PK_CAP ); }
	}
	if ( false === get_option( 'pk_settings' ) ) { add_option( 'pk_settings', pk_default_settings(), '', false ); }
	if ( ! get_option( 'pk_pages' ) ) { set_transient( 'pk_setup_notice', 1, WEEK_IN_SECONDS ); }
}

function pk_t( $n ) { global $wpdb; return $wpdb->prefix . 'pk_' . $n; }

function pk_install_schema() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$a = pk_t( 'ahli' ); $tg = pk_t( 'tanggungan' ); $k = pk_t( 'korban' ); $kp = pk_t( 'korban_peserta' ); $p = pk_t( 'pelan' ); $b = pk_t( 'bayaran' );

	dbDelta( "CREATE TABLE $a (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  no_ahli varchar(30) NOT NULL DEFAULT '',
  nama varchar(190) NOT NULL,
  no_kp varchar(20) NOT NULL DEFAULT '',
  no_hp varchar(30) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  alamat text NULL,
  kawasan varchar(120) NOT NULL DEFAULT '',
  pekerjaan varchar(120) NOT NULL DEFAULT '',
  sektor varchar(60) NOT NULL DEFAULT '',
  majikan varchar(190) NOT NULL DEFAULT '',
  tel_p varchar(30) NOT NULL DEFAULT '',
  tel_r varchar(30) NOT NULL DEFAULT '',
  p_nama varchar(190) NOT NULL DEFAULT '',
  p_kp varchar(20) NOT NULL DEFAULT '',
  p_hp varchar(30) NOT NULL DEFAULT '',
  p_pekerjaan varchar(120) NOT NULL DEFAULT '',
  p_sektor varchar(60) NOT NULL DEFAULT '',
  p_majikan varchar(190) NOT NULL DEFAULT '',
  p_tel_p varchar(30) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'menunggu',
  sumber varchar(10) NOT NULL DEFAULT 'online',
  tarikh_daftar date NULL,
  catatan text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY no_kp (no_kp),
  KEY status (status),
  KEY no_ahli (no_ahli),
  KEY user_id (user_id)
) $c;" );

	dbDelta( "CREATE TABLE $tg (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ahli_id bigint(20) unsigned NOT NULL,
  nama varchar(190) NOT NULL,
  tarikh_lahir date NULL,
  hubungan varchar(30) NOT NULL DEFAULT '',
  status_t char(1) NOT NULL DEFAULT '',
  tinggal char(1) NOT NULL DEFAULT 'Y',
  PRIMARY KEY  (id),
  KEY ahli_id (ahli_id)
) $c;" );

	dbDelta( "CREATE TABLE $k (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  musim varchar(20) NOT NULL DEFAULT '',
  jenis varchar(10) NOT NULL DEFAULT 'korban',
  nama varchar(190) NOT NULL,
  no_kp varchar(20) NOT NULL DEFAULT '',
  no_hp varchar(30) NOT NULL DEFAULT '',
  tel_r varchar(30) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  alamat text NULL,
  pilihan varchar(20) NOT NULL DEFAULT '',
  bahagian_lain varchar(120) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'menunggu',
  sumber varchar(10) NOT NULL DEFAULT 'online',
  catatan text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY musim (musim),
  KEY no_kp (no_kp)
) $c;" );

	dbDelta( "CREATE TABLE $kp (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  korban_id bigint(20) unsigned NOT NULL,
  nama varchar(190) NOT NULL,
  bahagian smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY korban_id (korban_id)
) $c;" );

	dbDelta( "CREATE TABLE $p (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  jenis varchar(10) NOT NULL,
  ref_id bigint(20) unsigned NOT NULL,
  kategori varchar(20) NOT NULL DEFAULT 'lain',
  liputan varchar(60) NOT NULL DEFAULT '',
  tajuk varchar(190) NOT NULL DEFAULT '',
  jumlah decimal(10,2) NOT NULL DEFAULT 0,
  cara varchar(10) NOT NULL DEFAULT 'sekaligus',
  bil_ansuran smallint(5) unsigned NOT NULL DEFAULT 1,
  amaun_ansuran decimal(10,2) NOT NULL DEFAULT 0,
  tarikh_mula date NULL,
  status varchar(10) NOT NULL DEFAULT 'aktif',
  catatan text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ref (jenis,ref_id),
  KEY status (status)
) $c;" );

	dbDelta( "CREATE TABLE $b (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pelan_id bigint(20) unsigned NOT NULL,
  jumlah decimal(10,2) NOT NULL DEFAULT 0,
  tarikh date NULL,
  kaedah varchar(10) NOT NULL DEFAULT 'qr',
  no_resit varchar(60) NOT NULL DEFAULT '',
  bukti varchar(255) NOT NULL DEFAULT '',
  resit varchar(255) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'menunggu',
  sumber varchar(10) NOT NULL DEFAULT 'online',
  catatan text NULL,
  direkod_oleh bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY pelan_id (pelan_id),
  KEY status (status),
  KEY tarikh (tarikh)
) $c;" );

	$km = pk_t( 'kemaskini' ); $lg = pk_t( 'log' );
	dbDelta( "CREATE TABLE $km (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ahli_id bigint(20) unsigned NOT NULL,
  data longtext NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'menunggu',
  nota_ahli text NULL,
  nota_ajk text NULL,
  created_at datetime NOT NULL,
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY ahli_id (ahli_id),
  KEY status (status)
) $c;" );

	dbDelta( "CREATE TABLE $lg (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ahli_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tindakan varchar(40) NOT NULL DEFAULT '',
  butiran text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ahli_id (ahli_id)
) $c;" );

	add_role( 'pk_ajk', 'AJK Kariah', [ 'read' => true, 'upload_files' => true, PK_CAP => true ] );
	add_role( 'pk_ahli', 'Ahli Kariah', [ 'read' => true ] );
	update_option( 'pk_db_ver', PK_DB_VER, false );
}

/* ================================================================= AHLI */

const PK_AHLI_FIELDS = [ 'user_id', 'no_ahli', 'nama', 'no_kp', 'no_hp', 'email', 'alamat', 'kawasan', 'pekerjaan', 'sektor', 'majikan', 'tel_p', 'tel_r', 'p_nama', 'p_kp', 'p_hp', 'p_pekerjaan', 'p_sektor', 'p_majikan', 'p_tel_p', 'status', 'sumber', 'tarikh_daftar', 'catatan' ];

function pk_get_ahli( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . ' WHERE id=%d', $id ), ARRAY_A );
}

function pk_save_ahli_data( array $d, $id = 0 ) {
	global $wpdb;
	$row = array_intersect_key( $d, array_flip( PK_AHLI_FIELDS ) );
	foreach ( [ 'no_kp', 'p_kp' ] as $f ) { if ( isset( $row[ $f ] ) ) { $row[ $f ] = pk_digits( $row[ $f ] ); } }
	if ( isset( $row['nama'] ) ) { $row['nama'] = mb_strtoupper( trim( $row['nama'] ) ); }
	if ( isset( $row['p_nama'] ) ) { $row['p_nama'] = mb_strtoupper( trim( $row['p_nama'] ) ); }
	$row['updated_at'] = pk_now();
	if ( $id ) {
		$wpdb->update( pk_t( 'ahli' ), $row, [ 'id' => $id ] );
	} else {
		$row['created_at'] = pk_now();
		$row['created_by'] = get_current_user_id();
		$wpdb->insert( pk_t( 'ahli' ), $row );
		$id = (int) $wpdb->insert_id;
	}
	// assign membership number when a member becomes active
	$a = pk_get_ahli( $id );
	if ( $a && 'aktif' === $a['status'] && '' === $a['no_ahli'] ) {
		$wpdb->update( pk_t( 'ahli' ), [ 'no_ahli' => pk_next_no_ahli(), 'tarikh_daftar' => $a['tarikh_daftar'] ?: pk_today() ], [ 'id' => $id ] );
	}
	return $id;
}

function pk_next_no_ahli() {
	global $wpdb;
	$pre = pk_opt( 'prefix_ahli' ) ?: 'KK';
	$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(CAST(SUBSTRING(no_ahli,%d) AS UNSIGNED)) FROM ' . pk_t( 'ahli' ) . ' WHERE no_ahli LIKE %s', strlen( $pre ) + 2, $wpdb->esc_like( $pre . '-' ) . '%' ) );
	return sprintf( '%s-%04d', $pre, $max + 1 );
}

function pk_tanggungan_get( $ahli_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'tanggungan' ) . ' WHERE ahli_id=%d ORDER BY id', $ahli_id ), ARRAY_A );
}

function pk_tanggungan_replace( $ahli_id, array $rows ) {
	global $wpdb;
	$wpdb->delete( pk_t( 'tanggungan' ), [ 'ahli_id' => $ahli_id ] );
	foreach ( $rows as $r ) {
		$nama = mb_strtoupper( trim( sanitize_text_field( $r['nama'] ?? '' ) ) );
		if ( '' === $nama ) { continue; }
		$wpdb->insert( pk_t( 'tanggungan' ), [
			'ahli_id'      => $ahli_id,
			'nama'         => $nama,
			'tarikh_lahir' => pk_date_in( $r['tarikh_lahir'] ?? '' ),
			'hubungan'     => sanitize_text_field( $r['hubungan'] ?? '' ),
			'status_t'     => substr( sanitize_text_field( $r['status_t'] ?? '' ), 0, 1 ),
			'tinggal'      => ( ( $r['tinggal'] ?? 'Y' ) === 'T' ) ? 'T' : 'Y',
		] );
	}
}

/** rows from $_POST['tg'] array */
function pk_tanggungan_from_post() {
	$out = [];
	$tg  = isset( $_POST['tg'] ) && is_array( $_POST['tg'] ) ? wp_unslash( $_POST['tg'] ) : [];
	foreach ( array_slice( $tg, 0, 15 ) as $r ) { if ( is_array( $r ) ) { $out[] = array_map( 'pk_clean', array_slice( $r, 0, 12, true ) ); } }
	return $out;
}

function pk_find_ahli_by_kp( $kp ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . ' WHERE no_kp=%s ORDER BY id DESC LIMIT 1', pk_digits( $kp ) ), ARRAY_A );
}

function pk_delete_ahli( $id ) {
	global $wpdb;
	foreach ( pk_pelan_list( 'khairat', $id ) as $p ) { pk_delete_pelan( $p['id'] ); }
	$wpdb->delete( pk_t( 'tanggungan' ), [ 'ahli_id' => $id ] );
	$wpdb->delete( pk_t( 'kemaskini' ), [ 'ahli_id' => $id ] );
	$wpdb->delete( pk_t( 'log' ), [ 'ahli_id' => $id ] );
	$wpdb->delete( pk_t( 'ahli' ), [ 'id' => $id ] );
}

/* ================================================================= KORBAN */

const PK_KORBAN_FIELDS = [ 'musim', 'jenis', 'nama', 'no_kp', 'no_hp', 'tel_r', 'email', 'alamat', 'pilihan', 'bahagian_lain', 'status', 'sumber', 'catatan' ];

function pk_get_korban( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'korban' ) . ' WHERE id=%d', $id ), ARRAY_A );
}

function pk_save_korban_data( array $d, $id = 0 ) {
	global $wpdb;
	$row = array_intersect_key( $d, array_flip( PK_KORBAN_FIELDS ) );
	if ( isset( $row['no_kp'] ) ) { $row['no_kp'] = pk_digits( $row['no_kp'] ); }
	if ( isset( $row['nama'] ) ) { $row['nama'] = mb_strtoupper( trim( $row['nama'] ) ); }
	$row['updated_at'] = pk_now();
	if ( $id ) {
		$wpdb->update( pk_t( 'korban' ), $row, [ 'id' => $id ] );
		return $id;
	}
	$row['created_at'] = pk_now();
	$row['created_by'] = get_current_user_id();
	$wpdb->insert( pk_t( 'korban' ), $row );
	return (int) $wpdb->insert_id;
}

function pk_peserta_get( $korban_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'korban_peserta' ) . ' WHERE korban_id=%d ORDER BY id', $korban_id ), ARRAY_A );
}

function pk_peserta_replace( $korban_id, array $rows ) {
	global $wpdb;
	$wpdb->delete( pk_t( 'korban_peserta' ), [ 'korban_id' => $korban_id ] );
	$total = 0;
	foreach ( array_slice( $rows, 0, 21 ) as $r ) {
		$nama = mb_strtoupper( trim( sanitize_text_field( $r['nama'] ?? '' ) ) );
		if ( '' === $nama ) { continue; }
		$b = max( 1, min( 7, (int) ( $r['bahagian'] ?? 1 ) ) );
		$total += $b;
		$wpdb->insert( pk_t( 'korban_peserta' ), [ 'korban_id' => $korban_id, 'nama' => $nama, 'bahagian' => $b ] );
	}
	return $total;
}

function pk_peserta_from_post() {
	$out = [];
	$ps  = isset( $_POST['ps'] ) && is_array( $_POST['ps'] ) ? wp_unslash( $_POST['ps'] ) : [];
	foreach ( array_slice( $ps, 0, 28 ) as $r ) { if ( is_array( $r ) ) { $out[] = array_map( 'pk_clean', array_slice( $r, 0, 6, true ) ); } }
	return $out;
}

function pk_korban_bahagian( $korban_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bahagian),0) FROM ' . pk_t( 'korban_peserta' ) . ' WHERE korban_id=%d', $korban_id ) );
}

function pk_delete_korban( $id ) {
	global $wpdb;
	foreach ( pk_pelan_list( 'korban', $id ) as $p ) { pk_delete_pelan( $p['id'] ); }
	$wpdb->delete( pk_t( 'korban_peserta' ), [ 'korban_id' => $id ] );
	$wpdb->delete( pk_t( 'korban' ), [ 'id' => $id ] );
}

/* ================================================================= PELAN (obligation) & BAYARAN */

function pk_pelan_list( $jenis, $ref_id ) {
	global $wpdb;
	$b = pk_t( 'bayaran' );
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT p.*, COALESCE((SELECT SUM(jumlah) FROM $b WHERE pelan_id=p.id AND status='sah'),0) AS dibayar,
		        COALESCE((SELECT SUM(jumlah) FROM $b WHERE pelan_id=p.id AND status='menunggu'),0) AS menunggu,
		        (SELECT COUNT(*) FROM $b WHERE pelan_id=p.id AND status='sah') AS bil_bayar
		 FROM " . pk_t( 'pelan' ) . ' p WHERE p.jenis=%s AND p.ref_id=%d ORDER BY p.id', $jenis, $ref_id ), ARRAY_A );
}

function pk_get_pelan( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'pelan' ) . ' WHERE id=%d', $id ), ARRAY_A );
}

function pk_pelan_create( array $a ) {
	global $wpdb;
	$jumlah = pk_money( $a['jumlah'] ?? 0 );
	$mod    = ( $a['cara'] ?? '' ) === 'ansuran' ? 'ansuran' : 'sekaligus';
	$bil    = 'ansuran' === $mod ? max( 2, min( 36, (int) ( $a['bil_ansuran'] ?? 2 ) ) ) : 1;
	$amaun  = isset( $a['amaun_ansuran'] ) && (float) $a['amaun_ansuran'] > 0 ? pk_money( $a['amaun_ansuran'] ) : round( $jumlah / $bil, 2 );
	$liputan = $a['liputan'] ?? '';
	if ( is_array( $liputan ) ) { $liputan = implode( ',', array_filter( array_map( 'intval', $liputan ) ) ); }
	$wpdb->insert( pk_t( 'pelan' ), [
		'jenis'         => $a['jenis'],
		'ref_id'        => (int) $a['ref_id'],
		'kategori'      => $a['kategori'] ?? 'lain',
		'liputan'       => $liputan,
		'tajuk'         => sanitize_text_field( $a['tajuk'] ?? '' ),
		'jumlah'        => $jumlah,
		'cara'          => $mod,
		'bil_ansuran'   => $bil,
		'amaun_ansuran' => $amaun,
		'tarikh_mula'   => $a['tarikh_mula'] ?? pk_today(),
		'status'        => 'aktif',
		'catatan'       => $a['catatan'] ?? '',
		'created_at'    => pk_now(),
	] );
	return (int) $wpdb->insert_id;
}

function pk_pelan_refresh( $pelan_id ) {
	global $wpdb;
	$p = pk_get_pelan( $pelan_id );
	if ( ! $p || 'batal' === $p['status'] ) { return; }
	$paid = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(jumlah),0) FROM ' . pk_t( 'bayaran' ) . " WHERE pelan_id=%d AND status='sah'", $pelan_id ) );
	$st   = ( $paid + 0.001 >= (float) $p['jumlah'] && (float) $p['jumlah'] > 0 ) ? 'selesai' : 'aktif';
	if ( $st !== $p['status'] ) { $wpdb->update( pk_t( 'pelan' ), [ 'status' => $st ], [ 'id' => $pelan_id ] ); }
}

function pk_delete_pelan( $id ) {
	global $wpdb;
	$wpdb->delete( pk_t( 'bayaran' ), [ 'pelan_id' => $id ] );
	$wpdb->delete( pk_t( 'pelan' ), [ 'id' => $id ] );
}

function pk_bayaran_create( array $a ) {
	global $wpdb;
	$wpdb->insert( pk_t( 'bayaran' ), [
		'pelan_id'     => (int) $a['pelan_id'],
		'jumlah'       => pk_money( $a['jumlah'] ?? 0 ),
		'tarikh'       => $a['tarikh'] ?? pk_today(),
		'kaedah'       => array_key_exists( $a['kaedah'] ?? '', pk_label( 'kaedah' ) ) ? $a['kaedah'] : 'qr',
		'no_resit'     => sanitize_text_field( $a['no_resit'] ?? '' ),
		'bukti'        => $a['bukti'] ?? '',
		'resit'        => $a['resit'] ?? '',
		'status'       => $a['status'] ?? 'menunggu',
		'sumber'       => $a['sumber'] ?? 'online',
		'catatan'      => $a['catatan'] ?? '',
		'direkod_oleh' => get_current_user_id(),
		'created_at'   => pk_now(),
	] );
	$id = (int) $wpdb->insert_id;
	pk_pelan_refresh( (int) $a['pelan_id'] );
	return $id;
}

function pk_bayaran_list( $pelan_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'bayaran' ) . ' WHERE pelan_id=%d ORDER BY tarikh, id', $pelan_id ), ARRAY_A );
}

/** Does a khairat member have a fully paid plan covering $year? */
function pk_ahli_paid_year( $ahli_id, $year ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . pk_t( 'pelan' ) . " WHERE jenis='khairat' AND ref_id=%d AND status='selesai' AND FIND_IN_SET(%d, liputan) LIMIT 1", $ahli_id, $year ) );
}

/** Owner record (name, link) for a plan */
function pk_pelan_owner( $p ) {
	if ( 'khairat' === $p['jenis'] ) {
		$a = pk_get_ahli( $p['ref_id'] );
		return [ $a ? $a['nama'] : '(dipadam)', admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $p['ref_id'] ), $a ];
	}
	$k = pk_get_korban( $p['ref_id'] );
	return [ $k ? $k['nama'] : '(dipadam)', admin_url( 'admin.php?page=pk-korban&action=edit&id=' . $p['ref_id'] ), $k ];
}

/* ================================================================= audit log & member link */

function pk_log( $ahli_id, $tindakan, $butiran = '' ) {
	global $wpdb;
	$wpdb->insert( pk_t( 'log' ), [ 'ahli_id' => (int) $ahli_id, 'user_id' => get_current_user_id(), 'tindakan' => $tindakan, 'butiran' => is_array( $butiran ) ? wp_json_encode( $butiran, JSON_UNESCAPED_UNICODE ) : (string) $butiran, 'created_at' => pk_now() ] );
}

function pk_log_list( $ahli_id, $limit = 25 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'log' ) . ' WHERE ahli_id=%d ORDER BY id DESC LIMIT %d', $ahli_id, $limit ), ARRAY_A );
}

/** Member record linked to a WP user */
function pk_ahli_for_user( $user_id = 0 ) {
	global $wpdb;
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) { return null; }
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 1', $user_id ), ARRAY_A );
}

function pk_kemaskini_pending( $ahli_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'kemaskini' ) . " WHERE ahli_id=%d AND status='menunggu' ORDER BY id DESC LIMIT 1", $ahli_id ), ARRAY_A );
}

/** Is this private file attached to a payment belonging to the given member (khairat record or korban with same KP)? */
function pk_file_belongs_to_ahli( $rel, array $a ) {
	global $wpdb;
	$b = pk_t( 'bayaran' ); $p = pk_t( 'pelan' ); $k = pk_t( 'korban' );
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM $b bb JOIN $p pp ON pp.id=bb.pelan_id LEFT JOIN $k kk ON pp.jenis='korban' AND kk.id=pp.ref_id
		 WHERE (bb.bukti=%s OR bb.resit=%s) AND ((pp.jenis='khairat' AND pp.ref_id=%d) OR (pp.jenis='korban' AND kk.no_kp<>'' AND kk.no_kp=%s)) LIMIT 1",
		$rel, $rel, $a['id'], $a['no_kp'] ) );
}

/* ================================================================= staff who are also kariah members */

/** AJK / administrators / editors (log in via wp-login — where 2FA plugins apply — not via the member form). */
function pk_is_staff( $user = null ) {
	$user = $user ?: wp_get_current_user();
	return $user && $user->exists() && ( user_can( $user, PK_CAP ) || user_can( $user, 'edit_posts' ) );
}

/** Does this plan belong to the logged-in user's own member record (khairat, or korban under the same No. KP)? */
function pk_is_own_pelan( $p, $user_id = 0 ) {
	$me = pk_ahli_for_user( $user_id );
	if ( ! $me || ! $p ) { return false; }
	if ( 'khairat' === $p['jenis'] ) { return (int) $p['ref_id'] === (int) $me['id']; }
	$k = pk_get_korban( $p['ref_id'] );
	return $k && $me['no_kp'] && $k['no_kp'] === $me['no_kp'];
}

/** Segregation of duties: AJK may not approve their own payments / family changes (administrators may; it is logged). */
function pk_self_review_blocked( $own ) { return $own && ! current_user_can( 'manage_options' ); }

function pk_my_ahli_id() { $me = pk_ahli_for_user(); return $me ? (int) $me['id'] : 0; }
