<?php
/**
 * Plugin Name: Site Hardening
 * Description: Stops anonymous visitors from discovering account logins --
 * the REST users endpoints, author-archive enumeration, the users sitemap,
 * wp-login's error messages, and display names that match a login. Always
 * on, independent of the privateSite login wall (which switches off at
 * go-live). Managed by the enact-wp Helm chart's `siteHardening.enabled`
 * value -- see this repo's README.
 */

if (!defined('ABSPATH')) {
    exit;
}

// --- REST: /wp/v2/users ------------------------------------------------------

// Logged-in users still need these (the block editor's author picker calls
// them), so this only blocks anonymous requests, not the routes themselves.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null || is_user_logged_in()) {
        return $result;
    }
    if (preg_match('#^/wp/v2/users(/\d+)?$#', $request->get_route())) {
        return new WP_Error('enact_rest_users_forbidden', 'Not found.', ['status' => 401]);
    }
    return $result;
}, 10, 3);

// --- Author enumeration: ?author=N and /author/<slug>/ -----------------------

// redirect_canonical() is what turns ?author=1 into a 301 to /author/<slug>/,
// leaking the login in the Location header; it bails out immediately if the
// query is already a 404 (`if ( is_404() ) { return; }`). Runs at priority 1,
// ahead of redirect_canonical's default priority 10, so the 404 is set first.
add_action('template_redirect', function () {
    if (is_author() && !is_user_logged_in()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}, 1);

// --- Sitemap: drop the users provider -----------------------------------------

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    return $name === 'users' ? false : $provider;
}, 10, 2);

// --- Login errors: don't confirm which usernames exist -----------------------

add_filter('login_errors', function () {
    return 'Invalid username or password.';
});

// --- Display names: never the account's own login ----------------------------

// WordPress defaults a new user's display_name to their login (notch8-admin
// included), which a post byline or oEmbed's author_name then exposes to
// anyone, defeating the REST/enumeration blocks above. Runs once per deploy,
// flagged by an option, rather than querying every user on every request.
add_action('init', function () {
    if (get_option('enact_hardening_display_names_fixed')) {
        return;
    }
    foreach (get_users(['fields' => ['ID', 'user_login', 'display_name']]) as $user) {
        if ($user->display_name === $user->user_login) {
            wp_update_user(['ID' => $user->ID, 'display_name' => 'Enact Editorial Team']);
        }
    }
    update_option('enact_hardening_display_names_fixed', true);
});
