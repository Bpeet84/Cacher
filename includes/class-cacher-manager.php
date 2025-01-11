<?php
/**
 * A Cacher fő kezelő osztálya. Felelős a cache driver-ek kezeléséért és a cache műveletek koordinálásáért.
 *
 * @package Cacher
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cacher_Manager {
    /**
     * @var array $drivers A használt cache driver-ek
     */
    private $drivers = array();

    /**
     * @var string $default_driver Az alapértelmezett cache driver
     */
    private $default_driver = 'file';

    /**
     * @var array Belső cache tároló
     */
    private $cache = array();

    /**
     * @var bool $compression_enabled Tömörítés engedélyezve
     */
    private $compression_enabled = false;

    /**
     * @var bool $exclude_logged_in Bejelentkezett felhasználók kizárása
     */
    private $exclude_logged_in = false;

    /**
     * @var array $stats Cache statisztikák
     */
    private $stats = array(
        'get' => 0,
        'set' => 0,
        'delete' => 0,
        'hits' => 0,
        'misses' => 0
    );

    /**
     * @var array $traffic_stats Forgalmi statisztikák
     */
    private $traffic_stats = array();

    /**
     * Cache statisztikák lekérése
     * @return array Cache statisztikák
     */
    public function get_stats() {
        $stats = [
            'types' => [
                'page' => [
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0,
                    'avg_generation_time' => 0,
                    'current_driver' => 'none',
                    'driver_available' => false
                ],
                'query' => [
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0,
                    'avg_generation_time' => 0,
                    'current_driver' => 'none',
                    'driver_available' => false
                ]
            ],
            'drivers' => [
                'file' => [
                    'available' => false,
                    'used_space' => '0 B',
                    'items' => 0
                ],
                'apcu' => [
                    'available' => false,
                    'used_space' => '0 B',
                    'items' => 0
                ],
                'redis' => [
                    'available' => false,
                    'used_space' => '0 B',
                    'items' => 0
                ]
            ]
        ];

        // Cache típusok statisztikáinak lekérése
        foreach (['page', 'query'] as $type) {
            // Statisztikák lekérése az options táblából
            $type_stats = get_option("cacher_stats_{$type}", [
                'hits' => 0,
                'misses' => 0,
                'total_time' => 0,
                'last_update' => time()
            ]);

            // Hit ratio számítás
            $total_requests = (int)$type_stats['hits'] + (int)$type_stats['misses'];
            $hit_ratio = $total_requests > 0 ? 
                round(((int)$type_stats['hits'] / $total_requests) * 100, 2) : 0;

            // Átlagos generálási idő számítás (mikroszekundumban)
            $avg_generation_time = $total_requests > 0 ? 
                round(($type_stats['total_time'] / $total_requests) / 1000, 2) : 0;

            $driver = $this->get_driver($type);
            
            $stats['types'][$type] = [
                'hits' => (int)$type_stats['hits'],
                'misses' => (int)$type_stats['misses'],
                'hit_ratio' => $hit_ratio,
                'avg_generation_time' => $avg_generation_time,
                'current_driver' => $driver,
                'driver_available' => isset($this->drivers[$driver]) ? $this->drivers[$driver]->is_available() : false
            ];
        }

        // Driver statisztikák lekérése
        foreach (['file', 'apcu', 'redis'] as $driver_name) {
            // Alapértelmezett értékek beállítása
            $stats['drivers'][$driver_name] = [
                'available' => false,
                'used_space' => '0 B',
                'items' => 0
            ];
            
            // Ha a driver létezik és elérhető, akkor frissítjük a statisztikákat
            if (isset($this->drivers[$driver_name]) && $this->drivers[$driver_name]->is_available()) {
                try {
                    $driver_stats = $this->drivers[$driver_name]->get_stats();
                    $stats['drivers'][$driver_name]['available'] = true;
                    
                    // Használt memória/tárhely
                    if (isset($driver_stats['used_memory'])) {
                        $stats['drivers'][$driver_name]['used_space'] = $driver_stats['used_memory'];
                    } elseif (isset($driver_stats['cache_size'])) {
                        $stats['drivers'][$driver_name]['used_space'] = size_format($driver_stats['cache_size']);
                    }
                    
                    // Cache elemek száma
                    $stats['drivers'][$driver_name]['items'] = $driver_stats['items'] ?? 0;
                } catch (Exception $e) {
                    error_log("[Cacher Error] Failed to get stats from {$driver_name} driver: " . $e->getMessage());
                }
            }
        }

        return $stats;
    }

    /**
     * Cache találat rögzítése
     * @param string $type Cache típusa (page/query)
     * @param float $generation_time Generálási idő mikroszekundumban
     */
    public function record_hit($type, $generation_time = 0) {
        // WordPress options statisztika
        $stats = get_option("cacher_stats_{$type}", [
            'hits' => 0,
            'misses' => 0,
            'total_time' => 0,
            'last_update' => time()
        ]);

        $stats['hits']++;
        $stats['total_time'] += $generation_time;
        $stats['last_update'] = time();

        update_option("cacher_stats_{$type}", $stats, false);

        // Driver statisztika frissítése
        $driver = $this->get_driver($type);
        if (isset($this->drivers[$driver])) {
            $this->drivers[$driver]->record_hit();
        }
    }

    /**
     * Cache kihagyás rögzítése
     * @param string $type Cache típusa (page/query)
     * @param float $generation_time Generálási idő mikroszekundumban
     */
    public function record_miss($type, $generation_time = 0) {
        // WordPress options statisztika
        $stats = get_option("cacher_stats_{$type}", [
            'hits' => 0,
            'misses' => 0,
            'total_time' => 0,
            'last_update' => time()
        ]);

        $stats['misses']++;
        $stats['total_time'] += $generation_time;
        $stats['last_update'] = time();

        update_option("cacher_stats_{$type}", $stats, false);

        // Driver statisztika frissítése
        $driver = $this->get_driver($type);
        if (isset($this->drivers[$driver])) {
            $this->drivers[$driver]->record_miss();
        }
    }

    /**
     * Statisztikák törlése
     */
    public function reset_stats() {
        foreach (['page', 'query'] as $type) {
            delete_option("cacher_stats_{$type}");
        }
    }

    /**
     * Forgalmi statisztikák törlése
     */
    private function reset_traffic_stats() {
        foreach (['page', 'query'] as $type) {
            $this->traffic_stats[$type] = ['hits' => 0, 'hours' => 0, 'last_update' => 0];
        }
        delete_option('cacher_traffic_stats');
    }

    /**
     * Plugin inicializálása
     */
    public function init() {
        // Először ellenőrizzük, hogy a cache engedélyezve van-e
        $cache_enabled = get_option('cacher_enabled');
        
        // Ha nincs beállítva vagy 0, akkor nem inicializálunk
        if ($cache_enabled === false || (int)$cache_enabled === 0) {
            return;
        }
        
        // Manager inicializálása
        $this->reconfigure_settings();
        $this->load_drivers();
        $this->register_hooks();
    }    

    /**
     * Objektum cache lekérése
     */
    public function get_cache($key, $group = 'default', $force = false) {
        $start_time = microtime(true);
        
        // Belső cache ellenőrzése
        if (isset($this->cache[$group][$key]) && !$force) {
            $duration = microtime(true) - $start_time;
            $this->record_cache_hit('object', $duration);
            return $this->cache[$group][$key];
        }
    
        // Driver cache ellenőrzése
        $cache_key = $this->build_key($key, $group);
        
        $data = $this->get($cache_key);
        $duration = microtime(true) - $start_time;
    
        if ($data === false) {
            $this->record_cache_miss('object', $duration);
            return false;
        }
    
        // Cache találat rögzítése
        $this->cache[$group][$key] = $data;
        $this->record_cache_hit('object', $duration);
        
        return $data;
    }    
    
    /**
     * Objektum cache törlése
     */
    public function delete_cache($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        $cache_key = $this->build_key($key, $group);
        $this->stats['delete']++;

        unset($this->cache[$group][$key]);
        return $this->delete($cache_key);
    }

    /**
     * Teljes cache ürítése
     */
    public function flush_cache() {
        $this->cache = array();
        return $this->flush();
    }

    /**
     * Cache statisztikák lekérése
     */
    public function get_cache_stats() {
        return $this->stats;
    }

    /**
     * Singleton példány
     */
    private static $instance = null;

    /**
     * Singleton példány lekérése
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Cache driver-ek betöltése
     * @responsibility Driver-ek inicializálása és elérhetőségük ellenőrzése
     */
    private function load_drivers() {
        $config = $this->get_config();
        
        // File driver mindig betöltődik
        $this->drivers['file'] = new Cacher_File(
            $config['file']['cache_dir']
        );

        // APCu driver betöltése ha elérhető
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            $this->drivers['apcu'] = new Cacher_Apcu($this);
        }

        // Redis driver betöltése ha elérhető
        if (extension_loaded('redis')) {
            $this->drivers['redis'] = new Cacher_Redis($this);
        }

        // Alapértelmezett driver beállítása
        $this->default_driver = get_option('cacher_default_driver', 'file');
    }

    /**
     * Redis elérhetőségének ellenőrzése
     * @responsibility Redis szerver elérhetőségének és működőképességének ellenőrzése
     * @return bool True ha a Redis elérhető
     */
    private function check_redis_availability() {
        // Redis kiterjesztés ellenőrzése
        if (!extension_loaded('redis')) {
            $this->log_error('Redis extension not loaded');
            return false;
        }

        // Redis osztály ellenőrzése
        if (!class_exists('Redis')) {
            $this->log_error('Redis class not found');
            return false;
        }

        // Redis szerver elérhetőségének ellenőrzése
        try {
            $redis = new Redis();
            
            if (@$redis->connect('127.0.0.1', 6379, 2)) {
                $ping = $redis->ping();
                if ($ping === true || $ping === '+PONG') {
                    // Teszteljük a működést
                    $test_key = 'cacher_test_' . uniqid();
                    $test_value = 'test_' . time();
                    
                    if (!$redis->set($test_key, $test_value)) {
                        $this->log_error('Redis SET test failed');
                        return false;
                    }
                    
                    $retrieved_value = $redis->get($test_key);
                    if ($retrieved_value !== $test_value) {
                        $this->log_error('Redis GET test failed');
                        return false;
                    }
                    
                    $redis->del($test_key);
                    $redis->close();
                    return true;
                }
                $this->log_error('Redis server not responding to PING');
            }
        } catch (Exception $e) {
            $this->log_error('Redis connection error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Naplózás
     * @param string $message Az üzenet
     */
    protected function log($message, $data = null, $is_error = false) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if ($is_error) {
            // Csak kritikus hibák logolása WordPress szabvány szerint
            $log_message = sprintf('[Cacher] %s%s',
                $message,
                $data ? ' Data: ' . wp_json_encode($data) : ''
            );
            error_log($log_message);
        }
    }

    /**
     * Kritikus hiba naplózása
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Cacher] %s', $message));
        }
    }

    /**
     * APCu driver elérhetőségének ellenőrzése
     *
     * @return bool True ha a driver elérhető és működőképes
     */
    private function is_apcu_available() {
        if (!extension_loaded('apcu') || !function_exists('apcu_store') || !function_exists('apcu_fetch')) {
            $this->log_error('APCu extension not loaded');
            return false;
        }
        
        // Teszteljük a működést egy egyszerű cache művelettel
        $test_key = 'cacher_apcu_test';
        $test_value = 'test_value';
        
        try {
            if (!apcu_store($test_key, $test_value, 1)) {
                $this->log_error('APCu store operation failed');
                return false;
            }
            if (apcu_fetch($test_key) !== $test_value) {
                $this->log_error('APCu fetch operation failed');
                return false;
            }
            apcu_delete($test_key);
            return true;
        } catch (Exception $e) {
            $this->log_error('APCu test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Redis driver elérhetőségének ellenőrzése
     *
     * @return bool True ha a driver elérhető és működőképes
     */
    private function is_redis_available() {
        if (!extension_loaded('redis')) {
            $this->log_error('Redis extension not loaded');
            return false;
        }

        if (!class_exists('Redis')) {
            $this->log_error('Redis class not found');
            return false;
        }

        try {
            $redis = new Redis();
            
            if (@$redis->connect('127.0.0.1', 6379, 2)) {
                $ping = $redis->ping();
                if ($ping === true || $ping === '+PONG') {
                    $test_key = 'cacher_test_' . uniqid();
                    $test_value = 'test_' . time();
                    
                    if (!$redis->set($test_key, $test_value)) {
                        $this->log_error('Redis SET test failed');
                        return false;
                    }
                    
                    $retrieved_value = $redis->get($test_key);
                    if ($retrieved_value !== $test_value) {
                        $this->log_error('Redis GET test failed');
                        return false;
                    }
                    
                    $redis->del($test_key);
                    $redis->close();
                    return true;
                }
                $this->log_error('Redis server not responding to PING');
            }
        } catch (Exception $e) {
            $this->log_error('Redis connection error: ' . $e->getMessage());
        }
        return false;
    }
    
    /**
     * Driver elérhetőségének ellenőrzése
     *
     * @param string $driver_name A driver neve
     * @return bool True ha a driver elérhető
     */
    public function is_driver_available($driver_name) {
        if (!isset($this->drivers[$driver_name])) {
            return false;
        }

        try {
            return $this->drivers[$driver_name]->is_available();
        } catch (Exception $e) {
            error_log("[Cacher Error] Driver availability check failed for {$driver_name}: " . $e->getMessage());
            return false;
        }
    }    

    /**
     * WordPress hook-ok regisztrálása
     * @responsibility AJAX hívások kezelése, biztonsági beállítások
     */
    private function register_hooks() {
        // Oldal cache hook-ok
        add_action('template_redirect', array($this, 'handle_page_cache'), 1);
        add_action('save_post', array($this, 'invalidate_post_cache'));
        add_action('delete_post', array($this, 'invalidate_post_cache'));
        
        // Query cache hook-ok
        add_filter('posts_pre_query', array($this, 'handle_query_cache'), 10, 2);
        
        // AJAX hook-ok biztonsági beállításokkal
        add_action('wp_ajax_cacher_admin_action', function() {
            // Rate limiting ellenőrzése
            $transient_key = 'cacher_rate_limit_' . md5($_SERVER['REMOTE_ADDR']);
            $attempts = get_transient($transient_key) ?: 0;
            
            if ($attempts > 5) {
                wp_send_json_error(['message' => __('Túl sok kérés, kérlek várj egy kicsit!', 'cacher')], 429);
                return;
            }
            
            // Input validáció
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cacher_admin_nonce')) {
                wp_send_json_error(['message' => __('Érvénytelen kérés!', 'cacher')], 403);
                return;
            }
            
            // Timeout beállítása
            set_time_limit(30); // 30 másodperces timeout
            
            try {
                // Kérés feldolgozása
                $result = $this->handle_admin_request($_POST);
                
                // Rate limiting frissítése
                set_transient($transient_key, $attempts + 1, MINUTE_IN_SECONDS);
                
                wp_send_json_success($result);
            } catch (Exception $e) {
                wp_send_json_error(['message' => $e->getMessage()], 500);
            }
        });
    }

    /**
     * Admin kérés kezelése
     * @param array $data A bejövő adatok
     * @return array Az eredmény
     * @throws Exception Hibák esetén
     */
    private function handle_admin_request($data) {
        if (empty($data['action'])) {
            throw new Exception(__('Hiányzó művelet!', 'cacher'));
        }
        
        switch ($data['action']) {
            case 'clear_cache':
                if (!current_user_can('manage_options')) {
                    throw new Exception(__('Nincs jogosultságod ehhez a művelethez!', 'cacher'));
                }
                return ['success' => $this->clear_all_cache()];
                
            case 'get_stats':
                return $this->get_stats();
                
            default:
                throw new Exception(__('Ismeretlen művelet!', 'cacher'));
        }
    }

    /**
     * Oldal cache kezelése
     */
    public function handle_page_cache() {
        if (is_admin() || !get_option('cacher_enable_page_cache', 1)) {
            return;
        }

        $url = $_SERVER['REQUEST_URI'];

        // Kizárások ellenőrzése
        $excluded_urls = explode("\n", get_option('cacher_excluded_urls', ''));
        foreach ($excluded_urls as $pattern) {
            $pattern = trim($pattern);
            if (!empty($pattern) && fnmatch($pattern, $url)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Cacher: URL excluded from cache: {$url} (pattern: {$pattern})");
                }
                return;
            }
        }

        // Hook kizárások ellenőrzése
        $excluded_hooks = explode("\n", get_option('cacher_excluded_hooks', ''));
        foreach ($excluded_hooks as $hook) {
            $hook = trim($hook);
            if (!empty($hook) && has_action($hook)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Cacher: Page excluded due to active hook: {$hook}");
                }
                return;
            }
        }

        // Cache kulcs generálása
        $cache_key = 'page_' . md5($url . serialize($_GET));

        // Cache ellenőrzése
        $cached_content = $this->get_page_cache($url, function() {
            return false; // Első ellenőrzésnél csak megnézzük van-e cache
        });

        if ($cached_content !== false) {
            error_log("Cacher: Serving page from cache");
            echo wp_kses_post($cached_content);
            exit;
        }

        // Ha nincs cache, akkor buffer indítása
        ob_start(function($buffer) use ($url, $cache_key) {
            if (!empty($buffer)) {
                error_log("Cacher: Saving page to cache: {$url}");
                $this->get_page_cache($url, function() use ($buffer) {
                    return $buffer;
                });
            }
            return $buffer;
        });
        
        // Biztosítjuk, hogy a buffer lezáruljon
        add_action('shutdown', function() {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }, 0);
    }

    /**
     * Query cache kezelése
     * @param mixed $posts Az eredeti posts tömb
     * @param WP_Query $query A WP_Query példány
     * @return mixed A cache-elt vagy eredeti posts tömb
     */
    public function handle_query_cache($posts, $query) {
        if (!get_option('cacher_enable_db_cache', 1) || $query->is_admin) {
            return $posts;
        }

        $sql = $query->request;
        if (empty($sql)) {
            return $posts;
        }

        // Kizárások ellenőrzése
        $excluded_queries = explode("\n", get_option('cacher_excluded_db_queries', ''));
        foreach ($excluded_queries as $pattern) {
            $pattern = trim($pattern);
            if (!empty($pattern) && fnmatch($pattern, $sql)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Cacher: Query excluded from cache: {$sql} (pattern: {$pattern})");
                }
                return $posts;
            }
        }

        return $this->get_query_cache($sql, function() use ($posts) {
            return $posts;
        });
    }


    /**
     * Adatok lekérése a cache-ből
     *
     * @param string $key A cache kulcs
     * @param string $driver A használandó driver (opcionális)
     * @return mixed A cache-ből lekérdezett adat
     */
    public function get($key, $driver = null) {
        $driver_name = $driver ?? $this->get_driver('object');
        
        if (isset($this->drivers[$driver_name]) && $this->drivers[$driver_name] !== null) {
            $value = $this->drivers[$driver_name]->get($key);
            
            if ($value !== false) {
                $this->drivers[$driver_name]->record_hit();
                return $value;
            } else {
                $this->drivers[$driver_name]->record_miss();
                return false;
            }
        }
        
        return false;
    }

    /**
     * Adatok mentése a cache-be
     *
     * @param string $key A cache kulcs
     * @param mixed $data A mentendő adat
     * @param int $expiration Lejárati idő másodpercben
     * @param string $driver A használandó driver (opcionális)
     * @return bool Sikeres mentés esetén true
     */
    public function set($key, $data, $expiration = 0, $driver = null) {
        $driver_name = $driver ?? $this->get_driver('object');
        
        // Explicit int konverzió a lejárati időre
        $expiration = (int)$expiration;
        
        if (isset($this->drivers[$driver_name]) && $this->drivers[$driver_name] !== null) {
            $result = $this->drivers[$driver_name]->set($key, $data, $expiration);
            if (!$result) {
                $this->log_error("Failed to set cache for key: {$key}");
            }
            return $result;
        }
        $this->log_error("Driver not available for set operation: {$driver_name}");
        return false;
    }

    /**
     * Adatok törlése a cache-ből
     *
     * @param string $key A cache kulcs
     * @param string $driver A használandó driver (opcionális)
     * @return bool Sikeres törlés esetén true
     */
    public function delete($key, $driver = null) {
        $driver_name = $driver ?? $this->get_driver('object');
        if (isset($this->drivers[$driver_name]) && $this->drivers[$driver_name] !== null) {
            return $this->drivers[$driver_name]->delete($key);
        }
        $this->log_error("Driver not available for delete operation: {$driver_name}");
        return false;
    }

    /**
     * File driver példányának lekérése
     *
     * @return Cacher_File
     */
    public function get_file_driver() {
        return $this->drivers['file'];
    }

    /**
     * APCu driver példányának lekérése
     *
     * @return Cacher_Apcu|null
     */
    public function get_apcu_driver() {
        return $this->drivers['apcu'];
    }

    /**
     * Redis driver példányának lekérése
     *
     * @return Cacher_Redis|null
     */
    public function get_redis_driver() {
        return $this->drivers['redis'];
    }


    /**
     * Méret formázása olvasható formátumra
     *
     * @param int $size Méret bájtokban
     * @return string Formázott méret
     */
    public function format_size($size) {
        if ($size == 0) {
            return '0 B';
        }

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = floor(log($size, 1024));
        return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
    }

    /**
     * Összes cache törlése
     */
    public function clear_all_cache() {
        foreach ($this->drivers as $driver) {
            if ($driver) {
                try {
                    $driver->clear_all();
                } catch (Exception $e) {
                    $this->log_error('Failed to clear cache: ' . $e->getMessage());
                }
            }
        }
        
        wp_cache_flush();
        $this->reset_cache_stats();
    }


    /**
     * URL preload végrehajtása
     */
    private function preload_url($url) {
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'headers' => array(
                'X-WP-Cacher-Preload' => '1'
            )
        );

        wp_remote_get($url, $args);
    }

    /**
     * Driver példány lekérése vagy létrehozása
     * 
     * @param string $type A cache típusa (page, query, object)
     * @return string A driver neve
     */
    public function get_driver($type) {
        $driver = get_option("cacher_{$type}_driver", 'auto');
        
        // Ha auto, akkor válasszuk ki a legjobb elérhető drivert
        if ($driver === 'auto') {
            if ($this->is_driver_available('redis')) {
                return 'redis';
            } elseif ($this->is_driver_available('apcu')) {
                return 'apcu';
            }
            return 'file';
        }
        
        // Ha a kiválasztott driver nem elérhető, használjuk a file drivert
        if ($driver !== 'file' && !$this->is_driver_available($driver)) {
            return 'file';
        }
        
        return $driver;
    }

    /**
     * Driver inicializálása
     *
     * @param string $driver_name A driver neve
     */
    private function initialize_driver($driver_name) {
        if (isset($this->drivers[$driver_name])) {
            return;
        }

        try {
            switch ($driver_name) {
                case 'redis':
                    $this->drivers[$driver_name] = new Cacher_Redis($this);
                    break;
                
                case 'apcu':
                    $this->drivers[$driver_name] = new Cacher_APCu($this);
                    break;
                
                case 'file':
                    $this->drivers[$driver_name] = new Cacher_File($this);
                    break;
            }
        } catch (Exception $e) {
            error_log("[Cacher Error] Failed to initialize driver {$driver_name}: " . $e->getMessage());
            // Fallback a file driver-re hiba esetén
            if ($driver_name !== 'file') {
                $this->initialize_driver('file');
            }
        }
    }

    /**
     * Automatikus driver meghatározása
     *
     * @param string $type A cache típusa (page, query)
     * @return string A kiválasztott driver neve
     */
    public function get_auto_driver($type) {
        // Mindig ellenőrizzük a legjobb elérhető driver-t
        if ($this->is_driver_available('redis')) {
            // Redis elérhető - mentsük el és használjuk
            update_option("cacher_{$type}_driver_auto", 'redis');
            return 'redis';
        } 
        
        if ($this->is_driver_available('apcu')) {
            // APCu elérhető - mentsük el és használjuk
            update_option("cacher_{$type}_driver_auto", 'apcu');
            return 'apcu';
        }

        // Ha egyik sem érhető el, használjuk a file driver-t
        // File esetén nem mentjük el az auto választást, hogy következő alkalommal
        // újra megpróbáljuk megtalálni a jobb driver-t
        delete_option("cacher_{$type}_driver_auto");
        return 'file';
    }

    /**
     * Cache találat rögzítése
     * @param string $type Cache típusa (page, object, query)
     * @param float $generation_time Generálási idő nanoszekundumban
     */
    private function record_cache_hit($type, $generation_time = 0) {
        if (!in_array($type, ['page', 'query'])) {
            return;
        }

        $stats = get_option("cacher_stats_{$type}", [
            'hits' => 0,
            'misses' => 0,
            'total_time' => 0,
            'last_update' => time()
        ]);
        
        $stats['hits']++;
        $stats['total_time'] += $generation_time;
        $stats['last_update'] = time();
        
        update_option("cacher_stats_{$type}", $stats, false);
    }

    /**
     * Cache kihagyás rögzítése
     * @param string $type Cache típusa (page, object, query)
     */
    private function record_cache_miss($type) {
        if (!in_array($type, ['page', 'query'])) {
            return;
        }

        $stats = get_option("cacher_stats_{$type}", [
            'hits' => 0,
            'misses' => 0,
            'total_time' => 0,
            'last_update' => time()
        ]);
        
        $stats['misses']++;
        $stats['last_update'] = time();
        
        update_option("cacher_stats_{$type}", $stats, false);
    }

    /**
     * Cache statisztikák nullázása
     * @param string $type Cache típusa (page, object, query), ha null, akkor minden típus
     */
    private function reset_cache_stats($type = null) {
        if ($type) {
            update_option("cacher_stats_{$type}", [
                'hits' => 0,
                'misses' => 0,
                'total_time' => 0,
                'last_update' => time()
            ], false);
        } else {
            foreach (['page', 'object', 'query'] as $cache_type) {
                update_option("cacher_stats_{$cache_type}", [
                    'hits' => 0,
                    'misses' => 0,
                    'total_time' => 0,
                    'last_update' => time()
                ], false);
            }
        }
    }

    /**
     * Cache művelet végrehajtása statisztika rögzítéssel
     * @param string $type Cache típusa (page, object, query)
     * @param string $key Cache kulcs
     * @param callable $callback Cache miss esetén végrehajtandó függvény
     * @param int|string $ttl Lejárati idő másodpercben vagy 'auto'
     * @return mixed Cache tartalom
     */
    public function cache_with_stats($type, $key, $callback, $ttl = 3600) {
        if ($ttl === 'auto') {
            $ttl = $this->calculate_auto_ttl($type);
        }
        $ttl = (int)$ttl;

        if ($ttl <= 0) {
            $ttl = $this->get_default_ttl($type);
        }

        if ($this->exclude_logged_in && is_user_logged_in()) {
            return $callback();
        }
        
        $start_time = hrtime(true);
        $driver_name = $this->get_driver($type);
        
        if (!isset($this->drivers[$driver_name]) || $this->drivers[$driver_name] === null) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_error("Driver not available: {$driver_name}");
            }
            $result = $callback();
            $this->record_cache_miss($type);
            return $result;
        }

        $driver = $this->drivers[$driver_name];
        
        try {
            $cached = $driver->get($key);
            
            if ($cached !== false) {
                $duration = hrtime(true) - $start_time;
                try {
                    $decompressed = $this->decompress_data($cached);
                    $this->record_cache_hit($type, $duration);
                    return $decompressed;
                } catch (Exception $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $this->log_error("Error decompressing data: " . $e->getMessage());
                    }
                    return $callback();
                }
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_error("Error getting cache: " . $e->getMessage());
            }
        }

        try {
            $result = $callback();
            $duration = hrtime(true) - $start_time;
            
            if ($result !== false) {
                try {
                    $compressed_result = $this->compress_data($result);
                    if (!$driver->set($key, $compressed_result, $ttl) && defined('WP_DEBUG') && WP_DEBUG) {
                        $this->log_error("Failed to cache result for type: {$type}, key: {$key}");
                    }
                } catch (Exception $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $this->log_error("Error compressing data: " . $e->getMessage());
                    }
                }
            }
            
            $this->record_cache_miss($type);
            return $result;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_error("Error executing callback: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Tartalom tömörítése
     * @param mixed $data Tömörítendő adat
     * @return string Tömörített adat
     */
    private function compress_data($data) {
        if (!$this->compression_enabled) {
            return $data;
        }
        
        // Optimális tömörítési szint a sebesség/méret arányhoz
        static $compression_level = 6;
        
        if (is_string($data)) {
            return gzencode($data, $compression_level);
        } elseif (is_array($data) || is_object($data)) {
            return gzencode(serialize($data), $compression_level);
        }
        
        return $data;
    }

    /**
     * Tömörített tartalom kibontása
     * @param string $data Tömörített adat
     * @return mixed Kibontott adat
     */
    private function decompress_data($data) {
        if (!$this->compression_enabled) {
            return $data;
        }
        
        if (!is_string($data)) {
            return $data;
        }
        
        $decoded = @gzdecode($data);
        if ($decoded === false) {
            return $data;
        }
        
        $unserialized = @unserialize($decoded);
        return $unserialized !== false ? $unserialized : $decoded;
    }

    /**
     * Alapértelmezett TTL értékek lekérése típus szerint
     * @param string $type Cache típusa
     * @return int TTL másodpercekben
     */
    private function get_default_ttl($type) {
        $defaults = [
            'page' => 3600,    // 1 óra
            'object' => 300,   // 5 perc
            'query' => 60      // 1 perc
        ];
        
        return $defaults[$type] ?? 3600;
    }

    /**
     * Automatikus TTL számítás forgalom alapján
     * @param string $type Cache típusa
     * @return int TTL másodpercekben
     */
    private function calculate_auto_ttl($type) {
        $default_ttl = $this->get_default_ttl($type);
        
        if (empty($this->traffic_stats[$type])) {
            return $default_ttl;
        }
        
        // Statisztikák lekérése
        $stats = get_option("cacher_stats_{$type}", [
            'hits' => 0,
            'misses' => 0,
            'total_time' => 0,
            'last_update' => time()
        ]);
        
        // Forgalom alapú számítás
        $total_requests = $stats['hits'] + $stats['misses'];
        $hours_since_update = max(1, (time() - $stats['last_update']) / 3600);
        $requests_per_hour = $total_requests / $hours_since_update;
        
        // TTL módosítása forgalom alapján
        if ($requests_per_hour > 1000) {
            // Magas forgalomnál rövidebb TTL
            $ttl = max(60, $default_ttl / 2);
        } elseif ($requests_per_hour < 100) {
            // Alacsony forgalomnál hosszabb TTL
            $ttl = min(86400, $default_ttl * 2);
        } else {
            // Közepes forgalomnál alapértelmezett TTL
            $ttl = $default_ttl;
        }
        
        return $ttl;
    }

    /**
     * Oldal cache lekérése vagy generálása
     * @param string $url Az oldal URL-je
     * @param callable $callback Cache miss esetén végrehajtandó függvény
     * @return string Az oldal tartalma
     */
    public function get_page_cache($url, $callback) {
        $key = 'page_' . md5($url);
        $ttl = (int)get_option('cacher_page_ttl', 3600);
        
        // Először nézzük meg az object cache-ben
        $cached = wp_cache_get($key, 'page_cache');
        if ($cached !== false) {
            return $cached;
        }
        
        return $this->cache_with_stats('page', $key, $callback, $ttl);
    }

    /**
     * Objektum cache lekérése vagy generálása
     * @param string $key Cache kulcs
     * @param callable $callback Cache miss esetén végrehajtandó függvény
     * @return mixed Az objektum
     */
    public function get_object_cache($key, $callback) {
        $ttl = (int)get_option('cacher_object_ttl', 300);
        
        // Először nézzük meg az object cache-ben
        $cached = wp_cache_get($key, 'object_cache');
        if ($cached !== false) {
            return $cached;
        }
        
        return $this->cache_with_stats('object', $key, $callback, $ttl);
    }

    /**
     * Query cache lekérése vagy generálása
     * @param string $query Az SQL lekérdezés
     * @param callable $callback Cache miss esetén végrehajtandó függvény
     * @return mixed A lekérdezés eredménye
     */
    public function get_query_cache($query, $callback) {
        $key = 'query_' . md5($query);
        $ttl = (int)get_option('cacher_query_ttl', 60);
        
        // Először nézzük meg az object cache-ben
        $cached = wp_cache_get($key, 'query_cache');
        if ($cached !== false) {
            return $cached;
        }
        
        return $this->cache_with_stats('query', $key, $callback, $ttl);
    }

    /**
     * Cache invalidálása minden rétegben
     * @param string $type Cache típusa
     * @param string $key Cache kulcs
     */
    private function invalidate_cache($type, $key) {
        $driver_name = $this->get_driver($type);
        if (isset($this->drivers[$driver_name]) && $this->drivers[$driver_name] !== null) {
            $this->drivers[$driver_name]->delete($key);
            $this->drivers[$driver_name]->delete($key . '_compressed');
            
            if ($type === 'page') {
                $this->invalidate_related_caches($key);
            }
        }
        
        $this->update_traffic_stats($type);
    }

    /**
     * Kapcsolódó cache-ek invalidálása
     * @param string $key Eredeti cache kulcs
     */
    private function invalidate_related_caches($key) {
        $related_queries = wp_cache_get('related_' . $key, 'query_cache');
        if ($related_queries) {
            foreach ($related_queries as $query_key) {
                $this->invalidate_cache('query', $query_key);
            }
            wp_cache_delete('related_' . $key, 'query_cache');
        }
    }

    /**
     * Elérhető driver-ek lekérése
     * @return array Az elérhető driver-ek listája
     */
    public function get_available_drivers() {
        $available_drivers = array(
            'auto' => __('Automatikus', 'cacher'),
            'file' => __('File', 'cacher')
        );

        // APCu driver ellenőrzése
        if (isset($this->drivers['apcu']) && $this->drivers['apcu'] !== null) {
            $available_drivers['apcu'] = 'APCu';
        }

        // Redis driver ellenőrzése
        if (isset($this->drivers['redis']) && $this->drivers['redis'] !== null && $this->drivers['redis']->is_available()) {
            $available_drivers['redis'] = 'Redis';
        }

        return $available_drivers;
    }

    /**
     * Driver beállítása egy cache típushoz
     * @param string $type Cache típusa (page, object, query)
     * @param string $driver Driver neve
     * @return bool Sikeres volt-e a beállítás
     */
    public function set_driver($type, $driver) {
        if (!in_array($type, ['page', 'query'])) {
            return false;
        }

        $available_drivers = $this->get_available_drivers();
        if (!isset($available_drivers[$driver])) {
            return false;
        }

        update_option("cacher_{$type}_driver", $driver);
        return true;
    }

    /**
     * Traffic statisztikák frissítése
     * @param string $type Cache típus
     * @param array $stats Frissítendő statisztikák
     */
    public function update_traffic_stats($type, $stats) {
        if (!isset($this->traffic_stats[$type])) {
            $this->traffic_stats[$type] = [
                'hits' => 0,
                'misses' => 0,
                'total_time' => 0,
                'last_update' => time()
            ];
        }
        
        foreach ($stats as $key => $value) {
            if (isset($this->traffic_stats[$type][$key])) {
                $this->traffic_stats[$type][$key] += $value;
            }
        }
        
        $this->traffic_stats[$type]['last_update'] = time();
        
        // Statisztikák mentése
        $this->save_traffic_stats();
    }

    /**
     * Cache művelet statisztikák rögzítése
     * @param string $type Cache típusa (page, query)
     * @param bool $hit Találat volt-e
     * @param float $duration Művelet időtartama másodpercben
     */
    private function record_cache_operation($type, $hit, $duration) {
        static $stats_cache = [];
        static $last_save = 0;
        
        if (!isset($stats_cache[$type])) {
            $stats_cache[$type] = get_option("cacher_stats_{$type}", [
                'hits' => 0,
                'misses' => 0,
                'total_time' => 0,
                'last_update' => time()
            ]);
        }
        
        if ($hit) {
            $stats_cache[$type]['hits']++;
        } else {
            $stats_cache[$type]['misses']++;
        }
        
        $stats_cache[$type]['total_time'] += $duration;
        $stats_cache[$type]['last_update'] = time();
        
        if (time() - $last_save >= 60) {
            update_option("cacher_stats_{$type}", $stats_cache[$type], false);
            $last_save = time();
        }
        
        // Csak WP_DEBUG esetén és csak a kritikusan lassú műveleteknél logolunk
        if (defined('WP_DEBUG') && WP_DEBUG && !$hit && $duration > 1.0) {
            $this->log_error(sprintf(
                'Slow cache operation - Type: %s, Duration: %.6f ms',
                $type,
                $duration * 1000
            ));
        }
    }

    /**
     * Beállítások újrakonfigurálása
     */
    public function reconfigure_settings() {
        try {
            $cache_enabled = get_option('cacher_enabled');
            
            if ($cache_enabled === false) {
                delete_option('cacher_enabled');
                add_option('cacher_enabled', 0, '', 'no');
                $cache_enabled = 0;
            }
            
            $cache_enabled = (int)$cache_enabled === 1;
            
            $this->compression_enabled = (bool)get_option('cacher_enable_compression', 0);
            $this->exclude_logged_in = (bool)get_option('cacher_exclude_logged_in', 0);
            
            if (!$cache_enabled) {
                try {
                    $this->clear_all_cache();
                    return false;
                } catch (Exception $e) {
                    $this->log_error('Failed to clear cache: ' . $e->getMessage());
                }
            }
            
            if ($cache_enabled && !empty($this->drivers)) {
                foreach ($this->drivers as $driver_name => $driver) {
                    if ($driver && method_exists($driver, 'reconfigure')) {
                        try {
                            $driver->reconfigure();
                        } catch (Exception $e) {
                            $this->log_error("Failed to reconfigure {$driver_name} driver: " . $e->getMessage());
                        }
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log_error('Settings reconfiguration failed: ' . $e->getMessage());
            return false;
        }
    }

    private function check_driver_availability($driver_name) {
        if (!isset($this->drivers[$driver_name])) {
            return false;
        }

        $available = $this->drivers[$driver_name]->is_available();
        
        return $available;
    }

    /**
     * Cache állapot lekérése - javított változat
     * @return array A cache aktuális állapotát tartalmazó tömb
     */
    public function get_cache_status() {
        $status = [
            'enabled' => (bool)get_option('cacher_enabled', 0),
            'compression' => $this->compression_enabled,
            'exclude_logged_in' => $this->exclude_logged_in,
            'drivers' => $this->get_active_drivers(),
            'cache_types' => [
                'page' => [
                    'enabled' => (bool)get_option('cacher_enable_page_cache', 1),
                    'driver' => $this->get_actual_driver('page'),
                    'ttl' => (int)get_option('cacher_page_ttl', 3600)
                ],
                'query' => [
                    'enabled' => (bool)get_option('cacher_enable_db_cache', 1),
                    'driver' => $this->get_actual_driver('query'),
                    'ttl' => (int)get_option('cacher_query_ttl', 60)
                ],
                'object' => [
                    'enabled' => (bool)get_option('cacher_enable_object_cache', 1),
                    'driver' => $this->get_actual_driver('object'),
                    'ttl' => (int)get_option('cacher_object_ttl', 3600)
                ]
            ]
        ];
        
        return $status;
    }

    /**
     * Aktív driver-ek lekérése
     * @return array A ténylegesen használt driver-ek listája
     */
    private function get_active_drivers() {
        $active_drivers = [];
        foreach ($this->drivers as $name => $driver) {
            if ($driver && $driver->is_available()) {
                $active_drivers[] = $name;
            }
        }
        return $active_drivers;
    }

    /**
     * Ténylegesen használt driver lekérése típus alapján
     * @param string $type A cache típusa (page|query|object)
     * @return string A ténylegesen használt driver neve
     */
    private function get_actual_driver($type) {
        $driver_name = $this->get_driver($type);
        
        if (isset($this->drivers[$driver_name])) {
            return $driver_name;
        }
        
        return 'none';
    }

    /**
     * Ellenőrzi, hogy egy adott kulcs szerepel-e a cache-ben
     * @param string $key A keresett kulcs
     * @return bool True, ha a kulcs szerepel a cache-ben
     */
    public function is_cached($key) {
        $driver = $this->get_driver('page'); // Alapértelmezetten a page cache driver-t használjuk
        if ($driver) {
            try {
                return $driver->has($key);
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[Cacher Error] Failed to check cache for key {$key}: " . $e->getMessage());
                }
                return false;
            }
        }
        return false;
    }

    /**
     * A wp_filesystem_put_contents() helper függvény hozzáadása
     * @param string $file A fájl elérési útja
     * @param string $contents A fájl tartalma
     * @return bool Sikeres volt-e a fájl írása
     */
    private function wp_filesystem_put_contents($file, $contents) {
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        if (!WP_Filesystem()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_error('WP_Filesystem initialization failed');
            }
            return false;
        }
        
        return $wp_filesystem->put_contents($file, $contents);
    }

    /**
     * Cache preload végrehajtása
     * @return array A preload eredménye (success és message)
     */
    public function preload_cache() {
        try {
            // Cache engedélyezés ellenőrzése
            if (!get_option('cacher_enabled')) {
                throw new Exception('Cache system is disabled');
            }

            // Driver elérhetőség ellenőrzése
            $driver_name = $this->get_driver('page');
            if (!isset($this->drivers[$driver_name]) || !$this->drivers[$driver_name]->is_available()) {
                throw new Exception("Cache driver '{$driver_name}' not available");
            }

            // TTL érték lekérése a page cache beállításokból
            $ttl = (int)get_option('cacher_page_ttl', 3600);
            if ($ttl <= 0) {
                $ttl = 3600; // Alapértelmezett 1 óra
            }

            // Leggyakrabban látogatott oldalak lekérése
            $popular_posts = $this->get_popular_pages();
            $preloaded = 0;
            $errors = [];

            foreach ($popular_posts as $post) {
                $url = get_permalink($post->ID);
                if (!$url) continue;

                // Cache kulcs generálása
                $cache_key = 'page_' . md5($url);

                // Oldal tartalmának lekérése és cache-elése
                $response = wp_remote_get($url, [
                    'timeout' => 30,
                    'sslverify' => false,
                    'user-agent' => 'Cacher Preload Bot'
                ]);

                if (is_wp_error($response)) {
                    $errors[] = $url;
                    continue;
                }

                $content = wp_remote_retrieve_body($response);
                if (!empty($content)) {
                    if (!$this->set($cache_key, $content, $ttl, $driver_name)) {
                        $errors[] = $url;
                        error_log("[Cacher Error] Failed to cache page: {$url}");
                        continue;
                    }

                    // Ütemezzük az automatikus újratöltést
                    $this->schedule_cache_refresh($url, $ttl);
                    $preloaded++;
                }

                // 500ms késleltetés minden oldal után
                usleep(100000);
            }

            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: 1: number of preloaded pages, 2: number of failed pages, 3: refresh time in seconds */
                    __('Sikeresen előtöltve %1$d oldal. %2$d hibás oldal. Az oldalak automatikusan frissülnek %3$d másodperc után.', 'cacher'),
                    $preloaded,
                    count($errors),
                    $ttl
                )
            ];

        } catch (Exception $e) {
            error_log('[Cacher Error] Preload failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Cache frissítés ütemezése
     * @param string $url Az oldal URL-je
     * @param int $ttl TTL érték másodpercekben
     */
    private function schedule_cache_refresh($url, $ttl) {
        $timestamp = time() + $ttl;
        $hook = 'cacher_refresh_cache';
        
        // Töröljük a korábbi ütemezést, ha létezik
        wp_clear_scheduled_hook($hook, array($url));
        
        // Új ütemezés beállítása
        wp_schedule_single_event($timestamp, $hook, array($url));
    }

    /**
     * Egy oldal cache-ének frissítése
     * @param string $url Az oldal URL-je
     */
    public function refresh_cache($url) {
        try {
            // TTL érték lekérése
            $ttl = (int)get_option('cacher_page_ttl', 3600);
            if ($ttl <= 0) {
                $ttl = 3600;
            }

            // Cache kulcs generálása
            $cache_key = 'page_' . md5($url);

            // Oldal tartalmának lekérése
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'sslverify' => false,
                'user-agent' => 'Cacher Refresh Bot'
            ]);

            if (is_wp_error($response)) {
                throw new Exception("Failed to fetch page: {$url}");
            }

            $content = wp_remote_retrieve_body($response);
            if (empty($content)) {
                throw new Exception("Empty content received for: {$url}");
            }

            // Cache frissítése
            $driver_name = $this->get_driver('page');
            if (!$this->set($cache_key, $content, $ttl, $driver_name)) {
                throw new Exception("Failed to update cache for: {$url}");
            }

            // Következő frissítés ütemezése
            $this->schedule_cache_refresh($url, $ttl);

            error_log("[Cacher] Successfully refreshed cache for: {$url}");

        } catch (Exception $e) {
            error_log("[Cacher Error] Cache refresh failed for {$url}: " . $e->getMessage());
        }
    }

    /**
     * Népszerű oldalak lekérése preloadhoz
     * @return array WP_Post objektumok
     */
    private function get_popular_pages() {
        $posts = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'posts_per_page' => 100, // 100 leg utoljára módosított oldal lekérése
            'orderby' => 'modified',
            'order' => 'DESC'
        ]);

        // 500ms késleltetés minden oldal után
        usleep(100000); // 500 milliszekundum = 500000 mikroszekundum

        return $posts;
    }

    /**
     * Cache kulcs generálása
     *
     * @param string $key A cache kulcs
     * @param string $group A cache csoport
     * @return string A generált cache kulcs
     */
    public function build_key($key, $group = 'default') {
        // Biztonságos kulcs generálása
        $safe_key = preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        $safe_group = preg_replace('/[^a-z0-9_\-]/', '', strtolower($group));
        
        // Egyedi kulcs generálása a group és key kombinációjából
        return md5($safe_group . '_' . $safe_key);
    }

    /**
     * Konfiguráció lekérése
     * 
     * @return array A cache konfiguráció
     */
    private function get_config() {
        return array(
            'file' => array(
                'cache_dir' => defined('CACHER_FILE_CACHE_DIR') ? CACHER_FILE_CACHE_DIR : WP_CONTENT_DIR . '/cache/cacher/',
                'max_size' => defined('CACHER_FILE_MAX_SIZE') ? CACHER_FILE_MAX_SIZE : 104857600, // 100MB
                'compression' => defined('CACHER_FILE_COMPRESSION') ? CACHER_FILE_COMPRESSION : false
            ),
            'apcu' => array(
                'enabled' => function_exists('apcu_enabled') && apcu_enabled(),
                'ttl' => defined('CACHER_APCU_TTL') ? CACHER_APCU_TTL : 3600
            ),
            'redis' => array(
                'host' => defined('CACHER_REDIS_HOST') ? CACHER_REDIS_HOST : '127.0.0.1',
                'port' => defined('CACHER_REDIS_PORT') ? CACHER_REDIS_PORT : 6379,
                'timeout' => defined('CACHER_REDIS_TIMEOUT') ? CACHER_REDIS_TIMEOUT : 1,
                'auth' => defined('CACHER_REDIS_AUTH') ? CACHER_REDIS_AUTH : null,
                'database' => defined('CACHER_REDIS_DB') ? CACHER_REDIS_DB : 0
            )
        );
    }

    /**
     * Teljes cache tartalom törlése
     * @return bool Sikeres törlés esetén true
     */
    public function clear_all() {
        $success = true;

        // Cache driverek ürítése
        foreach ($this->drivers as $driver) {
            if ($driver && $driver->is_available()) {
                try {
                    if (!$driver->clear_all()) {
                        $success = false;
                        error_log('[Cacher Error] Failed to clear cache for driver: ' . get_class($driver));
                    }
                } catch (Exception $e) {
                    $success = false;
                    error_log('[Cacher Error] Exception while clearing cache: ' . $e->getMessage());
                }
            }
        }

        // Belső cache ürítése
        $this->cache = array();

        // Minden típusú statisztika nullázása
        foreach (['page', 'query'] as $type) {
            update_option("cacher_stats_{$type}", [
                'hits' => 0,
                'misses' => 0,
                'total_time' => 0,
                'last_update' => time()
            ], false);
        }

        // Cache állapot frissítése
        update_option('cacher_last_cleared', time());

        // WordPress cache ürítése
        wp_cache_flush();

        return $success;
    }
}
