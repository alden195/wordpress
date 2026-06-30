<?php
/**
 * Plugin Name: Staff Portal Manager
 * Description: Custom CMS plugin for the internal Staff Portal.
 *              Lets staff view announcements/resources and lets
 *              administrators publish announcements, manage resources,
 *              and install portal "template packs" (plugins).
 * Version: 1.0
 * Author: Staff Portal Team
 *
 * ---------------------------------------------------------------------
 * THIS PLUGIN IS INTENTIONALLY VULNERABLE. DO NOT USE IN PRODUCTION.
 *
 * Bug 1 (Broken Access Control / Privilege Escalation):
 *   wp_ajax_staffportal_publish_announcement only checks
 *   is_user_logged_in() instead of current_user_can('manage_options')
 *   (or similar), so any authenticated staff account can perform
 *   admin-only content actions.
 *
 * Bug 2 (Insecure Plugin Upload -> RCE):
 *   wp_ajax_staffportal_import_template accepts an uploaded .zip,
 *   extracts it straight into wp-content/plugins with no capability
 *   check, no nonce check, and no validation of file contents, then
 *   auto-activates any newly added plugin. Because the handler also
 *   skips the activate_plugins capability check, even a low-privilege
 *   subscriber can drop and activate a plugin containing a PHP web
 *   shell, yielding remote code execution as the web server user.
 *   (The extracted PHP is also directly web-accessible under
 *   wp-content/plugins/, so RCE works even without activation.)
 * ---------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------
// Admin menu page (just renders forms; auth handled - badly - in the
// AJAX handlers below).
// ---------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_menu_page(
        'Staff Portal Manager',
        'Staff Portal',
        'read', // <- intentionally low: any logged-in user can see the menu
        'staff-portal-manager',
        'staffportal_render_admin_page'
    );
} );

function staffportal_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>Staff Portal Manager</h1>

        <h2>Publish Announcement</h2>
        <form id="staffportal-announcement-form">
            <input type="text" name="title" placeholder="Announcement title" /><br><br>
            <textarea name="body" placeholder="Announcement body"></textarea><br><br>
            <button type="submit">Publish</button>
        </form>

        <hr>

        <h2>Import Portal Template Pack</h2>
        <p>Upload a .zip template/plugin pack to extend the portal.</p>
        <form id="staffportal-template-form" enctype="multipart/form-data">
            <input type="file" name="template_pack" accept=".zip" /><br><br>
            <button type="submit">Import &amp; Activate</button>
        </form>

        <pre id="staffportal-result"></pre>

        <script>
        document.getElementById('staffportal-announcement-form').addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'staffportal_publish_announcement');
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.text())
                .then(t => document.getElementById('staffportal-result').textContent = t);
        });
        document.getElementById('staffportal-template-form').addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'staffportal_import_template');
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.text())
                .then(t => document.getElementById('staffportal-result').textContent = t);
        });
        </script>
    </div>
    <?php
}

// ---------------------------------------------------------------------
// BUG 1: Privilege escalation via missing capability check.
// Any logged-in user (including a low-privilege "staff" account) can
// call this directly: POST action=staffportal_publish_announcement
// to wp-admin/admin-ajax.php
// ---------------------------------------------------------------------
add_action( 'wp_ajax_staffportal_publish_announcement', 'staffportal_publish_announcement' );

function staffportal_publish_announcement() {
    if ( ! is_user_logged_in() ) {                 // <-- should also check
        wp_die( 'Not logged in', 403 );             //     current_user_can('publish_pages')
    }

    $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Untitled';
    $body  = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

    $post_id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => $body,
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => get_current_user_id(),
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_die( 'Failed to publish announcement.' );
    }

    echo 'Announcement published as post ID ' . intval( $post_id );
    wp_die();
}

// ---------------------------------------------------------------------
// BUG 2: Insecure plugin upload -> RCE.
// Any logged-in user can upload a zip which gets extracted directly
// into wp-content/plugins/ and activated. No nonce, no capability
// check, no file-type validation.
// ---------------------------------------------------------------------
add_action( 'wp_ajax_staffportal_import_template', 'staffportal_import_template' );

function staffportal_import_template() {
    if ( ! is_user_logged_in() ) {                 // <-- should be current_user_can('install_plugins')
        wp_die( 'Not logged in', 403 );
    }

    if ( empty( $_FILES['template_pack'] ) ) {
        wp_die( 'No file uploaded.' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    WP_Filesystem();

    $tmp_name  = $_FILES['template_pack']['tmp_name'];
    $plugin_dir = WP_PLUGIN_DIR;

    // Snapshot installed plugins so we can auto-activate whatever the
    // upload adds. (No capability check anywhere here - that's the bug.)
    $before = array_keys( get_plugins() );

    // No validation of zip contents whatsoever.
    $unzip_result = unzip_file( $tmp_name, $plugin_dir );

    if ( is_wp_error( $unzip_result ) ) {
        wp_die( 'Import failed: ' . esc_html( $unzip_result->get_error_message() ) );
    }

    // Auto-activate any plugin the upload introduced. activate_plugin()
    // fires the new plugin's activation hook immediately, and the plugin
    // then loads on every subsequent request - all triggered by a plain
    // logged-in user, with no install_plugins / activate_plugins check.
    wp_clean_plugins_cache();
    $after = array_keys( get_plugins() );
    $new   = array_diff( $after, $before );

    $activated = [];
    foreach ( $new as $plugin_file ) {
        $result = activate_plugin( $plugin_file ); // intentionally no cap check
        if ( ! is_wp_error( $result ) ) {
            $activated[] = $plugin_file;
        }
    }

    echo 'Template pack imported into wp-content/plugins/. ';
    if ( $activated ) {
        echo 'Auto-activated: ' . esc_html( implode( ', ', $activated ) ) . '. ';
    }
    echo 'Uploaded PHP is also reachable directly under wp-content/plugins/.';
    wp_die();
}
