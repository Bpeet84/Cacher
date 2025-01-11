<?php
/**
 * Plugin Name: Cacher
 * Description: Efficient caching system for WordPress. Supports full page caching, object caching, database query caching and preload operations. Boost your site's performance with advanced caching features.
 * Version: 1.0.0
 * Author: Peter Bakonyi
 * Author URI: https://hostfix.hu/plugins
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cacher
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Biztonsági ellenőrzés
if (!defined('ABSPATH')) {
    exit;
}

// WP_CACHE definiálása, ha még nincs definiálva
if (!defined('WP_CACHE')) {
    define('WP_CACHE', true);
}

// Plugin konstansok definiálása
define('CACHER_VERSION', '1.0.0');
define('CACHER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CACHER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Osztályok korai betöltése
require_once CACHER_PLUGIN_DIR . 'includes/class-cacher-manager.php';
require_once CACHER_PLUGIN_DIR . 'includes/class-cacher-file.php';
require_once CACHER_PLUGIN_DIR . 'includes/class-cacher-apcu.php';
require_once CACHER_PLUGIN_DIR . 'includes/class-cacher-redis.php';
require_once CACHER_PLUGIN_DIR . 'includes/class-cacher-admin.php';

// Globális manager példány létrehozása a WordPress betöltődése előtt
global $cacher_manager;
if (!isset($cacher_manager)) {
    $cacher_manager = new Cacher_Manager();
}

// Plugin inicializálása
function cacher_init() {
    global $cacher_manager;
    
    // Debug információk a fordítás betöltéséről
    error_log('Cacher trying to load translation:');
    error_log('Locale: ' . determine_locale());
    error_log('MO file: ' . 'cacher-' . determine_locale() . '.mo');
    error_log('MO path: ' . dirname(plugin_basename(__FILE__)) . '/languages/');
    
    // Nyelvi fájlok betöltése
    $domain = 'cacher';
    $locale = determine_locale();
    $mofile = $domain . '-' . $locale . '.mo';
    $mofile_path = dirname(plugin_basename(__FILE__)) . '/languages/' . $mofile;
    
    $mo_full_path = WP_PLUGIN_DIR . '/' . $mofile_path;
    error_log('Full MO path: ' . $mo_full_path);
    error_log('MO file exists: ' . (file_exists($mo_full_path) ? 'yes' : 'no'));
    error_log('MO file permissions: ' . decoct(fileperms($mo_full_path) & 0777));
    
    $loaded = load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages/');
    
    error_log('Translation loaded: ' . ($loaded ? 'yes' : 'no'));

    // Cache állapot explicit ellenőrzése
    $cache_enabled = get_option('cacher_enabled');
    
    // Ha nincs beállítva, állítsuk be kikapcsolt állapotba
    if ($cache_enabled === false) {
        delete_option('cacher_enabled');
        add_option('cacher_enabled', 0, '', 'no');
        $cache_enabled = 0;
    }

    // Explicit típuskonverzió
    $cache_enabled = (int)$cache_enabled === 1;

    // Admin felület mindig inicializálódik
    if (is_admin()) {
        $cacher_admin = new Cacher_Admin($cacher_manager);
        $cacher_admin->init();
        
        // Admin JavaScript és CSS betöltése
        add_action('admin_enqueue_scripts', function() {
            wp_enqueue_script(
                'cacher-admin-js',
                CACHER_PLUGIN_URL . 'templates/admin.js',
                array('jquery'),
                CACHER_VERSION,
                true
            );
            wp_enqueue_style(
                'cacher-admin-css',
                CACHER_PLUGIN_URL . 'templates/admin.css',
                array(),
                CACHER_VERSION
            );
        });
    }

    // Cache manager csak akkor inicializálódik, ha engedélyezve van
    if ($cache_enabled) {
        $cacher_manager->init();
    } else {
        error_log('Cacher: Cache is disabled, skipping initialization');
    }

    error_log('WordPress locale: ' . get_locale());
    error_log('Site locale: ' . get_option('WPLANG'));
    error_log('User locale: ' . get_user_locale());
}
add_action('init', 'cacher_init', 0);

// Aktiválás hook
register_activation_hook(__FILE__, function() {
    // Először töröljük az összes régi beállítást
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cacher_%'");
    
    // Alapbeállítások hozzáadása - mind 'no' autoload értékkel
    $default_settings = array(
        'cacher_enabled' => 0,                    // Cache alapból bekapcsolva
        'cacher_enable_compression' => 0,         // Tömörítés bekapcsolva
        'cacher_exclude_logged_in' => 1,          // Bejelentkezett felhasználók kizárva
        'cacher_default_driver' => 'file',        // File driver az alap
        'cacher_enable_page_cache' => 1,          // Page cache bekapcsolva
        'cacher_enable_db_cache' => 1,            // DB cache bekapcsolva
        'cacher_page_driver' => 'auto',           // Auto driver választás
        'cacher_query_driver' => 'auto',          // Auto driver választás
        'cacher_page_ttl' => 3600,               // 1 óra page cache
        'cacher_query_ttl' => 60,                // 1 perc query cache
        'cacher_file_path' => WP_CONTENT_DIR . '/cache/cacher'  // Cache könyvtár
    );
    
    foreach ($default_settings as $option_name => $default_value) {
        add_option($option_name, $default_value, '', 'no');
    }
    
    // Cache könyvtárak létrehozása
    $cache_dir = WP_CONTENT_DIR . '/cache/cacher';
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }
    
    error_log('Cacher: Plugin activated with default settings (cache disabled)');
});

// Deaktiválás hook
register_deactivation_hook(__FILE__, function() {
    // Jogosultság ellenőrzés
    if (!current_user_can('activate_plugins')) {
        wp_die('Nincs megfelelő jogosultságod a plugin deaktiválásához!');
    }
    
    // Cache kikapcsolása
    update_option('cacher_enabled', 0);
    
    error_log('Cacher: Plugin deactivated and cache disabled');
});

// Cache frissítés hook regisztrálása
add_action('cacher_refresh_cache', array($cacher_manager, 'refresh_cache'));

// A plugin betöltésekor
add_action('plugins_loaded', function() {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    
    // Globális manager példány létrehozása
    global $cacher_manager;
    if (!isset($cacher_manager)) {
        $cacher_manager = new Cacher_Manager();
    }
    
    // Csak akkor inicializáljuk, ha a cache engedélyezve van
    $cache_enabled = get_option('cacher_enabled', 0);
    if ($cache_enabled) {
        $cacher_manager->init();
    }
});

// Plugin meta linkek hozzáadása
add_filter('plugin_row_meta', function($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $row_meta = array(
            'docs' => sprintf(
                '<a href="%s" target="_blank"><span class="dashicons dashicons-admin-site-alt3" style="font-size: 1em; line-height: 1.4em; width: 1em; height: 1em;"></span> %s</a>',
                esc_url('https://hostfix.hu/plugins/cacher'),
                esc_html__('Plugin Website', 'cacher')
            ),
            'support' => sprintf(
                '<a href="mailto:%s"><span class="dashicons dashicons-email" style="font-size: 1em; line-height: 1.4em; width: 1em; height: 1em;"></span> %s</a>',
                'plugins@hostfix.hu',
                esc_html__('Contact Developer', 'cacher')
            ),
            'donate' => sprintf(
                '<a href="%s" target="_blank" style="color: #8D6E63;"><span class="dashicons dashicons-heart" style="font-size: 1em; line-height: 1.4em; width: 1em; height: 1em; color: #E91E63;"></span> %s</a>',
                esc_url('https://www.paypal.com/donate/?hosted_button_id=D3SHN8RX5FKS2'),
                esc_html__('Support Development', 'cacher')
            )
        );
        
        return array_merge($links, $row_meta);
    }
    return $links;
}, 10, 2);

// Plugin action linkek hozzáadása
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('options-general.php?page=cacher-settings'),
        esc_html__('Settings', 'cacher')
    );
    
    // Settings link az elejére
    array_unshift($links, $settings_link);
    
    return $links;
});
