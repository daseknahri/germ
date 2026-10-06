<?php
/**
 * Full-article Facebook posts (9.45.0) — the article as plain text, for the two "full article" Facebook post styles
 * (Settings → Facebook Page posting → Post style): in the post text, or in the first comment with the link.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const WPAP_SOCIAL_FB_MAX      = 60000;   /* Facebook post text limit is ~63k */
const WPAP_SOCIAL_COMMENT_MAX = 7900;    /* Facebook comments stop at 8,000 characters */

/* The article as plain text with paragraph breaks (no HTML, shortcodes, page breaks or link tokens). */
function wpap_social_plain_text( $pid ) {
	$html = (string) get_post_field( 'post_content', $pid );
	$html = str_replace( array( '<!--nextpage-->', '[nextpage]' ), "\n\n", $html );
	$html = preg_replace( '/\[\[link:[^\]]*\]\]/', '', strip_shortcodes( $html ) );
	$html = preg_replace( '#<(script|style|figure|figcaption)\b[^>]*>.*?</\1>#is', '', (string) $html );
	$html = preg_replace( '#<li\b[^>]*>#i', "\n• ", (string) $html );
	$html = preg_replace( '#<br\s*/?>|</(p|h[1-6]|li|blockquote|div|ul|ol)>#i', "\n\n", (string) $html );
	$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/[ \t]+/u', ' ', (string) $text );
	$text = preg_replace( '/ *\n */u', "\n", (string) $text );
	return trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) $text ) );
}

/* Cut text to $max characters at the last sentence end (else word), adding "…" when it was shortened. */
function wpap_social_fit( $text, $max ) {
	if ( $max < 20 ) { return ''; }
	if ( mb_strlen( $text ) <= $max ) { return $text; }
	$cut = mb_substr( $text, 0, $max - 1 );
	$end = max( (int) mb_strrpos( $cut, '. ' ), (int) mb_strrpos( $cut, ".\n" ), (int) mb_strrpos( $cut, '? ' ), (int) mb_strrpos( $cut, '! ' ) );
	if ( $end > $max * 0.5 ) { return mb_substr( $cut, 0, $end + 1 ) . ' …'; }
	$sp = (int) mb_strrpos( $cut, ' ' );
	return rtrim( mb_substr( $cut, 0, $sp > 0 ? $sp : $max - 1 ) ) . '…';
}

/* Post text for "image + hook + full article": the hook, then the whole article. */
function wpap_social_fb_full_caption( $pid, $hook ) {
	$body = wpap_social_plain_text( $pid );
	return '' === $body ? $hook : wpap_social_fit( $hook . "\n\n" . $body, WPAP_SOCIAL_FB_MAX );
}

/* First comment for "full article in the comment": the article (fitted to the comment limit), then the usual link comment. */
function wpap_social_fb_full_comment( $pid, $link_comment ) {
	$body = wpap_social_fit( wpap_social_plain_text( $pid ), WPAP_SOCIAL_COMMENT_MAX - mb_strlen( $link_comment ) - 2 );
	return '' === $body ? $link_comment : $body . "\n\n" . $link_comment;
}
