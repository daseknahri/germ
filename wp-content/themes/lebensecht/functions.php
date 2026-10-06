<?php
/**
 * Oma Gerda (theme slug "lebensecht") — child theme of Viral Reader for a German story site read mostly from Facebook on phones.
 *
 * Everything here is presentation: brand CSS, the end-of-story block (reader question, share, next story),
 * the sticky next-story bar, branded hero/covers and social-card fallbacks for non-article pages.
 *
 * @package Lebensecht
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'LE_VERSION', '2.0.5' );
define( 'LE_FB_PAGE', 'https://www.facebook.com/profile.php?id=61595073230591' );

define( 'LE_HERO_SIZES', '(max-width:1200px) 100vw, 1200px' );

function le_asset( $rel ) {
	return get_stylesheet_directory_uri() . '/assets/' . ltrim( $rel, '/' );
}

/* ---------- Styles ----------
   The parent inlines get_stylesheet_directory()/style.css, which for a child theme is only the child's
   header. So inline the parent stylesheet first, then the brand layer, keeping the parent's no-blocking-CSS
   speed win. Font URLs in the brand CSS are made absolute because inline CSS resolves against the page. */
add_action( 'wp_enqueue_scripts', function () {
	$parent = get_template_directory() . '/style.css';
	$brand  = get_stylesheet_directory() . '/assets/lebensecht.css';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled theme files.
	$css = ( is_readable( $parent ) ? file_get_contents( $parent ) : '' ) . "\n" . ( is_readable( $brand ) ? file_get_contents( $brand ) : '' );
	$css = str_replace( '__THEME__', get_stylesheet_directory_uri(), $css );
	wp_add_inline_style( 'viral-reader', $css );
}, 11 );

/* Preload the reading font so the first paragraph paints in Literata, not a fallback. */
add_action( 'wp_head', function () {
	printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( le_asset( 'fonts/literata-400.woff2' ) ) );
}, 2 );

/* ---------- Parent feature switches ---------- */
add_filter( 'vr_enable_share_at_end', '__return_false' );     /* replaced by the richer end block below */
add_filter( 'vr_enable_jump_to_section', '__return_false' );  /* stories have no sections */
add_filter( 'vr_enable_pinterest_save', '__return_false' );   /* Facebook audience */
add_filter( 'vr_hero_image_url', function () { return le_asset( 'brand/hero-1280.webp' ); } );   /* 43 KB, was a 168 KB 1600px JPEG */

/* The parent preloads the newest story's cover as the home LCP image, but this child's home hero is the brand
   photo above, so that preload fetched a second, unused image on a slow phone connection. Preload the real hero. */
add_action( 'wp_head', function () {
	if ( ! is_front_page() || is_paged() ) { return; }
	remove_action( 'wp_head', 'vr_preload_lcp', 1 );
	/* Phones fetch the 640w file (13 KB) instead of the 1280w one (32 KB): same srcset/sizes as the <img> below. */
	printf( '<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="%s" type="image/webp" fetchpriority="high">' . "\n", esc_url( le_asset( 'brand/hero-1280.webp' ) ), esc_attr( le_hero_srcset() ), esc_attr( LE_HERO_SIZES ) );
}, 0 );
add_filter( 'vr_site_icon_svg_url', function () { return le_asset( 'brand/mark.svg' ); } );

/* Category tiles on the home page use the newest story image of that category. */
add_filter( 'vr_category_cover_url', function ( $url, $term_id ) {
	if ( '' !== $url || ! $term_id ) { return $url; }
	$cache = get_transient( 'le_cover2_' . $term_id );
	if ( false !== $cache ) { return $cache; }
	$q   = get_posts( array( 'cat' => (int) $term_id, 'numberposts' => 1, 'meta_key' => '_thumbnail_id', 'fields' => 'ids' ) );
	$img = $q ? (string) get_the_post_thumbnail_url( $q[0], 'le-card' ) : '';
	set_transient( 'le_cover2_' . $term_id, $img, DAY_IN_SECONDS );
	return $img;
}, 10, 2 );

/* ---------- The next story ----------
   Same category first (keeps the mood), newest older story; wraps to the newest of the category,
   then to any recent story. Cached per post for an hour. */
