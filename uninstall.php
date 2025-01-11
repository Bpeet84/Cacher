<?php
/**
 * Cacher plugin eltávolítási script. Felelős a cache adatok és beállítások törléséért.
 *
 * @package Cacher
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Beállítások törlése
delete_option('cacher_enabled');
delete_option('cacher_enable_compression');
delete_option('cacher_exclude_logged_in');
delete_option('cacher_default_driver');
delete_option('cacher_enable_page_cache');
delete_option('cacher_enable_db_cache');
delete_option('cacher_page_ttl');
delete_option('cacher_query_ttl');
delete_option('cacher_page_driver');
delete_option('cacher_query_driver');

// Cache könyvtár törlése
$cache_dir = WP_CONTENT_DIR . '/cache/cacher/';
if (file_exists($cache_dir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $action = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$action($fileinfo->getRealPath());
    }

    global $wp_filesystem;
    WP_Filesystem();
    if ($wp_filesystem->exists($cache_dir)) {
        $wp_filesystem->rmdir($cache_dir, true);
    }
}

// APCu cache törlése
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
}

// Redis cache törlése
if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $keys = $redis->keys('cacher_*');
        if (!empty($keys)) {
            $redis->del($keys);
        }
    } catch (Exception $e) {
        // Hibakezelés
    }
}
