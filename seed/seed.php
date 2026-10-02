<?php
/**
 * Lebensecht site seed — run by docker/site-init.sh via `wp eval-file` on every start.
 *
 * Idempotent: the brand/site setup runs once per LE_SEED_VERSION, and each story bundle is imported once
 * (tracked in the le_imported_bundles option), so a restart mid-import simply resumes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const LE_SEED_VERSION = 2;
const LE_DATA         = '/opt/site/data';
const LE_BRAND_DIR    = WP_CONTENT_DIR . '/themes/lebensecht/assets/brand';

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function le_log( $msg ) { WP_CLI::log( '[seed] ' . $msg ); }

function le_admin_id() {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	return $admins ? (int) $admins[0] : 1;
}

/* Copy a bundled brand file into the media library once (keyed by filename). */
function le_brand_media( $file, $title ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_le_brand', 'meta_value' => $file, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $found ) { return (int) $found[0]; }
	$tmp = wp_tempnam( $file );
	copy( LE_BRAND_DIR . '/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) { le_log( 'media failed: ' . $file . ' ' . $id->get_error_message() ); return 0; }
	update_post_meta( $id, '_le_brand', $file );
	return (int) $id;
}

function le_page( $slug, $title, $html, $status = 'publish' ) {
	$p = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $html, 'post_status' => $status, 'post_author' => le_admin_id() );
	if ( $p ) { $data['ID'] = $p->ID; return (int) wp_update_post( wp_slash( $data ) ); }
	return (int) wp_insert_post( wp_slash( $data ) );
}

