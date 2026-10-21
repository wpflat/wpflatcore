<?php
namespace WPFlatCore;

use WPFlatCore\Services\Crypto;

defined( 'ABSPATH' ) || exit;

/** Email configuration: sender and mailer (the site's own, or WPFlat's SMTP). Each module keeps its own message templates. */
class Mail_Settings {

	const OPTION = 'wpflatcore_mail';

	public static function defaults() {
		return array(
			'method'          => 'wp', // wp = the site's mailer (wp_mail), smtp = WPFlat SMTP below.
			'from_name'       => '',
			'from_email'      => '',
			'reply_to'        => '',
			'smtp_host'       => '',
			'smtp_port'       => 587,
			'smtp_secure'     => 'tls', // tls | ssl | none
			'smtp_auth'       => true,
			'smtp_user'       => '',
			'smtp_pass_enc'   => '',
			'bcc_self'        => false,
		);
	}

	/** Everything, including the encrypted password blob. Server-side use only. */
	public static function get_full() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	/** Safe for the browser: never contains the password. */
	public static function get_public() {
		$s                 = self::get_full();
		$s['smtp_pass_set'] = '' !== $s['smtp_pass_enc'];
		unset( $s['smtp_pass_enc'] );
		$s['resolved_from'] = self::from();
		return $s;
	}

	public static function update( array $in ) {
		$s = self::get_full();
		foreach ( array( 'from_name', 'smtp_host', 'smtp_user' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = trim( str_replace( array( "\r", "\n" ), ' ', sanitize_text_field( $in[ $k ] ) ) );
			}
		}
		foreach ( array( 'from_email', 'reply_to' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = sanitize_email( $in[ $k ] );
			}
		}
		if ( isset( $in['method'] ) ) {
			$s['method'] = 'smtp' === $in['method'] ? 'smtp' : 'wp';
		}
		if ( isset( $in['smtp_secure'] ) ) {
			$s['smtp_secure'] = in_array( $in['smtp_secure'], array( 'tls', 'ssl', 'none' ), true ) ? $in['smtp_secure'] : 'tls';
		}
		if ( isset( $in['smtp_port'] ) ) {
			$s['smtp_port'] = max( 1, min( 65535, (int) $in['smtp_port'] ) );
		}
		foreach ( array( 'smtp_auth', 'bcc_self' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		// Password: omitted/empty keeps the stored one; "__clear__" removes it.
		if ( isset( $in['smtp_pass'] ) && '' !== $in['smtp_pass'] ) {
			$s['smtp_pass_enc'] = '__clear__' === $in['smtp_pass'] ? '' : Crypto::encrypt( (string) $in['smtp_pass'] );
		}
		update_option( self::OPTION, $s, false );
		return self::get_public();
	}

	public static function smtp_password() {
		return Crypto::decrypt( self::get_full()['smtp_pass_enc'] );
	}

	/** Resolved sender: configured values, falling back to the business profile / site admin. */
	public static function from() {
		$m  = self::get_full();
		$s  = Settings::get();
		$email = $m['from_email'] ? $m['from_email'] : ( $s['business_email'] ? sanitize_email( $s['business_email'] ) : get_option( 'admin_email' ) );
		$name  = $m['from_name'] ? $m['from_name'] : $s['business_name'];
		return array( 'name' => $name, 'email' => $email, 'reply_to' => $m['reply_to'] ? $m['reply_to'] : ( $s['business_email'] ? sanitize_email( $s['business_email'] ) : '' ) );
	}
}
