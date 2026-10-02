<?php
/**
 * Lebensecht — child theme of Viral Reader for a German story site read mostly from Facebook on phones.
 *
 * Everything here is presentation: brand CSS, the end-of-story block (reader question, share, next story),
 * the sticky next-story bar, branded hero/covers and social-card fallbacks for non-article pages.
 *
 * @package Lebensecht
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'LE_VERSION', '1.0.0' );

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
	printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( le_asset( 'fonts/literata.woff2' ) ) );
}, 2 );

/* ---------- Parent feature switches ---------- */
add_filter( 'vr_enable_share_at_end', '__return_false' );     /* replaced by the richer end block below */
add_filter( 'vr_enable_jump_to_section', '__return_false' );  /* stories have no sections */
add_filter( 'vr_enable_pinterest_save', '__return_false' );   /* Facebook audience */
add_filter( 'vr_hero_image_url', function () { return le_asset( 'brand/hero.jpg' ); } );
add_filter( 'vr_site_icon_svg_url', function () { return le_asset( 'brand/mark.svg' ); } );

/* Category tiles on the home page use the newest story image of that category. */
add_filter( 'vr_category_cover_url', function ( $url, $term_id ) {
	if ( '' !== $url || ! $term_id ) { return $url; }
	$cache = get_transient( 'le_cover_' . $term_id );
	if ( false !== $cache ) { return $cache; }
	$q   = get_posts( array( 'cat' => (int) $term_id, 'numberposts' => 1, 'meta_key' => '_thumbnail_id', 'fields' => 'ids' ) );
	$img = $q ? (string) get_the_post_thumbnail_url( $q[0], 'medium_large' ) : '';
	set_transient( 'le_cover_' . $term_id, $img, DAY_IN_SECONDS );
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
	$out .= '</div></aside>';

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
	if ( '' === $desc ) { $desc = 'Bewegende Geschichten aus dem echten Leben: Familie, Liebe, kleine Rache und große Herzensmomente.'; }
	$url = is_front_page() ? home_url( '/' ) : ( is_category() ? get_category_link( get_queried_object_id() ) : get_permalink() );
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( le_asset( 'brand/og.jpg' ) ) . '">' . "\n";
	echo '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	if ( is_front_page() || is_category() ) { echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n"; }
}, 5 );
