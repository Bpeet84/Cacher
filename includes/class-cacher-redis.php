<?php
/**
 * Redis alapú cache kezelő osztály. Felelős a Redis szerveren tárolt cache adatok kezeléséért.
 *
 * @package Cacher
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Redis driver osztály
 */
class Cacher_Redis {
    /**
     * @var \Redis|null $redis A Redis kapcsolat
     */
    private $redis = null;

    /**
     * @var string $prefix A cache kulcsok előtagja
     */
    private $prefix;

    /**
     * @var object $manager A cache manager példány
     */
    private $manager;

    /**
     * @var bool $is_available A Redis elérhetősége
     */
    private $is_available = false;

    /**
     * @var array $last_error Az utolsó hiba adatai
     */
    private $last_error = array();

    // Kapcsolódási paraméterek konstansai
    const REDIS_HOST = '127.0.0.1';
    const REDIS_PORT = 6379;
    const REDIS_TIMEOUT = 2;
    const REDIS_READ_TIMEOUT = 2;
    const REDIS_RETRY_INTERVAL = 100;
    const REDIS_RETRY_COUNT = 3;
    const CONNECTION_CHECK_INTERVAL = 30; // másodperc
    const CONNECTION_POOL_SIZE = 5;

    /**
     * @var int $last_connection_check Az utolsó kapcsolat ellenőrzés időbélyege
     */
    private $last_connection_check = 0;

    /**
     * @var array $connection_pool Kapcsolat pool tárolása
     */
    private $connection_pool = array();

    /**
     * @var array $stats A Redis cache statisztikái
     */
    private $stats = array();

    /**
     * Konstruktor
     * 
     * @param object $manager A cache manager példány.
     */
    public function __construct( $manager ) {
        $this->prefix = 'cacher_';
        $this->manager = $manager;
        
        // Statisztikák inicializálása
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'used_memory' => '0 B',
            'items' => 0
        ];
        
        if ( ! extension_loaded( 'redis' ) ) {
            $this->log_error( 'Redis PHP extension is not loaded' );
            return;
        }