function le_next_story( $post_id ) {
	$key  = 'le_next_' . $post_id;
	$next = get_transient( $key );
	if ( false !== $next ) { return $next ? get_post( $next ) : null; }

	$cats = wp_get_post_categories( $post_id );
	$base = array( 'numberposts' => 1, 'post__not_in' => array( $post_id ), 'fields' => 'ids', 'ignore_sticky_posts' => true );
	$date = get_post_field( 'post_date', $post_id );
	$try  = array();
	if ( $cats ) {
		$try[] = $base + array( 'category__in' => $cats, 'date_query' => array( array( 'before' => $date ) ) );
		$try[] = $base + array( 'category__in' => $cats );
	}
	$try[] = $base;
	$id = 0;
	foreach ( $try as $args ) {
		$found = get_posts( $args );
		if ( $found ) { $id = (int) $found[0]; break; }
	}
	set_transient( $key, $id, HOUR_IN_SECONDS );
	return $id ? get_post( $id ) : null;
}

function le_icon( $name ) {
	$icons = array(
		'fb'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.6-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H7.9v3h2.6V21h3z"/></svg>',
		'wa'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3zm4.6 12.6c-.2.6-1.1 1.1-1.6 1.2-.4.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.9.9-2.1c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.6 1.9 1.1.9 2 1.2 2.3 1.4.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>',
		'arrow' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
	);
	return $icons[ $name ] ?? '';
}

/* ---------- End of story: the reader's question, share, next story ----------
   Priority 30: after the plugin's in-content ads (15) so the block always closes the story. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
	$id       = get_the_ID();
	$url      = rawurlencode( get_permalink( $id ) );
	$question = trim( (string) get_post_meta( $id, '_le_question', true ) );
	if ( '' === $question ) { $question = 'Wie hättest du an meiner Stelle entschieden?'; }

	$out  = '<aside class="le-end" aria-label="Deine Meinung">';
	$out .= '<img class="le-end__mark" src="' . esc_url( le_asset( 'brand/mark.svg' ) ) . '" alt="" width="44" height="44">';
	$out .= '<p class="le-end__q">' . esc_html( $question ) . '</p>';
	$out .= '<div class="le-end__actions">';
	$out .= '<a class="le-btn le-btn--fb" href="https://www.facebook.com/sharer/sharer.php?u=' . $url . '" target="_blank" rel="noopener nofollow">' . le_icon( 'fb' ) . 'Auf Facebook teilen</a>';
	$out .= '<a class="le-btn le-btn--wa" href="https://wa.me/?text=' . $url . '" target="_blank" rel="noopener nofollow">' . le_icon( 'wa' ) . 'Per WhatsApp senden</a>';
	$out .= '</div>';
	$out .= '<p class="le-follow">Mehr von Oma Gerda gibt es auf <a href="' . esc_url( LE_FB_PAGE ) . '" target="_blank" rel="noopener nofollow">Facebook</a> – schau vorbei, ihr Lieben.</p>';
	$out .= '</aside>';

	$next = le_next_story( $id );
	if ( $next ) {
		$out .= '<a class="le-next" href="' . esc_url( get_permalink( $next ) ) . '">';
		$thumb = get_the_post_thumbnail_url( $next, 'thumbnail' );
		if ( $thumb ) { $out .= '<img src="' . esc_url( $thumb ) . '" alt="" width="120" height="120" loading="lazy">'; }
		$out .= '<span><span class="le-next__eyebrow">Nächste Geschichte ' . le_icon( 'arrow' ) . '</span><span class="le-next__title">' . esc_html( get_the_title( $next ) ) . '</span></span></a>';
	}
	$out .= '<p class="le-note">Namen, Orte und Details wurden verändert. Ähnlichkeiten mit realen Personen sind zufällig.</p>';
	return $content . $out;
}, 30 );

/* ---------- Sticky next-story bar (phones + desktop corner) ---------- */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	$next = le_next_story( get_queried_object_id() );
	if ( ! $next ) { return; }
	$thumb = get_the_post_thumbnail_url( $next, 'thumbnail' );
	?>
	<a class="le-bar" id="le-bar" href="<?php echo esc_url( get_permalink( $next ) ); ?>" aria-hidden="true" tabindex="-1">
		<?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" width="48" height="48" loading="lazy"><?php endif; ?>
		<span class="le-bar__t"><b>Weiterlesen</b><span><?php echo esc_html( get_the_title( $next ) ); ?></span></span>
		<span class="le-bar__go"><?php echo le_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
		<button class="le-bar__x" type="button" aria-label="Schließen">&times;</button>
	</a>
	<script>
	(function(){
		var bar=document.getElementById('le-bar'),src=document.querySelector('.entry-content');
		if(!bar||!src)return;
		try{if(sessionStorage.getItem('leBarOff'))return;}catch(e){}
		bar.querySelector('.le-bar__x').addEventListener('click',function(e){e.preventDefault();e.stopPropagation();bar.classList.remove('is-on');try{sessionStorage.setItem('leBarOff','1');}catch(x){}});
		var tick=false,update=function(){var r=src.getBoundingClientRect(),seen=(innerHeight-r.top)/r.height;var on=seen>.6;bar.classList.toggle('is-on',on);bar.setAttribute('aria-hidden',on?'false':'true');bar.tabIndex=on?0:-1;tick=false;};
		addEventListener('scroll',function(){if(!tick){tick=true;requestAnimationFrame(update);}},{passive:true});
		/* Google's anchor ad is a fixed bar at the bottom of the screen; lift our bar above it so neither hides the other. */
		var anchor=function(){var a=document.querySelector('ins.adsbygoogle[data-anchor-status="displayed"],ins.adsbygoogle-noablate[data-anchor-status="displayed"]'),h=0;if(a){var r=a.getBoundingClientRect();if(r.bottom>=innerHeight-2&&r.height<200){h=Math.round(r.height);}}document.documentElement.style.setProperty('--le-anchor',h+'px');};
		setInterval(anchor,1500);
	})();
	</script>
	<?php
}, 30 );

