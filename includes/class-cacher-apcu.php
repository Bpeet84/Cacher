<?php
/**
 * APCu alapú cache kezelő osztály. Felelős a memóriában tárolt cache adatok kezeléséért.
 *
 * @package Cacher
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cacher_Apcu {
    /**
     * @var string $prefix A cache kulcsok előtagja
     */
    private $prefix = 'cacher_';

    /**
     * @var Cacher_Manager $manager A cache manager példány
     */
    private $manager;

    /**
     * Konstruktor
     */
    public function __construct($manager) {
        $this->manager = $manager;
    }

    /**
     * Cache statisztikák lekérése
     *
     * @return array Cache statisztikák (hits, misses, hit_ratio, cache_size, items)
     */
    public function get_stats() {
        try {
            $info = apcu_cache_info(true);
            $sma = apcu_sma_info();
            
            return array(
                'hits' => $info['num_hits'],
                'misses' => $info['num_misses'],
                'hit_ratio' => $info['num_hits'] > 0 ? 
                    round($info['num_hits'] / ($info['num_hits'] + $info['num_misses']), 2) : 0,
                'cache_size' => $sma['seg_size'] - $sma['avail_mem'],
                'items' => $info['num_entries']
            );
        } catch (Exception $e) {
            return array(
                'hits' => 0,
                'misses' => 0,
                'hit_ratio' => 0,
                'cache_size' => 0,
                'items' => 0
            );
        }
    }

    /**
     * Adatok lekérése a cache-ből
     *
     * @param string $key A cache kulcs
     * @return mixed A cache-ből lekérdezett adat
     */
    public function get($key) {
        if (!$this->is_available()) {
            return false;
        }

        $full_key = $this->prefix . $key;
        try {
            $data = apcu_fetch($full_key);
            if ($data === false) {
                return false;
            }

            if ($data['expiration'] > 0 && time() > $data['expiration']) {
                $this->delete($key);
                return false;
            }

            return $data['value'];
        } catch (Exception $e) {
            error_log('APCu get error: ' . $e->getMessage());
            return false;
        }
    }

    // Maximális memória méret konstans
    const MAX_MEMORY_SIZE = 10485760; // 10MB

    /**
     * Adatok mentése a cache-be
     * @responsibility Cache adatok mentése és memória ellenőrzés
     * @param string $key A cache kulcs
     * @param mixed $value A mentendő adat
     * @param int $expiration Lejárati idő másodpercben
     * @return bool Sikeres mentés esetén true
     */
    public function set($key, $value, $expiration = 0) {
        if (!$this->is_available()) {
            return false;
        }

        // Méretlimit ellenőrzés
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_MEMORY_SIZE) {
            error_log('APCu value too large: ' . $key);
            return false;
        }

        // Memória ellenőrzés
        $sma = apcu_sma_info();
        if ($sma['avail_mem'] < 1024 * 1024) { // 1MB-nál kevesebb szabad memória
            error_log('APCu memory is almost full, clearing cache');
            $this->clear_all();
        }

        $full_key = $this->prefix . $key;
        $data = array(
            'value' => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0
        );

        try {
            return apcu_store($full_key, $data, $expiration);
        } catch (Exception $e) {
            error_log('APCu set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Adatok törlése a cache-ből
     * @responsibility Cache adatok törlése és hibakezelés
     * @param string $key A cache kulcs
     * @return bool Sikeres törlés esetén true
     */
    public function delete($key) {
        if (!$this->is_available()) {
            return false;
        }

        $full_key = $this->prefix . $key;
        try {
            return apcu_delete($full_key);
        } catch (Exception $e) {
            error_log('APCu delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Teljes cache tartalom törlése
     * @responsibility Teljes cache törlése és hibakezelés
     * @return bool Sikeres törlés esetén true
     */
    public function clear_all() {
        if (!$this->is_available()) {
            return false;
        }

        try {
            return apcu_clear_cache();
        } catch (Exception $e) {
            error_log('APCu clear error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * APCu driver elérhetőségének ellenőrzése
     * @return bool True ha a driver elérhető és működőképes
     */
    public function is_available() {
        return extension_loaded('apcu') && 
               function_exists('apcu_store') && 
               function_exists('apcu_fetch');
    }
}
