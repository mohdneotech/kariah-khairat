<?php
/**
 * Makluman Takziah — AJK records a death, the plugin generates a high-resolution poster
 * (assets/takziah template) and shows it on the front page until the display end date.
 * Front page: [pk_takziah] shortcode, the 'perepat_front_after_prayer' theme hook, or automatically at the top of the
 * front page content (Tetapan → Makluman takziah).
 */
defined( 'ABSPATH' ) || exit;

/* ================================================================= data */

function pk_tz_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'takziah' ) . ' WHERE id=%d', $id ), ARRAY_A );
}

/** Announcements currently shown on the front page. */
function pk_tz_aktif( $limit = 6 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'takziah' ) . " WHERE status='aktif' AND imej<>'' AND (papar_hingga IS NULL OR papar_hingga >= %s) ORDER BY tarikh_meninggal DESC, id DESC LIMIT %d", pk_today(), $limit ), ARRAY_A );
}

/** Public folder for generated posters (they are published on purpose). */
function pk_tz_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'takziah';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
	if ( ! file_exists( "$dir/index.php" ) ) { @file_put_contents( "$dir/index.php", "<?php // silence\n" ); }
	return $dir;
}
function pk_tz_url( $file ) { $up = wp_upload_dir(); return $file ? trailingslashit( $up['baseurl'] ) . 'takziah/' . rawurlencode( $file ) : ''; }
function pk_tz_web( $file ) { return $file ? preg_replace( '/\.jpg$/', '-web.jpg', $file ) : ''; }

function pk_tz_delete_files( $t ) {
	foreach ( [ $t['imej'], pk_tz_web( $t['imej'] ) ] as $f ) {
		if ( $f && preg_match( '/^takziah-[A-Za-z0-9\-]+\.jpg$/', $f ) && is_file( pk_tz_dir() . '/' . $f ) ) { @unlink( pk_tz_dir() . '/' . $f ); }
	}
}

/** "ABDUL RAHMAN BIN ISMAIL" → "Abdul Rahman Bin Ismail" */
function pk_tz_nama( $n ) {
	$n = mb_convert_case( mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $n ) ) ), MB_CASE_TITLE );
	return preg_replace_callback( '#\b(A/L|A/P)\b#iu', fn( $m ) => mb_strtoupper( $m[0] ), $n );
}

/** (Re)generate the poster for an announcement. @return true|WP_Error */
function pk_tz_generate( $id ) {
	global $wpdb;
	$t = pk_tz_get( $id );
	if ( ! $t ) { return new WP_Error( 'tz', 'Rekod tidak ditemui.' ); }
	$photo = $t['gambar'] ? pk_tz_load_photo( pk_private_path( $t['gambar'] ) ) : null;
	$im    = pk_tz_render( [ 'nama' => $t['nama'], 'tarikh_lahir' => $t['tarikh_lahir'], 'tarikh_meninggal' => $t['tarikh_meninggal'], 'photo' => $photo ], [ 'doa' => pk_opt( 'takziah_doa' ), 'kaki' => pk_opt( 'takziah_kaki' ) ] );
	if ( $photo ) { imagedestroy( $photo ); }
	if ( is_wp_error( $im ) ) { return $im; }
	$file = 'takziah-' . (int) $id . '-' . strtolower( wp_generate_password( 8, false ) ) . '.jpg';
	$dir  = pk_tz_dir();
	imageinterlace( $im, true );
	$ok = imagejpeg( $im, "$dir/$file", 90 );
	$web = imagescale( $im, 750, 1125, IMG_BICUBIC );
	if ( $web ) { imageinterlace( $web, true ); imagejpeg( $web, $dir . '/' . pk_tz_web( $file ), 84 ); imagedestroy( $web ); }
	imagedestroy( $im );
	if ( ! $ok ) { return new WP_Error( 'tz', 'Imej tidak dapat disimpan ke folder uploads/takziah.' ); }
	pk_tz_delete_files( $t );
	$wpdb->update( pk_t( 'takziah' ), [ 'imej' => $file ], [ 'id' => $id ] );
	return true;
}

/* ================================================================= admin screens */