/* ---------- Social cards for the home page, categories and pages ----------
   Story pages get their own og: tags from the plugin; everything else falls back to the brand card so a
   shared category or home link never shows an empty preview on Facebook. */
add_action( 'wp_head', function () {
	if ( is_singular( 'post' ) ) { return; }
	$title = is_front_page() ? get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ) : wp_get_document_title();
	$desc  = is_category() ? wp_strip_all_tags( category_description() ) : '';
	if ( '' === $desc ) { $desc = 'Oma Gerda erzählt: Geschichten, Tipps und Erinnerungen – Herzensweisheiten mit einem Augenzwinkern.'; }
	$url = is_front_page() ? home_url( '/' ) : ( is_category() ? get_category_link( get_queried_object_id() ) : get_permalink() );
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( le_asset( 'brand/og.jpg' ) ) . '">' . "\n";
	echo '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="theme-color" content="#5B3A70">' . "\n";
	if ( is_front_page() || is_category() ) { echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n"; }
}, 5 );

/* ---------- Ads: fixed sizes, no layout jumps ----------
   Responsive "auto" units resize themselves after the page paints (a phone got a 375 px square, then collapsed
   again when no ad was served), which shoved the whole page down and back up. Fixed sizes (AdSense's documented
   way to modify responsive code) let us reserve the exact space up front: 300x250 on phones, a slim 728x90 on
   desktop. Phone-or-not is decided on the server, so a desktop visitor never loads the big format. */
function le_ad_client() {
	$ads = function_exists( 'wpap_get_ads' ) ? wpap_get_ads() : array();
	if ( empty( $ads['enabled'] ) || 'off' === trim( (string) getenv( 'ADS_MANUAL' ) ) ) { return ''; }
	return preg_match( '/client=(ca-pub-\d+)/', (string) ( $ads['auto_code'] ?? '' ), $m ) ? $m[1] : '';
}

function le_ad_ins( $client, $slot ) {
	$size = wp_is_mobile() ? array( 300, 250 ) : array( 728, 90 );
	return '<ins class="adsbygoogle" style="display:inline-block;width:' . $size[0] . 'px;height:' . $size[1] . 'px" data-ad-client="' . esc_attr( $client ) . '" data-ad-slot="' . esc_attr( $slot ) . '"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
}

