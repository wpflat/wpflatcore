<?php
namespace BizyCore\Services;

use BizyCore\App_Page;
use BizyCore\Roles;
use BizyCore\Settings;
use BizyCore\Terminology;

defined( 'ABSPATH' ) || exit;

/** The invite a team member gets when they are given access to Bizy: who invited them, the role, and a link to choose their password. */
class Team_Invite {

	/** Where the "choose your password" link opens: the app's own sign-in page when it is on, otherwise WordPress's. */
	private static function link( \WP_User $u, $key ) {
		if ( Settings::get( 'app_frontend' ) && Settings::get( 'app_login' ) ) {
			return add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $u->user_login ) ), App_Page::url() );
		}
		return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
	}

	/**
	 * Email a team member their invite.
	 *
	 * @param int  $user_id The WordPress user.
	 * @param bool $reset   True when they still need to choose a password (a new login); false to just tell an existing user they now have access.
	 * @return true|\WP_Error
	 */
	public static function send( $user_id, $reset = true ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			return new \WP_Error( 'bizycore_invite', 'That person has no email address to invite.', array( 'status' => 400 ) );
		}
		list( $role_key ) = Roles::role_of( $u->ID );
		$roles            = Roles::roles();
		$role             = isset( $roles[ $role_key ] ) ? $roles[ $role_key ]['label'] : '';
		$biz              = (string) Settings::get( 'business_name' );
		$biz              = $biz ? $biz : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$by               = wp_get_current_user();
		$by_name          = ( $by && $by->ID ) ? $by->display_name : '';
		$app              = App_Page::url();
		$first            = $u->first_name ? $u->first_name : $u->display_name;
		$intro            = ( '' !== $by_name ? esc_html( $by_name ) . ' has' : esc_html( $biz ) . ' has' ) . ' invited you to join ' . esc_html( $biz ) . ' on Bizy' . ( '' !== $role ? ' as <strong>' . esc_html( $role ) . '</strong>' : '' ) . '.';
		$text_intro       = ( '' !== $by_name ? $by_name : $biz ) . ' has invited you to join ' . $biz . ' on Bizy' . ( '' !== $role ? ' as ' . $role : '' ) . '.';
		if ( $reset ) {
			$key = get_password_reset_key( $u );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$link = self::link( $u, $key );
			$html = '<p>Hi ' . esc_html( $first ) . ',</p><p>' . $intro . ' It is where the team works on ' . esc_html( strtolower( Terminology::label( 'client', true ) ) ) . ', projects and files.</p><p><a href="' . esc_url( $link ) . '"><strong>Accept the invite and choose your password</strong></a></p><p>Your username is <strong>' . esc_html( $u->user_login ) . '</strong>, or use your email address. This link works for a limited time; after that choose “Forgot password” on the sign-in page: ' . esc_url( $app ) . '</p>';
			$text = "Hi {$first},\n\n{$text_intro}\nAccept the invite and choose your password: {$link}\n\nYour username is {$u->user_login}, or use your email address.\nSign in later at {$app}\n";
		} else {
			$html = '<p>Hi ' . esc_html( $first ) . ',</p><p>' . $intro . ' You can sign in with your existing login.</p><p><a href="' . esc_url( $app ) . '"><strong>Open ' . esc_html( $biz ) . ' on Bizy</strong></a></p>';
			$text = "Hi {$first},\n\n{$text_intro}\nSign in with your existing login: {$app}\n";
		}
		$r = Mailer::send( array( 'to' => array( $u->user_email ), 'subject' => 'You are invited to join ' . $biz . ' on Bizy', 'heading' => 'You are invited to join ' . $biz, 'preheader' => $text_intro, 'html' => $html, 'text' => $text ) );
		if ( empty( $r['ok'] ) ) {
			return new \WP_Error( 'bizycore_mail', isset( $r['error'] ) ? $r['error'] : 'The email could not be sent.', array( 'status' => 500 ) );
		}
		update_user_meta( $u->ID, 'bizycore_team_invited', time() );
		return true;
	}
}
