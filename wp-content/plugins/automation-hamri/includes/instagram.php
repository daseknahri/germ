<?php
/**
 * Instagram auto-poster (build-final 8.87.0, ported to build-v9 9.44.0; hidden-photo image hosting 8.87.1). OPT-IN: nothing runs until the owner ticks "Post to Instagram" in
 * Settings → "📸 Instagram posting". It rides on the Facebook Page poster: the same Page token (it needs the
 * instagram_basic + instagram_content_publish permissions and an Instagram professional account linked to the Page),
 * the same posts-per-day window, start date and backlog, and the same 15-minute tick. Its own queue and day counter
 * (`_wpap_igp_done`, `wpap_igp_day`) keep it independent of the Facebook shares.
 *
 * Each post goes up once as a single-image Instagram post: the post's PIN image (else its featured image) made into a
 * JPEG Instagram accepts (1080 wide, between 4:5 and 1.91:1; a taller pin is letterboxed over a blurred copy of
 * itself, never cropped). Caption = the Facebook hook, the pin description, a "link in bio" line and a few hashtags
 * from the post's tags. Instagram captions can't carry clickable links, so no URL is added. Settings live in
 * `wpap_igpage`.
 *
 * @package Automation_Hamri
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const WPAP_IGP_WIDTH       = 1080;
const WPAP_IGP_MIN_RATIO   = 0.8;    /* 4:5, the tallest feed image Instagram accepts */
const WPAP_IGP_MAX_RATIO   = 1.91;   /* the widest */
const WPAP_IGP_CAPTION_MAX = 2200;
const WPAP_IGP_POLLS       = 6;      /* container status checks, 3 s apart */
const WPAP_IGP_MAX_PIXELS  = 40000000;   /* refuse to decode bigger images (memory guard, as the FB card code) */

function wpap_igp_opts() {
	$o = get_option( 'wpap_igpage', array() );
	$o = is_array( $o ) ? $o : array();
	return array(
		'enabled'     => ! empty( $o['enabled'] ),
		'ig_id'       => preg_replace( '/\D/', '', (string) ( $o['ig_id'] ?? '' ) ),
		'ig_user'     => (string) ( $o['ig_user'] ?? '' ),
		'caption_end' => (string) ( $o['caption_end'] ?? 'Full recipe: tap the link in our bio.' ),
		'hashtags'    => max( 0, min( 10, (int) ( $o['hashtags'] ?? 5 ) ) ),
	);
}

/* Called from the main settings save handler (same nonce + capability check), after the Facebook settings. */
function wpap_igp_save_from_post() {
	update_option( 'wpap_igpage', array(
		'enabled'     => isset( $_POST['wpap_igp_enabled'] ) ? 1 : 0,                                                    // phpcs:ignore WordPress.Security.NonceVerification -- verified by the caller
		'ig_id'       => '',   /* re-read from the Page on the next post, in case the Page or token changed */
		'ig_user'     => '',
		'caption_end' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['wpap_igp_caption_end'] ?? '' ) ), 0, 300 ),   // phpcs:ignore WordPress.Security.NonceVerification
		'hashtags'    => (int) ( $_POST['wpap_igp_hashtags'] ?? 5 ),                                                     // phpcs:ignore WordPress.Security.NonceVerification
	), false );
	delete_option( 'wpap_igp_paused' );   /* saving (e.g. after fixing the token or the link) resumes Instagram */
	delete_transient( 'wpap_igp_backoff' );
	wpap_fbp_schedule( wpap_fbp_cron_wanted() );
}