/* Phone and desktop get different ad sizes from the same URL: tell any cache in between. */
add_action( 'send_headers', function () { header( 'Vary: User-Agent', false ); } );

/* The ad right under the header, the first thing a Facebook visitor sees. The strip has a fixed height; if Google
   has no ad for a view, a "most read" card of exactly the same size takes its place (pure CSS, see lebensecht.css),
   so nothing moves and the space still earns a click through to another story. */
function le_top_ad() {
	$client = le_ad_client();
	if ( '' === $client ) { return; }
	$fb = null;
	if ( is_singular( 'post' ) ) { $fb = le_next_story( get_queried_object_id() ); }
	if ( ! $fb ) {
		$latest = get_posts( array( 'numberposts' => 1, 'ignore_sticky_posts' => true, 'post__not_in' => is_singular() ? array( get_queried_object_id() ) : array() ) );
		$fb     = $latest ? $latest[0] : null;
	}
	echo '<div class="vr-ad-strip le-top ' . ( wp_is_mobile() ? 'le-top--m' : 'le-top--d' ) . '"><div class="vr-ad-strip__inner">';
	echo '<div class="wpap-ad wpap-ad-zone-header">' . le_ad_ins( $client, '6634489785' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in le_ad_ins()
	if ( $fb ) {
		echo '<a class="le-top-fb" href="' . esc_url( get_permalink( $fb ) ) . '"><span>Meistgelesen</span><strong>' . esc_html( get_the_title( $fb ) ) . '</strong><em>Jetzt lesen &rarr;</em></a>';
	}
	echo '</div></div>';
}

/* Site Kit's AdSense snippet loads the very same adsbygoogle.js a second time (plus a ca-host-pub param): twice the
   JavaScript on a phone. The plugin already prints the one tag this site needs. */
add_filter( 'googlesitekit_adsense_tag_blocked', function ( $blocked ) {
	return '' !== le_ad_client() ? true : $blocked;
} );

/* ---------- In-feed ads: one after every 4 story cards on home, category, tag, author and search lists ----------
   Hooked on the_post, which fires right before each card renders, so the ad lands between cards inside the
   grid (styled to span the full row). Uses the "LE - in feed / sidebar" unit; ADS_MANUAL=off disables. */
define( 'LE_FEED_EVERY', 4 );
define( 'LE_FEED_MAX', 3 );

add_action( 'the_post', function ( $post, $query ) {
	static $counts = array();
	if ( is_admin() || is_singular() || 'off' === trim( (string) getenv( 'ADS_MANUAL' ) ) ) { return; }
	if ( ! ( is_home() || is_front_page() || is_archive() || is_search() ) ) { return; }
	if ( 'post' !== $post->post_type ) { return; }
	$key = spl_object_hash( $query );
	$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
	$n = $counts[ $key ];
	/* $n is the card about to render; insert before cards 5, 9, 13 (after every 4th). */
	$max = wp_is_mobile() ? LE_FEED_MAX : 1;   /* desktop: one in-feed ad (lighter); never render ads CSS would hide */
	if ( $n <= 1 || 0 !== ( $n - 1 ) % LE_FEED_EVERY || ( $n - 1 ) / LE_FEED_EVERY > $max ) { return; }
	$client = le_ad_client();
	if ( '' === $client ) { return; }
	echo '<div class="le-feed-ad"><div class="wpap-ad wpap-ad-feed">' . le_ad_ins( $client, '6714380837' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in le_ad_ins()
}, 10, 2 );

/* ---------- Visitor statistics (Histats, account 5055954) ----------
   Counter in the footer, loaded 1.2 s after the page has finished so it never competes with the story. Front end
   only; logged-in editors are skipped so the owner's own visits don't count. HISTATS_ID=off (env) disables it.
   Disclosed in the Datenschutz page (section 5). */
add_action( 'wp_footer', function () {
	$id = trim( (string) getenv( 'HISTATS_ID' ) );
	if ( 'off' === $id || is_admin() || is_user_logged_in() ) { return; }
	$id = ctype_digit( $id ) ? $id : '5055954';
	echo "<script>var _Hasync=_Hasync||[];_Hasync.push(['Histats.start','1," . esc_js( $id ) . ",4,0,0,0,00010000']);_Hasync.push(['Histats.fasi','1']);_Hasync.push(['Histats.track_hits','']);addEventListener('load',function(){setTimeout(function(){var hs=document.createElement('script');hs.async=true;hs.src='//s10.histats.com/js15_as.js';(document.head||document.body).appendChild(hs);},1200);});</script>"
		. '<noscript><img src="//sstatic1.histats.com/0.gif?' . esc_attr( $id ) . '&amp;101" alt="" width="1" height="1" style="position:absolute;left:-9999px"></noscript>' . "\n";
}, 50 );

/* ---------- Organization schema: Facebook page as sameAs ----------
   The plugin's JSON-LD graph has a minimal Organization (@id home#organization) without sameAs. Emitting the same
   @id here MERGES with it for crawlers, adding the logo + the Facebook page. Front end only, tiny. */
add_action( 'wp_head', function () {
	if ( is_admin() || is_feed() ) { return; }
	$home = home_url( '/' );
	$org  = array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'@id'      => $home . '#organization',
		'name'     => get_bloginfo( 'name' ),
		'url'      => $home,
		'logo'     => array( '@type' => 'ImageObject', 'url' => le_asset( 'brand/omagerda-icon-512.png' ), 'width' => 512, 'height' => 512 ),
		'sameAs'   => array( LE_FB_PAGE ),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $org, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-encoded
}, 24 );

/* ---------- Home intro (brand voice) ----------
   One compact, server-rendered strip on the first home page (fixed-size portrait: no layout shift). Printed
   from header.php, under the header ad. */
function le_home_intro() {
	if ( ! is_front_page() || is_paged() ) { return; }
	echo '<section class="le-intro" aria-label="Über Oma Gerda"><div class="vr-container le-intro__in">';
	echo '<img class="le-intro__img" src="' . esc_url( le_asset( 'brand/oma-gerda-avatar.webp' ) ) . '" alt="Oma Gerda mit einer Tasse Kaffee" width="64" height="64" loading="lazy" decoding="async">';
	echo '<div class="le-intro__t"><p class="le-intro__h">Oma Gerda erzählt: Geschichten, Tipps und Erinnerungen</p>';
	echo '<p class="le-intro__s">Herzensweisheiten mit einem Augenzwinkern – komm rein, der Kaffee ist fertig. <a href="' . esc_url( LE_FB_PAGE ) . '" target="_blank" rel="noopener nofollow">Oma Gerda auf Facebook</a></p></div>';
	echo '</div></section>';
}

/* ---------- Byline + author box only on Oma Gerda's own posts ----------
   The site is branded Oma Gerda, but the older relationship/drama stories are told by other narrators,
   so crediting her there reads wrong. Show the byline/author box only on single posts inside the
   "oma-gerda" category tree (parent + Nostalgie / Omas Alltag / Omas Tipps); everywhere else it's off. */
function le_is_oma_gerda_post( $post_id = 0 ) {
	$parent = get_category_by_slug( 'oma-gerda' );
	if ( ! $parent ) { return false; }
	$tree = array_merge( array( (int) $parent->term_id ), array_map( 'intval', get_term_children( $parent->term_id, 'category' ) ) );
	return has_category( $tree, $post_id ? $post_id : get_the_ID() );
}
add_filter( 'theme_mod_vr_byline_author', function ( $value ) {
	if ( is_admin() || ! is_singular( 'post' ) ) { return $value; }
	return le_is_oma_gerda_post() ? $value : false;
} );

/* ---------- AdSense ad-blocking recovery (Google Funding Choices) ----------
   Germany has one of Europe's highest ad-blocker rates. This is Google's standard recovery tag: when an
   "Ad blocking recovery" message is published in AdSense (Privacy & messaging), visitors with a blocker are
   asked to allow ads; without a published message the tag does nothing. Injected after the window load event (speed), no layout impact. */
add_action( 'wp_head', function () {
	$client = le_ad_client();
	if ( '' === $client || is_admin() ) { return; }
	$pub = str_replace( 'ca-', '', $client );
	/* The funding-choices script itself is injected after load (+2.5 s); only the tiny presence signal stays in <head>. */
	echo '<script>addEventListener("load",function(){setTimeout(function(){var s=document.createElement("script");s.async=true;s.src="https://fundingchoicesmessages.google.com/i/' . esc_js( $pub ) . '?ers=1";document.head.appendChild(s);},2500);});</script>' . "\n";
	echo "<script>(function(){function signalGooglefcPresent(){if(!window.frames['googlefcPresent']){if(document.body){var f=document.createElement('iframe');f.style='width:0;height:0;border:none;z-index:-1000;left:-1000px;top:-1000px;';f.style.display='none';f.name='googlefcPresent';document.body.appendChild(f);}else{setTimeout(signalGooglefcPresent,0);}}}signalGooglefcPresent();})();</script>\n";
}, 3 );

/* ================= Speed layer (theme 2.0.3) ================= */

/* ---------- Hero: srcset ----------
   The parent prints the standing hero as a plain <img src=…hero-1280.webp>. Give it a srcset (640/768/960/1280) and
   sizes through a tiny output-buffer swap on the first home page; width/height keep the 16:9 box so nothing shifts. */
function le_hero_srcset() {
	return le_asset( 'brand/hero-640.webp' ) . ' 640w, ' . le_asset( 'brand/hero-768.webp' ) . ' 768w, ' . le_asset( 'brand/hero-960.webp' ) . ' 960w, ' . le_asset( 'brand/hero-1280.webp' ) . ' 1280w';
}
add_action( 'template_redirect', function () {
	if ( ! is_front_page() || is_paged() ) { return; }
	ob_start( function ( $html ) {
		$needle = 'src="' . esc_url( le_asset( 'brand/hero-1280.webp' ) ) . '"';
		if ( false === strpos( $html, $needle ) ) { return $html; }
		return preg_replace(
			'#<img class="home-hero__img"( src="' . preg_quote( esc_url( le_asset( 'brand/hero-1280.webp' ) ), '#' ) . '")#',
			'<img class="home-hero__img" width="1280" height="720" srcset="' . esc_attr( le_hero_srcset() ) . '" sizes="' . esc_attr( LE_HERO_SIZES ) . '"$1',
			$html,
			1
		);
	} );
}, 0 );

/* ---------- Hardening CSS: inline, not a blocking <link> ----------
   The parent loads hardening.css as a render-blocking stylesheet (a whole extra round trip before first paint).
   It is small, so append it to the already-inlined parent CSS (after the brand layer: same cascade order as the
   old <link>, which also came last). */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_style_is( 'viral-reader-hardening', 'enqueued' ) ) { return; }
	$file = get_template_directory() . '/assets/css/hardening.css';
	if ( ! is_readable( $file ) ) { return; }
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled theme file.
	$css = (string) file_get_contents( $file );
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$css = trim( preg_replace( '/\s+/', ' ', $css ) );
	if ( '' === $css || strlen( $css ) > 40000 ) { return; }   /* fall back to the normal <link> */
	wp_add_inline_style( 'viral-reader', $css );
	wp_dequeue_style( 'viral-reader-hardening' );
}, 12 );

