<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/** What the Security & privacy screen shows and saves. */
class Stealth_Admin {

	public static function make_secret() {
		return strtolower( wp_generate_password( 14, false, false ) );
	}

	public static function overview() {
		$s      = Stealth::settings();
		$active = Stealth::active_now();
		return array(
			'enabled'     => (bool) $s['enabled'] && $active,
			'confirmed'   => (bool) $s['confirmed'],
			'seconds_left' => ( $s['enabled'] && ! $s['confirmed'] ) ? max( 0, (int) $s['deadline'] - time() ) : 0,
			'secret'      => $s['secret'],
			'secret_url'  => $s['secret'] ? home_url( '/' . $s['secret'] ) : '',
			'layers'      => array( 'hide_login' => $s['hide_login'], 'hide_admin' => $s['hide_admin'], 'hide_rest' => $s['hide_rest'], 'hide_misc' => $s['hide_misc'], 'only_allowlist' => $s['only_allowlist'], 'strip' => $s['strip'] ),
			'recovery'    => Stealth::recovery_flag(),
			'rest_allow'  => $s['rest_allow'],
			'gate'        => Stealth::has_gate(),
			'rules'       => class_exists( '\\BizyCore\\Stealth_Rules' ) ? Stealth_Rules::status() : array( 'applied' => false ),
			'files'       => Paths::status(),
			'readiness'   => Stealth_Readiness::overview(),
			'wpconfig'    => array( 'constant' => "define( 'BIZYCORE_STEALTH_OFF', true );", 'file' => Paths::root() . '/STEALTH_OFF', 'wpcli' => 'wp bizy stealth off' ),
		);
	}

	public static function save( array $in ) {
		if ( isset( $in['secret_generate'] ) && $in['secret_generate'] ) {
			$in['secret'] = self::make_secret();
		}
		$r = Stealth::save( $in );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( class_exists( '\\BizyCore\\Stealth_Rules' ) ) {
			Stealth_Rules::sync( Stealth::settings() );
		}
		return self::overview();
	}

	/** A plain-text card to keep offline: the secret address and every way back in. */
	public static function recovery_card() {
		$s    = Stealth::settings();
		$site = home_url( '/' );
		$lines = array(
			'Bizy — recovery card for ' . $site,
			'Made ' . gmdate( 'Y-m-d H:i' ) . ' UTC. Keep this somewhere safe; it is not stored in your backups.',
			'',
			'Your secret WordPress admin address (open it once in your browser, then sign in as usual):',
			'  ' . ( $s['secret'] ? home_url( '/' . $s['secret'] ) : '(not chosen yet)' ),
			'',
			'If the site stops working or you cannot reach WordPress admin, do ONE of these (they switch stealth mode off):',
			'  1. In wp-config.php, above the line "That\'s all, stop editing!", add:',
			'       define( \'BIZYCORE_STEALTH_OFF\', true );',
			'  2. With FTP or the host\'s file manager, create an empty file called STEALTH_OFF here:',
			'       ' . Paths::root() . '/STEALTH_OFF',
			'  3. On the server run:  wp bizy stealth off',
			'',
			'If the site shows a server error after the server rules were added:',
			'  - Open the .htaccess file in the WordPress folder and delete everything from "# BEGIN Bizy Stealth" to "# END Bizy Stealth".',
			'  - Or replace .htaccess with the saved copy .htaccess.bd-backup (same folder).',
			'',
			'Where your Bizy files live:',
			'  ' . Paths::root() . '  (each plugin\'s private files under "private"; logos and images under "public")',
			'',
			'After recovering, remove the line or file again if you want stealth mode back, and run the hosting test again.',
		);
		return implode( "\n", $lines ) . "\n";
	}
}