function pk_page_takziah() {
	$act = pk_gs( 'action' );
	if ( 'baru' === $act || 'edit' === $act ) { pk_tz_edit_screen( 'edit' === $act ? (int) pk_gs( 'id' ) : 0 ); return; }
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . pk_t( 'takziah' ) . ' ORDER BY tarikh_meninggal DESC, id DESC LIMIT 200', ARRAY_A );
	pk_admin_header( 'Makluman Takziah', ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=pk-takziah&action=baru' ) ) . '">+ Makluman Baharu</a>' );
	echo '<p class="description">Poster takziah dijana secara automatik dan dipaparkan di laman utama sehingga tarikh tamat paparan. Untuk ahli khairat, anda juga boleh mula dari halaman rekod ahli (butang <b>Makluman Takziah</b>).</p>';
	echo '<table class="widefat striped"><thead><tr><th style="width:70px">Poster</th><th>Nama</th><th>Tarikh meninggal</th><th>Papar hingga</th><th>Status</th><th></th></tr></thead><tbody>';
	if ( ! $rows ) { echo '<tr><td colspan="6">Tiada makluman.</td></tr>'; }
	foreach ( $rows as $t ) {
		$live = 'aktif' === $t['status'] && ( ! $t['papar_hingga'] || $t['papar_hingga'] >= pk_today() );
		$st   = 'sembunyi' === $t['status'] ? '<span class="pk-badge pk-b-mute">Disembunyikan</span>' : ( $live ? '<span class="pk-badge pk-b-ok">Dipaparkan</span>' : '<span class="pk-badge pk-b-mute">Tamat</span>' );
		$edit = admin_url( 'admin.php?page=pk-takziah&action=edit&id=' . $t['id'] );
		echo '<tr><td>' . ( $t['imej'] ? '<a href="' . esc_url( $edit ) . '"><img class="pk-tz-thumb" src="' . esc_url( pk_tz_url( pk_tz_web( $t['imej'] ) ) ) . '" alt=""></a>' : '—' ) . '</td>'
			. '<td><a href="' . esc_url( $edit ) . '"><b>' . esc_html( $t['nama'] ) . '</b></a>' . ( $t['ahli_id'] ? '<div class="description"><a href="' . esc_url( admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $t['ahli_id'] ) ) . '">Rekod ahli</a></div>' : '' ) . '</td>'
			. '<td>' . esc_html( pk_date( $t['tarikh_meninggal'] ) ) . '</td><td>' . esc_html( pk_date( $t['papar_hingga'] ) ) . '</td><td>' . $st . '</td><td class="pk-actions">'
			. ( $t['imej'] ? '<a class="button button-small" href="' . esc_url( pk_tz_url( $t['imej'] ) ) . '" download>Muat turun</a> ' : '' )
			. pk_post_btn( 'pk_tz_status', [ 'id' => $t['id'], 'status' => 'sembunyi' === $t['status'] ? 'aktif' : 'sembunyi' ], 'sembunyi' === $t['status'] ? 'Papar' : 'Sembunyi' ) . ' '
			. pk_post_btn( 'pk_tz_del', [ 'id' => $t['id'] ], 'Padam', 'pk-link-del', 'Padam makluman ini berserta posternya?' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

function pk_tz_edit_screen( $id ) {
	$t = $id ? pk_tz_get( $id ) : null;
	if ( $id && ! $t ) { echo '<div class="wrap"><p>Rekod tidak ditemui.</p></div>'; return; }
	$ahli = null;
	if ( $t ) {
		$ahli = $t['ahli_id'] ? pk_get_ahli( $t['ahli_id'] ) : null;
	} else {
		$ahli = ( $aid = (int) pk_gs( 'ahli' ) ) ? pk_get_ahli( $aid ) : null;
		[ $dob ] = $ahli ? pk_kp_info( $ahli['no_kp'] ) : [ null ];
		$t = [ 'id' => 0, 'ahli_id' => $ahli['id'] ?? 0, 'nama' => $ahli ? pk_tz_nama( $ahli['nama'] ) : '', 'tarikh_lahir' => $dob, 'tarikh_meninggal' => pk_today(), 'info' => '', 'gambar' => '', 'imej' => '', 'papar_hingga' => '', 'status' => 'aktif' ];
	}
	pk_admin_header( $id ? 'Takziah — ' . $t['nama'] : 'Makluman Takziah Baharu', ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=pk-takziah' ) ) . '">← Senarai</a>' );
	$hari = (int) pk_opt( 'takziah_hari' ) ?: 7;
	?>
	<div class="pk-tz-grid">
	<form class="pk-box" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pk_tz_save' ); ?><input type="hidden" name="action" value="pk_tz_save"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<input type="hidden" name="ahli_id" value="<?php echo (int) ( $ahli['id'] ?? 0 ); ?>">
		<h2>Maklumat Allahyarham / Allahyarhamah</h2>
		<?php if ( $ahli ) : ?>
			<p>Dipautkan kepada rekod ahli: <a href="<?php echo esc_url( admin_url( 'admin.php?page=pk-ahli&action=edit&id=' . $ahli['id'] ) ); ?>"><b><?php echo esc_html( $ahli['nama'] ); ?></b></a> (<?php echo esc_html( $ahli['no_ahli'] ?: 'tiada No. Ahli' ); ?>) — <?php echo pk_badge( 'status_ahli', $ahli['status'] ); ?>
			<label style="margin-left:1em"><input type="checkbox" name="putus" value="1"> Nyahpaut</label></p>
			<?php if ( 'meninggal' !== $ahli['status'] ) : ?><p><label><input type="checkbox" name="tanda_meninggal" value="1" checked> Tukar status ahli kepada <b>Meninggal Dunia</b></label></p><?php endif; ?>
		<?php else : ?>
			<p><label>Pautkan kepada ahli khairat (pilihan) — No. KP atau No. Ahli<br><input type="text" name="cari_ahli" class="regular-text" placeholder="cth. 480312101234 atau KK-0012"></label><br><span class="description">Boleh dibiarkan kosong untuk tanggungan atau bukan ahli.</span></p>
		<?php endif; ?>
		<div class="pk-fgrid">
			<label class="pk-span2">Nama (seperti dipaparkan pada poster) *<input type="text" name="nama" required maxlength="120" value="<?php echo esc_attr( $t['nama'] ); ?>"></label>
			<label>Tarikh lahir <small>(pilihan)</small><input type="date" name="tarikh_lahir" value="<?php echo esc_attr( $t['tarikh_lahir'] ); ?>"></label>
			<label>Tarikh meninggal *<input type="date" name="tarikh_meninggal" required value="<?php echo esc_attr( $t['tarikh_meninggal'] ); ?>"></label>
			<label class="pk-span2">Maklumat jenazah (dipaparkan di laman utama, bukan pada poster)<textarea name="info" rows="3" placeholder="<?php echo esc_attr( 'cth. Jenazah akan disolatkan di ' . pk_opt( 'nama_masjid' ) . ' selepas solat Zohor dan dikebumikan di tanah perkuburan Islam berhampiran.' ); ?>"><?php echo esc_textarea( $t['info'] ); ?></textarea></label>
		</div>
		<h2>Gambar</h2>
		<div class="pk-tz-opts">
			<?php $def = $t['gambar'] ? 'kekal' : ( ! empty( $ahli['gambar'] ) ? 'ahli' : 'tiada' ); ?>
			<?php if ( $t['gambar'] ) : ?><label><input type="radio" name="sumber_gambar" value="kekal" <?php checked( $def, 'kekal' ); ?>> Kekalkan gambar semasa</label><?php endif; ?>
			<?php if ( ! empty( $ahli['gambar'] ) ) : ?><label><input type="radio" name="sumber_gambar" value="ahli" <?php checked( $def, 'ahli' ); ?>> Guna gambar profil ahli <?php echo pk_avatar_html( $ahli, 'pk-avatar' ); ?></label><?php endif; ?>
			<label><input type="radio" name="sumber_gambar" value="baru"> Muat naik gambar baharu: <input type="file" name="gambar" accept="image/jpeg,image/png,image/webp"></label>
			<label><input type="radio" name="sumber_gambar" value="tiada" <?php checked( $def, 'tiada' ); ?>> Tanpa gambar</label>
			<p class="description">Gambar akan dipaparkan hitam putih dalam bingkai bujur. Pastikan waris bersetuju gambar disiarkan.</p>
		</div>
		<h2>Paparan</h2>
		<div class="pk-fgrid">
			<label>Papar di laman utama hingga<input type="date" name="papar_hingga" value="<?php echo esc_attr( $t['papar_hingga'] ); ?>"><small>Kosong = <?php echo (int) $hari; ?> hari selepas tarikh meninggal</small></label>
			<label>Status<?php echo pk_select( 'status', [ 'aktif' => 'Papar di laman utama', 'sembunyi' => 'Sembunyikan' ], $t['status'], '', null ); ?></label>
		</div>
		<p><button class="button button-primary button-large"><?php echo $id ? 'Simpan &amp; Jana Semula Poster' : 'Simpan &amp; Jana Poster'; ?></button></p>
	</form>
	<div class="pk-box pk-tz-preview">
		<h2>Poster</h2>
		<?php if ( $t['imej'] ) : ?>
			<a href="<?php echo esc_url( pk_tz_url( $t['imej'] ) ); ?>" target="_blank"><img src="<?php echo esc_url( pk_tz_url( pk_tz_web( $t['imej'] ) ) ); ?>" alt="Poster takziah"></a>
			<p><a class="button button-primary" href="<?php echo esc_url( pk_tz_url( $t['imej'] ) ); ?>" download="<?php echo esc_attr( 'Takziah-' . sanitize_file_name( $t['nama'] ) . '.jpg' ); ?>">Muat turun (1500 × 2250)</a> <a class="button" href="<?php echo esc_url( home_url( '/#takziah' ) ); ?>" target="_blank">Lihat di laman utama</a></p>
		<?php else : ?>
			<p class="description">Poster akan dijana selepas anda menekan Simpan.</p>
		<?php endif; ?>
	</div>
	</div></div>
	<?php
}

/* ================================================================= admin actions */

add_action( 'admin_post_pk_tz_save', function () {
	pk_guard( 'pk_tz_save' );
	global $wpdb;
	$id   = (int) pk_p( 'id' );
	$old  = $id ? pk_tz_get( $id ) : null;
	if ( $id && ! $old ) { pk_back( 'Rekod tidak ditemui.' ); }
	$nama = trim( pk_p( 'nama', '', 120 ) );
	$mati = pk_date_in( pk_p( 'tarikh_meninggal' ) );
	if ( '' === $nama || ! $mati ) { pk_back( 'Sila isi nama dan tarikh meninggal.' ); }
	$lahir = pk_date_in( pk_p( 'tarikh_lahir' ) );
	if ( $lahir && $lahir > $mati ) { pk_back( 'Tarikh lahir tidak boleh selepas tarikh meninggal.' ); }

	// member link
	$ahli_id = (int) pk_p( 'ahli_id' );
	if ( pk_p( 'putus' ) ) { $ahli_id = 0; }
	if ( ! $ahli_id && ( $q = pk_p( 'cari_ahli' ) ) ) {
		$found = 12 === strlen( pk_digits( $q ) ) ? pk_find_ahli_by_kp( $q ) : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'ahli' ) . ' WHERE no_ahli=%s LIMIT 1', strtoupper( $q ) ), ARRAY_A );
		if ( ! $found ) { pk_back( 'Ahli dengan No. KP / No. Ahli tersebut tidak ditemui. Kosongkan medan itu jika bukan ahli.' ); }
		$ahli_id = (int) $found['id'];
	}
	$ahli = $ahli_id ? pk_get_ahli( $ahli_id ) : null;
	if ( $ahli_id && ! $ahli ) { $ahli_id = 0; }

	// photo
	$gambar = $old['gambar'] ?? '';
	$src    = pk_p( 'sumber_gambar', $gambar ? 'kekal' : 'tiada' );
	if ( 'baru' === $src ) {
		$r = pk_store_foto( 'gambar', 1600, false );
		if ( null === $r ) { pk_back( 'Sila pilih fail gambar untuk dimuat naik.' ); }
		if ( is_wp_error( $r ) ) { pk_back( $r->get_error_message() ); }
		$new_g = $r;
	} elseif ( 'ahli' === $src && ! empty( $ahli['gambar'] ) ) {
		$r = pk_foto_copy( $ahli['gambar'] );
		$new_g = is_wp_error( $r ) ? $gambar : $r;
	} elseif ( 'tiada' === $src ) {
		$new_g = '';
	} else {
		$new_g = $gambar;
	}
	if ( $gambar && $new_g !== $gambar ) { pk_private_delete( $gambar ); }

	$hari  = (int) pk_opt( 'takziah_hari' ) ?: 7;
	$papar = pk_date_in( pk_p( 'papar_hingga' ) ) ?: gmdate( 'Y-m-d', strtotime( $mati . " +$hari days" ) );
	$row   = [
		'ahli_id'          => $ahli_id,
		'nama'             => $nama,
		'tarikh_lahir'     => $lahir,
		'tarikh_meninggal' => $mati,
		'info'             => pk_pt( 'info', 1500 ),
		'gambar'           => $new_g,
		'papar_hingga'     => $papar,
		'status'           => 'sembunyi' === pk_p( 'status' ) ? 'sembunyi' : 'aktif',
		'updated_at'       => pk_now(),
	];
	if ( $id ) {
		$wpdb->update( pk_t( 'takziah' ), $row, [ 'id' => $id ] );
	} else {
		$row += [ 'created_at' => pk_now(), 'created_by' => get_current_user_id(), 'imej' => '' ];
		$wpdb->insert( pk_t( 'takziah' ), $row );
		$id = (int) $wpdb->insert_id;
	}

	$msg = 'Makluman takziah disimpan dan poster dijana.';
	if ( $ahli ) {
		if ( pk_p( 'tanda_meninggal' ) && 'meninggal' !== $ahli['status'] ) {
			$wpdb->update( pk_t( 'ahli' ), [ 'status' => 'meninggal', 'updated_at' => pk_now() ], [ 'id' => $ahli['id'] ] );
			pk_log( $ahli['id'], 'status_meninggal', 'Status ditukar kepada Meninggal Dunia (tarikh ' . pk_date( $mati ) . ') melalui Makluman Takziah' );
			$msg .= ' Status ahli dikemas kini kepada Meninggal Dunia.';
		}
		if ( ! $old || (int) $old['ahli_id'] !== (int) $ahli['id'] ) { pk_log( $ahli['id'], 'takziah', 'Makluman takziah diterbitkan' ); }
	}
	$g = pk_tz_generate( $id );
	if ( is_wp_error( $g ) ) { $msg = 'Makluman disimpan tetapi poster gagal dijana: ' . $g->get_error_message(); }
	pk_back( $msg, admin_url( 'admin.php?page=pk-takziah&action=edit&id=' . $id ) );
} );