/* ---------- Logo: WebP, and the light-on-dark variant only in dark mode ----------
   The seed stores the PNG logo as the custom logo; serve the WebP twin from the theme instead (13 KB vs 35 KB).
   A <picture> with a prefers-color-scheme:dark source means exactly one logo file is fetched per visitor. */
add_filter( 'get_custom_logo', function ( $html ) {
	$img = '<img width="417" height="96" src="' . esc_url( le_asset( 'brand/omagerda-logo.webp' ) ) . '" class="custom-logo" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" decoding="async" />';
	$pic = '<picture><source media="(prefers-color-scheme:dark)" srcset="' . esc_url( le_asset( 'brand/logo-light.webp' ) ) . '">' . $img . '</picture>';
	/* Replace only the <img> (core would keep a PNG srcset the browser prefers over our src). */
	return preg_replace( '#<img\b[^>]*>#', $pic, $html, 1 );
} );

/* ---------- Story card thumbnails ----------
   A 480px size for list cards (the grid is ~280-380px wide), chosen instead of medium_large (768), with `sizes`
   so the browser picks the smallest candidate that is sharp. New uploads get WebP sub-sizes (the original keeps
   its format; only generated sizes change). */
add_action( 'after_setup_theme', function () {
	add_image_size( 'le-card', 480, 9999, false );
}, 20 );
add_filter( 'post_thumbnail_size', function ( $size ) {
	return ( 'medium_large' === $size && ! is_singular() && ! is_admin() ) ? 'le-card' : $size;
} );
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $att, $size ) {
	if ( 'le-card' === $size ) { $attr['sizes'] = '(max-width:640px) calc(100vw - 32px), 380px'; }
	return $attr;
}, 10, 3 );
add_filter( 'image_editor_output_format', function ( $formats ) {
	$formats['image/jpeg'] = 'image/webp';
	return $formats;
} );

