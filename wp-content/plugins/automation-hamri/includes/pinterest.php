<?php
/**
 * Pinterest distribution (build-final 8.82.0, ported to build-v9 9.44.0) — the Pinterest twin of the Facebook poster rows. OPT-IN: nothing is output until
 * the owner switches it on in Settings → Publishing & reader options → "Pinterest pins".
 *
 * Per post it derives a pin: title (≤100), description (≤500), link (+ optional UTM), board (category → board map),
 * keywords (tags), alt text and an IMAGE WITH FALLBACKS (pin image → featured → external image → first content
 * image → site default). Two ways out:
 *   1. an RSS feed Pinterest auto-publishes from (/feed/pinterest/, and per category /category/<slug>/feed/pinterest/)
 *   2. a CSV in Pinterest's bulk-upload format (200 pins per file), optionally scheduled N pins per day.
 * Settings live in the `wpap_pinterest` option.
 *
 * @package Automation_Hamri
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── build-v9 ports (guarded: build-final defines these in its main file) ──────────────────────────────── */

/* A shareable image URL: http(s) with a dotted host (rejects bare words / localhost). */
if ( ! function_exists( 'wpap_is_usable_image_url' ) ) {
	function wpap_is_usable_image_url( $url ) {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url ) { return false; }
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean ) { return false; }
		$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $clean, PHP_URL_HOST );
		return ( in_array( $scheme, array( 'http', 'https' ), true ) && false !== strpos( $host, '.' ) );
	}
}

const WPAP_PIN_TITLE_MAX = 100;   /* Pinterest limits (help.pinterest.com bulk-upload + pin builder) */
const WPAP_PIN_DESC_MAX  = 500;
const WPAP_PIN_CSV_ROWS  = 200;   /* max pins per bulk-upload file */
const WPAP_PIN_FEED_ITEMS = 50;    /* default items per feed (setting: 10–500) */

/* ── Settings ─────────────────────────────────────────────────────────────────────────────────────────────── */

function wpap_pin_opts() {
	$o = get_option( 'wpap_pinterest', array() );
	$o = is_array( $o ) ? $o : array();
	return array(
		'feed'          => ! empty( $o['feed'] ),
		'default_board' => (string) ( $o['default_board'] ?? '' ),
		'board_map'     => (string) ( $o['board_map'] ?? '' ),
		'default_image' => (string) ( $o['default_image'] ?? '' ),
		'utm'           => ! isset( $o['utm'] ) || ! empty( $o['utm'] ),
		'per_day'       => max( 0, min( 50, (int) ( $o['per_day'] ?? 0 ) ) ),
		'feed_items'    => max( 10, min( 500, (int) ( $o['feed_items'] ?? WPAP_PIN_FEED_ITEMS ) ) ),
	);
}

/* Sanitise a raw settings array (form POST or settings import) into the stored shape. */
function wpap_pin_sanitize( $raw ) {
	$raw = is_array( $raw ) ? $raw : array();
	$img = esc_url_raw( trim( (string) ( $raw['default_image'] ?? '' ) ) );
	return array(
		'feed'          => ! empty( $raw['feed'] ) ? 1 : 0,
		'default_board' => mb_substr( sanitize_text_field( (string) ( $raw['default_board'] ?? '' ) ), 0, 100 ),
		'board_map'     => mb_substr( sanitize_textarea_field( (string) ( $raw['board_map'] ?? '' ) ), 0, 4000 ),
		'default_image' => ( '' !== $img && wpap_is_usable_image_url( $img ) ) ? $img : '',
		'utm'           => ! empty( $raw['utm'] ) ? 1 : 0,
		'per_day'       => max( 0, min( 50, (int) ( $raw['per_day'] ?? 0 ) ) ),
		'feed_items'    => max( 10, min( 500, (int) ( $raw['feed_items'] ?? WPAP_PIN_FEED_ITEMS ) ) ),
	);
}

