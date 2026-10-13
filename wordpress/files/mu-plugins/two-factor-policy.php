<?php
/**
 * Plugin Name: Two-Factor Policy
 * Description: Site policy around the Two Factor plugin (wordpress.org/plugins/two-factor).
 * Authenticator app plus backup codes only, application passwords limited to
 * named automation accounts, and, when enforcement is on, no editing access
 * for any browser session that has not passed two-factor. Managed by the
 * enact-wp Helm chart's `twoFactor` values -- see this repo's README.
 *
 * Configuration comes from the container environment (set by the chart, and by
 * docker-compose.yml locally):
 *   ENACT_2FA_ENFORCE             "1" to enforce; anything else is policy-only.
 *   ENACT_2FA_APP_PASSWORD_USERS  JSON list of user logins allowed to use
 *                                 application passwords, e.g. ["notch8-admin"].
 */

if (!defined('ABSPATH')) {
    exit;
}

const ENACT_2FA_PLUGIN = 'two-factor/two-factor.php';
const ENACT_2FA_REST_NAMESPACE = '/two-factor/1.0/';

function enact_2fa_enforced() {
    return getenv('ENACT_2FA_ENFORCE') === '1';
}

function enact_2fa_app_password_users() {
    $users = json_decode((string) getenv('ENACT_2FA_APP_PASSWORD_USERS'), true);
    return is_array($users) ? array_map('strval', $users) : [];
}

// --- Policy (always on) ------------------------------------------------------

// Authenticator app (TOTP) and backup codes only. Email codes are removed
// because the pods have no outbound mail, so a code would never arrive; the
// debug-only dummy method goes too. Priority 99 runs after the plugin's own
// filter on this hook. With Email unregistered, the plugin's lockout fallback
// (Email by default) cannot resolve, so a user whose methods disappear is
// refused rather than let through on a password alone.
add_filter('two_factor_providers', function ($providers) {
    unset($providers['Two_Factor_Email'], $providers['Two_Factor_Dummy']);
    return $providers;
}, 99);

// Application passwords are the deliberate API path around two-factor (the
// plugin itself allows them for 2FA users). Limit them to the automation
// accounts that bin/provision uses. This also disables any application
// password already issued to anyone else.
add_filter('wp_is_application_passwords_available_for_user', function ($available, $user) {
    return $available && in_array($user->user_login, enact_2fa_app_password_users(), true);
}, 10, 2);

// --- Enforcement (ENACT_2FA_ENFORCE=1) -----------------------------------------

if (!enact_2fa_enforced()) {
    return;
}

// Once Two Factor is active, nobody can deactivate it from wp-admin: any write
// of the active-plugins list that drops it puts it back. (A plugin must be
// inactive before WordPress will delete it, so this also blocks deletion.)
// If it is deactivated some other way, the gate below fails closed.
add_filter('pre_update_option_active_plugins', function ($new, $old) {
    $new = (array) $new;
    if (in_array(ENACT_2FA_PLUGIN, (array) $old, true) && !in_array(ENACT_2FA_PLUGIN, $new, true)
        && file_exists(WP_PLUGIN_DIR . '/' . ENACT_2FA_PLUGIN)) {
        $new[] = ENACT_2FA_PLUGIN;
    }
    return $new;
}, 10, 2);

add_filter('plugin_action_links_' . ENACT_2FA_PLUGIN, function ($links) {
    unset($links['deactivate'], $links['delete']);
    return $links;
});

