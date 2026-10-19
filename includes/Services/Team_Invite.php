<?php
namespace BizyCore\Services;

use BizyCore\App_Login;
use BizyCore\App_Page;
use BizyCore\Database\Schema;
use BizyCore\Roles;
use BizyCore\Settings;
use BizyCore\Terminology;

defined( 'ABSPATH' ) || exit;

/**
 * Inviting people to the team. An invite does not create a WordPress login: it records the role, emails a private link, and only when
 * the person accepts (choosing their own username or using their email, and a password) is the login created with that role and linked to
 * their team record. Until then they appear as "Invited" and the invite can be sent again.
 */
class Team_Invite {

	const OPTION = 'bizycore_team_invites';
	const DAYS   = 7;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'bizycore_ready', array( __CLASS__, 'convert_old' ), 9 );
	}

	/* ───────────── Pending invites ───────────── */

	private static function all() {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	private static function save( array $all ) {
		update_option( self::OPTION, $all, false );
	}

	/** The waiting invite for a team member: array( role, modules, sent_at, expires ) or null. */
	public static function pending( $member_id ) {
		$all = self::all();
		return isset( $all[ (int) $member_id ] ) ? $all[ (int) $member_id ] : null;
	}

	/** Remember the role someone will get when they accept (no email yet, no login yet). */
	public static function set_pending( $member_id, $role, array $modules = array() ) {
		$all = self::all();
		$cur = isset( $all[ (int) $member_id ] ) ? $all[ (int) $member_id ] : array( 'hash' => '', 'sent_at' => 0, 'expires' => 0 );
		$all[ (int) $member_id ] = array_merge( $cur, array( 'role' => (string) $role, 'modules' => array_values( array_map( 'sanitize_key', $modules ) ) ) );
		self::save( $all );
	}

	public static function forget( $member_id ) {
		$all = self::all();
		unset( $all[ (int) $member_id ] );
		self::save( $all );
	}

	private static function member( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bizy_team_members' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function business() {
		$biz = (string) Settings::get( 'business_name' );
		return $biz ? $biz : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	private static function role_label( $key ) {
		$roles = Roles::roles();
		return isset( $roles[ $key ] ) ? $roles[ $key ]['label'] : '';
	}

	public static function accept_url( $token ) {
		return add_query_arg( 'bd_invite', $token, home_url( '/' ) );
	}

	/* ───────────── Sending ───────────── */

	/**
	 * Email a team member an invite to join (a new private link each time; older links stop working).
	 *
	 * @return true|\WP_Error
	 */
	public static function send( $member_id ) {
		$m = self::member( $member_id );
		if ( ! $m || ! is_email( $m['email'] ) ) {
			return new \WP_Error( 'bizycore_invite', 'That person has no valid email address to invite.', array( 'status' => 400 ) );
		}
		$p = self::pending( $member_id );
		if ( ! $p || '' === $p['role'] ) {
			return new \WP_Error( 'bizycore_invite', 'Choose a role for this person first, then invite them.', array( 'status' => 400 ) );
		}
		$token = wp_generate_password( 40, false, false );
		$all   = self::all();
		$all[ (int) $member_id ]['hash']    = hash( 'sha256', $token );
		$all[ (int) $member_id ]['expires'] = time() + self::DAYS * DAY_IN_SECONDS;
		$all[ (int) $member_id ]['sent_at'] = time();
		$all[ (int) $member_id ]['by']      = get_current_user_id();
		$biz     = self::business();
		$by      = wp_get_current_user();
		$by_name = ( $by && $by->ID ) ? $by->display_name : '';
		$role    = self::role_label( $p['role'] );
		$first   = '' !== trim( (string) $m['name'] ) ? trim( explode( ' ', trim( $m['name'] ) )[0] ) : 'there';
		$link    = self::accept_url( $token );
		$who     = '' !== $by_name ? esc_html( $by_name ) . ' has' : esc_html( $biz ) . ' has';
		$intro   = $who . ' invited you to join ' . esc_html( $biz ) . ' on Bizy' . ( '' !== $role ? ' as <strong>' . esc_html( $role ) . '</strong>' : '' ) . '.';
		$ti      = ( '' !== $by_name ? $by_name : $biz ) . ' has invited you to join ' . $biz . ' on Bizy' . ( '' !== $role ? ' as ' . $role : '' ) . '.';
		$html    = '<p>Hi ' . esc_html( $first ) . ',</p><p>' . $intro . ' It is where the team works on ' . esc_html( strtolower( Terminology::label( 'client', true ) ) ) . ', projects and files.</p><p><a href="' . esc_url( $link ) . '">Accept the invite</a></p><p>You will choose your own username and password. You can use your email address as your username if you prefer. This invite works for ' . self::DAYS . ' days; if it has expired, ask ' . ( '' !== $by_name ? esc_html( $by_name ) : 'the person who invited you' ) . ' to send it again.</p>';
		$text    = "Hi {$first},\n\n{$ti}\nAccept the invite: {$link}\n\nYou will choose your own username and password (you can use your email address as your username). The invite works for " . self::DAYS . " days.\n";
		$r       = Mailer::send( array( 'to' => array( $m['email'] ), 'subject' => 'You are invited to join ' . $biz . ' on Bizy', 'heading' => 'You are invited to join ' . $biz, 'preheader' => $ti, 'html' => $html, 'text' => $text ) );
		if ( empty( $r['ok'] ) ) {
			return new \WP_Error( 'bizycore_mail', isset( $r['error'] ) ? $r['error'] : 'The email could not be sent.', array( 'status' => 500 ) );
		}
		self::save( $all ); // only now: the new link is the one that was emailed
		return true;
	}

	/** A person who already has a login on this site just learns they now have access. */
	public static function notify_existing( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			return new \WP_Error( 'bizycore_invite', 'That person has no email address.', array( 'status' => 400 ) );
		}
		list( $rk ) = Roles::role_of( $u->ID );
		$role       = self::role_label( $rk );
		$biz        = self::business();
		$by         = wp_get_current_user();
		$by_name    = ( $by && $by->ID ) ? $by->display_name : $biz;
		$app        = App_Page::url();
		$first      = $u->first_name ? $u->first_name : $u->display_name;
		$line       = $by_name . ' has given you access to ' . $biz . ' on Bizy' . ( '' !== $role ? ' as ' . $role : '' ) . '.';
		$html       = '<p>Hi ' . esc_html( $first ) . ',</p><p>' . esc_html( $line ) . ' You can sign in with your existing login.</p><p><a href="' . esc_url( $app ) . '">Open ' . esc_html( $biz ) . ' on Bizy</a></p>';
		$r          = Mailer::send( array( 'to' => array( $u->user_email ), 'subject' => 'You now have access to ' . $biz . ' on Bizy', 'heading' => 'You have been added to ' . $biz, 'html' => $html, 'text' => "Hi {$first},\n\n{$line}\nSign in with your existing login: {$app}\n" ) );
		return empty( $r['ok'] ) ? new \WP_Error( 'bizycore_mail', isset( $r['error'] ) ? $r['error'] : 'The email could not be sent.', array( 'status' => 500 ) ) : true;
	}

	/* ───────────── Accepting ───────────── */

	private static function find( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$hash = hash( 'sha256', $token );
		foreach ( self::all() as $mid => $rec ) {
			if ( ! empty( $rec['hash'] ) && hash_equals( $rec['hash'], $hash ) ) {
				if ( (int) $rec['expires'] < time() ) {
					return null;
				}
				$m = self::member( $mid );
				return $m ? array( 'member' => $m, 'rec' => $rec ) : null;
			}
		}
		return null;
	}

	public static function maybe_serve() {
		if ( empty( $_GET['bd_invite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizycore invite' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$token = sanitize_text_field( wp_unslash( $_GET['bd_invite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$found = self::find( $token );
		$base  = add_query_arg( 'bd_invite', $token, home_url( '/' ) );
		if ( ! $found ) {
			App_Login::render_invite( null, '', $base );
			exit;
		}
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $post ) {
			App_Login::render_invite( $found, isset( $_GET['bd_error'] ) ? sanitize_key( wp_unslash( $_GET['bd_error'] ) ) : '', $base ); // phpcs:ignore WordPress.Security.NonceVerification
			exit;
		}
		$err = self::accept_post( $found );
		if ( true === $err ) {
			wp_safe_redirect( App_Page::url() );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'bd_error', $err, $base ) );
		exit;
	}

	/** @return true|string true when the login was created, otherwise an error code. */
	private static function accept_post( array $found ) {
		global $wpdb;
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'bizy_inv_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 12 ) {
			return 'rate';
		}
		set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bd_invite' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return 'nonce';
		}
		$m     = $found['member'];
		$rec   = $found['rec'];
		$email = sanitize_email( $m['email'] );
		$use   = ! empty( $_POST['use_email'] );
		$login = $use ? $email : sanitize_user( isset( $_POST['user_login'] ) ? wp_unslash( $_POST['user_login'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$p1    = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$p2    = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $login || strlen( $login ) < 3 ) {
			return 'login';
		}
		if ( ! $use && is_email( $login ) && strtolower( $login ) !== strtolower( $email ) ) {
			return 'login_email';
		}
		if ( username_exists( $login ) ) {
			return 'taken';
		}
		if ( email_exists( $email ) ) {
			return 'email_taken';
		}
		if ( strlen( $p1 ) < 8 ) {
			return 'short';
		}
		if ( $p1 !== $p2 ) {
			return 'mismatch';
		}
		$first = '' !== trim( (string) $m['name'] ) ? trim( explode( ' ', trim( $m['name'] ) )[0] ) : '';
		$last  = '' !== trim( (string) $m['name'] ) ? trim( implode( ' ', array_slice( explode( ' ', trim( $m['name'] ) ), 1 ) ) ) : '';
		$uid   = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $p1, 'display_name' => $m['name'], 'first_name' => $first, 'last_name' => $last, 'role' => '' ) );
		if ( is_wp_error( $uid ) ) {
			return 'rule';
		}
		update_user_meta( $uid, 'show_admin_bar_front', 'false' );
		$r = Roles::assign( $uid, $rec['role'], (array) $rec['modules'] );
		if ( is_wp_error( $r ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
			return 'role';
		}
		$wpdb->update( Schema::table( 'bizy_team_members' ), array( 'wp_user_id' => $uid ), array( 'id' => (int) $m['id'] ) );
		update_user_meta( $uid, 'bizycore_team_joined', time() );
		update_user_meta( $uid, 'bizycore_last_login', time() );
		self::forget( (int) $m['id'] );
		do_action( 'bizycore_team_joined', $uid, (int) $m['id'] );
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );
		return true;
	}

	/**
	 * Earlier invites created a WordPress login straight away. Someone invited that way who has never signed in gets their login removed
	 * and becomes a waiting invite instead, so they choose their own username when they accept.
	 */
	public static function convert_old() {
		global $wpdb;
		if ( get_option( 'bizycore_team_invites_converted' ) ) {
			return;
		}
		update_option( 'bizycore_team_invites_converted', 1, false );
		foreach ( (array) $wpdb->get_results( 'SELECT id, wp_user_id FROM ' . Schema::table( 'bizy_team_members' ) . ' WHERE wp_user_id>0', ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			$uid = (int) $m['wp_user_id'];
			$u   = get_userdata( $uid );
			if ( ! $u || user_can( $u, 'manage_options' ) || ! get_user_meta( $uid, 'bizycore_team_invited', true ) ) {
				continue;
			}
			if ( (int) get_user_meta( $uid, 'bizycore_last_login', true ) > 0 || get_user_meta( $uid, 'session_tokens', true ) || (int) count_user_posts( $uid ) > 0 ) {
				continue; // they have already joined or have content: leave the login alone
			}
			list( $role, $mods ) = Roles::role_of( $uid );
			if ( '' === $role ) {
				continue;
			}
			self::set_pending( (int) $m['id'], $role, (array) $mods );
			$wpdb->update( Schema::table( 'bizy_team_members' ), array( 'wp_user_id' => 0 ), array( 'id' => (int) $m['id'] ) );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		}
	}
}
