<?php
/**
 * MU-Plugin: Staff Portal XML-RPC User Check
 *
 * THIS IS INTENTIONALLY VULNERABLE. DO NOT USE IN PRODUCTION.
 *
 * Vuln #1 (Username Enumeration via XML-RPC):
 *   Registers a custom XML-RPC method, `staffportal.checkUser`, on
 *   /xmlrpc.php. Valid usernames return a success string; invalid ones
 *   return a distinct 404 fault. An unauthenticated attacker wordlists
 *   through this to identify valid staff accounts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'xmlrpc_methods', function ( $methods ) {
    $methods['staffportal.checkUser'] = 'staffportal_xmlrpc_check_user';
    return $methods;
} );

function staffportal_xmlrpc_check_user( $args ) {
    // IXR_Server unwraps single-parameter calls, so $args arrives as a
    // bare string for a one-arg call (and an array for multi-arg calls).
    if ( is_array( $args ) ) {
        $username = isset( $args[0] ) ? (string) $args[0] : '';
    } else {
        $username = (string) $args;
    }

    if ( username_exists( $username ) ) {
        return 'KNOWN_STAFF_ACCOUNT: ' . $username;
    }

    return new IXR_Error( 404, 'No such staff account' );
}