function enact_2fa_has_authenticator($user) {
    return class_exists('Two_Factor_Core')
        && in_array('Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user($user), true);
}

/**
 * Whether the current request may use the account's full capabilities.
 *
 * True for WP-CLI and for requests authenticated with an application password
 * (the API exemption). For browser sessions, true only when the user has an
 * authenticator app set up AND this session passed two-factor (the plugin
 * records that in the session at its 2FA prompt, and when someone enables
 * two-factor from the profile form). Password-only sessions, including ones
 * that started before the user enrolled, are not verified. If the Two Factor
 * plugin is missing, nothing is verified: enforcement fails closed.
 */
function enact_2fa_request_verified() {
    static $verified = [];
    $user = wp_get_current_user();
    if (isset($verified[$user->ID])) {
        return $verified[$user->ID];
    }

    if ((defined('WP_CLI') && WP_CLI) || did_action('application_password_did_authenticate')) {
        $result = true;
    } elseif (!class_exists('Two_Factor_Core')) {
        $result = false;
    } else {
        $result = $user->exists()
            && enact_2fa_has_authenticator($user)
            && Two_Factor_Core::is_user_using_two_factor($user)
            && Two_Factor_Core::is_current_user_session_two_factor() !== false;
    }

    // Cache per user for the rest of the request, but only once every plugin
    // has loaded; an earlier answer could be a false negative from
    // Two_Factor_Core not being defined yet.
    if (did_action('plugins_loaded')) {
        $verified[$user->ID] = $result;
    }
    return $result;
}

// The gate itself: an unverified session keeps `read` (front end, dashboard,
// and its own profile page, where it can enroll) and nothing else. Editing
// your own profile needs no capability in WordPress, and the plugin's
// enrollment endpoints check exactly that, so enrollment still works.
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (!($user instanceof WP_User) || !did_action('set_current_user') || $user->ID !== get_current_user_id()) {
        return $allcaps;
    }
    if (enact_2fa_request_verified()) {
        return $allcaps;
    }
    return array_intersect_key($allcaps, ['read' => true, 'level_0' => true]);
}, 99, 4);

// Cookie-authenticated REST calls from an unverified session may reach only
// the plugin's own enrollment endpoints. Capability checks already refuse
// most of the rest; this closes off anything that only needs `read`.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null || !is_user_logged_in() || enact_2fa_request_verified()) {
        return $result;
    }
    if (strpos($request->get_route(), ENACT_2FA_REST_NAMESPACE) === 0) {
        return $result;
    }
    return new WP_Error('enact_2fa_required', 'Two-factor authentication is required.', ['status' => 403]);
}, 10, 3);

// Send people where they can fix it: users without an authenticator app go to
// their profile to set one up; users with one, whose session has not passed
// two-factor, go to the plugin's code prompt, which upgrades this session.
function enact_2fa_next_step_url() {
    if (!enact_2fa_has_authenticator(wp_get_current_user())) {
        return admin_url('profile.php#two-factor-options');
    }
    return add_query_arg('redirect_to', rawurlencode(admin_url()), Two_Factor_Core::get_user_two_factor_revalidate_url());
}

function enact_2fa_redirect_unverified() {
    global $pagenow;
    if (wp_doing_ajax() || $pagenow === 'profile.php' || enact_2fa_request_verified()) {
        return;
    }
    // Plugin missing: there is nowhere useful to send anyone, so the notice
    // below explains and the stripped capabilities do the rest.
    if (!class_exists('Two_Factor_Core')) {
        return;
    }
    wp_safe_redirect(enact_2fa_next_step_url());
    exit;
}

// Both hooks: admin_init covers pages an unverified session can still open
// (the dashboard), and admin_page_access_denied covers the ones WordPress's
// menu check refuses with a 403 before admin_init ever runs (edit.php etc.).
add_action('admin_page_access_denied', 'enact_2fa_redirect_unverified');
add_action('admin_init', 'enact_2fa_redirect_unverified', 1);

add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if ($user instanceof WP_User && class_exists('Two_Factor_Core') && !enact_2fa_has_authenticator($user)) {
        return admin_url('profile.php#two-factor-options');
    }
    return $redirect_to;
}, 10, 3);

add_action('admin_notices', function () {
    if (enact_2fa_request_verified()) {
        return;
    }
    if (!class_exists('Two_Factor_Core')) {
        $message = 'This site requires two-factor authentication, but the Two Factor plugin is not installed. '
            . 'Editing is suspended for every account until a site operator restores it.';
    } elseif (!enact_2fa_has_authenticator(wp_get_current_user())) {
        $message = 'This site requires two-factor authentication. Set up an authenticator app under '
            . '<a href="#two-factor-options">Two-Factor Options</a> below, and save the backup codes somewhere safe. '
            . 'Until then your account can view the site but not edit it.';
    } else {
        $message = 'Your authenticator app is set up. <a href="' . esc_url(enact_2fa_next_step_url())
            . '">Enter a code from it</a> to finish signing in and get your editing access back.';
    }
    echo '<div class="notice notice-warning"><p>' . wp_kses($message, ['a' => ['href' => []]]) . '</p></div>';
});