/* The Instagram account linked to the Page (read once from the Graph API, then cached in the settings). */
function wpap_igp_account( $o ) {
	$io = wpap_igp_opts();
	if ( '' !== $io['ig_id'] ) { return $io['ig_id']; }
	$r = wpap_fbp_call( $o['page_id'], array( 'fields' => 'instagram_business_account{id,username}' ), $o['token'], 'GET' );
	if ( is_wp_error( $r ) ) { return $r; }
	$id = preg_replace( '/\D/', '', (string) ( $r['instagram_business_account']['id'] ?? '' ) );
	if ( '' === $id ) {
		return new WP_Error( 'igp_config', 'No Instagram professional account is linked to the Page (Page settings → Linked accounts), or the token lacks instagram_basic.' );
	}
	$raw            = (array) get_option( 'wpap_igpage', array() );
	$raw['ig_id']   = $id;
	$raw['ig_user'] = sanitize_text_field( (string) ( $r['instagram_business_account']['username'] ?? '' ) );
	update_option( 'wpap_igpage', $raw, false );
	return $id;
}

/* ── Tick (called from the Facebook poster's tick) ────────────────────────────────────────────────────────── */

/* Instagram also waits out a Facebook back-off: rate limits count against the app, user and Page, and both
   channels use the same Page token. */
function wpap_igp_ready( $o ) {
	return wpap_igp_opts()['enabled'] && '' !== $o['page_id'] && '' !== $o['token']
		&& ! get_option( 'wpap_igp_paused' ) && ! get_transient( 'wpap_igp_backoff' ) && ! get_transient( 'wpap_fbp_backoff' );
}

function wpap_igp_tick( $o ) {
	if ( ! wpap_igp_ready( $o ) ) { return; }
	wpap_igp_sweep();
	if ( wpap_fbp_shares_today( 'wpap_igp_day' ) >= wpap_fbp_slots_due( $o ) ) { return; }
	$pid = wpap_fbp_next_post( $o, '_wpap_igp_done' );
	if ( $pid ) { wpap_igp_share( $pid, $o ); }
}

/* ── Posting one post ─────────────────────────────────────────────────────────────────────────────────────── */

/* Post one post. The post is CLAIMED first (add_post_meta … unique), so a double click or a click during the cron
   tick can never post it twice. Error kinds (see wpap_fbp_share) with two Instagram twists: a setup problem
   (igp_config: no linked account, no GD, no upload dir) pauses Instagram instead of failing every post in turn, and a
   network error before media_publish is retried normally, since nothing can be live yet. */
function wpap_igp_share( $pid, $o ) {
	$pid = (int) $pid;
	if ( ! add_post_meta( $pid, '_wpap_igp_done', 'pending', true ) ) {
		return new WP_Error( 'igp_busy', 'This post is already being posted to Instagram.' );
	}
	$ig = wpap_igp_account( $o );
	if ( is_wp_error( $ig ) ) { return wpap_igp_fail( $pid, $ig, false ); }
	$img = wpap_igp_image( $pid );
	if ( is_wp_error( $img ) ) { return wpap_igp_fail( $pid, $img, false ); }
	$hosted = wpap_igp_hosted_url( $o, $img['path'] );
	$url    = '' !== $hosted['url'] ? $hosted['url'] : $img['url'];
	$res    = wpap_igp_publish( $ig, $url, wpap_igp_caption( $pid ), $o['token'] );
	wp_delete_file( $img['path'] );   /* Instagram has its own copy by now */
	if ( '' !== $hosted['photo_id'] ) { wpap_fbp_call( $hosted['photo_id'], array(), $o['token'], 'DELETE' ); }
	if ( is_wp_error( $res ) ) { return wpap_igp_fail( $pid, $res, ! empty( $res->get_error_data()['publishing'] ) ); }
	wpap_fbp_count_share( 'wpap_igp_day' );
	update_post_meta( $pid, '_wpap_igp_done', time() );
	update_post_meta( $pid, '_wpap_igp_media_id', $res['media_id'] );
	wpap_fbp_log( $pid, 'ok', 'Instagram: posted' );
	return $res;
}

/* Instagram downloads the image itself, and some hosts refuse Meta's servers ("Media download has failed", code 9004;
   seen on kitchen). So the JPEG is first uploaded to the Page as an UNPUBLISHED photo — a direct file upload, which
   always works — and Instagram gets Facebook's own copy (the largest "images" entry, on Facebook's image servers).
   The hidden photo is deleted after posting. Returns array( 'url', 'photo_id' ); both '' when this fails, and the
   caller then falls back to the site's own image URL. */
