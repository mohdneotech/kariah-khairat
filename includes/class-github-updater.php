<?php
/**
 * Minimal GitHub Releases updater.
 *
 * Checks https://github.com/{repo}/releases/latest and offers the release asset
 * "{slug}.zip" (built by .github/workflows/release.yml) as a normal WordPress
 * plugin update. Disable with: add_filter( 'pk_github_updates', '__return_false' );
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PK_GitHub_Updater' ) ) :
final class PK_GitHub_Updater {
	private $file;
	private $basename;
	private $slug;
	private $repo;
	private $version;
	private $cache_key;

	public function __construct( $file, $repo, $version ) {
		$this->file      = $file;
		$this->basename  = plugin_basename( $file );
		$this->slug      = dirname( $this->basename );
		$this->repo      = $repo;
		$this->version   = $version;
		$this->cache_key = 'pk_gh_release';
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', function () { delete_site_transient( $this->cache_key ); } );
	}

	/** Latest release (cached 6 h). */
	private function release() {
		$r = get_site_transient( $this->cache_key );
		if ( is_array( $r ) ) {
			return $r ?: null;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . $this->slug ),
			)
		);
		$r = array();
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $j ) && ! empty( $j['tag_name'] ) ) {
				$pkg = '';
				foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
					if ( ( $a['name'] ?? '' ) === $this->slug . '.zip' ) {
						$pkg = $a['browser_download_url'];
						break;
					}
				}
				$r = array(
					'version' => ltrim( (string) $j['tag_name'], 'vV' ),
					'package' => $pkg ?: (string) ( $j['zipball_url'] ?? '' ),
					'url'     => (string) ( $j['html_url'] ?? 'https://github.com/' . $this->repo ),
					'notes'   => (string) ( $j['body'] ?? '' ),
					'date'    => (string) ( $j['published_at'] ?? '' ),
				);
			}
		}
		set_site_transient( $this->cache_key, $r, $r ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $r ?: null;
	}

	public function check( $t ) {
		if ( ! is_object( $t ) || empty( $t->checked ) || ! apply_filters( 'pk_github_updates', true ) ) {
			return $t;
		}
		$r    = $this->release();
		$item = (object) array(
			'id'          => 'github.com/' . $this->repo,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $r ? $r['version'] : $this->version,
			'url'         => 'https://github.com/' . $this->repo,
			'package'     => $r ? $r['package'] : '',
		);
		if ( $r && $r['package'] && version_compare( $r['version'], $this->version, '>' ) ) {
			$t->response[ $this->basename ] = $item;
		} else {
			$t->no_update[ $this->basename ] = $item;
		}
		return $t;
	}

	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->slug ) {
			return $res;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$r = $this->release();
		$d = get_plugin_data( $this->file, false, false );
		return (object) array(
			'name'          => $d['Name'],
			'slug'          => $this->slug,
			'version'       => $r ? $r['version'] : $this->version,
			'author'        => $d['Author'],
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => $d['RequiresWP'],
			'requires_php'  => $d['RequiresPHP'],
			'last_updated'  => $r ? $r['date'] : '',
			'download_link' => $r ? $r['package'] : '',
			'sections'      => array(
				'description' => wpautop( esc_html( $d['Description'] ) ),
				'changelog'   => $r ? wpautop( esc_html( $r['notes'] ) ) : '',
			),
		);
	}

	/** GitHub zipballs unpack to "owner-repo-hash/" — rename to the plugin folder. */
	public function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		if ( ( $extra['plugin'] ?? '' ) !== $this->basename ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		return ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) ? $want : $source;
	}
}
endif;
