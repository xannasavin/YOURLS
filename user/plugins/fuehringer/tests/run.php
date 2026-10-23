<?php
/* Standalone checks for the fuehringer plugin: php user/plugins/fuehringer/tests/run.php
 * Not deployed (hidden in .rsyncignore). */

if ( PHP_SAPI !== 'cli' ) exit;

define( 'YOURLS_ABSPATH', dirname( __DIR__, 4 ) );

$hooks = [];
function yourls_add_filter( $hook, $callback ) { global $hooks; $hooks[] = $hook; }
function yourls_add_action( $hook, $callback ) { global $hooks; $hooks[] = $hook; }

require dirname( __DIR__ ) . '/plugin.php';

$failed = 0;
function check( string $label, $actual, $expected ) {
    global $failed;
    if ( $actual === $expected ) {
        echo "ok   $label\n";
    } else {
        $failed++;
        echo "FAIL $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
    }
}

$core    = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
$charset = 'abcdefghjkmnpqrtuvwxy346789';

// Random keywords
$bad = 0;
for ( $i = 0; $i < 10000; $i++ ) {
    $k = fuehringer_random_keyword( $charset, 5 );
    if ( strlen( $k ) !== 5 || strspn( $k, $charset ) !== 5 ) $bad++;
}
check( 'random keyword: 10000 x length 5, charset only', $bad, 0 );

check( 'charset: unchanged when valid', fuehringer_charset( $charset, $core ), $charset );
check( 'charset: foreign chars and duplicates dropped', fuehringer_charset( 'aa-b_c!', $core ), 'abc' );
check( 'charset: hyphen dropped', fuehringer_charset( 'ab-', '0123456789abcdefghijklmnopqrstuvwxyz' ), 'ab' );
check( 'charset: < 2 chars left -> core', fuehringer_charset( '-_!x', $core ), $core );
check( 'charset: empty -> core', fuehringer_charset( '', $core ), $core );

check( 'length: 0 -> 4', fuehringer_keyword_length( 0 ), 4 );
check( 'length: -3 -> 4', fuehringer_keyword_length( -3 ), 4 );
check( "length: 'abc' -> 4", fuehringer_keyword_length( 'abc' ), 4 );
check( 'length: 5 -> 5', fuehringer_keyword_length( 5 ), 5 );
check( "length: '6' -> 6", fuehringer_keyword_length( '6' ), 6 );
check( 'length: 999 -> 32', fuehringer_keyword_length( 999 ), 32 );

check( 'next decimal: 10 -> 9', fuehringer_filter_next_decimal( 10 ), 9 );

// IPs
check( 'ip: IPv4 /24', fuehringer_pseudonymize_ip( '192.168.1.77' ), '192.168.1.0' );
check( 'ip: IPv4-mapped IPv6 -> IPv4 /24', fuehringer_pseudonymize_ip( '::ffff:10.1.2.3' ), '10.1.2.0' );
check( 'ip: IPv6 /48', fuehringer_pseudonymize_ip( '2001:db8:abcd:1234::1' ), '2001:db8:abcd::' );
check( 'ip: IPv6 expanded /48', fuehringer_pseudonymize_ip( '2001:0db8:abcd:1234:0000:0000:0000:0001' ), '2001:db8:abcd::' );
check( 'ip: IPv6 full /48', fuehringer_pseudonymize_ip( '2a02:810d:4b3f:ffff:1:2:3:4' ), '2a02:810d:4b3f::' );
check( 'ip: ::1', fuehringer_pseudonymize_ip( '::1' ), '::' );
check( "ip: '' unchanged", fuehringer_pseudonymize_ip( '' ), '' );
check( 'ip: garbage unchanged', fuehringer_pseudonymize_ip( 'garbage' ), 'garbage' );

// 404 body
check( '404: page from file', fuehringer_404_body( dirname( __DIR__ ) . '/404.html' ), file_get_contents( dirname( __DIR__ ) . '/404.html' ) );
check( '404: fallback when file missing', str_contains( fuehringer_404_body( __DIR__ . '/missing.html' ), '404' ), true );

// Hooks
$expected = [ 'random_keyword', 'get_next_decimal', 'redirect_keyword_not_found', 'infos_keyword_not_found',
              'get_IP', 'shunt_update_clicks', 'shunt_log_redirect' ];
check( 'hooks registered', $hooks, $expected );

echo $failed ? "\n$failed check(s) failed\n" : "\nall checks passed\n";
exit( $failed ? 1 : 0 );
