<?php
namespace WPFlatCore\Rest;

use WPFlatCore\Mail_Settings;
use WPFlatCore\Media;
use WPFlatCore\Services\Mailer;
use WPFlatCore\Paths;
use WPFlatCore\Roles;
use WPFlatCore\Services\Activity;
use WPFlatCore\Settings;
use WPFlatCore\Shell;
use WPFlatCore\Stealth;
use WPFlatCore\Stealth_Admin;
use WPFlatCore\Stealth_Check;
use WPFlatCore\Stealth_Readiness;

defined( 'ABSPATH' ) || exit;

/** Settings, preferences, uploads and the security (stealth) screen. Namespace wpflatcore/v1. */
class System_Routes {

	const NS = 'wpflatcore/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	private static function done( $r ) {
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function register() {
		$use     = array( Resource_Controller::class, 'can' );
		$admin   = array( Roles::class, 'can_admin' );
		$wpadmin = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( self::NS, '/demo', array(
			array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \WPFlatCore\Services\Demo::status() ); }, 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => function () { return self::done( \WPFlatCore\Services\Demo::load() ); }, 'permission_callback' => $admin ),
			array( 'methods' => 'DELETE', 'callback' => function () { return rest_ensure_response( \WPFlatCore\Services\Demo::clear() ); }, 'permission_callback' => $admin ),
		) );
		register_rest_route( self::NS, '/meta', array( 'methods' => 'GET', 'callback' => function () {
			return rest_ensure_response( Shell::meta_payload() );
		}, 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/prefs', array(
			array( 'methods' => 'GET', 'callback' => function () {
				return rest_ensure_response( array( 'theme' => Shell::theme() ) );
			}, 'permission_callback' => $use ),
			array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'save_prefs' ), 'permission_callback' => $use ),
		) );
		register_rest_route( self::NS, '/settings', array(
			array( 'methods' => 'GET', 'callback' => function () {
				return rest_ensure_response( Settings::get() );
			}, 'permission_callback' => $use ),
			array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( self::NS, '/mail-settings', array(
			array( 'methods' => 'GET', 'callback' => function () {
				return rest_ensure_response( Mail_Settings::get_public() );
			}, 'permission_callback' => $admin ),
			array( 'methods' => 'POST,PUT', 'callback' => function ( \WP_REST_Request $r ) {
				return rest_ensure_response( Mail_Settings::update( (array) $r->get_json_params() ) );
			}, 'permission_callback' => $admin ),
		) );
		register_rest_route( self::NS, '/mail/senders', array( 'methods' => 'GET', 'callback' => function () {
			return rest_ensure_response( Mail_Settings::sender_choices() );
		}, 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/mail-settings/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mail_test' ), 'permission_callback' => $admin ) );
		// The WordPress Media Library, for pickers such as the logo fields: list images, and add to the library.
		register_rest_route( self::NS, '/media-library', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'library_list' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/media-library/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'library_upload' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/files/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/files/status', array( 'methods' => 'GET', 'callback' => function () {
			return rest_ensure_response( Paths::status() );
		}, 'permission_callback' => $admin ) );

		/* Security & privacy (Beta): only WordPress administrators */
		register_rest_route( self::NS, '/stealth', array(
			array( 'methods' => 'GET', 'callback' => function () {
				return rest_ensure_response( Stealth_Admin::overview() );
			}, 'permission_callback' => $wpadmin ),
			array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $r ) {
				return self::done( Stealth_Admin::save( (array) $r->get_json_params() ) );
			}, 'permission_callback' => $wpadmin ),
		) );
		register_rest_route( self::NS, '/stealth/confirm', array( 'methods' => 'POST', 'callback' => function () {
			Stealth::confirm();
			return rest_ensure_response( Stealth_Admin::overview() );
		}, 'permission_callback' => $wpadmin ) );
		register_rest_route( self::NS, '/stealth/secret', array( 'methods' => 'POST', 'callback' => function () {
			return rest_ensure_response( array( 'secret' => Stealth_Admin::make_secret() ) );
		}, 'permission_callback' => $wpadmin ) );
		register_rest_route( self::NS, '/stealth/test', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $r ) {
			$p = (array) $r->get_json_params();
			return self::done( Stealth_Readiness::run_step( (string) ( isset( $p['step'] ) ? $p['step'] : 'start' ) ) );
		}, 'permission_callback' => $wpadmin ) );
		register_rest_route( self::NS, '/stealth/ack', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $r ) {
			$p = (array) $r->get_json_params();
			return rest_ensure_response( Stealth_Readiness::acknowledge( ! empty( $p['ack'] ) ) );
		}, 'permission_callback' => $wpadmin ) );
		register_rest_route( self::NS, '/stealth/recovery-card', array( 'methods' => 'GET', 'callback' => function () {
			return rest_ensure_response( array( 'text' => Stealth_Admin::recovery_card() ) );
		}, 'permission_callback' => $wpadmin ) );
		register_rest_route( self::NS, '/stealth/check', array( 'methods' => 'POST', 'callback' => function () {
			return rest_ensure_response( Stealth_Check::run() );
		}, 'permission_callback' => $wpadmin ) );
	}

	public static function save_prefs( \WP_REST_Request $r ) {
		$p = (array) $r->get_json_params();
		if ( isset( $p['theme'] ) ) {
			update_user_meta( get_current_user_id(), 'wpflatcore_theme', in_array( $p['theme'], array( 'dark', 'auto' ), true ) ? $p['theme'] : 'light' );
		}
		return rest_ensure_response( array( 'theme' => Shell::theme() ) );
	}

	public static function save_settings( \WP_REST_Request $r ) {
		$out = Settings::update( (array) $r->get_json_params() );
		Activity::log( 'settings', 0, 0, 'updated', 'Settings updated' );
		return rest_ensure_response( $out );
	}

	/** Send a short test message so the owner knows the connection works before emailing a client. */
	public static function mail_test( \WP_REST_Request $r ) {
		$p  = (array) $r->get_json_params();
		$to = sanitize_email( isset( $p['to'] ) ? (string) $p['to'] : '' );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'wpflatcore_invalid', 'Enter a valid email address.', array( 'status' => 400 ) );
		}
		$m    = Mail_Settings::get_full();
		$via  = 'smtp' === $m['method'] ? 'WPFlat SMTP (' . $m['smtp_host'] . ':' . $m['smtp_port'] . ')' : 'the site mailer (wp_mail)';
		$biz  = (string) Settings::get( 'business_name' );
		$html = '<p>This is a test message from ' . esc_html( $biz ) . '.</p><p>If you can read it, your email settings work. It was sent through ' . esc_html( $via ) . '.</p>';
		$res  = Mailer::send( array( 'to' => array( $to ), 'subject' => ( '' !== Settings::app_name() ? Settings::app_name() : 'WPFlat' ) . ' test email', 'html' => $html, 'text' => 'This is a test message. It was sent through ' . $via . '.' ) );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( 'wpflatcore_mail_failed', $res['error'], array( 'status' => 502 ) );
		}
		Activity::log( 'settings', 0, 0, 'test_email', 'Email test sent to ' . $to );
		return rest_ensure_response( array( 'ok' => true, 'via' => $via, 'to' => $to ) );
	}

	private static function library_row( $id ) {
		$thumb = wp_get_attachment_image_url( $id, 'medium' );
		$url   = wp_get_attachment_url( $id );
		return array(
			'id'    => (int) $id,
			'title' => get_the_title( $id ),
			'url'   => (string) $url,
			'thumb' => $thumb ? $thumb : (string) $url,
			'mime'  => (string) get_post_mime_type( $id ),
			'date'  => get_the_date( 'Y-m-d', $id ),
		);
	}

	/** Images in the WordPress Media Library, newest first. Query: search, page. */
	public static function library_list( \WP_REST_Request $r ) {
		$per = 24;
		$q   = new \WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			's'              => sanitize_text_field( (string) $r->get_param( 'search' ) ),
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) $r->get_param( 'page' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		return rest_ensure_response( array(
			'items'   => array_map( array( __CLASS__, 'library_row' ), $q->posts ),
			'total'   => (int) $q->found_posts,
			'pages'   => (int) $q->max_num_pages,
			'can_add' => current_user_can( 'upload_files' ),
		) );
	}

	/** Add an image to the WordPress Media Library (WordPress's own upload rules and sizes apply). */
	public static function library_upload( \WP_REST_Request $r ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'wpflatcore_forbidden', 'Your account cannot add files to the WordPress Media Library. Choose an image that is already there, or ask an administrator.', array( 'status' => 403 ) );
		}
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'wpflatcore_upload', 'No file was sent.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$ft = wp_check_filetype( (string) $files['file']['name'] );
		if ( 0 !== strpos( (string) $ft['type'], 'image/' ) ) {
			return new \WP_Error( 'wpflatcore_type', 'Choose an image file (PNG, JPG, GIF or WebP).', array( 'status' => 400 ) );
		}
		$_FILES['file'] = $files['file'];
		$id = media_handle_upload( 'file', 0 );
		return is_wp_error( $id ) ? $id : rest_ensure_response( self::library_row( $id ) );
	}

	public static function upload( \WP_REST_Request $r ) {
		$f = isset( $r->get_file_params()['file'] ) ? $r->get_file_params()['file'] : null;
		if ( ! $f ) {
			return new \WP_Error( 'wpflatcore_upload', 'No file was received.', array( 'status' => 400 ) );
		}
		return self::done( Media::store( $f ) );
	}
}