add_action( 'admin_post_pk_tz_status', function () {
	pk_guard( 'pk_tz_status' );
	global $wpdb;
	$wpdb->update( pk_t( 'takziah' ), [ 'status' => 'sembunyi' === pk_p( 'status' ) ? 'sembunyi' : 'aktif', 'updated_at' => pk_now() ], [ 'id' => (int) pk_p( 'id' ) ] );
	pk_back( 'Status paparan dikemas kini.' );
} );

add_action( 'admin_post_pk_tz_del', function () {
	pk_guard( 'pk_tz_del' );
	global $wpdb;
	if ( $t = pk_tz_get( (int) pk_p( 'id' ) ) ) {
		pk_tz_delete_files( $t );
		if ( $t['gambar'] ) { pk_private_delete( $t['gambar'] ); }
		$wpdb->delete( pk_t( 'takziah' ), [ 'id' => $t['id'] ] );
	}
	pk_back( 'Makluman dipadam.', admin_url( 'admin.php?page=pk-takziah' ) );
} );

/* ================================================================= front page */

function pk_tz_front_html() {
	$rows = pk_tz_aktif();
	if ( ! $rows ) { return ''; }
	$lb = wp_script_is( 'glightbox', 'registered' );
	if ( $lb ) { wp_enqueue_style( 'glightbox' ); wp_enqueue_script( 'glightbox' ); }
	if ( function_exists( 'pk_register_public' ) ) { pk_register_public(); }
	if ( function_exists( 'pk_maybe_fa' ) ) { did_action( 'wp_enqueue_scripts' ) ? pk_maybe_fa() : add_action( 'wp_enqueue_scripts', 'pk_maybe_fa', 100 ); }
	ob_start(); ?>
	<style>
	.pk-tz { background: radial-gradient(ellipse at top, #2a2a2a, #0d0d0d 70%); color: #eee; padding: 3rem 0 3.25rem; box-sizing: border-box; }
	.wp-site-blocks .pk-tz, .is-layout-constrained > .pk-tz { width: 100vw; max-width: 100vw; margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw); }
	.pk-tz *, .pk-tz *::before, .pk-tz *::after { box-sizing: border-box; }
	.pk-tz-in { max-width: 1320px; margin: 0 auto; padding: 0 clamp(1rem, 3vw, 2rem); }
	.pk-tz-head { text-align: center; margin-bottom: 1.5rem; }
	.pk-tz h2 { color: #fff; font-weight: 700; letter-spacing: .02em; font-size: clamp(1.4rem, 3vw, 1.9rem); margin: 0 0 .25rem; }
	.pk-tz-head p { color: rgba(255,255,255,.6); margin: 0; }
	.pk-tz .pk-tz-ar { font-family: Amiri, serif; font-size: 1.6rem; color: #fff; }
	.pk-tz-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.5rem; }
	.pk-tz-grid > div { flex: 0 1 380px; min-width: 0; }
	.pk-tz-card { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.12); border-radius: 14px; padding: 14px; height: 100%; display: flex; flex-direction: column; }
	.pk-tz-card img { width: 100%; height: auto; border-radius: 8px; display: block; box-shadow: 0 10px 30px rgba(0,0,0,.5); }
	.pk-tz-card .pk-tz-nama { font-weight: 700; color: #fff; font-size: 1.1rem; margin: .9rem 0 .15rem; }
	.pk-tz-card .pk-tz-meta { font-size: .9rem; color: #bbb; }
	.pk-tz-card .pk-tz-info { font-size: .92rem; color: #ddd; margin: .5rem 0 0; }
	.pk-tz-btns { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: auto; padding-top: 1rem; }
	.pk-tz-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .8rem; border-radius: 8px; font-size: .85rem; font-weight: 600; text-decoration: none !important; border: 1px solid rgba(255,255,255,.7); color: #fff !important; }
	.pk-tz-btn--light { background: #fff; color: #1c1a1b !important; border-color: #fff; }
	.pk-tz-btn:hover { filter: brightness(.92); }
	</style>
	<section class="pk-tz" id="takziah" aria-label="Makluman takziah">
		<div class="pk-tz-in">
			<div class="pk-tz-head">
				<div class="pk-tz-ar" lang="ar" dir="rtl">إِنَّا لِلَّٰهِ وَإِنَّا إِلَيْهِ رَاجِعُونَ</div>
				<h2>Takziah &amp; Al-Fatihah</h2>
				<p>Pihak masjid merakamkan ucapan takziah kepada keluarga dan waris. Semoga roh Allahyarham/Allahyarhamah dicucuri rahmat.</p>
			</div>
			<div class="pk-tz-grid">
			<?php foreach ( $rows as $t ) :
				$full = pk_tz_url( $t['imej'] );
				$tgl  = date_i18n( 'j F Y', strtotime( $t['tarikh_meninggal'] ) );
				$wa   = "Innalillahi wa inna ilaihi raji'un.\n\nTelah kembali ke rahmatullah " . $t['nama'] . ' pada ' . $tgl . ".\n" . ( $t['info'] ? "\n" . $t['info'] . "\n" : '' ) . "\nAl-Fatihah.\n" . $full;
				?>
				<div>
					<div class="pk-tz-card">
						<a class="glightbox" href="<?php echo esc_url( $full ); ?>"<?php echo $lb ? '' : ' target="_blank" rel="noopener"'; ?> data-gallery="takziah" data-title="<?php echo esc_attr( 'Al-Fatihah — ' . $t['nama'] ); ?>">
							<img src="<?php echo esc_url( pk_tz_url( pk_tz_web( $t['imej'] ) ) ); ?>" width="750" height="1125" loading="lazy" alt="<?php echo esc_attr( 'Poster takziah ' . $t['nama'] . ', ' . $tgl ); ?>">
						</a>
						<div class="pk-tz-nama"><?php echo esc_html( $t['nama'] ); ?></div>
						<div class="pk-tz-meta">Kembali ke rahmatullah pada <?php echo esc_html( $tgl ); ?></div>
						<?php if ( $t['info'] ) : ?><p class="pk-tz-info"><?php echo nl2br( esc_html( $t['info'] ) ); ?></p><?php endif; ?>
						<div class="pk-tz-btns">
							<a class="pk-tz-btn pk-tz-btn--light" href="<?php echo esc_url( $full ); ?>" download="<?php echo esc_attr( 'Takziah-' . sanitize_file_name( $t['nama'] ) . '.jpg' ); ?>"><i class="fa-solid fa-download"></i> Muat turun (resolusi tinggi)</a>
							<a class="pk-tz-btn" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $wa ) ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Kongsi</a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

// Optional: put the notice at the top of the front page content (themes without the hook)
add_filter( 'the_content', function ( $c ) {
	if ( ! pk_opt( 'takziah_utama' ) || ! is_front_page() || ! in_the_loop() || ! is_main_query() || did_action( 'perepat_front_after_prayer' ) ) { return $c; }
	static $done = false;
	if ( $done ) { return $c; }
	$done = true;
	return pk_tz_front_html() . $c;
}, 6 );

add_action( 'perepat_front_after_prayer', function () { echo pk_tz_front_html(); } );
add_shortcode( 'pk_takziah', 'pk_tz_front_html' );
