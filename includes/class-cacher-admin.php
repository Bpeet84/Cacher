<?php
/**
 * Admin felület kezelő osztály. Felelős a cache beállítások és statisztikák megjelenítéséért.
 *
 * @package Cacher
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cacher_Admin {
    /**
     * @var string $page_slug Az admin oldal slug-ja
     */
    private $page_slug = 'cacher-settings';

    /**
     * @var Cacher_Manager $manager A cache manager példány
     */
    private $manager;

    /**
     * Konstruktor
     *
     * @param Cacher_Manager $manager A cache manager példány
     */
    public function __construct(Cacher_Manager $manager) {
        $this->manager = $manager;
    }

    /**
     * Admin felület inicializálása
     */
    public function init() {
        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook !== 'toplevel_page_' . $this->page_slug) {
                return;
            }

            // Admin CSS betöltése
            wp_enqueue_style(
                'cacher-admin-css',
                CACHER_PLUGIN_URL . 'templates/admin.css',
                array(),
                CACHER_VERSION
            );

            // Admin JavaScript betöltése
            wp_enqueue_script(
                'cacher-admin-js',
                CACHER_PLUGIN_URL . 'templates/admin.js',
                array('jquery'),
                CACHER_VERSION,
                true
            );

            // Lokalizációs adatok átadása
            wp_localize_script('cacher-admin-js', 'cacherL10n', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cacher_nonce'),
                'messages' => array(
                    'cacheCleared' => __('Cache cleared successfully!', 'cacher'),
                    'settingsSaved' => __('Settings saved successfully.', 'cacher'),
                    'cacheActivated' => __('Cache system activated.', 'cacher'),
                    'cacheDeactivated' => __('Cache system deactivated.', 'cacher'),
                    'compressionEnabled' => __('Compression enabled.', 'cacher'),
                    'compressionDisabled' => __('Compression disabled.', 'cacher'),
                    'userExclusionEnabled' => __('User exclusion enabled.', 'cacher'),
                    'userExclusionDisabled' => __('User exclusion disabled.', 'cacher'),
                    'redisSettingsSaved' => __('Redis settings saved successfully!', 'cacher'),
                    'preloadCompleted' => __('Cache preload completed successfully!', 'cacher'),
                    'error' => __('Error occurred: ', 'cacher'),
                    'settingError' => __('Error occurred while saving setting!', 'cacher'),
                    'requestError' => __('Error occurred during request!', 'cacher'),
                    /* translators: %s: cache type (e.g., "page" or "database") */
                    'autoTtlEnabled' => __('Automatic TTL enabled for %s cache!', 'cacher'),
                    'confirmClearCache' => __('Are you sure you want to clear the cache?', 'cacher'),
                    'settingsSaveFailed' => __('Failed to save settings!', 'cacher'),
                    'cacheClearFailed' => __('Failed to clear cache!', 'cacher'),
                    'cachePreloadFailed' => __('Error occurred during cache preload!', 'cacher'),
                    'ajaxError' => __('AJAX error occurred: ', 'cacher'),
                    'preloadFunctionNotEnabled' => __('Preload function is not enabled!', 'cacher'),
                    'preloadFunctionEnabled' => __('Preload function enabled!', 'cacher'),
                    'preloadFunctionDisabled' => __('Preload function disabled!', 'cacher'),
                    /* translators: %s: error message from Redis connection attempt */
                    'redisSettingsSavedButConnectionFailed' => __('Redis settings saved but connection failed: %s', 'cacher'),
                    'redisSettingsSaveFailed' => __('Failed to save Redis settings!', 'cacher'),
                    /* translators: %s: cache type (e.g., "page" or "database") */
                    'cacheSettingsSaved' => __(' cache settings saved!', 'cacher'),
                    /* translators: %s: selected driver name */
                    'autoDriverSelected' => __('Auto driver selected: %s', 'cacher'),
                    'cacheStatsTableStructure' => __('Cache stats table structure:', 'cacher'),
                    /* translators: %s: row type name */
                    'rowType' => __('Row type: %s', 'cacher'),
                    'rowStructure' => __('Row structure:', 'cacher')
                )
            ));
        });

        // Admin menü hozzáadása
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX műveletek regisztrálása
        add_action('wp_ajax_cacher_toggle_cache', array($this, 'ajax_toggle_cache'));
        add_action('wp_ajax_cacher_update_driver', array($this, 'ajax_update_driver'));
        add_action('wp_ajax_cacher_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_cacher_update_setting', array($this, 'ajax_update_setting'));
        add_action('wp_ajax_cacher_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_cacher_preload_cache', array($this, 'ajax_preload_cache'));
        add_action('wp_ajax_cacher_get_auto_driver', array($this, 'ajax_get_auto_driver'));
        add_action('wp_ajax_cacher_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_cacher_get_available_drivers', array($this, 'ajax_get_available_drivers'));
        add_action('wp_ajax_cacher_save_redis_settings', array($this, 'ajax_save_redis_settings'));
    }

    /**
     * Admin menü hozzáadása
     */
    public function add_admin_menu() {
        if (current_user_can('manage_options')) {
            add_menu_page(
                esc_html__('Cacher Settings', 'cacher'),
                esc_html__('Cacher', 'cacher'),
                'manage_options',
                $this->page_slug,
                array($this, 'render_admin_page'),
                'dashicons-performance',
                80
            );
        }
    }

    /**
     * Beállítások regisztrálása
     */
    public function register_settings() {
        register_setting('cacher_settings_group', 'cacher_default_driver');
        register_setting('cacher_settings_group', 'cacher_enable_page_cache');
        register_setting('cacher_settings_group', 'cacher_enable_db_cache');
        register_setting('cacher_settings_group', 'cacher_excluded_urls');
        register_setting('cacher_settings_group', 'cacher_excluded_hooks');
        register_setting('cacher_settings_group', 'cacher_excluded_db_queries');
        register_setting('cacher_settings_group', 'cacher_enable_preload');
        register_setting('cacher_settings_group', 'cacher_redis_host');
        register_setting('cacher_settings_group', 'cacher_redis_port');
        register_setting('cacher_settings_group', 'cacher_redis_auth');
        register_setting('cacher_settings_group', 'cacher_redis_db');

        add_settings_section(
            'cacher_main_section',
            __('Alapbeállítások', 'cacher'),
            array($this, 'render_main_section'),
            $this->page_slug
        );

        add_settings_field(
            'cacher_default_driver',
            __('Alapértelmezett Cache Driver', 'cacher'),
            array($this, 'render_driver_field'),
            $this->page_slug,
            'cacher_main_section'
        );

        add_settings_field(
            'cacher_enable_page_cache',
            __('Teljes Oldal Cache', 'cacher'),
            array($this, 'render_page_cache_field'),
            $this->page_slug,
            'cacher_main_section'
        );

        add_settings_field(
            'cacher_enable_db_cache',
            __('Adatbázis Cache', 'cacher'),
            array($this, 'render_db_cache_field'),
            $this->page_slug,
            'cacher_main_section'
        );
    }

    /**
     * Fő beállítások szekció tartalmának megjelenítése
     */
    public function render_main_section() {
        echo '<p>' . esc_html__('A Cacher plugin alapbeállításai.', 'cacher') . '</p>';
    }

    /**
     * Cache driver mező megjelenítése
     */
    public function render_driver_field() {
        $current_driver = get_option('cacher_default_driver', 'file');
        
        ?>
        <select name="cacher_default_driver">
            <?php foreach (['file', 'apcu', 'redis'] as $driver): ?>
                <?php $available = $this->manager->is_driver_available($driver); ?>
                <option value="<?php echo esc_attr($driver); ?>" 
                    <?php selected($current_driver, $driver); ?>
                    <?php if (!$available) echo 'disabled'; ?>>
                    <?php echo esc_html(ucfirst($driver)); ?>
                    <?php if ($available): ?>
                        <span class="cacher-driver-status available">✓</span>
                    <?php else: ?>
                        <span class="cacher-driver-status unavailable">✗</span>
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('A ✓ jelöli az elérhető, a ✗ pedig a nem elérhető drivereket.', 'cacher'); ?>
        </p>
        <?php
    }

    /**
     * Teljes oldal cache mező megjelenítése
     */
    public function render_page_cache_field() {
        $enabled = get_option('cacher_enable_page_cache', 1);
        ?>
        <input type="checkbox" name="cacher_enable_page_cache" value="1" <?php checked($enabled, 1); ?>>
        <?php
    }

    /**
     * Adatbázis cache mező megjelenítése
     */
    public function render_db_cache_field() {
        $enabled = get_option('cacher_enable_db_cache', 1);
        ?>
        <input type="checkbox" name="cacher_enable_db_cache" value="1" <?php checked($enabled, 1); ?>>
        <?php
    }

    /**
     * Admin oldal tartalmának megjelenítése
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Kizárási beállítások lekérése
        $excluded_urls = get_option('cacher_excluded_urls', '');
        $excluded_hooks = get_option('cacher_excluded_hooks', '');
        $excluded_db_queries = get_option('cacher_excluded_db_queries', '');

        // Debug információk
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Cacher: Loading exclusion settings:');
            error_log('URLs: ' . print_r($excluded_urls, true));
            error_log('Hooks: ' . print_r($excluded_hooks, true));
            error_log('Queries: ' . print_r($excluded_db_queries, true));
        }

        // Statisztikák összegyűjtése
        $stats = [
            'file' => [],
            'apcu' => [],
            'redis' => [],
        ];
        
        if ($this->manager !== null) {
            $stats = [
                'file' => [],
                'apcu' => [],
                'redis' => [],
            ];
            
            $file_driver = $this->manager->get_file_driver();
            if ($file_driver !== null) {
                $stats['file'] = $file_driver->get_stats();
            }
            
            $apcu_driver = $this->manager->get_apcu_driver();
            if ($apcu_driver !== null) {
                $stats['apcu'] = $apcu_driver->get_stats();
            }
            
            $redis_driver = $this->manager->get_redis_driver();
            if ($redis_driver !== null) {
                $stats['redis'] = $redis_driver->get_stats();
            }
        }

        // Sablon betöltése
        include CACHER_PLUGIN_DIR . 'templates/admin.php';
    }

    /**
     * Cache be/kikapcsolás AJAX kezelő
     */
    public function ajax_toggle_cache() {
        check_ajax_referer('cacher_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => esc_html__('You do not have permission to perform this action.', 'cacher')
            ));
        }

        $enabled = (bool)$_POST['enabled'];
        update_option('cacher_enabled', $enabled ? 1 : 0);

        wp_send_json_success(array(
            'status' => $enabled,
            'message' => $enabled ? 
                esc_html__('Cache system activated!', 'cacher') : 
                esc_html__('Cache system deactivated!', 'cacher')
        ));
    }

    /**
     * Driver beállítások mentése AJAX kezelő
     */
    public function ajax_update_driver() {
        check_ajax_referer('cacher_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => esc_html__('You do not have permission to perform this action.', 'cacher')
            ));
        }

        $type = sanitize_text_field($_POST['cache_type']);
        $driver = sanitize_text_field($_POST['driver']);
        $ttl = (int)$_POST['ttl'];

        update_option("cacher_{$type}_driver", $driver);
        update_option("cacher_{$type}_ttl", $ttl);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %s: cache type (e.g., "page" or "database") */
                    esc_html__('%s cache settings saved successfully!', 'cacher'),
                    ucfirst($type)
                )
            ));
        }
    }

    /**
     * Összes beállítás mentése
     */
    public function ajax_save_settings() {
        try {
            check_ajax_referer('cacher_nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html__('You do not have permission to perform this action.', 'cacher'));
            }

            // Driver és TTL beállítások mentése
            $settings = isset($_POST['settings']) ? $_POST['settings'] : array();
            foreach ($settings as $type => $config) {
                update_option("cacher_{$type}_driver", sanitize_text_field($config['driver']));
                update_option("cacher_{$type}_ttl", (int)$config['ttl']);
            }

            // Kizárások mentése debug naplózással
            if (isset($_POST['excluded_urls'])) {
                $excluded_urls = sanitize_textarea_field($_POST['excluded_urls']);
                $saved = update_option('cacher_excluded_urls', $excluded_urls);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Cacher: Saving excluded URLs: ' . $excluded_urls);
                    error_log('Save result: ' . ($saved ? 'success' : 'failed'));
                }
            }

            if (isset($_POST['excluded_hooks'])) {
                $excluded_hooks = sanitize_textarea_field($_POST['excluded_hooks']);
                $saved = update_option('cacher_excluded_hooks', $excluded_hooks);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Cacher: Saving excluded hooks: ' . $excluded_hooks);
                    error_log('Save result: ' . ($saved ? 'success' : 'failed'));
                }
            }

            if (isset($_POST['excluded_db_queries'])) {
                $excluded_queries = sanitize_textarea_field($_POST['excluded_db_queries']);
                $saved = update_option('cacher_excluded_db_queries', $excluded_queries);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Cacher: Saving excluded queries: ' . $excluded_queries);
                    error_log('Save result: ' . ($saved ? 'success' : 'failed'));
                }
            }

            wp_send_json_success([
                'message' => esc_html__('Settings saved successfully!', 'cacher')
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Általános beállítások frissítése
     */
    public function ajax_update_setting() {
        try {
            check_ajax_referer('cacher_nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html__('You do not have permission to perform this action.', 'cacher'));
            }

            $setting = sanitize_text_field($_POST['setting']);
            $value = (int)$_POST['value'];

            $allowed_settings = array(
                'cacher_enable_preload',
                'cacher_exclude_logged_in',
                'cacher_enable_compression'
            );

            if (!in_array($setting, $allowed_settings)) {
                throw new Exception(esc_html__('Invalid setting!', 'cacher'));
            }

            if (update_option($setting, $value)) {
                $this->manager->reconfigure_settings();
                
                wp_send_json_success(array(
                    'setting' => $setting,
                    'value' => $value,
                    'message' => esc_html__('Setting saved successfully!', 'cacher')
                ));
            } else {
                throw new Exception(esc_html__('Failed to save setting!', 'cacher'));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * Cache törlés AJAX kezelő
     */
    public function ajax_clear_cache() {
        check_ajax_referer('cacher_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => esc_html__('You do not have permission to perform this action.', 'cacher')
            ));
        }

        $result = $this->manager->clear_all();

        if ($result) {
            wp_send_json_success(array(
                'message' => esc_html__('Cache cleared successfully!', 'cacher')
            ));
        } else {
            wp_send_json_error(array(
                'message' => esc_html__('Failed to clear cache!', 'cacher')
            ));
        }
    }

    /**
     * Cache preload AJAX kezelő
     */
    public function ajax_preload_cache() {
        try {
            check_ajax_referer('cacher_nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error([
                    'message' => esc_html__('You do not have permission to perform this action.', 'cacher')
                ]);
                return;
            }

            if (!get_option('cacher_enable_preload', 0)) {
                wp_send_json_error([
                    'message' => esc_html__('Preload function is not enabled!', 'cacher')
                ]);
                return;
            }

            if (!get_option('cacher_enabled')) {
                wp_send_json_error([
                    'message' => esc_html__('Cache system is not enabled!', 'cacher')
                ]);
                return;
            }

            $result = $this->manager->preload_cache();

            if ($result['success']) {
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: 1: number of preloaded pages, 2: number of failed pages, 3: refresh time in seconds */
                        esc_html__('Successfully preloaded %1$d pages. %2$d failed pages. Pages will automatically refresh after %3$d seconds.', 'cacher'),
                        $result['preloaded'],
                        $result['failed'],
                        $result['refresh_time']
                    )
                ]);
            } else {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: error message from cache preload attempt */
                        esc_html__('Error occurred during cache preload: %s', 'cacher'),
                        $result['message']
                    )
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Automatikus driver információ lekérése
     */
    public function ajax_get_auto_driver() {
        check_ajax_referer('cacher_nonce');
        
        $type = sanitize_text_field($_POST['cache_type']);
        $driver = $this->manager->get_auto_driver($type);
        
        wp_send_json_success(array(
            'driver' => $driver
        ));
    }

    /**
     * Cache statisztikák lekérése
     */
    public function ajax_get_stats() {
        check_ajax_referer('cacher_nonce');
        
        if ($this->manager === null) {
            return array(); // Visszatérünk egy üres tömbbel, ha a manager nincs inicializálva
        }
        $stats = $this->manager->get_stats();
        
        wp_send_json_success($stats);
    }

    /**
     * Driver választó legördülő menü renderelése
     * @param string $type Cache típusa (page, query)
     */
    private function render_driver_select($type) {
        if (!in_array($type, ['page', 'query'])) {
            return;
        }
        
        $current_driver = get_option("cacher_{$type}_driver", 'auto');
        $available_drivers = $this->manager->get_available_drivers();
        
        echo '<select name="cacher_' . esc_attr($type) . '_driver" class="cacher-driver-select">';
        foreach ($available_drivers as $driver => $label) {
            echo '<option value="' . esc_attr($driver) . '" ' . selected($current_driver, $driver, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
    }

    /**
     * Beállítások mentése
     */
    public function save_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Driver beállítások mentése
        $types = ['page', 'query'];
        foreach ($types as $type) {
            if (isset($_POST["cacher_{$type}_driver"])) {
                $driver = sanitize_text_field($_POST["cacher_{$type}_driver"]);
                $this->manager->set_driver($type, $driver);
            }
        }

        // Egyéb beállítások mentése
        update_option('cacher_enable_compression', isset($_POST['cacher_enable_compression']) ? 1 : 0);
        update_option('cacher_exclude_logged_in', isset($_POST['cacher_exclude_logged_in']) ? 1 : 0);
        
        // Időzítések mentése
        $ttl_fields = [
            'page_ttl',
            'query_ttl'
        ];
        
        foreach ($ttl_fields as $field) {
            if (isset($_POST["cacher_{$field}"])) {
                $value = absint($_POST["cacher_{$field}"]);
                update_option("cacher_{$field}", $value);
            }
        }
    }

    /**
     * Elérhető driver-ek lekérése AJAX-on keresztül
     */
    public function ajax_get_available_drivers() {
        try {
            check_ajax_referer('cacher_nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(esc_html__('You do not have permission to perform this action.', 'cacher'));
                return;
            }

            $drivers = array(
                'file' => $this->manager->is_driver_available('file'),
                'apcu' => $this->manager->is_driver_available('apcu'),
                'redis' => $this->manager->is_driver_available('redis')
            );

            wp_send_json_success(array(
                'drivers' => $drivers
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Redis beállítások mentése AJAX kezelő
     */
    public function ajax_save_redis_settings() {
        check_ajax_referer('cacher_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => esc_html__('You do not have permission to perform this action.', 'cacher')
            ));
        }

        try {
            if (!isset($_POST['host']) || !isset($_POST['port'])) {
                throw new Exception(esc_html__('Missing required fields!', 'cacher'));
            }

            $host = sanitize_text_field($_POST['host']);
            $port = (int)$_POST['port'];
            $auth = isset($_POST['auth']) ? sanitize_text_field($_POST['auth']) : '';

            if (empty($host)) {
                throw new Exception(esc_html__('Host field cannot be empty!', 'cacher'));
            }
            if ($port <= 0 || $port > 65535) {
                throw new Exception(esc_html__('Invalid port number!', 'cacher'));
            }

            update_option('cacher_redis_host', $host);
            update_option('cacher_redis_port', $port);
            update_option('cacher_redis_auth', $auth);

            wp_send_json_success(array(
                'message' => esc_html__('Redis settings saved successfully!', 'cacher')
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
}