/* Called from the main settings save handler (same nonce + capability check). */
function wpap_pin_save_from_post() {
	$before = wpap_pin_opts();
	$new    = wpap_pin_sanitize( array(
		'feed'          => isset( $_POST['wpap_pin_feed'] ),                                    // phpcs:ignore WordPress.Security.NonceVerification -- verified by the caller
		'default_board' => wp_unslash( $_POST['wpap_pin_default_board'] ?? '' ),                // phpcs:ignore WordPress.Security.NonceVerification
		'board_map'     => wp_unslash( $_POST['wpap_pin_board_map'] ?? '' ),                    // phpcs:ignore WordPress.Security.NonceVerification
		'default_image' => wp_unslash( $_POST['wpap_pin_default_image'] ?? '' ),                // phpcs:ignore WordPress.Security.NonceVerification
		'utm'           => isset( $_POST['wpap_pin_utm'] ),                                     // phpcs:ignore WordPress.Security.NonceVerification
		'per_day'       => wp_unslash( $_POST['wpap_pin_per_day'] ?? 0 ),                       // phpcs:ignore WordPress.Security.NonceVerification
		'feed_items'    => wp_unslash( $_POST['wpap_pin_feed_items'] ?? WPAP_PIN_FEED_ITEMS ),   // phpcs:ignore WordPress.Security.NonceVerification
	) );
	update_option( 'wpap_pinterest', $new, false );
	if ( (bool) $new['feed'] !== (bool) $before['feed'] ) {
		update_option( 'wpap_pin_flush_rules', 1, false );   /* /feed/pinterest/ needs a rewrite refresh */
	}
}

/* ── Pin data for one post ────────────────────────────────────────────────────────────────────────────────── */

function wpap_pin_clean_text( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '#https?://\S+#i', '', $text );   /* no raw URLs in pin text — the Link field carries it */
	$text = str_replace( '{{link}}', '', $text );
	return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
}

/* Trim to $max characters on a word boundary, with an ellipsis when cut. */
function wpap_pin_trim( $text, $max ) {
	$text = (string) $text;
	if ( mb_strlen( $text ) <= $max ) { return $text; }
	$cut   = mb_substr( $text, 0, $max - 1 );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > $max * 0.6 ) { $cut = mb_substr( $cut, 0, $space ); }
	return rtrim( $cut, " ,.;:-" ) . '…';
}

/* Image fallback chain: dedicated pin image → Facebook card image (build-v9) → featured image → external image → first content image → site default. */
function wpap_pin_image_url( $pid ) {
	$pid        = (int) $pid;
	$candidates = array(
		(string) get_post_meta( $pid, '_wpap_pin_image', true ),
		(string) get_post_meta( $pid, '_wpap_fb_image_url', true ),   /* build-v9: the portrait Facebook card suits a pin */
		(string) get_the_post_thumbnail_url( $pid, 'full' ),
		(string) get_post_meta( $pid, '_wpap_image_url', true ),
	);
	$content = (string) get_post_field( 'post_content', $pid );
	if ( preg_match( '#<img[^>]+src=["\']([^"\']+)["\']#i', $content, $m ) ) { $candidates[] = $m[1]; }
	$candidates[] = wpap_pin_opts()['default_image'];
	foreach ( $candidates as $url ) {
		$url = trim( $url );
		if ( '' !== $url && wpap_is_usable_image_url( $url ) ) { return $url; }
	}
	return '';
}

/* "category-slug = Board name" lines → board for this post; else the default board; else the primary category name. */
function wpap_pin_board( $pid ) {
	$opts = wpap_pin_opts();
	$cats = get_the_category( (int) $pid );
	$map  = array();
	foreach ( preg_split( '/\r?\n/', $opts['board_map'] ) as $line ) {
		if ( false === strpos( $line, '=' ) ) { continue; }
		list( $k, $v ) = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( '' !== $k && '' !== $v ) { $map[ strtolower( $k ) ] = $v; }
	}
	foreach ( (array) $cats as $c ) {
		if ( isset( $map[ strtolower( $c->slug ) ] ) ) { return $map[ strtolower( $c->slug ) ]; }
		if ( isset( $map[ strtolower( $c->name ) ] ) ) { return $map[ strtolower( $c->name ) ]; }
	}
	if ( '' !== $opts['default_board'] ) { return $opts['default_board']; }
	return ! empty( $cats ) ? html_entity_decode( $cats[0]->name, ENT_QUOTES, 'UTF-8' ) : get_bloginfo( 'name' );
}

