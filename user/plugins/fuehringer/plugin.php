<?php
/*
Plugin Name: fuehringer
Plugin URI: https://url.fuehringer.de/
Description: Site plugin for url.fuehringer.de: random keywords, real 404 page, IP pseudonymization, no click tracking for logged-in admins
Version: 1.0
Author: Thomas Führinger
*/

/* Replaces the third-party plugins 404-Redirect, Keywords Charset & Length,
 * Pseudonymize, Don't Track Admins, SSL for SSL and the core plugin Random
 * ShortURLs. See .claude-bw/plans/2026-10-23-own-plugin.md.
 *
 * Settings can be overridden in user/config.php:
 *   define( 'FUEHRINGER_KEYWORD_CHARSET', 'abcdefghjkmnpqrtuvwxy346789' );
 *   define( 'FUEHRINGER_KEYWORD_LENGTH', 5 );
 */

// No direct call
if( !defined( 'YOURLS_ABSPATH' ) ) die();

yourls_add_filter( 'random_keyword', 'fuehringer_filter_random_keyword' );
yourls_add_filter( 'get_next_decimal', 'fuehringer_filter_next_decimal' );
yourls_add_action( 'redirect_keyword_not_found', 'fuehringer_send_404' );
yourls_add_action( 'infos_keyword_not_found', 'fuehringer_send_404' ); // defensive, see plan
yourls_add_filter( 'get_IP', 'fuehringer_pseudonymize_ip' );
yourls_add_filter( 'shunt_update_clicks', 'fuehringer_shunt_admin_tracking' );
yourls_add_filter( 'shunt_log_redirect', 'fuehringer_shunt_admin_tracking' );


/* Random keywords */

// Keep only chars allowed by the core charset, without duplicates. A keyword
// with foreign chars would pass the free-check but be sanitized differently
// on insert, so fall back to the core charset if too little is left.
function fuehringer_charset( string $wanted, string $core ): string {
    $charset = '';
    foreach( str_split( $wanted ) as $char ) {
        if( str_contains( $core, $char ) && !str_contains( $charset, $char ) ) {
            $charset .= $char;
        }
    }
    return strlen( $charset ) >= 2 ? $charset : $core;
}

// At least 4 chars: with fewer the keyspace runs out and the core retry loop
// in yourls_add_new_link() never terminates.
function fuehringer_keyword_length( $raw ): int {
    return max( 4, min( 32, (int) $raw ) );
}

function fuehringer_random_keyword( string $charset, int $length ): string {
    $max = strlen( $charset ) - 1;
    $keyword = '';
    for( $i = 0; $i < $length; $i++ ) {
        $keyword .= $charset[ random_int( 0, $max ) ];
    }
    return $keyword;
}

function fuehringer_filter_random_keyword(): string {
    $wanted = defined( 'FUEHRINGER_KEYWORD_CHARSET' ) ? FUEHRINGER_KEYWORD_CHARSET : 'abcdefghjkmnpqrtuvwxy346789';
    $length = defined( 'FUEHRINGER_KEYWORD_LENGTH' ) ? FUEHRINGER_KEYWORD_LENGTH : 5;

    return fuehringer_random_keyword(
        fuehringer_charset( (string) $wanted, yourls_get_shorturl_charset() ),
        fuehringer_keyword_length( $length )
    );
}

// Don't increment the sequential keyword counter
function fuehringer_filter_next_decimal( $next ) {
    return $next - 1;
}


/* Real 404 page, without any HTTP request */

function fuehringer_404_body( string $file ): string {
    if( is_readable( $file ) ) {
        $body = file_get_contents( $file );
        if( $body !== false ) {
            return $body;
        }
    }
    return '<!doctype html><html lang="de"><meta charset="utf-8"><title>404</title>'
         . '<p>404: Diesen Kurzlink gibt es nicht. <a href="https://fuehringer.de">fuehringer.de</a></p></html>';
}

function fuehringer_send_404() {
    yourls_status_header( 404 );
    http_response_code( 404 ); // yourls_status_header() writes HTTP/1.0 on HTTP/2
    yourls_no_cache_headers();
    yourls_content_type_header( 'text/html' );
    echo fuehringer_404_body( __DIR__ . '/404.html' );
    die();
}


/* IP pseudonymization: IPv4 /24, IPv6 /48 */

function fuehringer_pseudonymize_ip( $ip ) {
    $packed = @inet_pton( (string) $ip );
    if( $packed === false ) {
        return $ip;
    }

    // IPv4-mapped IPv6 (::ffff:a.b.c.d): treat as IPv4
    if( strlen( $packed ) === 16 && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
        $packed = substr( $packed, 12 );
    }

    if( strlen( $packed ) === 4 ) {
        $packed = substr( $packed, 0, 3 ) . "\0";
    } else {
        $packed = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
    }

    return inet_ntop( $packed );
}


/* No click tracking for logged-in admins */

// Checked lazily, only when a redirect is counted, and only via the auth
// cookie: yourls_is_valid_user() would also run login/logout handling.
function fuehringer_shunt_admin_tracking( $pre ) {
    if( isset( $_COOKIE[ yourls_cookie_name() ] ) && yourls_check_auth_cookie() ) {
        return true;
    }
    return $pre;
}
