<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Settings that belong to the suite as a whole: the business profile, the app address, the portal, messages and
 * notification timing. Modules keep their own settings in their own options and may add defaults here only for things
 * Core itself reads. Types are taken from the defaults: bool, int, array and string.
 */
class Settings {

	const OPTION = 'wpflatcore_settings';

	/** Strings that may hold line breaks. */
	const TEXTAREAS = array( 'business_address', 'portal_welcome' );

	public static function defaults() {
		return apply_filters(
			'wpflatcore_settings_defaults',
			array(
				'company_name'           => '',
				'business_name'          => '',
				'business_logo_id'       => 0,
				'business_logo_email_id' => 0,
				'brand_color'            => '#222530',
				'business_address'       => '',
				'business_email'         => '',
				'business_phone'         => '',
				'business_website'       => '',
				'app_fullscreen'         => true,
				'app_keep_staff'         => true,
				'app_frontend'           => true,
				'app_login'              => true,
				'app_home'               => false,
				'app_slug'               => 'app',
				'home_module'            => '',
				'portal_enabled'         => true,
				'portal_auto_users'      => true,
				'portal_slug'            => 'portal',
				'portal_title'           => '',
				'portal_welcome'         => '',
				'portal_show_messages'   => true,
				'messages_max_mb'        => 25,
				'messages_notify'        => '',
				'notify_disabled'        => array(),
				'notify_due_days'        => 3,
				'notify_overdue_every'   => 7,
				'notify_overdue_max'     => 3,
				'delete_on_uninstall'    => false,
				'delete_backups_on_uninstall' => false,
			)
		);
	}

	public static function get( $key = null ) {
		$s                      = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$s['business_logo_url'] = ! empty( $s['business_logo_id'] ) ? (string) wp_get_attachment_url( (int) $s['business_logo_id'] ) : '';
		$s['business_logo_email_url'] = ! empty( $s['business_logo_email_id'] ) ? (string) wp_get_attachment_url( (int) $s['business_logo_email_id'] ) : '';
		if ( '' === $s['business_logo_email_url'] && $s['business_logo_url'] && ! preg_match( '/\.svg(\?|$)/i', $s['business_logo_url'] ) ) {
			$s['business_logo_email_url'] = $s['business_logo_url']; // raster logos work in email as-is
		}
		if ( '' === trim( (string) $s['business_name'] ) ) {
			$s['business_name'] = $s['company_name'] ? $s['company_name'] : get_bloginfo( 'name' );
		}
		if ( null === $key ) {
			return $s;
		}
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/** Save the keys that were sent; anything unknown is ignored. */
	public static function update( array $in ) {
		$cur = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$def = self::defaults();
		$out = $cur;
		foreach ( $def as $key => $default ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$v = $in[ $key ];
			if ( is_bool( $default ) ) {
				$out[ $key ] = ! empty( $v ) && 'false' !== $v && '0' !== $v;
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = absint( $v );
			} elseif ( is_array( $default ) ) {
				$out[ $key ] = is_array( $v ) ? array_values( array_map( 'sanitize_text_field', $v ) ) : $default;
			} elseif ( in_array( $key, self::TEXTAREAS, true ) ) {
				$out[ $key ] = sanitize_textarea_field( (string) $v );
			} elseif ( 'home_module' === $key ) {
				$id          = sanitize_key( (string) $v );
				$out[ $key ] = ( '' === $id || '' !== Registry::front_url( $id ) ) ? $id : $cur[ $key ]; // only a module that has a front-end page
			} elseif ( 'brand_color' === $key ) {
				$hex         = sanitize_hex_color( (string) $v );
				$out[ $key ] = $hex ? $hex : $default;
			} elseif ( 'app_slug' === $key || 'portal_slug' === $key ) {
				$slug        = sanitize_title( (string) $v );
				$out[ $key ] = '' !== $slug ? $slug : $default;
			} elseif ( false !== strpos( $key, '_email' ) && 'messages_notify' !== $key && false === strpos( $key, '_logo' ) ) {
				$out[ $key ] = sanitize_email( (string) $v );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $v );
			}
		}
		$out = apply_filters( 'wpflatcore_settings_sanitize', $out, $in );
		update_option( self::OPTION, $out, false );
		if ( $cur['app_slug'] !== $out['app_slug'] || $cur['portal_slug'] !== $out['portal_slug'] || $cur['app_home'] !== $out['app_home'] || $cur['home_module'] !== $out['home_module'] ) {
			do_action( 'wpflatcore_addresses_changed' );
			do_action( 'litespeed_purge_all' );
		}
		return self::get();
	}
}