function wpap_pin_link( $pid ) {
	$link = (string) wpap_public_permalink( (int) $pid );
	if ( '' === $link || ! wpap_pin_opts()['utm'] ) { return $link; }
	return add_query_arg( array(
		'utm_source'   => 'pinterest',
		'utm_medium'   => 'social',
		'utm_campaign' => (string) get_post_field( 'post_name', (int) $pid ),
	), $link );
}

/* The full pin for one published post, or null when it can't make a valid pin (no image or no link). */
function wpap_pin_for_post( $pid ) {
	$pid  = (int) $pid;
	$post = get_post( $pid );
	if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) { return null; }

	$img  = wpap_pin_image_url( $pid );
	$link = wpap_pin_link( $pid );
	if ( '' === $img || '' === $link ) { return null; }

	$title = (string) get_post_meta( $pid, '_wpap_pin_title', true );
	if ( '' === trim( $title ) ) { $title = get_the_title( $pid ); }
	$title = wpap_pin_trim( wpap_pin_clean_text( $title ), WPAP_PIN_TITLE_MAX );

	$desc = '';
	foreach ( array( '_wpap_pin_description', '_wpap_fb_hook' ) as $key ) {
		$desc = wpap_pin_clean_text( get_post_meta( $pid, $key, true ) );
		if ( '' !== $desc ) { break; }
	}
	if ( '' === $desc && has_excerpt( $pid ) ) { $desc = wpap_pin_clean_text( get_the_excerpt( $pid ) ); }
	if ( '' === $desc ) { $desc = wpap_pin_clean_text( wp_trim_words( strip_shortcodes( $post->post_content ), 60, '' ) ); }
	$desc = wpap_pin_trim( $desc, WPAP_PIN_DESC_MAX );

	$kw = array();
	foreach ( (array) get_the_tags( $pid ) as $t ) { if ( isset( $t->name ) ) { $kw[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ); } }
	foreach ( explode( ',', (string) get_post_meta( $pid, '_wpap_keywords', true ) ) as $k ) { $kw[] = trim( $k ); }
	$kw = array_slice( array_values( array_unique( array_filter( array_map( 'trim', $kw ), 'strlen' ) ) ), 0, 10 );

	$alt   = '';
	$thumb = (int) get_post_thumbnail_id( $pid );
	if ( $thumb ) { $alt = trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ); }
	if ( '' === $alt ) { $alt = $title; }

	return array(
		'id'          => $pid,
		'title'       => $title,
		'description' => $desc,
		'link'        => $link,
		'image'       => $img,
		'board'       => wpap_pin_board( $pid ),
		'keywords'    => $kw,
		'alt'         => wpap_pin_trim( wpap_pin_clean_text( $alt ), 500 ),
		'date'        => get_post_time( 'U', true, $pid ),
	);
}

/* ── 1. RSS feed Pinterest auto-publishes from ────────────────────────────────────────────────────────────── */

add_action( 'init', function () {
	if ( ! wpap_pin_opts()['feed'] ) { return; }
	add_feed( 'pinterest', 'wpap_pin_render_feed' );
	if ( get_option( 'wpap_pin_flush_rules' ) ) {
		delete_option( 'wpap_pin_flush_rules' );
		flush_rewrite_rules( false );
	}
}, 20 );

/* Also refresh rules once when the feed is switched OFF, so /feed/pinterest/ stops resolving. */
add_action( 'init', function () {
	if ( wpap_pin_opts()['feed'] || ! get_option( 'wpap_pin_flush_rules' ) ) { return; }
	delete_option( 'wpap_pin_flush_rules' );
	flush_rewrite_rules( false );
}, 21 );

function wpap_pin_feed_url( $cat_slug = '' ) {
	if ( '' === $cat_slug ) { return get_feed_link( 'pinterest' ); }
	$term = get_category_by_slug( $cat_slug );
	return $term ? get_category_feed_link( $term->term_id, 'pinterest' ) : '';
}