/* ---------- Home topic tiles: load their cover photos only when scrolled into view ----------
   The parent prints each tile's photo as an inline CSS background (style="--cover:url(...)"), which the browser
   fetches immediately — 9 photos competing with the hero on a slow phone. Move the URL to data-cover and let a tiny
   IntersectionObserver apply it near the viewport (tiles without JS simply stay plain, as tiles without a cover do). */
add_action( 'template_redirect', function () {
	if ( ! is_front_page() || is_paged() ) { return; }
	ob_start( function ( $html ) {
		$html = preg_replace( '#(<a class="topic-tile[^"]*" href="[^"]*") style="--cover:url\(\x27([^\x27]+)\x27\)"#', '$1 data-cover="$2"', $html );
		$js   = "<script>(function(){var t=document.querySelectorAll('.topic-tile[data-cover]');if(!t.length)return;function s(e){e.style.setProperty('--cover',\"url('\"+e.getAttribute('data-cover')+\"')\");e.removeAttribute('data-cover');}if(!('IntersectionObserver' in window)){t.forEach(s);return;}var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){s(e.target);o.unobserve(e.target);}});},{rootMargin:'300px 0px'});t.forEach(function(e){o.observe(e);});})();</script>";
		return str_replace( '</body>', $js . '</body>', $html );
	} );
}, 1 );

