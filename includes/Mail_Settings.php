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

	/** The domain mail is sent from: the SMTP "From" address, else the SMTP login when it is an address, else the resolved sender. */
	public static function sender_domain() {
		$m = self::get_full();
		foreach ( array( $m['from_email'], 'smtp' === $m['method'] ? $m['smtp_user'] : '' ) as $e ) {
			if ( is_email( $e ) ) {
				return strtolower( substr( strrchr( $e, '@' ), 1 ) );
			}
		}
		$f = self::from();
		return is_email( $f['email'] ) ? strtolower( substr( strrchr( $f['email'], '@' ), 1 ) ) : '';
	}

	/** Is this the signed-in person's own address, and on the sending domain? Only then may they send as themselves. */
	public static function may_send_as( $email, $user_id = 0 ) {
		$user = get_userdata( $user_id ? (int) $user_id : get_current_user_id() );
		$email = strtolower( trim( (string) $email ) );
		if ( ! $user || ! is_email( $email ) || strtolower( $user->user_email ) !== $email ) {
			return false;
		}
		$d = self::sender_domain();
		return '' !== $d && strtolower( substr( strrchr( $email, '@' ), 1 ) ) === $d;
	}

	/** The From addresses the signed-in person may pick: the business sender, and their own address when it is on the same domain. */
	public static function sender_choices( $user_id = 0 ) {
		$def  = self::from();
		$out  = array( array( 'key' => 'default', 'name' => $def['name'], 'email' => $def['email'], 'label' => trim( $def['name'] . ' <' . $def['email'] . '>' ) ) );
		$user = get_userdata( $user_id ? (int) $user_id : get_current_user_id() );
		if ( $user && self::may_send_as( $user->user_email, $user->ID ) && strtolower( $user->user_email ) !== strtolower( $def['email'] ) ) {
			$out[] = array( 'key' => 'me', 'name' => $user->display_name, 'email' => $user->user_email, 'label' => $user->display_name . ' <' . $user->user_email . '>' );
		}
		return $out;
	}

	/** The sender for one message: the person's own address when they asked for it and may use it, otherwise the business sender. */
	public static function sender_for( $email = '', $name = '', $user_id = 0 ) {
		$def = self::from();
		if ( '' !== (string) $email && self::may_send_as( $email, $user_id ) ) {
			$user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();
			return array( 'name' => $user->display_name ? $user->display_name : (string) $name, 'email' => $user->user_email, 'reply_to' => $user->user_email );
		}
		return $def;
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