function le_seed_site() {
	update_option( 'blogname', 'Lebensecht' );
	update_option( 'blogdescription', 'Geschichten, die das Leben schreibt' );
	update_option( 'timezone_string', 'Europe/Berlin' );
	update_option( 'date_format', 'j. F Y' );
	update_option( 'time_format', 'H:i' );
	update_option( 'start_of_week', 1 );
	update_option( 'posts_per_page', 12 );
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );
	update_option( 'blog_public', 1 );
	update_option( 'permalink_structure', '/%postname%/' );

	/* Theme options: no single author byline (many narrators), story wording. */
	set_theme_mod( 'vr_byline_author', false );
	set_theme_mod( 'vr_noun', 'story' );

	$icon = le_brand_media( 'site-icon-512.png', 'Lebensecht Icon' );
	if ( $icon ) { update_option( 'site_icon', $icon ); }
	$logo = le_brand_media( 'logo.png', 'Lebensecht Logo' );
	if ( $logo ) { set_theme_mod( 'custom_logo', $logo ); update_option( 'site_logo', $logo ); }

	/* WordPress sample content. */
	foreach ( array( array( 'hello-world', 'post' ), array( 'sample-page', 'page' ) ) as $x ) {
		$p = get_page_by_path( $x[0], OBJECT, $x[1] );
		if ( $p ) { wp_delete_post( $p->ID, true ); }
	}

	/* Categories — slugs fixed so the mood colours in the CSS match. */
	$cats = array(
		'rache-geschichten'  => array( 'Rache-Geschichten', 'Wenn jemand den Bogen überspannt – und das Leben (oder ein cleverer Plan) für Gerechtigkeit sorgt.' ),
		'familiendrama'      => array( 'Familiendrama', 'Erbe, Schwiegereltern, Geschwisterstreit: Geschichten über die Menschen, die uns am nächsten stehen.' ),
		'beziehungen'        => array( 'Beziehungen', 'Liebe, Vertrauen, Verrat und Neuanfänge – Geschichten aus Ehe, Partnerschaft und Freundschaft.' ),
		'herzensgeschichten' => array( 'Herzensgeschichten', 'Kleine Gesten, große Wirkung: Geschichten, die berühren und den Glauben an das Gute zurückgeben.' ),
	);
	foreach ( $cats as $slug => $c ) {
		$t = get_term_by( 'slug', $slug, 'category' );
		if ( ! $t ) { $t = get_term_by( 'name', $c[0], 'category' ); }
		if ( $t ) { wp_update_term( $t->term_id, 'category', array( 'name' => $c[0], 'slug' => $slug, 'description' => $c[1] ) ); }
		else { wp_insert_term( $c[0], 'category', array( 'slug' => $slug, 'description' => $c[1] ) ); }
	}
	$uncat = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncat ) { wp_update_term( $uncat->term_id, 'category', array( 'name' => 'Allgemein', 'slug' => 'allgemein' ) ); }

	/* Pages */
	$site = home_url( '/' );
	le_page( 'ueber-uns', 'Über uns',
		'<p><strong>Lebensecht</strong> sammelt Geschichten, die das Leben schreibt: über Familie und Liebe, über Menschen, die zu weit gehen, und über die kleinen Gesten, die alles verändern.</p>'
		. '<p>Wir lesen jede Geschichte, bevor sie erscheint, kürzen sie behutsam und achten darauf, dass sie gut zu lesen ist – am liebsten mit einer Tasse Kaffee am Abend.</p>'
		. '<p>Namen, Orte und Details werden in allen Geschichten verändert oder frei gestaltet. Ähnlichkeiten mit realen Personen sind zufällig.</p>' );
	le_page( 'kontakt', 'Kontakt',
		'<p>Du hast eine Frage, einen Hinweis oder möchtest uns etwas mitteilen? Schreib uns über die Facebook-Seite von Lebensecht – wir lesen jede Nachricht.</p>' );
	$privacy = le_page( 'datenschutz', 'Datenschutzerklärung',
		'<h2>1. Verantwortlicher</h2><p>Die Kontaktdaten des Verantwortlichen findest du im Impressum.</p>'
		. '<h2>2. Hosting und Server-Logfiles</h2><p>Beim Aufruf dieser Website werden technisch notwendige Daten (z. B. IP-Adresse, Datum und Uhrzeit, aufgerufene Seite, Browser) in Server-Logfiles verarbeitet, um den sicheren Betrieb zu gewährleisten (Art. 6 Abs. 1 lit. f DSGVO).</p>'
		. '<h2>3. Schriftarten</h2><p>Die verwendeten Schriftarten werden lokal von unserem Server geladen. Es findet keine Verbindung zu Servern von Google Fonts statt.</p>'
		. '<h2>4. Werbung (Google AdSense) und Einwilligung</h2><p>Diese Website finanziert sich über Werbung von Google AdSense (Google Ireland Ltd., Gordon House, Barrow Street, Dublin 4, Irland). Google kann Cookies und ähnliche Technologien einsetzen, um Anzeigen auszuliefern und zu messen. Personalisierte Werbung erfolgt nur mit deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die du über das Einwilligungsbanner erteilen, ablehnen und jederzeit widerrufen kannst. Weitere Informationen: <a href="https://policies.google.com/technologies/ads" rel="nofollow noopener">policies.google.com/technologies/ads</a>.</p>'
		. '<h2>5. Teilen-Schaltflächen</h2><p>Die Schaltflächen „Auf Facebook teilen“ und „Per WhatsApp senden“ sind einfache Links. Daten werden erst übertragen, wenn du sie anklickst.</p>'
		. '<h2>6. Deine Rechte</h2><p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht auf Beschwerde bei einer Datenschutz-Aufsichtsbehörde.</p>' );
	update_option( 'wp_page_for_privacy_policy', $privacy );
	/* Impressum (§ 5 DDG / § 18 MStV) — operator details supplied by the owner on 2026-10-02. */
	le_page( 'impressum', 'Impressum',
		'<h2>Angaben gemäß § 5 DDG</h2>'
		. '<p>Dasek Nahri<br>Boukhalef<br>90090 Tanger<br>Marokko</p>'
		. '<h2>Kontakt</h2><p>E-Mail: <a href="mailto:daseknahri@gmail.com">daseknahri@gmail.com</a></p>'
		. '<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2><p>Dasek Nahri, Anschrift wie oben</p>'
		. '<h2>Hinweis zu den Geschichten</h2><p>Die Geschichten auf dieser Website werden redaktionell bearbeitet. Namen, Orte und Details sind verändert oder frei gestaltet; Ähnlichkeiten mit realen Personen sind zufällig.</p>'
		. '<h2>Haftung für Links</h2><p>Unsere Seiten können Links zu externen Websites enthalten, auf deren Inhalte wir keinen Einfluss haben. Für diese Inhalte ist stets der jeweilige Anbieter verantwortlich.</p>'
		. '<h2>Verbraucherstreitbeilegung</h2><p>Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>',
		'publish' );
	le_page( 'hinweis', 'Hinweis zu unseren Geschichten',
		'<p>Die Geschichten auf Lebensecht sind von Erlebnissen aus dem Alltag inspiriert und werden redaktionell bearbeitet. Namen, Orte und Details sind verändert oder frei gestaltet. Ähnlichkeiten mit realen Personen oder Ereignissen sind zufällig.</p>' );

	/* Primary menu: the four moods. */
	$menu = wp_get_nav_menu_object( 'Hauptmenü' );
	$mid  = $menu ? $menu->term_id : wp_create_nav_menu( 'Hauptmenü' );
	if ( ! $menu ) {
		foreach ( array_keys( $cats ) as $slug ) {
			$t = get_term_by( 'slug', $slug, 'category' );
			if ( $t ) { wp_update_nav_menu_item( $mid, 0, array( 'menu-item-object' => 'category', 'menu-item-object-id' => $t->term_id, 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) ); }
		}
	}
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( array_keys( get_registered_nav_menus() ) as $loc ) { if ( false !== strpos( $loc, 'primary' ) || 'menu-1' === $loc ) { $locs[ $loc ] = $mid; } }
	set_theme_mod( 'nav_menu_locations', $locs );

	/* Drop WordPress's default sidebar widgets (English 'Recent Posts', Archives, Meta…); the theme renders its own
	   'Recent stories' + topics blocks when the sidebar is empty. */
	$sw = (array) get_option( 'sidebars_widgets', array() );
	foreach ( $sw as $k => $v ) { if ( is_array( $v ) && 'wp_inactive_widgets' !== $k ) { $sw[ $k ] = array(); } }
	update_option( 'sidebars_widgets', $sw );

	flush_rewrite_rules( false );
	update_option( 'le_seed_version', LE_SEED_VERSION );
	le_log( 'site seeded' );
}