/* ---------- No WordPress emoji script (phones render emoji natively; saves a request) ---------- */
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );

/* ---------- Story pages: lazy-load the ads further down ----------
   Every ad unit pushed at once makes the phone download and run Google's ad code for 5 slots before the story
   photo can paint (~30 PageSpeed points on story pages). The first two slots (top strip + first in-article ad)
   still load immediately; the rest (repeat, end-of-story multiplex, sidebar) are pushed when they come within
   ~600px of the screen. Unscrolled ads were never viewable anyway, so this protects Active View / RPM too. */
define( 'LE_EAGER_ADS', 2 );
add_action( 'template_redirect', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	ob_start( function ( $html ) {
		$push  = '#(<ins class="adsbygoogle")([^>]*></ins>)\s*<script>\s*\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{\}\);\s*</script>#';
		$count = 0;
		$html  = preg_replace_callback( $push, function ( $m ) use ( &$count ) {
			$count++;
			if ( $count <= LE_EAGER_ADS ) { return $m[0]; }
			return $m[1] . ' data-le-lazy="1"' . $m[2];
		}, $html );
		if ( $count <= LE_EAGER_ADS ) { return $html; }
		$js = "<script>(function(){var l=document.querySelectorAll('ins.adsbygoogle[data-le-lazy]');if(!l.length)return;function go(e){if(!e.hasAttribute('data-le-lazy'))return;e.removeAttribute('data-le-lazy');(window.adsbygoogle=window.adsbygoogle||[]).push({});}if(!('IntersectionObserver' in window)){l.forEach(go);return;}var o=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){o.unobserve(x.target);go(x.target);}});},{rootMargin:'600px 0px'});l.forEach(function(e){o.observe(e);});})();</script>";
		return str_replace( '</body>', $js . '</body>', $html );
	} );
}, 2 );