function wpap_igp_hosted_url( $o, $file ) {
	$none = array( 'url' => '', 'photo_id' => '' );
	$up   = wpap_fbp_upload( $o['page_id'] . '/photos', array( 'published' => 'false' ), $file, $o['token'] );
	$pid  = is_wp_error( $up ) ? '' : preg_replace( '/\D/', '', (string) ( $up['id'] ?? '' ) );
	if ( '' === $pid ) { return $none; }
	$info = wpap_fbp_call( $pid, array( 'fields' => 'images' ), $o['token'], 'GET' );
	$best = '';
	$w    = 0;
	foreach ( ( is_wp_error( $info ) ? array() : (array) ( $info['images'] ?? array() ) ) as $im ) {
		if ( (int) ( $im['width'] ?? 0 ) > $w && wp_http_validate_url( (string) ( $im['source'] ?? '' ) ) ) {
			$w    = (int) $im['width'];
			$best = (string) $im['source'];
		}
	}
	if ( '' === $best ) {
		wpap_fbp_call( $pid, array(), $o['token'], 'DELETE' );
		return $none;
	}
	return array( 'url' => $best, 'photo_id' => $pid );
}

/* Route an Instagram error. $publishing = the error came from media_publish, the only call that can make a post live. */
function wpap_igp_fail( $pid, WP_Error $err, $publishing ) {
	if ( 'igp_config' === $err->get_error_code() ) {
		delete_post_meta( $pid, '_wpap_igp_done' );
		update_option( 'wpap_igp_paused', $err->get_error_message(), false );   /* shown in the panel; Save resumes */
		wpap_fbp_log( $pid, 'error', 'Instagram paused: ' . $err->get_error_message() );
		return $err;
	}
	if ( 'fbp_network' === $err->get_error_code() && ! $publishing ) {
		$err = new WP_Error( 'fbp_api', $err->get_error_message() );   /* nothing is live yet: an ordinary retry */
	}
	return wpap_fbp_fail( $pid, $err, 'igp', 'Instagram' );
}

