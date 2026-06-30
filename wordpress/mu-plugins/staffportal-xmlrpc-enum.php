<?php
/**
 * MU-Plugin: Staff Portal XML-RPC User Check
 *
 * ---------------------------------------------------------------------
 * THIS IS INTENTIONALLY VULNERABLE. DO NOT USE IN PRODUCTION.
 *
 * Vuln #1 (Username Enumeration via XML-RPC):
 *   Registers a custom XML-RPC method, `staffportal.checkUser`, exposed
 *   on /xmlrpc.php. Its response *differs* for valid vs. invalid
 *   usernames: a known staff account returns a success string, while an
 *   unknown one returns a distinct XML-RPC fault. An unauthenticated
 *   attacker can call this method (e.g. via system.multicall) to peel
 *   valid staff usernames out of a wordlist.
 *
 *   NOTE: default WordPress core does NOT leak this — it returns the
 *   same generic "Incorrect username or password" fault for both bad
 *   usernames and bad passwords. This method is what makes the
 *   enumeration deterministic and genuinely "via XML-RPC".
 *
 * mu-plugins load automatically on every request with no activation
 * step, so this is always live once the file is present.
 * ---------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'xmlrpc_methods', function ( $methods ) {
    $methods['staffportal.checkUser'] = 'staffportal_xmlrpc_check_user';
    return $methods;
} );

/**
 * @param array $args XML-RPC params; $args[0] is the username to test.
 * @return string|IXR_Error Distinguishable response per username validity.
 */
function staffportal_xmlrpc_check_user( $args ) {
    $username = isset( $args[0] ) ? (string) $args[0] : '';

    // Distinguishable behaviour: existing user -> success string,
    // unknown user -> different fault code/message.
    if ( username_exists( $username ) ) {
        return 'KNOWN_STAFF_ACCOUNT: ' . $username;
    }

    return new IXR_Error( 404, 'No such staff account' );
}
