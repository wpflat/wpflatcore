<?php
namespace BizyCore\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: back up or list Bizy backups from the command line (handy for server cron on big sites).
 *
 *   wp bizy backup [--no-files] [--no-logs]
 *   wp bizy backups
 */
class CLI {

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bizy backup', array( __CLASS__, 'backup' ) );
			\WP_CLI::add_command( 'bizy backups', array( __CLASS__, 'backups' ) );
			\WP_CLI::add_command( 'bizy stealth', array( __CLASS__, 'stealth' ) );
			\WP_CLI::add_command( 'bizy files', array( __CLASS__, 'files' ) );
		}
	}

	public static function backup( $args, $assoc ) {
		$s = Backup_Manager::start( 'manual', array( 'files' => ! isset( $assoc['no-files'] ), 'logs' => ! isset( $assoc['no-logs'] ) ) );
		while ( ! is_wp_error( $s ) && ! $s['done'] ) {
			$s = Backup_Manager::step( $s['id'], '', 20 );
		}
		if ( is_wp_error( $s ) ) {
			\WP_CLI::error( $s->get_error_message() );
		}
		\WP_CLI::success( 'Backup saved: ' . $s['result']['id'] . ' (' . size_format( $s['result']['size'] ) . ')' );
	}

	public static function backups() {
		$rows = array();
		foreach ( Backup_Manager::store()->all() as $m ) {
			$rows[] = array( 'id' => $m['id'], 'created' => $m['created_at'], 'type' => $m['type'] ?? '', 'size' => size_format( (int) $m['size'] ), 'protected' => ! empty( $m['encrypted'] ) ? 'yes' : 'no' );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created', 'type', 'size', 'protected' ) );
	}

	/** wp bizy stealth status|off|on */
	public static function stealth( $args ) {
		$do = $args[0] ?? 'status';
		if ( 'off' === $do ) {
			$r = \BizyCore\Stealth::save( array( 'enabled' => false ) );
			\BizyCore\Stealth_Rules::remove();
			\WP_CLI::success( 'Stealth mode is off and the server rules are removed.' );
		} elseif ( 'test' === $do ) {
			\BizyCore\Stealth_Readiness::run_step( 'start' );
			foreach ( array_keys( \BizyCore\Stealth_Readiness::steps() ) as $id ) {
				$o = \BizyCore\Stealth_Readiness::run_step( $id );
			}
			foreach ( $o['items'] as $i ) {
				\WP_CLI::line( strtoupper( $i['level'] ) . '  ' . $i['label'] . ( $i['detail'] ? ' — ' . $i['detail'] : '' ) );
			}
			\WP_CLI::line( 'Verdicts: ' . wp_json_encode( $o['verdicts'] ) );
		} elseif ( 'on' === $do ) {
			$r = \BizyCore\Stealth_Admin::save( array( 'enabled' => true, 'rules' => true ) );
			is_wp_error( $r ) ? \WP_CLI::error( $r->get_error_message() ) : \WP_CLI::success( 'Stealth mode is on. Confirm it in Settings → Security & privacy within 5 minutes.' );
		} else {
			$o = \BizyCore\Stealth_Admin::overview();
			\WP_CLI::line( 'enabled: ' . ( $o['enabled'] ? 'yes' : 'no' ) . ' | confirmed: ' . ( $o['confirmed'] ? 'yes' : 'no' ) . ' | secret: ' . ( $o['secret_url'] ?: '(none)' ) . ' | server rules: ' . ( $o['rules']['applied'] ? 'yes' : 'no' ) );
		}
	}

	/** wp bizy files status */
	public static function files( $args, $assoc = array() ) {
		$s = \BizyCore\Paths::status();
		\WP_CLI::line( 'root: ' . $s['root'] . ' | outside web root: ' . ( $s['outside_webroot'] ? 'yes' : 'no' ) );
	}
}
