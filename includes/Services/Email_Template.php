<?php
namespace BizyCore\Services;

use BizyCore\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one look every Bizy email has: your logo (or name) on top, a clean card, buttons in your brand colour and a footer with your
 * business details. Mailer::send() applies it to every email, so modules just write the words. Body HTML is plain paragraphs; a paragraph
 * that holds only a link becomes a button.
 */
class Email_Template {

	/** Present in a finished email so it is never wrapped twice. */
	const MARK = '<!--bizy-branded-->';

	const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	public static function accent() {
		$c = sanitize_hex_color( (string) Settings::get( 'brand_color' ) );
		return $c ? $c : '#222530';
	}

	/** White or near-black text, whichever reads on this colour. */
	private static function on( $hex ) {
		$h = ltrim( $hex, '#' );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		$l = ( 0.299 * hexdec( substr( $h, 0, 2 ) ) + 0.587 * hexdec( substr( $h, 2, 2 ) ) + 0.114 * hexdec( substr( $h, 4, 2 ) ) ) / 255;
		return $l > 0.62 ? '#111827' : '#ffffff';
	}

	private static function logo_url() {
		$s = Settings::get();
		foreach ( array( 'business_logo_email_url', 'business_logo_url' ) as $k ) {
			if ( ! empty( $s[ $k ] ) && ! preg_match( '/\.svg(\?|$)/i', $s[ $k ] ) ) { // email apps do not show SVG
				return (string) $s[ $k ];
			}
		}
		return '';
	}

	/** Give plain paragraphs and links the look; turn a link-only paragraph into a button. */
	private static function style( $html, $accent ) {
		$on   = self::on( $accent );
		$font = self::FONT;
		$html = preg_replace_callback(
			'#<p(?:\s[^>]*)?>\s*(?:<strong>|<b>)?\s*<a\s+([^>]*?)>(.*?)</a>\s*(?:</strong>|</b>)?\s*</p>#is',
			function ( $m ) use ( $accent, $on, $font ) {
				if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $m[1], $h ) ) {
					return $m[0];
				}
				$label = trim( wp_strip_all_tags( $m[2] ) );
				return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 22px"><tr><td style="background:' . $accent . ';border-radius:9px"><a href="' . esc_url( html_entity_decode( $h[1] ) ) . '" style="display:inline-block;padding:13px 26px;font-family:' . $font . ';font-size:15px;font-weight:600;line-height:1.2;color:' . $on . ';text-decoration:none">' . esc_html( $label ) . '</a></td></tr></table>';
			},
			$html
		);
		$html = preg_replace( '#<p(?=[\s>])(?![^>]*style=)([^>]*)>#i', '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:#1f2430"$1>', $html );
		$html = preg_replace( '#<a(?![^>]*style=)(\s[^>]*href=)#i', '<a style="color:' . $accent . ';text-decoration:underline"$1', $html );
		$html = preg_replace( '#<(ul|ol)(?![^>]*style=)([^>]*)>#i', '<$1 style="margin:0 0 16px;padding-left:22px;font-size:15px;line-height:1.65;color:#1f2430"$2>', $html );
		return $html;
	}

	/**
	 * @param string $body Inner HTML (paragraphs).
	 * @param array  $o    title, preheader, note (small print above the footer).
	 */
	public static function wrap( $body, array $o = array() ) {
		if ( false !== strpos( $body, self::MARK ) || preg_match( '/^\s*<(!doctype|html)/i', $body ) ) { // already a finished, designed email (invoices, payslips)
			return $body;
		}
		$s      = Settings::get();
		$biz    = (string) $s['business_name'];
		$accent = self::accent();
		$font   = self::FONT;
		$logo   = self::logo_url();
		$brand  = $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $biz ) . '" height="40" style="display:block;height:40px;width:auto;max-width:220px;border:0;outline:none">' : '<div style="font-family:' . $font . ';font-size:20px;font-weight:700;color:#0e121b">' . esc_html( $biz ) . '</div>';
		$title  = isset( $o['title'] ) ? (string) $o['title'] : '';
		$pre    = isset( $o['preheader'] ) ? (string) $o['preheader'] : '';
		$note   = isset( $o['note'] ) ? (string) $o['note'] : 'If you were not expecting this email, you can safely ignore it.';
		$foot   = array();
		$foot[] = '<strong style="color:#525866">' . esc_html( $biz ) . '</strong>';
		if ( ! empty( $s['business_address'] ) ) {
			$foot[] = nl2br( esc_html( $s['business_address'] ) );
		}
		$contact = array_filter( array( ! empty( $s['business_website'] ) ? '<a href="' . esc_url( $s['business_website'] ) . '" style="color:#868e9d">' . esc_html( preg_replace( '#^https?://#', '', rtrim( $s['business_website'], '/' ) ) ) . '</a>' : '', ! empty( $s['business_email'] ) ? '<a href="mailto:' . esc_attr( $s['business_email'] ) . '" style="color:#868e9d">' . esc_html( $s['business_email'] ) . '</a>' : '', ! empty( $s['business_phone'] ) ? esc_html( $s['business_phone'] ) : '' ) );
		if ( $contact ) {
			$foot[] = implode( ' &nbsp;·&nbsp; ', $contact );
		}
		$h = self::MARK . '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . esc_html( $title ? $title : $biz ) . '</title></head>';
		$h .= '<body style="margin:0;padding:0;background:#f3f5f8">';
		if ( '' !== $pre ) {
			$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f3f5f8">' . esc_html( $pre ) . '</div>';
		}
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f8"><tr><td align="center" style="padding:28px 12px">';
		$h .= '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px">';
		$h .= '<tr><td style="padding:0 6px 18px">' . $brand . '</td></tr>';
		$h .= '<tr><td style="background:#ffffff;border:1px solid #e6e9ef;border-top:4px solid ' . $accent . ';border-radius:14px;padding:34px 34px 26px;font-family:' . $font . ';color:#1f2430">';
		if ( '' !== $title ) {
			$h .= '<h1 style="margin:0 0 18px;font-family:' . $font . ';font-size:22px;line-height:1.3;font-weight:700;color:#0e121b">' . esc_html( $title ) . '</h1>';
		}
		$h .= self::style( $body, $accent );
		$h .= '</td></tr>';
		$h .= '<tr><td style="padding:20px 10px 4px;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#868e9d;text-align:center">';
		if ( '' !== $note ) {
			$h .= '<p style="margin:0 0 10px;color:#868e9d;font-size:12px;line-height:1.6">' . esc_html( $note ) . '</p>';
		}
		$h .= implode( '<br>', $foot ) . '</td></tr>';
		$h .= '</table></td></tr></table></body></html>';
		return $h;
	}
}