/* AdSense Auto ads through the plugin. Applied on every start so a changed ADSENSE_CLIENT takes effect on the next
   redeploy; falls back to the site's publisher id when the variable arrives empty (Coolify passes declared-but-unset
   variables through as empty strings). */
const LE_ADSENSE_DEFAULT = 'ca-pub-6869205417923902';
function le_apply_ads() {
	$client = trim( (string) getenv( 'ADSENSE_CLIENT' ) );
	if ( '' === $client ) { $client = LE_ADSENSE_DEFAULT; }
	if ( 'off' === $client || ! preg_match( '/^ca-pub-\d{10,20}$/', $client ) ) { return; }
	$ads = (array) get_option( 'wpap_ads_inject', array() );
	$ads['enabled']   = 1;
	$ads['scope_all'] = 1;
	$ads['auto_code'] = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client . '" crossorigin="anonymous"></script>';

	/* Manual placements in every story (Auto ads keeps the overlay formats: anchor + vignette).
	   Stories are ~9 paragraphs → ads after paragraphs 2, 5 and 8, plus a multiplex grid at the end.
	   Units: "LE - in story" (in-article) and "LE - end of story (multiplex)". ADS_MANUAL=off disables. */
	if ( 'off' !== trim( (string) getenv( 'ADS_MANUAL' ) ) ) {
		$push      = '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
		$in_story  = '<ins class="adsbygoogle" style="display:block;text-align:center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="' . $client . '" data-ad-slot="5976014231"></ins>' . $push;
		$multiplex = '<ins class="adsbygoogle" style="display:block" data-ad-format="autorelaxed" data-matched-content-ui-type="image_stacked,image_stacked" data-matched-content-rows-num="3,2" data-matched-content-columns-num="2,4" data-ad-client="' . $client . '" data-ad-slot="6921515967"></ins>' . $push;
		$ads['slots'] = array(
			'top'       => array( 'on' => 0, 'code' => '' ),
			'incontent' => array( 'on' => 1, 'code' => $in_story, 'after' => 2 ),
			'repeat'    => array( 'on' => 1, 'code' => $in_story, 'every' => 3, 'max' => 2 ),
			'bottom'    => array( 'on' => 1, 'code' => $multiplex ),
		);
		/* Page zones: an ad right under the header on EVERY page (first thing a Facebook visitor sees — the
		   child theme also enables it on the home page) and one in the desktop sidebar. */
		$display = function ( $slot ) use ( $client ) {
			return '<ins class="adsbygoogle" style="display:block" data-ad-client="' . $client . '" data-ad-slot="' . $slot . '" data-ad-format="auto" data-full-width-responsive="true"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
		};
		$ads['zones'] = array(
			'header'  => array( 'on' => 1, 'code' => $display( '6634489785' ) ),   /* LE - top (under header) */
			'sidebar' => array( 'on' => 1, 'code' => $display( '6714380837' ) ),   /* LE - in feed / sidebar */
			'footer'  => array( 'on' => 0, 'code' => '' ),
		);
		$ads['min_gap'] = 2;
		$ads['max_ads'] = 4;
		$ads['label']   = 0;   /* the theme already prints the translated "Anzeige" label above each slot */
	} else {
		$ads['slots'] = array();
		$ads['zones'] = array();
	}
	update_option( 'wpap_ads_inject', $ads );
	update_option( 'wpap_ads_txt', 'google.com, ' . str_replace( 'ca-', '', $client ) . ', DIRECT, f08c47fec0942fa0' );
}