function wpap_pin_render_feed() {
	/* Full post objects (not 'ids') so WP primes the post, meta and term caches in a few queries; the featured-image
	   attachments are primed too. Keeps a 500-item feed to a handful of queries instead of ~6 per post. */
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => wpap_pin_opts()['feed_items'],
		'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
		'update_post_meta_cache' => true, 'update_post_term_cache' => true );
	$scope = get_bloginfo( 'name' );
	if ( is_category() ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->term_id ) ) { $args['cat'] = (int) $term->term_id; $scope .= ' – ' . $term->name; }
	}
	$feed_q = new WP_Query( $args );
	update_post_thumbnail_cache( $feed_q );
	$ids = wp_list_pluck( $feed_q->posts, 'ID' );

	header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
	header( 'X-Robots-Tag: noindex', true );
	echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
	echo '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n";
	echo '<title>' . esc_html( $scope ) . "</title>\n";
	echo '<link>' . esc_url( home_url( '/' ) ) . "</link>\n";
	echo '<description>' . esc_html( get_bloginfo( 'description' ) ) . "</description>\n";
	echo '<language>' . esc_html( get_bloginfo( 'language' ) ) . "</language>\n";
	echo '<atom:link href="' . esc_url( get_self_link() ) . '" rel="self" type="application/rss+xml" />' . "\n";
	foreach ( $ids as $id ) {
		$pin = wpap_pin_for_post( $id );
		if ( ! $pin ) { continue; }
		$mime = wp_check_filetype( wp_parse_url( $pin['image'], PHP_URL_PATH ) ?: '' );
		$type = ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg';
		echo "<item>\n";
		echo '<title>' . esc_html( $pin['title'] ) . "</title>\n";
		echo '<link>' . esc_url( $pin['link'] ) . "</link>\n";
		echo '<guid isPermaLink="false">' . esc_html( 'wpap-pin-' . $pin['id'] ) . "</guid>\n";
		echo '<pubDate>' . esc_html( gmdate( 'D, d M Y H:i:s +0000', (int) $pin['date'] ) ) . "</pubDate>\n";
		echo '<description>' . esc_html( $pin['description'] ) . "</description>\n";
		foreach ( $pin['keywords'] as $kw ) { echo '<category>' . esc_html( $kw ) . "</category>\n"; }
		echo '<enclosure url="' . esc_url( $pin['image'] ) . '" length="0" type="' . esc_attr( $type ) . '" />' . "\n";
		echo '<media:content url="' . esc_url( $pin['image'] ) . '" medium="image" type="' . esc_attr( $type ) . '"><media:description>' . esc_html( $pin['alt'] ) . "</media:description></media:content>\n";
		echo "</item>\n";
	}
	echo "</channel>\n</rss>\n";
}

/* ── 2. Pinterest bulk-upload CSV ─────────────────────────────────────────────────────────────────────────── */

add_action( 'admin_post_wpap_pin_csv', 'wpap_pin_csv_download' );
function wpap_pin_csv_download() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', 403 ); }
	check_admin_referer( 'wpap_pin_csv' );

	$part     = max( 1, (int) ( $_GET['part'] ?? 1 ) );
	$cat      = sanitize_title( wp_unslash( $_GET['cat'] ?? '' ) );
	$new_only = ! empty( $_GET['new_only'] );
	$args     = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => WPAP_PIN_CSV_ROWS,
		'paged' => $part, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'ignore_sticky_posts' => true );
	if ( '' !== $cat ) { $args['category_name'] = $cat; }
	if ( $new_only ) { $args['meta_query'] = array( array( 'key' => '_wpap_pin_exported', 'compare' => 'NOT EXISTS' ) ); } // phpcs:ignore WordPress.DB.SlowDBQuery
	$ids = get_posts( $args );

	$per_day = wpap_pin_opts()['per_day'];
	$start   = strtotime( 'tomorrow 13:00 UTC' );   /* 13:00–23:00 UTC ≈ US morning to evening */
	$window  = 10 * HOUR_IN_SECONDS;

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="pinterest-pins-' . ( '' !== $cat ? $cat . '-' : '' ) . 'part' . $part . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Title', 'Media URL', 'Pinterest board', 'Thumbnail', 'Description', 'Link', 'Publish date', 'Keywords' ) );
	$n = 0;
	foreach ( $ids as $id ) {
		$pin = wpap_pin_for_post( $id );
		if ( ! $pin ) { continue; }
		$when = '';
		if ( $per_day > 0 ) {
			$day  = intdiv( $n, $per_day );
			$slot = $n % $per_day;
			$when = gmdate( 'Y-m-d\TH:i:s', $start + $day * DAY_IN_SECONDS + (int) floor( $window * ( $slot + 0.5 ) / $per_day ) );
		}
		fputcsv( $out, array_map( 'wpap_pin_csv_cell', array( $pin['title'], $pin['image'], $pin['board'], '', $pin['description'], $pin['link'], $when, implode( ', ', $pin['keywords'] ) ) ) );
		update_post_meta( $id, '_wpap_pin_exported', time() );
		$n++;
	}
	fclose( $out );
	exit;
}

