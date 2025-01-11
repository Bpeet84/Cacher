<?php
class Cacher_File {
    /**
     * @var string $cache_dir A cache fájlok könyvtára
     */
    private $cache_dir;

    /**
     * @var WP_Filesystem_Base $filesystem A fájlrendszer kezelő
     */
    private $filesystem;

    /**
     * Konstruktor
     * 
     * @param string|null $cache_dir Opcionális cache könyvtár útvonal
     */
    public function __construct($cache_dir = null) {
        // Ellenőrizzük, hogy a cache_dir string típusú-e
        if (!is_string($cache_dir)) {
            $cache_dir = WP_CONTENT_DIR . '/cache/cacher/';
        }
        
        $this->cache_dir = rtrim($cache_dir, '/') . '/';
        $this->init_filesystem();
        $this->ensure_cache_dir();
    }

    /**
     * Fájlrendszer inicializálása
     */
    private function init_filesystem() {
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        if (!WP_Filesystem()) {
            throw new Exception('Failed to initialize WP Filesystem');
        }
        
        $this->filesystem = $wp_filesystem;
    }

    /**
     * Cache könyvtár létrehozása, ha nem létezik
     */
    private function ensure_cache_dir() {
        // Ellenőrizzük, hogy a cache_dir string típusú-e
        if (!is_string($this->cache_dir)) {
            throw new Exception('Invalid cache directory path');
        }

        if (!$this->filesystem->exists($this->cache_dir)) {
            if (!wp_mkdir_p($this->cache_dir)) {
                error_log('Cacher: Failed to create cache directory: ' . $this->cache_dir);
                throw new Exception('Failed to create cache directory');
            }
            // Jogosultságok beállítása WP Filesystem használatával
            $this->filesystem->chmod($this->cache_dir, FS_CHMOD_DIR);
        }
    }

    // Maximális fájlméret konstans
    const MAX_FILE_SIZE = 5242880; // 5MB

    /**
     * Biztonságos fájl elérési út generálása
     *
     * @param string $key A cache kulcs
     * @return string A biztonságos fájl elérési út
     */
    private function get_safe_path($key) {
        // Karakter szűrés a kulcsból
        $key = preg_replace('/[^a-z0-9]/i', '', $key);
        $hash = md5($key);
        return $this->cache_dir . substr($hash, 0, 2) . '/' . substr($hash, 2) . '.cache';
    }

    /**
     * Adatok lekérése a cache-ből
     *
     * @param string $key A cache kulcs
     * @return mixed A cache-ből lekérdezett adat
     */
    public function get($key) {
        $file_path = $this->get_safe_path($key);
        
        if (!$this->filesystem->exists($file_path)) {
            return false;
        }

        $data = $this->filesystem->get_contents($file_path);
        if ($data === false) {
            return false;
        }

        $data = unserialize($data);
        if ($data === false) {
            return false;
        }

        if ($data['expiration'] > 0 && time() > $data['expiration']) {
            $this->delete($key);
            return false;
        }

        return $data['value'];
    }

    /**
     * Adatok mentése a cache-be
     * @responsibility Cache adatok biztonságos mentése zárolással és lemezterület ellenőrzéssel
     * @param string $key A cache kulcs
     * @param mixed $value A mentendő adat
     * @param int $expiration Lejárati idő másodpercben
     * @return bool Sikeres mentés esetén true
     */
    public function set($key, $value, $expiration = 0) {
        // Méretlimit ellenőrzés
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_FILE_SIZE) {
            error_log('Cache value too large: ' . $key);
            return false;
        }

        $file_path = $this->get_safe_path($key);
        $dir = dirname($file_path);
        
        // Lemezterület ellenőrzés
        if (function_exists('disk_free_space')) {
            $disk_free_space = @disk_free_space($this->cache_dir);
            if ($disk_free_space !== false && $disk_free_space < 1024 * 1024) {
                error_log('Low disk space, clearing cache');
                $this->clear_all();
            }
        }

