<?php
/**
 * Restore an All-in-One WP Migration (.wpress) backup from the command line, without wp-admin.
 *
 *   php wpress-restore.php /tmp/site.wpress /var/www/html
 *
 * 1. Unpacks every entry: database.sql + package.json to a work dir, everything else into <wp-root>/wp-content.
 * 2. Imports database.sql into the database from <wp-root>/wp-config.php, replacing AIO's SERVMASK_PREFIX_
 *    placeholder with this install's $table_prefix (the backup's own DROP/CREATE statements replace the tables).
 * wp-config.php is never touched, so this server's DB credentials and salts stay as they are.
 */

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

$archive = $argv[1] ?? '';
$root    = rtrim( $argv[2] ?? '/var/www/html', '/' );
$content = $root . '/wp-content';
$work    = sys_get_temp_dir() . '/wpress-' . getmypid();

if ( ! is_file( $archive ) ) { fwrite( STDERR, "archive not found\n" ); exit( 1 ); }
@mkdir( $work, 0755, true );

/* ---------- 1. unpack ---------- */
const HEADER = 4377;   /* name 255 + size 14 + mtime 12 + path 4096 */
$in = fopen( $archive, 'rb' );
$files = 0;
while ( ! feof( $in ) ) {
	$h = fread( $in, HEADER );
	if ( strlen( $h ) < HEADER || trim( $h, "\0" ) === '' ) { break; }   /* end-of-archive block */
	$name = rtrim( substr( $h, 0, 255 ), "\0" );
	$size = (int) rtrim( substr( $h, 255, 14 ), "\0" );
	$path = rtrim( substr( $h, 281, 4096 ), "\0" );
	$rel  = ( '.' === $path || '' === $path ) ? $name : $path . '/' . $name;
	if ( false !== strpos( $rel, '..' ) ) { fwrite( STDERR, "skip unsafe path $rel\n" ); fseek( $in, $size, SEEK_CUR ); continue; }

	$is_meta = in_array( $rel, array( 'database.sql', 'package.json', 'multisite.json', 'blogs.json' ), true );
	$dest    = $is_meta ? $work . '/' . $rel : $content . '/' . $rel;
	@mkdir( dirname( $dest ), 0755, true );
	$out  = fopen( $dest, 'wb' );
	$left = $size;
	while ( $left > 0 ) {
		$chunk = fread( $in, min( 1048576, $left ) );
		if ( false === $chunk || '' === $chunk ) { break; }
		fwrite( $out, $chunk );
		$left -= strlen( $chunk );
	}
	fclose( $out );
	$files++;
}
fclose( $in );
echo "unpacked $files files\n";

/* ---------- 2. database ---------- */
$cfg = file_get_contents( $root . '/wp-config.php' );
$get = function ( $const ) use ( $cfg ) {
	/* Official docker image reads env vars via getenv_docker(); resolve those the same way. */
	if ( preg_match( "/define\(\s*'$const',\s*getenv_docker\('([A-Z_]+)',\s*'([^']*)'\)/", $cfg, $m ) ) {
		$v = getenv( $m[1] );
		return ( false !== $v && '' !== $v ) ? $v : $m[2];
	}
	if ( preg_match( "/define\(\s*'$const',\s*'([^']*)'/", $cfg, $m ) ) { return $m[1]; }
	return '';
};
$prefix = 'wp_';
if ( preg_match( '/\$table_prefix\s*=\s*getenv_docker\(\s*\'([A-Z_]+)\',\s*\'([^\']*)\'/', $cfg, $m ) ) {
	$prefix = getenv( $m[1] ) ?: $m[2];
} elseif ( preg_match( '/\$table_prefix\s*=\s*\'([^\']*)\'/', $cfg, $m ) ) {
	$prefix = $m[1];
}

$host = $get( 'DB_HOST' );
$port = 3306;
if ( false !== strpos( $host, ':' ) ) { list( $host, $port ) = explode( ':', $host, 2 ); }
$db = new mysqli( $host, $get( 'DB_USER' ), $get( 'DB_PASSWORD' ), $get( 'DB_NAME' ), (int) $port );
if ( $db->connect_error ) { fwrite( STDERR, 'db: ' . $db->connect_error . "\n" ); exit( 1 ); }
$db->set_charset( 'utf8mb4' );
$db->query( 'SET foreign_key_checks = 0' );

$sql  = fopen( $work . '/database.sql', 'rb' );
$stmt = '';
$ok   = 0;
$fail = 0;
while ( ( $line = fgets( $sql ) ) !== false ) {
	$stmt .= $line;
	if ( substr( rtrim( $line ), -1 ) !== ';' ) { continue; }
	$q = str_replace( 'SERVMASK_PREFIX_', $prefix, trim( $stmt ) );
	$stmt = '';
	if ( '' === $q ) { continue; }
	if ( $db->query( $q ) ) { $ok++; } else { $fail++; if ( $fail <= 5 ) { fwrite( STDERR, 'sql: ' . $db->error . "\n" ); } }
}
fclose( $sql );
echo "sql statements ok=$ok fail=$fail prefix=$prefix\n";

$r = $db->query( "SELECT option_value FROM {$prefix}options WHERE option_name IN ('siteurl','blogname','stylesheet')" );
while ( $row = $r->fetch_row() ) { echo 'option: ' . $row[0] . "\n"; }
