jQuery(document).ready(function($) {
    // Cache Be/Ki kapcsoló kezelése
    $('#cacher-toggle').on('change', function() {
        const $switch = $(this);
        const isEnabled = $switch.prop('checked');
        
        // Kapcsoló letiltása az AJAX kérés idejére
        $switch.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_toggle_cache',
                enabled: isEnabled ? 1 : 0, // Explicit 1 vagy 0 érték küldése
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(
                        isEnabled ? 
                            cacherL10n.messages.cacheActivated : 
                            cacherL10n.messages.cacheDeactivated,
                        'success'
                    );
                    $switch.prop('checked', response.data.status);
                } else {
                    showNotice(
                        cacherL10n.messages.error + response.data.message, 
                        'error'
                    );
                    $switch.prop('checked', !isEnabled);
                }
            },
            error: function(xhr, status, error) {
                showNotice(
                    cacherL10n.messages.error + error, 
                    'error'
                );
                $switch.prop('checked', !isEnabled);
            },
            complete: function() {
                // Kapcsoló engedélyezése
                $switch.prop('disabled', false);
                
                // Statisztikák frissítése
                updateCacheStats();
            }
        });
    });
    
    // Cache driver és TTL választók kezelése
    $('.cacher-driver-dropdown').on('change', function() {
        const $select = $(this);
        const type = $select.attr('id').replace('cacher-', '').replace('-driver', '');
        const driver = $select.val();
        const $ttlSelect = $select.closest('.cacher-select-controls').find('.cacher-ttl-dropdown');

        // TTL select engedélyezése/tiltása
        $ttlSelect.prop('disabled', driver === 'disabled');

        // Ha auto driver van kiválasztva
        if (driver === 'auto') {
            showAutoDriverInfo(type);
        } else {
            hideAutoDriverInfo(type);
            saveDriverSettings(type, driver, $ttlSelect.val());
        }
    });

    // Driver opciók frissítése
    function updateDriverOptions($select) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_get_available_drivers',
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success && response.data.drivers) {
                    const currentValue = $select.val();
                    $select.find('option').each(function() {
                        const $option = $(this);
                        const driver = $option.val();
                        if (driver !== 'auto' && driver !== 'disabled') {
                            $option.prop('disabled', !response.data.drivers[driver]);
                        }
                    });
                    // Ha az aktuális érték nem elérhető, váltás auto-ra
                    if ($select.find('option:selected').prop('disabled')) {
                        $select.val('auto').trigger('change');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Driver options update failed:', error);
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    console.error('Server response:', xhr.responseJSON.data);
                }
            }
        });
    }

    // TTL változás kezelése
    $('.cacher-ttl-dropdown').on('change', function() {
        const $ttlSelect = $(this);
        const $driverSelect = $ttlSelect.closest('.cacher-select-controls').find('.cacher-driver-dropdown');
        const type = $driverSelect.attr('id').replace('cacher-', '').replace('-driver', '');
        
        saveDriverSettings(type, $driverSelect.val(), $ttlSelect.val());
    });

    // Cache műveletek kezelése
    $('button[name="cacher_action"]').on('click', function(e) {
        const $button = $(this);
        const action = $button.val();
        
        switch (action) {
            case 'save_settings':
                e.preventDefault();
                saveAllSettings($button);
                break;
                
            case 'clear_cache':
                if (!confirm(cacherL10n.messages.confirmClearCache)) {
                    e.preventDefault();
                } else {
                    e.preventDefault();
                    clearCache($button);
                }
                break;
                
            case 'preload_cache':
                e.preventDefault();
                startPreloadProcess($button);
                break;
        }
    });

    // Segédfüggvények
    function saveDriverSettings(type, driver, ttl) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_update_driver',
                cache_type: type,
                driver: driver,
                ttl: ttl,
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    if (!$(`#cacher-${type}-driver`).next('.cacher-auto-info').length) {
                        showNotice(
                            type.charAt(0).toUpperCase() + type.slice(1) + 
                            cacherL10n.messages.cacheSettingsSaved, 
                            'success'
                        );
                    }
                    updateCacheStats();
                } else {
                    showNotice(cacherL10n.messages.settingError, 'error');
                }
            }
        });
    }

    function saveAllSettings($button) {
        const originalText = $button.html();
        $button.prop('disabled', true)
            .html('<span class="cacher-spinner"></span>' + originalText);
        
        const settings = {
            page: {
                driver: $('#cacher-page-driver').val(),
                ttl: $('#cacher-page-ttl').val()
            },
            query: {
                driver: $('#cacher-query-driver').val(),
                ttl: $('#cacher-query-ttl').val()
            }
        };
        
        // Kizárások összegyűjtése
        const excluded_urls = $('#cacher_excluded_urls').val();
        const excluded_hooks = $('#cacher_excluded_hooks').val();
        const excluded_db_queries = $('#cacher_excluded_db_queries').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_save_settings',
                settings: settings,
                excluded_urls: excluded_urls,
                excluded_hooks: excluded_hooks,
                excluded_db_queries: excluded_db_queries,
                _ajax_nonce: $('input[name="cacher_nonce"]').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(cacherL10n.messages.settingsSaved, 'success');
                } else {
                    showNotice(cacherL10n.messages.settingsSaveFailed, 'error');
                }
            },
            error: function() {
                showNotice(cacherL10n.messages.settingError, 'error');
            },
            complete: function() {
                $button.prop('disabled', false)
                    .html(originalText);
            }
        });
    }

    function clearCache($button) {
        const originalText = $button.html();
        $button.prop('disabled', true)
            .html('<span class="cacher-spinner"></span>' + originalText);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_clear_cache',
                _ajax_nonce: $('input[name="cacher_nonce"]').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(cacherL10n.messages.cacheCleared, 'success');
                } else {
                    showNotice(cacherL10n.messages.cacheClearFailed, 'error');
                }
            },
            error: function() {
                showNotice(cacherL10n.messages.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false)
                    .html(originalText);
                
                setTimeout(updateCacheStats, 1000);
            }
        });
    }

    function startPreloadProcess($button) {
        const originalText = $button.html();
        $button.prop('disabled', true)
            .html('<span class="cacher-spinner"></span>' + originalText);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_preload_cache',
                _ajax_nonce: $('input[name="cacher_nonce"]').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(cacherL10n.messages.cachePreloadCompleted, 'success');
                    updateCacheStats();
                } else {
                    showNotice(cacherL10n.messages.cachePreloadFailed, 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice(cacherL10n.messages.ajaxError + error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false)
                    .html(originalText);
            }
        });
    }

    function showAutoDriverInfo(type, silent = false) {
        const $select = $(`#cacher-${type}-driver`);
        const $existingInfo = $select.next('.cacher-auto-info');
        
        if ($existingInfo.length) {
            return;
        }

        $select.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_get_auto_driver',
                cache_type: type,
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    const driverName = response.data.driver;
                    const isAvailable = response.data.available === true;
                    
                    // Először frissítjük a megjelenítést
                    hideAutoDriverInfo(type);
                    updateDisplayedDriver(type, driverName, isAvailable);
                    
                    // Majd mentjük a beállítást
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cacher_update_driver',
                            cache_type: type,
                            driver: 'auto',
                            ttl: $select.closest('.cacher-select-controls').find('.cacher-ttl-dropdown').val(),
                            _ajax_nonce: $('#cacher_nonce').val()
                        },
                        success: function() {
                            if (!silent) {
                                showNotice(
                                    cacherL10n.messages.autoDriverSelected.replace('%s', response.data.driver.toUpperCase()), 
                                    'success'
                                );
                            }
                        },
                        complete: function() {
                            $select.prop('disabled', false);
                        }
                    });
                }
            },
            error: function() {
                $select.prop('disabled', false);
            }
        });
    }

    // Új segédfüggvény a megjelenítés frissítéséhez
    function updateDisplayedDriver(type, driverName, isAvailable) {
        // Legördülő menü feletti információ frissítése
        $(`.cacher-select-label label[for="cacher-${type}-driver"] .auto-driver-info`).html(
            `<span class="auto-driver-name ${isAvailable ? 'available' : 'unavailable'}">${driverName}</span>`
        );

        // Statisztikai táblázat frissítése
        $(`.cacher-type-stats-table tr[data-type="${type}"] .current-driver`)
            .text(driverName)
            .removeClass('available unavailable')
            .addClass(isAvailable ? 'available' : 'unavailable');
    }

    function hideAutoDriverInfo(type) {
        $(`.cacher-select-label label[for="cacher-${type}-driver"] .auto-driver-info`).empty();
    }

    function updateCacheStats() {
        if (updateCacheStats.isRunning) {
            return;
        }
        updateCacheStats.isRunning = true;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_get_stats',
                _ajax_nonce: $('input[name="cacher_nonce"]').val()
            },
            success: function(response) {
                if (response.success && response.data) {
                    Object.entries(response.data.types || {}).forEach(([type, stats]) => {
                        // Frissítjük a statisztikai táblázatot
                        const $row = $(`.cacher-type-stats-table tr[data-type="${type}"]`);
                        if ($row.length) {
                            $row.find('.hits').text(stats.hits ?? '0');
                            $row.find('.misses').text(stats.misses ?? '0');
                            $row.find('.hit-ratio').text(stats.hit_ratio ? `${stats.hit_ratio}%` : '0%');
                            $row.find('.avg-gen-time').text(stats.avg_generation_time ? 
                                `${Math.round(stats.avg_generation_time).toLocaleString()} μs` : '0 μs');
                            
                            // Frissítjük a driver információt mindkét helyen
                            const driverName = stats.current_driver || 'none';
                            const isAvailable = stats.driver_available === true;
                            
                            // Statisztikai táblázatban
                            $row.find('.current-driver')
                                .text(driverName)
                                .removeClass('available unavailable')
                                .addClass(isAvailable ? 'available' : 'unavailable');
                                
                            // Legördülő menü feletti információban
                            if ($(`#cacher-${type}-driver`).val() === 'auto') {
                                $(`.cacher-select-label label[for="cacher-${type}-driver"] .auto-driver-info`)
                                    .html(`<span class="auto-driver-name ${isAvailable ? 'available' : 'unavailable'}">${driverName}</span>`);
                            }
                        }
                    });
                }
            },
            complete: function() {
                updateCacheStats.isRunning = false;
            }
        });
    }

    // Tömörítés és felhasználó kizárás kezelése
    $('input[name="cacher_enable_compression"], input[name="cacher_exclude_logged_in"]').on('change', function() {
        const $checkbox = $(this);
        const setting = $checkbox.attr('name');
        const isEnabled = $checkbox.prop('checked');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_update_setting',
                setting: setting,
                value: isEnabled ? 1 : 0,
                _ajax_nonce: $('input[name="cacher_nonce"]').val()
            },
            beforeSend: function() {
                $checkbox.prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    // Itt volt a hiba - visszaállítjuk az eredeti logikát
                    showNotice(
                        isEnabled ? 
                            (setting === 'cacher_enable_compression' ? 
                                cacherL10n.messages.compressionEnabled : 
                                cacherL10n.messages.userExclusionEnabled) :
                            (setting === 'cacher_enable_compression' ? 
                                cacherL10n.messages.compressionDisabled : 
                                cacherL10n.messages.userExclusionDisabled),
                        'success'
                    );
                    updateCacheStats();
                } else {
                    $checkbox.prop('checked', !isEnabled);
                    showNotice(response.data.message || cacherL10n.messages.settingError, 'error');
                }
            },
            error: function(xhr) {
                $checkbox.prop('checked', !isEnabled);
                showNotice(xhr.responseJSON?.message || cacherL10n.messages.requestError, 'error');
            },
            complete: function() {
                $checkbox.prop('disabled', false);
            }
        });
    });

    // Automatikus TTL kezelése
    $('.cacher-ttl-dropdown').on('change', function() {
        const $select = $(this);
        if ($select.val() === 'auto') {
            const type = $select.attr('id').replace('cacher-', '').replace('-ttl', '');
            showNotice(
                cacherL10n.messages.autoTtlEnabled.replace('%s', type),
                'success'
            );
        }
    });

    // Globális változó a már megjelenített üzenetek nyilvántartásához
    const shownNotices = new Set();

    function showNotice(message, type = 'success') {
        // Ha az üzenet már megjelenítésre került, nem jelenítjük meg újra
        if (shownNotices.has(message)) {
            return;
        }
        
        const $notice = $(
            `<div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
            </div>`
        );
        
        $('.wrap.cacher-admin-wrap > h1').after($notice);
        shownNotices.add(message);
        
        // WordPress dismissible notices inicializálása
        if (window.wp && window.wp.notices) {
            window.wp.notices.initialize();
        }
        
        // Automatikus eltüntetés 5 másodperc után
        setTimeout(() => {
            $notice.fadeOut(() => {
                $notice.remove();
                shownNotices.delete(message);
            });
        }, 5000);
    }

    function updateCacheStatus(isEnabled) {
        // Cache állapot UI frissítése
        const $driverSelects = $('.cacher-driver-dropdown');
        const $ttlSelects = $('.cacher-ttl-dropdown');
        
        if (isEnabled) {
            $driverSelects.prop('disabled', false);
            $ttlSelects.each(function() {
                $(this).prop('disabled', $(this).closest('.cacher-select-controls').find('.cacher-driver-dropdown').val() === 'disabled');
            });
        } else {
            $driverSelects.prop('disabled', true);
            $ttlSelects.prop('disabled', true);
        }
    }

    // CSS animáció a forgó ikonhoz és auto driver info stílusok
    $('<style>')
        .text(`
            .dashicons.spin {
                animation: cacherSpin 2s linear infinite;
            }
            @keyframes cacherSpin {
                100% { transform: rotate(360deg); }
            }
            .cacher-auto-info {
                margin-top: 5px;
                line-height: 1.6;
            }
            .cacher-auto-info .auto-label {
                color: #1d2327;
                font-weight: bold;
                font-size: 0.9em;
            }
            .cacher-auto-info .auto-driver {
                margin-top: 2px;
            }
            .cacher-auto-info .auto-driver-name {
                font-size: 1.2em;
                font-weight: bold;
                display: inline-block;
            }
            .cacher-auto-info .auto-driver-name.available {
                color: #00a32a;
            }
            .cacher-auto-info .auto-driver-name.unavailable {
                color: #dc3232;
            }
            .cacher-stats-table,
            .cacher-type-stats-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                background: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .cacher-stats-table th,
            .cacher-stats-table td,
            .cacher-type-stats-table th,
            .cacher-type-stats-table td {
                text-align: left;
                padding: 8px;
                border-bottom: 1px solid #c3c4c7;
            }
            .cacher-stats-table th,
            .cacher-type-stats-table th {
                background: #f0f0f1;
                font-weight: 600;
            }
            .cacher-type-stats-table .current-driver {
                font-weight: 600;
            }
            .cacher-type-stats-table .current-driver.available {
                color: #00a32a;
            }
            .cacher-type-stats-table .current-driver.unavailable {
                color: #dc3232;
            }
            .cacher-type-stats-table .hits,
            .cacher-type-stats-table .hit-ratio {
                color: #00a32a;
                font-weight: 600;
            }
            .cacher-type-stats-table .misses {
                color: #dc3232;
                font-weight: 600;
            }
            .cacher-type-stats-table .avg-gen-time {
                color: #2271b1;
                font-weight: 600;
            }
            .tooltip-trigger {
                position: relative;
                display: inline-block;
                cursor: help;
                margin-left: -5px;
                opacity: 1 !important;
            }
            .tooltip-trigger .dashicons {
                color: #787c82;
                width: 12px;
                height: 12px;
                font-size: 12px;
                display: inline-block;
                vertical-align: middle;
            }
            .tooltip-trigger:hover .dashicons {
                color: #2271b1;
            }
            .tooltip-trigger:hover {
                opacity: 1;
            }
            .tooltip-trigger:hover:after {
                content: attr(data-tooltip);
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.8);
                color: #fff;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 13px;
                line-height: 1.4;
                white-space: normal;
                z-index: 9999;
                margin-bottom: 10px;
                min-width: 250px;
                max-width: 300px;
                text-align: left;
            }
            .tooltip-trigger:hover:before {
                content: '';
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: rgba(0, 0, 0, 0.8);
                margin-bottom: 4px;
                z-index: 9999;
            }
        `)
        .appendTo('head');

    // Kezdeti betöltéskor frissítjük a driver opciókat
    $('.cacher-driver-dropdown').each(function() {
        updateDriverOptions($(this));
    });

    // Kezdeti betöltéskor is megjelenítjük az auto driver infót
    $(document).ready(function() {
        // Először betöltjük a statisztikákat
        updateCacheStats();

        // Statisztikák frissítése 30 másodpercenként
        setInterval(updateCacheStats, 30000);

        // Auto driver információk inicializálása - silent módban
        $('.cacher-driver-dropdown').each(function() {
            const $select = $(this);
            if ($select.val() === 'auto') {
                const type = $select.attr('id').replace('cacher-', '').replace('-driver', '');
                // Silent paraméter hozzáadása
                showAutoDriverInfo(type, true);
            }
        });

        // Debug információk a statisztika táblázatról
        if (window.cacher_debug) {  // Csak debug módban jelenjenek meg
            console.log(cacherL10n.messages.cacheStatsTableStructure);
            $('.cacher-type-stats-table tr').each(function() {
                const $row = $(this);
                const type = $row.data('type');
                console.log(cacherL10n.messages.rowType.replace('%s', type));
                console.log(cacherL10n.messages.rowStructure, {
                    hits: $row.find('.hits').length,
                    misses: $row.find('.misses').length,
                    hitRatio: $row.find('.hit-ratio').length,
                    avgGenTime: $row.find('.avg-gen-time').length,
                    items: $row.find('.items').length,
                    memory: $row.find('.memory').length,
                    driver: $row.find('.current-driver').length
                });
            });
        }
    });

    // Cache preload kezelése
    $('button[value="preload_cache"]').on('click', function(e) {
        e.preventDefault();
        
        // Ellenőrizzük, hogy a preload engedélyezve van-e
        if (!$('input[name="cacher_enable_preload"]').prop('checked')) {
            showNotice(cacherL10n.messages.preloadFunctionNotEnabled, 'error');
            return;
        }
        
        const $button = $(this);
        const originalText = $button.html();
        
        // Ellenőrizzük, hogy van-e már spinner
        if ($button.find('.cacher-spinner').length === 0) {
            $button.prop('disabled', true)
                .html('<span class="cacher-spinner"></span>' + originalText);
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_preload_cache',
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(cacherL10n.messages.cachePreloadCompleted, 'success');
                } else {
                    showNotice(cacherL10n.messages.cachePreloadFailed, 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice(cacherL10n.messages.ajaxError + error, 'error');
            },
            complete: function() {
                // Gomb visszaállítása
                $button.prop('disabled', false)
                    .html(originalText);
            }
        });
    });

    // Redis beállítások mentése
    $('#save-redis-settings').on('click', function() {
        const $button = $(this);
        const originalText = $button.text();
        
        $button.prop('disabled', true)
            .html('<span class="cacher-spinner"></span>' + originalText);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_save_redis_settings',
                host: $('input[name="cacher_redis_host"]').val(),
                port: $('input[name="cacher_redis_port"]').val(),
                auth: $('input[name="cacher_redis_auth"]').val(),
                db: $('input[name="cacher_redis_db"]').val(),
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(cacherL10n.messages.redisSettingsSaved, 'success');
                } else {
                    showNotice(
                        cacherL10n.messages.redisSettingsSavedButConnectionFailed.replace('%s', response.data.message),
                        'error'
                    );
                }
            },
            error: function() {
                showNotice(cacherL10n.messages.redisSettingsSaveFailed, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Preload checkbox kezelése
    $('input[name="cacher_enable_preload"]').on('change', function() {
        const $checkbox = $(this);
        const isEnabled = $checkbox.prop('checked');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cacher_update_setting',
                setting: 'cacher_enable_preload',
                value: isEnabled ? 1 : 0,
                _ajax_nonce: $('#cacher_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(
                        isEnabled ? 
                            cacherL10n.messages.preloadFunctionEnabled : 
                            cacherL10n.messages.preloadFunctionDisabled,
                        'success'
                    );
                } else {
                    $checkbox.prop('checked', !isEnabled);
                    showNotice(cacherL10n.messages.settingError, 'error');
                }
            },
            error: function() {
                $checkbox.prop('checked', !isEnabled);
                showNotice(cacherL10n.messages.settingError, 'error');
            }
        });
    });

    // Tooltip-ek esetén:
    $('[data-tooltip]').attr('data-tooltip', function(index, value) {
        return cacherL10n.__(value, 'cacher');
    });
});