/* CSV-injection guard: a cell starting with = + - @ would run as a formula in a spreadsheet app. */
function wpap_pin_csv_cell( $v ) {
	$v = (string) $v;
	return ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) ? "'" . $v : $v;
}

/* ── Settings UI (rendered inside the main settings form) ─────────────────────────────────────────────────── */

function wpap_pin_render_settings() {
	$o      = wpap_pin_opts();
	$counts = wp_count_posts( 'post' );
	$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
	$parts  = max( 1, (int) ceil( $total / WPAP_PIN_CSV_ROWS ) );
	$csv    = function ( $part, $new_only = false ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'wpap_pin_csv', 'part' => $part, 'new_only' => $new_only ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'wpap_pin_csv' );
	};
	?>
	<details class="wpap-group" id="wpap-grp-pinterest">
		<summary><span class="wpap-g-title">📌 Pinterest pins</span> <span class="wpap-g-sub">&mdash; auto-publish feed + bulk-upload CSV, with image fallbacks (opt-in)</span></summary>
		<div class="wpap-group-body">
		<table class="form-table">
			<tr>
				<th scope="row">Pinterest feed <?php echo wpap_help_tip( 'pin_feed' ); ?></th>
				<td>
					<label><input type="checkbox" name="wpap_pin_feed" value="1" <?php checked( $o['feed'] ); ?> /> <strong>Publish a Pinterest RSS feed</strong> for auto-publishing</label>
					<p style="margin:8px 0 0"><label>Recipes per feed <input type="number" name="wpap_pin_feed_items" min="10" max="500" class="small-text" value="<?php echo esc_attr( (string) $o['feed_items'] ); ?>" /></label> <?php echo wpap_help_tip( 'pin_feed_items' ); ?></p>
					<?php if ( $o['feed'] ) : ?>
						<p class="description" style="margin-top:6px">All recipes: <code><?php echo esc_html( wpap_pin_feed_url() ); ?></code></p>
						<p class="description">One board per feed &mdash; per category:</p>
						<ul style="margin:4px 0 0 18px;list-style:disc">
						<?php foreach ( get_categories( array( 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 12 ) ) as $c ) : ?>
							<li><code><?php echo esc_html( wpap_pin_feed_url( $c->slug ) ); ?></code> <span class="description">(<?php echo esc_html( $c->name ); ?>)</span></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Boards <?php echo wpap_help_tip( 'pin_boards' ); ?></th>
				<td>
					<p style="margin:0 0 4px"><label for="wpap_pin_default_board"><strong>Default board</strong></label></p>
					<input type="text" id="wpap_pin_default_board" name="wpap_pin_default_board" class="regular-text" value="<?php echo esc_attr( $o['default_board'] ); ?>" placeholder="(empty = the post's category name)" />
					<p style="margin:10px 0 4px"><label for="wpap_pin_board_map"><strong>Category &rarr; board</strong> <span style="font-weight:400;color:#666">(one per line: <code>category-slug = Board name</code>)</span></label></p>
					<textarea id="wpap_pin_board_map" name="wpap_pin_board_map" rows="4" class="large-text code" placeholder="desserts = Easy Dessert Recipes&#10;dinner = Weeknight Dinners"><?php echo esc_textarea( $o['board_map'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">Fallback image <?php echo wpap_help_tip( 'pin_default_image' ); ?></th>
				<td>
					<input type="url" name="wpap_pin_default_image" class="large-text" value="<?php echo esc_attr( $o['default_image'] ); ?>" placeholder="https://…/pin-fallback.jpg (used only when a post has no image at all)" />
					<p class="description">Order used for each pin: dedicated pin image &rarr; featured image &rarr; external image &rarr; first image in the post &rarr; this fallback. Posts with no image at all are skipped.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Link tracking <?php echo wpap_help_tip( 'pin_utm' ); ?></th>
				<td><label><input type="checkbox" name="wpap_pin_utm" value="1" <?php checked( $o['utm'] ); ?> /> Tag pin links with <code>utm_source=pinterest</code></label></td>
			</tr>
			<tr>
				<th scope="row">Bulk-upload CSV <?php echo wpap_help_tip( 'pin_csv' ); ?></th>
				<td>
					<label>Schedule <input type="number" name="wpap_pin_per_day" min="0" max="50" class="small-text" value="<?php echo esc_attr( (string) $o['per_day'] ); ?>" /> pins per day from tomorrow (0 = publish all at once)</label>
					<p style="margin:10px 0 4px"><strong>Download</strong> (<?php echo esc_html( (string) $total ); ?> published posts, <?php echo (int) WPAP_PIN_CSV_ROWS; ?> per file):</p>
					<p style="margin:0"><a class="button button-primary" href="<?php echo esc_url( $csv( 1, true ) ); ?>">Next 200 not yet exported</a>
					<?php for ( $i = 1; $i <= min( $parts, 10 ); $i++ ) : ?>
						<a class="button" href="<?php echo esc_url( $csv( $i ) ); ?>">Part <?php echo (int) $i; ?></a>
					<?php endfor; ?></p>
					<p class="description">Save your settings first. Then in Pinterest: <strong>Create &rarr; Create Pins in bulk &rarr; Upload .csv</strong>.</p>
				</td>
			</tr>
		</table>
		</div>
	</details>
	<?php
}

/* Help tips (merged into the main tips map). */
add_filter( 'wpap_help_tips_extra', function ( $t ) {
	return array_merge( (array) $t, array(
		'pin_feed'          => '<strong>An RSS feed Pinterest reads to create pins automatically</strong> — the lowest-effort way to pin every new recipe.<br><strong>How to use it:</strong> 1) claim this website in Pinterest (the <em>Pinterest domain verification</em> field above); 2) tick this box and save; 3) in Pinterest open <strong>Settings → Bulk create Pins → Auto-publish</strong>, paste a feed URL shown below and pick the board it should fill. Use one per-category feed per board.<br>Pinterest checks the feed about once a day, creates up to 200 pins/day, oldest first. Each item carries the pin title, description, link, image and alt text.<br><em>Default: OFF</em> — no feed exists until you tick this.',
		'pin_feed_items'    => '<strong>How many posts each feed lists</strong> (newest first). Pinterest pins the <em>oldest</em> items in a feed first and skips ones it already pinned, so a feed that lists a whole category lets Pinterest work through your older posts by itself — no CSV needed for the backlog.<br><strong>How to choose:</strong> at least as many as your biggest category (e.g. 200 when Desserts has 110 posts). Pinterest creates up to 200 pins a day across all feeds.<br><em>Default: 50.</em> Range 10–500.',
		'pin_boards'        => '<strong>Which board each pin goes to</strong> (used by the CSV; for the feed you pick the board in Pinterest).<br><strong>How to use it:</strong> write one line per category, e.g. <code>desserts = Easy Dessert Recipes</code> (the slug or the category name both work). Unmapped posts use the <em>Default board</em>, or their category name when that is empty. Boards that don\'t exist yet are created by Pinterest on upload.',
		'pin_default_image' => '<strong>Last-resort image</strong> for a post that has no image of its own anywhere (pin image, featured, external or in-post). Pinterest needs an image for every pin, so without this such posts are skipped.<br><strong>Tip:</strong> a vertical 1000×1500 branded image works best on Pinterest.',
		'pin_utm'           => '<strong>Adds utm_source=pinterest</strong> (plus utm_medium=social and the post slug as utm_campaign) to every pin link so Pinterest visits show separately in your analytics and ad reports.<br><em>Default: ON.</em> Untick for clean links.',
		'pin_csv'           => '<strong>A CSV in Pinterest\'s bulk-upload format</strong> (Title, Media URL, Pinterest board, Thumbnail, Description, Link, Publish date, Keywords) — 200 pins per file, published posts only.<br><strong>How to use it:</strong> set a schedule (e.g. 10 per day spreads the pins over days, US daytime, UTC) or 0 to publish at once, save, then download <em>Next 200 not yet exported</em> (remembers what you already exported) and upload it in Pinterest: <strong>Create → Create Pins in bulk</strong>. Needs a Pinterest business account.',
	) );
} );