/* Delete temp images left behind by a request that died before cleaning up (older than a day). */
function wpap_igp_sweep() {
	foreach ( (array) glob( wp_get_upload_dir()['basedir'] . '/wpap-ig/*.jpg' ) as $f ) {
		if ( is_string( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) { wp_delete_file( $f ); }
	}
}

/* Instagram's two-step publish: create a media container from a public image URL, wait until Instagram has
   fetched and processed it, then publish it. */
function wpap_igp_publish( $ig, $url, $caption, $token ) {
	$c = wpap_fbp_call( $ig . '/media', array( 'image_url' => $url, 'caption' => $caption ), $token );
	if ( is_wp_error( $c ) ) { return $c; }
	$cid = preg_replace( '/\D/', '', (string) ( $c['id'] ?? '' ) );
	if ( '' === $cid ) { return new WP_Error( 'fbp_api', 'Instagram did not return a media container.' ); }
	for ( $i = 0; $i < WPAP_IGP_POLLS; $i++ ) {
		$st     = wpap_fbp_call( $cid, array( 'fields' => 'status_code' ), $token, 'GET' );
		$status = is_wp_error( $st ) ? '' : (string) ( $st['status_code'] ?? '' );
		if ( 'FINISHED' === $status ) { break; }
		if ( 'ERROR' === $status || 'EXPIRED' === $status ) { return new WP_Error( 'fbp_api', 'Instagram could not process the image (' . $status . ').' ); }
		if ( $i < WPAP_IGP_POLLS - 1 ) { sleep( 3 ); }
	}
	$p = wpap_fbp_call( $ig . '/media_publish', array( 'creation_id' => $cid ), $token );
	if ( is_wp_error( $p ) ) { $p->add_data( array( 'publishing' => true ) ); return $p; }
	return array( 'media_id' => (string) ( $p['id'] ?? '' ) );
}

/* Caption: the hook, the "link in bio" line, then hashtags from the post's tags and keywords. */
function wpap_igp_caption( $pid ) {
	$io    = wpap_igp_opts();
	$clean = function ( $t ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' );
		$t = preg_replace( '#https?://\S+#i', '', str_replace( '{{link}}', '', $t ) );
		return trim( (string) preg_replace( '/[ \t]+/u', ' ', (string) $t ) );
	};
	$hook = $clean( get_post_meta( $pid, '_wpap_fb_hook', true ) );
	if ( '' === $hook ) { $hook = $clean( get_the_title( $pid ) ); }
	$tags  = array();
	if ( $io['hashtags'] > 0 ) {
		$post_tags = get_the_tags( $pid );
		$words     = is_array( $post_tags ) ? wp_list_pluck( $post_tags, 'name' ) : array();
		$words = array_merge( $words, explode( ',', (string) get_post_meta( $pid, '_wpap_keywords', true ) ) );
		foreach ( $words as $w ) {
			$tag = strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', html_entity_decode( (string) $w, ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $tag && mb_strlen( $tag ) <= 30 && ! in_array( '#' . $tag, $tags, true ) ) { $tags[] = '#' . $tag; }
			if ( count( $tags ) >= $io['hashtags'] ) { break; }
		}
	}
	$parts = array( $hook, $clean( $io['caption_end'] ), implode( ' ', $tags ) );   /* Instagram = the image + the hook, always */
	return mb_substr( implode( "\n\n", array_values( array_filter( $parts, 'strlen' ) ) ), 0, WPAP_IGP_CAPTION_MAX );
}

/* A local file for one image URL on this site (attachment or a path under uploads), else ''. */
function wpap_igp_file_for_url( $url ) {
	$url = (string) $url;
	if ( '' === $url ) { return ''; }
	$att = attachment_url_to_postid( $url );
	if ( $att ) {
		$f = (string) get_attached_file( $att );
		if ( '' !== $f && is_readable( $f ) ) { return $f; }
	}
	$up = wp_get_upload_dir();
	if ( 0 === strpos( $url, $up['baseurl'] . '/' ) ) {
		$f = $up['basedir'] . '/' . substr( $url, strlen( $up['baseurl'] ) + 1 );
		if ( false === strpos( $f, '..' ) && is_readable( $f ) ) { return $f; }
	}
	return '';
}

/* The image to post: the pin image when it is a file on this site, else (build-v9) the Facebook card image, else the
   featured image. */
function wpap_igp_source_file( $pid ) {
	/* the same portrait card Facebook posts (4:5 suits the feed), else the blog's featured image */
	$f = wpap_igp_file_for_url( (string) get_post_meta( $pid, '_wpap_fb_image_url', true ) );
	if ( '' !== $f ) { return $f; }
	return wpap_fbp_image_file( (int) get_post_thumbnail_id( $pid ) );
}

/* Build the public JPEG Instagram fetches: 1080 wide, ratio clamped to 4:5–1.91:1; a source outside that range is
   contained (never cropped) over a blurred, zoomed copy of itself. Written to uploads/wpap-ig/, deleted after posting. */
function wpap_igp_image( $pid ) {
	$src_file = wpap_igp_source_file( $pid );
	if ( '' === $src_file ) { return new WP_Error( 'fbp_api', 'This post has no image on the site to post.' ); }
	if ( ! function_exists( 'imagecreatetruecolor' ) ) { return new WP_Error( 'igp_config', 'Instagram posting needs the PHP GD image library.' ); }
	$info = @getimagesize( $src_file );
	if ( ! $info || $info[0] * $info[1] > WPAP_IGP_MAX_PIXELS ) { return new WP_Error( 'fbp_api', 'The post image is missing, unreadable or too large to convert.' ); }
	$src = @imagecreatefromstring( (string) file_get_contents( $src_file ) );   // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file
	if ( ! $src ) { return new WP_Error( 'fbp_api', 'The post image could not be read.' ); }
	$sw = imagesx( $src );
	$sh = imagesy( $src );
	$r  = max( WPAP_IGP_MIN_RATIO, min( WPAP_IGP_MAX_RATIO, $sw / $sh ) );
	$cw = WPAP_IGP_WIDTH;
	$ch = (int) round( $cw / $r );
	$im = imagecreatetruecolor( $cw, $ch );

	/* background: the whole image squeezed small, blurred, stretched to fill (only visible when letterboxed) */
	$tiny = imagecreatetruecolor( 108, max( 1, (int) round( 108 / $r ) ) );
	imagecopyresampled( $tiny, $src, 0, 0, 0, 0, imagesx( $tiny ), imagesy( $tiny ), $sw, $sh );
	if ( function_exists( 'imagefilter' ) ) { for ( $i = 0; $i < 8; $i++ ) { imagefilter( $tiny, IMG_FILTER_GAUSSIAN_BLUR ); } }
	imagecopyresampled( $im, $tiny, 0, 0, 0, 0, $cw, $ch, imagesx( $tiny ), imagesy( $tiny ) );
	imagedestroy( $tiny );
	/* smooth the blocks the upscale leaves (only when letterboxed; a full-bleed image covers the background anyway) */
	if ( function_exists( 'imagefilter' ) && abs( $r - $sw / $sh ) > 0.001 ) {
		for ( $i = 0; $i < 12; $i++ ) { imagefilter( $im, IMG_FILTER_GAUSSIAN_BLUR ); }
	}

	/* foreground: the whole image, contained and centred */
	$k  = min( $cw / $sw, $ch / $sh );
	$fw = (int) round( $sw * $k );
	$fh = (int) round( $sh * $k );
	imagecopyresampled( $im, $src, (int) ( ( $cw - $fw ) / 2 ), (int) ( ( $ch - $fh ) / 2 ), 0, 0, $fw, $fh, $sw, $sh );
	imagedestroy( $src );

	$up  = wp_get_upload_dir();
	$dir = $up['basedir'] . '/wpap-ig';
	if ( ! wp_mkdir_p( $dir ) ) { imagedestroy( $im ); return new WP_Error( 'igp_config', 'Could not create uploads/wpap-ig.' ); }
	$name = $pid . '-' . time() . '.jpg';
	$ok   = imagejpeg( $im, $dir . '/' . $name, 90 );
	imagedestroy( $im );
	if ( ! $ok ) { return new WP_Error( 'fbp_api', 'Could not write the Instagram image.' ); }
	return array( 'path' => $dir . '/' . $name, 'url' => $up['baseurl'] . '/wpap-ig/' . $name );
}

/* ── Admin: post the next one now ─────────────────────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_wpap_igp_share_now', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
	check_ajax_referer( 'wpap_fbp', 'nonce' );
	$o = wpap_fbp_opts();
	if ( '' === $o['page_id'] || '' === $o['token'] ) { wp_send_json_error( 'Connect the Facebook Page first (Page ID + token).' ); }
	$pid = wpap_fbp_next_post( $o, '_wpap_igp_done' );
	if ( ! $pid ) { wp_send_json_error( 'Nothing waiting to be posted.' ); }
	$r = wpap_igp_share( $pid, $o );
	if ( is_wp_error( $r ) ) { wp_send_json_error( $r->get_error_message() ); }
	$io = wpap_igp_opts();
	wp_send_json_success( 'Posted "' . get_the_title( $pid ) . '" to Instagram' . ( '' !== $io['ig_user'] ? ' @' . $io['ig_user'] : '' ) . '.' );
} );

function wpap_igp_render_settings() {
	$o  = wpap_fbp_opts();
	$io = wpap_igp_opts();
	$done = wpap_fbp_shares_today( 'wpap_igp_day' );
	?>
	<details class="wpap-group" id="wpap-grp-instagram">
		<summary><span class="wpap-g-title">📸 Instagram posting</span> <span class="wpap-g-sub">&mdash; post each new recipe's pin to the Instagram account linked to your Page (opt-in)</span></summary>
		<div class="wpap-group-body">
		<?php $ig_paused = (string) get_option( 'wpap_igp_paused', '' ); if ( '' !== $ig_paused ) : ?>
			<div class="notice notice-error inline"><p><strong>Instagram paused.</strong> <?php echo esc_html( $ig_paused ); ?> Fix the token or the Page link, then Save.</p></div>
		<?php endif; ?>
		<table class="form-table">
			<tr>
				<th scope="row">Post to Instagram <?php echo wpap_help_tip( 'igp_enabled' ); ?></th>
				<td><label><input type="checkbox" name="wpap_igp_enabled" value="1" <?php checked( $io['enabled'] ); ?> /> <strong>Post new recipes to Instagram automatically</strong></label>
					<p class="description">Uses the Facebook Page connection and schedule above. <?php echo '' !== $io['ig_user'] ? 'Account: <strong>@' . esc_html( $io['ig_user'] ) . '</strong>. ' : ''; ?>
					<?php echo esc_html( $done . ' of ' . $o['per_day'] . ' posted today.' ); ?> Waiting to be posted: <strong><?php echo (int) wpap_fbp_queue_count( $o, '_wpap_igp_done' ); ?></strong>.</p></td>
			</tr>
			<tr>
				<th scope="row">Caption ending <?php echo wpap_help_tip( 'igp_caption' ); ?></th>
				<td><input type="text" name="wpap_igp_caption_end" class="large-text" maxlength="300" value="<?php echo esc_attr( $io['caption_end'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row">Hashtags <?php echo wpap_help_tip( 'igp_hashtags' ); ?></th>
				<td><label><input type="number" name="wpap_igp_hashtags" min="0" max="10" class="small-text" value="<?php echo esc_attr( (string) $io['hashtags'] ); ?>" /> from the post's tags (0 = none)</label></td>
			</tr>
			<tr>
				<th scope="row">Check it <?php echo wpap_help_tip( 'igp_now' ); ?></th>
				<td><button type="button" class="button" data-wpap-fbp="wpap_igp_share_now">Post the next one to Instagram now</button>
					<p class="description">Save your settings first.</p></td>
			</tr>
		</table>
		</div>
	</details>
	<?php
}

add_filter( 'wpap_help_tips_extra', function ( $t ) {
	return array_merge( (array) $t, array(
		'igp_enabled'  => '<strong>Posts each new recipe to Instagram by itself</strong>, on the same schedule as the Facebook Page (its own queue, so both get every post).<br><strong>The image</strong> is the recipe\'s Pinterest pin (else its featured image), turned into a 1080-wide JPEG Instagram accepts; a tall pin sits on a soft blurred background so nothing is cut off.<br><strong>Needs:</strong> an Instagram professional account linked to your Facebook Page (Page settings &rarr; Linked accounts), and a token with <code>instagram_basic</code> + <code>instagram_content_publish</code>.<br><em>Default: OFF.</em>',
		'igp_caption'  => '<strong>The line after the description.</strong> Instagram captions can\'t hold clickable links, so point people to the link in your bio.<br><em>Default:</em> Full recipe: tap the link in our bio.',
		'igp_hashtags' => '<strong>How many hashtags</strong> to add, made from the post\'s tags and keywords (e.g. <em>slow cooker recipes</em> &rarr; <code>#slowcookerrecipes</code>). A few relevant ones help people find the post; many look spammy.<br><em>Default: 5.</em> 0 = none.',
		'igp_now'      => '<strong>Posts the next waiting recipe to Instagram straight away</strong> (it counts toward today\'s posts). Handy to check the image and caption once.',
	) );
} );