/* The reader question for the end-of-story box: the last line of the Facebook comment that ends in "?". */
function le_question_from_comment( $comment ) {
	$lines = array_reverse( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $comment ) ) ) );
	foreach ( $lines as $l ) {
		if ( false === strpos( $l, '{{link}}' ) && false !== strpos( $l, '?' ) ) { return trim( preg_replace( '/[\x{1F300}-\x{1FAFF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', '', $l ) ); }
	}
	return '';
}

function le_import_bundles() {
	if ( ! function_exists( 'wpap_publish_article' ) ) { le_log( 'plugin not loaded — skipping import' ); return; }
	$done  = (array) get_option( 'le_imported_bundles', array() );
	$zips  = glob( LE_DATA . '/bundles/*.zip' ) ?: array();
	sort( $zips );
	$admin = le_admin_id();
	wp_set_current_user( $admin );
	foreach ( $zips as $zip ) {
		$name = basename( $zip );
		if ( in_array( $name, $done, true ) ) { continue; }
		$work = trailingslashit( get_temp_dir() ) . 'le-' . sanitize_file_name( $name );
		$z = new ZipArchive();
		if ( true !== $z->open( $zip ) ) { le_log( "cannot open $name" ); continue; }
		$z->extractTo( $work );
		$z->close();
		$items = json_decode( (string) file_get_contents( $work . '/posts.json' ), true );
		$n = 0;
		foreach ( (array) $items as $item ) {
			$slug = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
			if ( $slug && get_page_by_path( $slug, OBJECT, 'post' ) ) { continue; }   /* resume-safe */
			$opts = array( 'default_parts' => 1, 'schedule_window' => 0, 'author' => $admin );
			$img  = isset( $item['image'] ) ? realpath( $work . '/' . ltrim( (string) $item['image'], '/' ) ) : false;
			if ( $img && 0 === strpos( $img, realpath( $work ) ) ) { $opts['local_image_path'] = $img; }
			$id = wpap_publish_article( $item, $opts );
			if ( is_wp_error( $id ) ) { le_log( 'skip: ' . $id->get_error_message() ); continue; }
			$q = le_question_from_comment( $item['comment'] ?? '' );
			if ( '' !== $q ) { update_post_meta( (int) $id, '_le_question', $q ); }
			$n++;
		}
		/* Clean up the extracted bundle. */
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
		rmdir( $work );
		$done[] = $name;
		update_option( 'le_imported_bundles', $done, false );
		le_log( "$name: $n stories imported" );
	}
	if ( function_exists( 'wpap_internal_links_bake' ) ) { wpap_internal_links_bake( 500 ); }
	delete_option( 'le_cover_cache' );
}

if ( (int) get_option( 'le_seed_version', 0 ) < LE_SEED_VERSION ) { le_seed_site(); }
le_apply_ads();
le_import_bundles();