        // Kezdeti kapcsolat létrehozása
        $this->initialize_connection_pool();
    }

    /**
     * Kapcsolat pool inicializálása
     */
    private function initialize_connection_pool() {
        try {
            // Első kapcsolat létrehozása és tesztelése
            $initial_connection = $this->create_connection();
            if ($initial_connection) {
                $this->redis = $initial_connection;
                $this->connection_pool[] = $initial_connection;
                $this->is_available = true;
                $this->last_connection_check = time();
            }
        } catch (\Exception $e) {
            $this->log_error('Initial Redis connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Új Redis kapcsolat létrehozása
     * 
     * @return \Redis|null
     */
    private function create_connection() {
        try {
            $redis = new \Redis();
            
            if (!@$redis->connect(
                self::REDIS_HOST,
                self::REDIS_PORT,
                self::REDIS_TIMEOUT,
                null,
                self::REDIS_RETRY_INTERVAL
            )) {
                return null;
            }

            // Alapbeállítások
            if (defined('Redis::SERIALIZER_PHP')) {
                $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            }
            $redis->setOption(Redis::OPT_PREFIX, $this->prefix);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, self::REDIS_READ_TIMEOUT);
            $redis->setOption(Redis::OPT_TCP_KEEPALIVE, 1);

            return $redis;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Kapcsolat ellenőrzése és újracsatlakozás szükség esetén
     */
    private function ensure_connection() {
        $current_time = time();
        
        // Csak akkor ellenőrizzük a kapcsolatot, ha eltelt a megadott intervallum
        if ($current_time - $this->last_connection_check < self::CONNECTION_CHECK_INTERVAL) {
            return;
        }

        $this->last_connection_check = $current_time;

        try {
            // Aktív kapcsolat ellenőrzése
            if ($this->redis && $this->redis->ping()) {
                return;
            }
        } catch (\Exception $e) {
            // Kapcsolat hiba esetén új kapcsolatot próbálunk létrehozni
        }

        // Kapcsolat pool kezelése
        $this->connection_pool = array_filter($this->connection_pool, function($conn) {
            try {
                return $conn && $conn->ping();
            } catch (\Exception $e) {
                return false;
            }
        });

        // Ha nincs elérhető kapcsolat, újat hozunk létre
        if (empty($this->connection_pool)) {
            $new_connection = $this->create_connection();
            if ($new_connection) {
                $this->connection_pool[] = $new_connection;
                $this->redis = $new_connection;
                $this->is_available = true;
            } else {
                $this->is_available = false;
            }
        } else {
            // Használjuk az első elérhető kapcsolatot
            $this->redis = reset($this->connection_pool);
            $this->is_available = true;
        }
    }

    /**
     * Érték beállítása a cache-ben
     *
     * @param string $key A cache kulcs
     * @param mixed $data A tárolandó adat
     * @param int $expiration Lejárati idő másodpercben
     * @return bool Sikeres volt-e a művelet
     */
    public function set($key, $data, $expiration = 0) {
        $this->ensure_connection();
        if (!$this->is_available || !$this->redis) {
            return false;
        }

        try {
            // Explicit int konverzió
            $expiration = (int)$expiration;
            
            $result = false;
            if ($expiration <= 0) {
                $result = $this->redis->set($key, $data);
            } else {
                $result = $this->redis->setex($key, $expiration, $data);
            }

            if ($result) {
                // Elemszám és memóriahasználat frissítése
                $this->update_stats();
            }

            return $result;
        } catch (Exception $e) {
            $this->log_error('Redis set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Érték lekérése a cache-ből
     *
     * @param string $key A cache kulcs
     * @return mixed A tárolt érték vagy false ha nem található
     */
    public function get($key) {
        $this->ensure_connection();
        if (!$this->is_available || !$this->redis) {
            return false;
        }

        try {
            $value = $this->redis->get($key);
            if ($value !== false) {
                // Találat számolása
                $this->stats['hits']++;
                // Elemszám frissítése
                $this->update_stats();
                return $value;
            }
            // Kihagyás számolása
            $this->stats['misses']++;
            return false;
        } catch (\Exception $e) {
            $this->log_error(sprintf('Redis GET error: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Érték törlése a cache-ből
     *
     * @param string $key A cache kulcs
     * @return bool Sikeres volt-e a művelet
     */
    public function delete( $key ) {
        $this->ensure_connection();
        if ( ! $this->is_available || ! $this->redis ) {
            return false;
        }

        try {
            return $this->redis->del( $key ) > 0;
        } catch ( \Exception $e ) {
            $this->log_error( sprintf( 'Redis DELETE error: %s', $e->getMessage() ) );
            return false;
        }
    }

    /**
     * Siker naplózása
     * @param string $message A naplózandó üzenet
     */
    private function log_success($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Cacher Redis] %s', $message));
        }
    }

    /**
     * Hiba naplózása
     * @param string $message A naplózandó hibaüzenet
     */
    private function log_error($message) {
        $this->last_error = array(
            'message' => $message,
            'time'    => time(),
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Cacher Redis Error] %s', $message));
        }
    }

    /**
     * Cache statisztikák lekérése
     * @return array A Redis cache statisztikái
     */
    public function get_stats() {
        if (!$this->is_available()) {
            return array(
                'status' => false,
                'used_memory' => '0 B',
                'keys' => 0,
                'cache_size' => 0,
                'items' => 0,
                'available' => false
            );
        }

        try {
            // Ha még nincs inicializálva a stats, akkor inicializáljuk
            if (empty($this->stats)) {
                $this->stats = [
                    'hits' => 0,
                    'misses' => 0,
                    'used_memory' => '0 B',
                    'items' => 0
                ];
            }

            // A Redis szervertől csak akkor kérünk adatot, ha nincs még statisztikánk
            if ($this->stats['used_memory'] === '0 B' && $this->redis && $this->redis->ping()) {
                $info = $this->redis->info();
                if (isset($info['used_memory_human'])) {
                    $this->stats['used_memory'] = $info['used_memory_human'];
                }
                
                if (isset($info['db0'])) {
                    $db_info = explode(',', $info['db0']);
                    $keys_info = explode('=', $db_info[0]);
                    $this->stats['items'] = intval($keys_info[1] ?? 0);
                }
            }

            return array(
                'status' => true,
                'used_memory' => $this->stats['used_memory'],
                'keys' => $this->stats['items'],
                'cache_size' => 0,
                'items' => $this->stats['items'],
                'available' => true
            );

        } catch (\Exception $e) {
            $this->log_error(sprintf('Redis stats error: %s', $e->getMessage()));
            return array(
                'status' => false,
                'used_memory' => '0 B',
                'keys' => 0,
                'cache_size' => 0,
                'items' => 0,
                'available' => false
            );
        }
    }

    /**
     * Cache méret lekérése
     * @return string A használt memória mérete
     */
    public function get_size() {
        if ( ! $this->is_available || ! $this->redis ) {
            return '0 B';
        }

        try {
            $info = $this->redis->info();
            return $info['used_memory_human'] ?? '0 B';
        } catch ( \Exception $e ) {
            $this->log_error( sprintf( 'Redis size error: %s', $e->getMessage() ) );
            return '0 B';
        }
    }

    /**
     * Cache elemek számának lekérése
     * @return int Az elemek száma
     */
    public function get_item_count() {
        if ( ! $this->is_available || ! $this->redis ) {
            return 0;
        }

        try {
            $info = $this->redis->info();
            if (isset($info['db0'])) {
                $db_info = explode(',', $info['db0']);
                $keys_info = explode('=', $db_info[0]);
                return intval($keys_info[1] ?? 0);
            }
            return 0;
        } catch ( \Exception $e ) {
            $this->log_error( sprintf( 'Redis item count error: %s', $e->getMessage() ) );
            return 0;
        }
    }

    /**
     * Redis driver elérhetőségének ellenőrzése
     * @return bool True ha a driver elérhető
     */
    public function is_available() {
        $this->ensure_connection();
        return $this->is_available;
    }

    /**
     * Teljes cache tartalom törlése
     * @return bool Sikeres törlés esetén true
     */
    public function clear_all() {
        if (!$this->is_available() || !$this->redis) {
            return false;
        }

        try {
            // Erősebb FLUSHDB parancs használata
            if ($this->redis->flushDb()) {
                $this->log_success('Redis cache cleared using FLUSHDB');
                
                // Statisztikák nullázása
                $this->stats = [
                    'hits' => 0,
                    'misses' => 0,
                    'used_memory' => '0 B',
                    'items' => 0
                ];
                
                return true;
            }

            // Ha a FLUSHDB nem sikerült, akkor próbáljuk meg a SCAN + DEL kombinációt
            $iterator = null;
            $deleted_total = 0;

            do {
                $keys = $this->redis->scan($iterator, $this->prefix . '*', 1000);
                if (!empty($keys)) {
                    $deleted = $this->redis->del($keys);
                    if ($deleted) {
                        $deleted_total += $deleted;
                    }
                }
            } while ($iterator > 0);

            // Statisztikák nullázása itt is
            $this->stats = [
                'hits' => 0,
                'misses' => 0,
                'used_memory' => '0 B',
                'items' => 0
            ];

            $this->log_success(sprintf('Redis cache cleared: %d keys deleted', $deleted_total));
            return true;

        } catch (\Exception $e) {
            $this->log_error(sprintf('Redis clear all error: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Az utolsó hiba lekérdezése
     * @return array A hiba adatai
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Statisztikák frissítése
     */
    private function update_stats() {
        try {
            if ($this->redis && $this->redis->ping()) {
                $info = $this->redis->info();
                if (isset($info['used_memory_human'])) {
                    $this->stats['used_memory'] = $info['used_memory_human'];
                }
                if (isset($info['db0'])) {
                    $db_info = explode(',', $info['db0']);
                    $keys_info = explode('=', $db_info[0]);
                    $this->stats['items'] = intval($keys_info[1] ?? 0);
                }
            }
        } catch (\Exception $e) {
            $this->log_error('Failed to update Redis stats: ' . $e->getMessage());
        }
    }
}