        if (!$this->filesystem->exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                error_log('Failed to create cache directory: ' . $dir);
                throw new Exception('Unable to create cache subdirectory');
            }
            $this->filesystem->chmod($dir, FS_CHMOD_DIR);
        }

        $data = array(
            'value' => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0
        );

        // Zárolás és írás WP Filesystem használatával
        $temp_file = $file_path . '.tmp.' . uniqid();
        if (!$this->filesystem->put_contents($temp_file, serialize($data), FS_CHMOD_FILE)) {
            error_log('Failed to write cache file: ' . $file_path);
            return false;
        }

        // Atomi átnevezés
        if (!$this->filesystem->move($temp_file, $file_path, true)) {
            $this->filesystem->delete($temp_file);
            error_log('Failed to move cache file: ' . $file_path);
            return false;
        }

        return true;
    }

    /**
     * Adatok törlése a cache-ből
     * 
     * @param string $key A cache kulcs
     * @return bool Sikeres törlés esetén true
     */
    public function delete($key) {
        $file_path = $this->get_safe_path($key);
        return $this->filesystem->delete($file_path);
    }

    /**
     * Cache statisztikák lekérése
     *
     * @return array Cache statisztikák (hits, misses, hit_ratio, cache_size, items)
     */
    public function get_stats() {
        try {
            $cache_size = $this->get_cache_size();
            $items = $this->get_item_count();
            
            return array(
                'status' => true,
                'used_memory' => size_format($cache_size),
                'keys' => $items,
                'cache_size' => $cache_size,
                'items' => $items,
                'available' => true
            );
        } catch (Exception $e) {
            error_log('Cacher: Error getting file cache stats: ' . $e->getMessage());
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
     * Cache találat rögzítése
     */
    public function record_hit() {
        // Nincs szükség külön statisztika tárolásra
        return;
    }

    /**
     * Cache kihagyás rögzítése
     */
    public function record_miss() {
        // Nincs szükség külön statisztika tárolásra
        return;
    }

    /**
     * Cache méret lekérése
     *
     * @return int Cache méret bájtokban
     */
    private function get_cache_size() {
        if (!file_exists($this->cache_dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache_dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Cache elemek számának lekérése
     *
     * @return int Cache elemek száma
     */
    private function get_item_count() {
        if (!file_exists($this->cache_dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache_dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Teljes cache tartalom törlése
     *
     * @return bool Sikeres törlés esetén true
     */
    public function clear_all() {
        try {
            if (!file_exists($this->cache_dir)) {
                return true;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $action = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                if (!@$action($fileinfo->getRealPath())) {
                    error_log('Cacher: Failed to delete cache file: ' . $fileinfo->getRealPath());
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            error_log('Cacher: Error clearing file cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * File driver elérhetőségének ellenőrzése
     * @responsibility File alapú cache működőképességének és jogosultságainak ellenőrzése
     * @return bool True ha a driver elérhető és működőképes
     */
    public function is_available() {
        try {
            if (!$this->filesystem->exists($this->cache_dir)) {
                if (!wp_mkdir_p($this->cache_dir)) {
                    error_log('Failed to create cache directory: ' . $this->cache_dir);
                    return false;
                }
                $this->filesystem->chmod($this->cache_dir, FS_CHMOD_DIR);
            }

            if (!$this->filesystem->is_writable($this->cache_dir)) {
                error_log('Cache directory is not writable: ' . $this->cache_dir);
                return false;
            }

            // Teszt fájl írása és olvasása
            $test_file = $this->cache_dir . 'test_' . uniqid() . '.tmp';
            $test_content = 'test_' . time();

            if (!$this->filesystem->put_contents($test_file, $test_content, FS_CHMOD_FILE)) {
                error_log('Failed to write test file: ' . $test_file);
                return false;
            }

            $read_content = $this->filesystem->get_contents($test_file);
            $this->filesystem->delete($test_file);

            if ($read_content !== $test_content) {
                error_log('Failed to verify test file content');
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log('File driver availability check error: ' . $e->getMessage());
            return false;
        }
    }
}
