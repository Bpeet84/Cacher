<?php
/**
 * Admin interface template
 * @responsibility Displaying and handling the admin interface
 */
?>
    <div class="wrap cacher-admin-wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <input type="hidden" id="cacher_nonce" name="cacher_nonce" value="<?php echo esc_attr(wp_create_nonce('cacher_nonce')); ?>" />

    <!-- Felső eszköztár -->
    <div class="cacher-toolbar">
        <!-- Bal oldali vezérlők -->
        <div class="cacher-controls">
            <!-- Cache Be/Ki kapcsoló -->
            <div class="cacher-switch-wrapper">
                <label class="cacher-switch">
                    <input type="checkbox" 
                           id="cacher-toggle" 
                           name="cacher_enabled"
                           <?php echo get_option('cacher_enabled') ? 'checked' : ''; ?>>
                    <span class="cacher-slider"></span>
                </label>
                <span class="cacher-switch-label">
                    <?php esc_html_e('Cache', 'cacher'); ?>
                </span>
            </div>

            <!-- Műveleti gombok -->
            <button type="submit" name="cacher_action" value="save_settings" class="button button-primary">
                <i class="dashicons dashicons-saved"></i>
                <?php esc_html_e('Save Settings', 'cacher'); ?>
                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                   data-tooltip="<?php esc_attr_e('Save all cache settings including driver selections and TTL values.', 'cacher'); ?>">
                </i>
            </button>

            <button type="submit" name="cacher_action" value="clear_cache" class="button">
                <i class="dashicons dashicons-trash"></i>
                <?php esc_html_e('Clear Cache', 'cacher'); ?>
                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                   data-tooltip="<?php esc_attr_e('Immediately clear all cache content from all drivers.', 'cacher'); ?>">
                </i>
            </button>

            <button type="submit" name="cacher_action" value="preload_cache" class="button">
                <i class="dashicons dashicons-update"></i>
                <?php esc_html_e('Cache Preload', 'cacher'); ?>
                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                   data-tooltip="<?php esc_attr_e('Pre-generate cache for most visited pages for faster loading.', 'cacher'); ?>">
                </i>
            </button>
        </div>

        <!-- Cache driver választók -->
        <div class="cacher-driver-select">
            <?php
            $ttl_options = array(
                'page' => array(
                    1800 => esc_html__('30 minutes', 'cacher'),
                    3600 => esc_html__('1 hour', 'cacher'),
                    7200 => esc_html__('2 hours', 'cacher'),
                    14400 => esc_html__('4 hours', 'cacher'),
                    28800 => esc_html__('8 hours', 'cacher'),
                    43200 => esc_html__('12 hours', 'cacher'),
                    86400 => esc_html__('24 hours', 'cacher')
                ),
                'query' => array(
                    60 => esc_html__('1 minute', 'cacher'),
                    300 => esc_html__('5 minutes', 'cacher'),
                    600 => esc_html__('10 minutes', 'cacher'),
                    900 => esc_html__('15 minutes', 'cacher'),
                    1800 => esc_html__('30 minutes', 'cacher'),
                    3600 => esc_html__('1 hour', 'cacher')
                )
            );

            $tooltips = array(
                'page' => esc_attr__('Full page cache: Stores complete HTML output. Ideal for static or rarely changing pages.', 'cacher'),
                'query' => esc_attr__('Query cache: Stores SQL query results. Significantly reduces database load.', 'cacher')
            );

            foreach(['page', 'query'] as $cache_type): 
                $current_driver = get_option("cacher_{$cache_type}_driver", 'auto');
                $current_ttl = get_option("cacher_{$cache_type}_ttl", array_key_first($ttl_options[$cache_type]));
            ?>
                <div class="cacher-select-group">
                    <div class="cacher-select-label">
                        <label for="cacher-<?php echo esc_attr($cache_type); ?>-driver">
                            <?php echo esc_html(ucfirst($cache_type)); ?> <?php esc_html_e('Cache', 'cacher'); ?>
                            <i class="dashicons dashicons-editor-help tooltip-trigger" 
                               data-tooltip="<?php echo esc_attr($tooltips[$cache_type]); ?>">
                            </i>
                            <span class="auto-driver-info"></span>
                        </label>
                    </div>
                    <div class="cacher-select-controls">
                        <select id="cacher-<?php echo esc_attr($cache_type); ?>-driver" 
                                name="cacher_<?php echo esc_attr($cache_type); ?>_driver"
                                class="cacher-driver-dropdown"
                                title="<?php esc_attr_e('Automatic driver selection based on availability', 'cacher'); ?>">
                            <option value="disabled" <?php selected($current_driver, 'disabled'); ?>>
                                <?php esc_html_e('Disabled', 'cacher'); ?>
                            </option>
                            <option value="auto" <?php selected($current_driver, 'auto'); ?>>
                                <?php esc_html_e('Automatic', 'cacher'); ?>
                            </option>
                            <?php foreach(['file', 'apcu', 'redis'] as $driver): ?>
                                <?php $available = $this->manager->is_driver_available($driver); ?>
                                <option value="<?php echo esc_attr($driver); ?>" 
                                        <?php selected($current_driver, $driver); ?>
                                        <?php echo !$available ? 'disabled' : ''; ?>>
                                    <?php echo esc_html(ucfirst($driver)); ?>
                                    <?php if ($available): ?>
                                        <i class="dashicons dashicons-yes-alt cacher-driver-status available"></i>
                                    <?php else: ?>
                                        <i class="dashicons dashicons-dismiss cacher-driver-status unavailable"></i>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select id="cacher-<?php echo esc_attr($cache_type); ?>-ttl" 
                                name="cacher_<?php echo esc_attr($cache_type); ?>_ttl"
                                class="cacher-ttl-dropdown"
                                title="<?php esc_attr_e('Time to Live - how long items are stored in cache', 'cacher'); ?>"
                                <?php echo $current_driver === 'disabled' ? 'disabled' : ''; ?>>
                                <option value="auto" <?php selected($current_ttl, 'auto'); ?>>
                            <?php esc_html_e('Automatic TTL', 'cacher'); ?>
                                </option>
                            <?php foreach($ttl_options[$cache_type] as $seconds => $label): ?>
                                <option value="<?php echo esc_attr($seconds); ?>" <?php selected($current_ttl, $seconds); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- Második eszköztár sor -->
    <div class="cacher-toolbar-row">
        <!-- Bal oldali vezérlők -->
        <div class="cacher-controls-left">
            <!-- Felhasználó kizárás -->
            <div class="cacher-control-item">
                <label class="cacher-checkbox-label">
                    <input type="checkbox" name="cacher_exclude_logged_in" 
                        <?php checked(get_option('cacher_exclude_logged_in', 0), 1); ?>>
                    <?php esc_html_e('Exclude Logged-in Users', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('When enabled, logged-in users will not receive cached content.', 'cacher'); ?>">
                    </i>
                </label>
            </div>

            <!-- Tömörítés -->
            <div class="cacher-control-item">
                <label class="cacher-checkbox-label">
                    <input type="checkbox" name="cacher_enable_compression" 
                        <?php checked(get_option('cacher_enable_compression', 0), 1); ?>>
                    <?php esc_html_e('Enable Compression', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('Use Gzip compression for cache contents.', 'cacher'); ?>">
                    </i>
                </label>
            </div>

            <!-- Preload engedélyezése -->
            <div class="cacher-control-item">
                <label class="cacher-checkbox-label">
                    <input type="checkbox" name="cacher_enable_preload" 
                        <?php checked(get_option('cacher_enable_preload', 0), 1); ?>>
                    <?php esc_html_e('Enable Preload', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('When enabled, cache preload function can be used. This automatically pre-generates cache for frequently visited pages.', 'cacher'); ?>">
                    </i>
                </label>
            </div>
        </div>

        <!-- Jobb oldali Redis beállítások -->
        <div class="cacher-controls-right">
            <div class="cacher-redis-settings">
                <div class="cacher-redis-header">
                    <?php esc_html_e('Redis Settings', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('Redis server connection settings. Only modify if you are using a custom Redis server.', 'cacher'); ?>">
                    </i>
                </div>
                <div class="cacher-redis-form">
                    <input type="text" name="cacher_redis_host" 
                           value="<?php echo esc_attr(get_option('cacher_redis_host', '127.0.0.1')); ?>" 
                           placeholder="<?php esc_attr_e('Host', 'cacher'); ?>" class="small-text">
                    <input type="number" name="cacher_redis_port" 
                           value="<?php echo esc_attr(get_option('cacher_redis_port', 6379)); ?>" 
                           placeholder="<?php esc_attr_e('Port', 'cacher'); ?>" class="small-text">
                    <input type="password" name="cacher_redis_auth" 
                           value="<?php echo esc_attr(get_option('cacher_redis_auth', '')); ?>" 
                           placeholder="<?php esc_attr_e('Auth', 'cacher'); ?>" class="small-text">
                    <button type="button" class="button button-secondary" id="save-redis-settings">
                        <?php esc_html_e('Save', 'cacher'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Fő konténer -->
    <div class="cacher-admin-container">
        <!-- Kizárások panel -->
        <div class="cacher-admin-panel">
            <div class="cacher-panel-header">
                <h2><?php esc_html_e('Exclusion Settings', 'cacher'); ?></h2>
            </div>
            <div class="cacher-panel-content">
                <!-- Automatikus kizárások -->
                <div class="cacher-exclusions">
                    <div class="cacher-exclusions-title">
                        <?php esc_html_e('Automatic Exclusions', 'cacher'); ?>
                    </div>
                    <?php if (class_exists('WooCommerce')): ?>
                        <ul class="cacher-auto-exclusions">
                            <li><?php esc_html_e('Cart Page', 'cacher'); ?></li>
                            <li><?php esc_html_e('Checkout Page', 'cacher'); ?></li>
                            <li><?php esc_html_e('Shipping Page', 'cacher'); ?></li>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Felhasználói kizárások -->
                <div class="cacher-exclusions">
                    <div class="cacher-exclusions-title">
                        <?php esc_html_e('URL Exclusions', 'cacher'); ?>
                        <i class="dashicons dashicons-editor-help tooltip-trigger" 
                           data-tooltip="<?php esc_attr_e('One URL pattern per line. You can use * wildcard. For example: /cart/*, /checkout/*, /admin/*', 'cacher'); ?>">
                        </i>
                    </div>
                    <textarea id="cacher_excluded_urls" 
                              name="cacher_excluded_urls" 
                              placeholder="/cart/*&#10;/checkout/*&#10;/admin/*&#10;*.pdf"><?php echo esc_textarea(get_option('cacher_excluded_urls', '')); ?></textarea>

                    <div class="cacher-exclusions-title">
                        <?php esc_html_e('Hook Exclusions', 'cacher'); ?>
                        <i class="dashicons dashicons-editor-help tooltip-trigger" 
                           data-tooltip="<?php esc_attr_e('One WordPress hook name per line. These hooks will not be cached. For example: woocommerce_cart_updated, user_profile_update', 'cacher'); ?>">
                        </i>
                    </div>
                    <textarea id="cacher_excluded_hooks" 
                              name="cacher_excluded_hooks"
                              placeholder="woocommerce_cart_updated&#10;user_profile_update&#10;custom_dynamic_hook"><?php echo esc_textarea(get_option('cacher_excluded_hooks', '')); ?></textarea>

                    <div class="cacher-exclusions-title">
                        <?php esc_html_e('SQL Exclusions', 'cacher'); ?>
                        <i class="dashicons dashicons-editor-help tooltip-trigger" 
                           data-tooltip="<?php esc_attr_e('One SQL query pattern per line. Queries matching these patterns will not be cached. You can use * wildcard.', 'cacher'); ?>">
                        </i>
                    </div>
                    <textarea id="cacher_excluded_db_queries" 
                              name="cacher_excluded_db_queries"
                              placeholder="SELECT * FROM wp_posts WHERE post_type = 'shop_order'&#10;UPDATE wp_*&#10;INSERT INTO wp_*"><?php echo esc_textarea(get_option('cacher_excluded_db_queries', '')); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Statisztikák panel -->
        <div class="cacher-admin-panel">
            <div class="cacher-panel-header">
                <h2><?php esc_html_e('Cache Statistics', 'cacher'); ?></h2>
            </div>
            <div class="cacher-panel-content">
                <table class="cacher-type-stats-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Type', 'cacher'); ?></th>
                            <th>
                                <?php esc_html_e('Hits', 'cacher'); ?>
                                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                                   data-tooltip="<?php esc_attr_e('Number of successful cache hits', 'cacher'); ?>">
                                </i>
                            </th>
                            <th>
                                <?php esc_html_e('Misses', 'cacher'); ?>
                                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                                   data-tooltip="<?php esc_attr_e('Number of cache misses (when content had to be regenerated)', 'cacher'); ?>">
                                </i>
                            </th>
                            <th>
                                <?php esc_html_e('Hit Ratio', 'cacher'); ?>
                                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                                   data-tooltip="<?php esc_attr_e('Cache hit ratio compared to all requests', 'cacher'); ?>">
                                </i>
                            </th>
                            <th>
                                <?php esc_html_e('Average Generation Time', 'cacher'); ?>
                                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                                   data-tooltip="<?php esc_attr_e('Average time to generate cache content', 'cacher'); ?>">
                                </i>
                            </th>
                            <th>
                                <?php esc_html_e('Current Driver', 'cacher'); ?>
                                <i class="dashicons dashicons-editor-help tooltip-trigger" 
                                   data-tooltip="<?php esc_attr_e('Currently used cache driver', 'cacher'); ?>">
                                </i>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach (['page' => 'Page Cache', 'query' => 'Query Cache'] as $type => $label): 
                            $stats = $this->manager->get_stats();
                            $type_stats = $stats['types'][$type] ?? [];
                            $driver_available = $type_stats['driver_available'] ?? false;
                        ?>
                            <tr data-type="<?php echo esc_attr($type); ?>">
                                <td><?php echo esc_html($label); ?></td>
                                <td class="hits"><?php echo esc_html($type_stats['hits'] ?? 0); ?></td>
                                <td class="misses"><?php echo esc_html($type_stats['misses'] ?? 0); ?></td>
                                <td class="hit-ratio"><?php echo esc_html($type_stats['hit_ratio'] ?? 0); ?>%</td>
                                <td class="avg-gen-time"><?php echo esc_html(number_format(($type_stats['avg_generation_time'] ?? 0) / 1000, 0, '.', ' ')); ?> μs</td>
                                <td class="current-driver <?php echo esc_attr($driver_available ? 'available' : 'unavailable'); ?>">
                                    <?php echo esc_html(ucfirst($type_stats['current_driver'] ?? 'none')); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Driver Statistics', 'cacher'); ?></h3>
                <table class="cacher-stats-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Driver', 'cacher'); ?></th>
                            <th><?php esc_html_e('Status', 'cacher'); ?></th>
                            <th><?php esc_html_e('Used Space', 'cacher'); ?></th>
                            <th><?php esc_html_e('Items', 'cacher'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach (['file' => 'File', 'apcu' => 'APCu', 'redis' => 'Redis'] as $driver => $label): 
                            $driver_stats = $stats['drivers'][$driver] ?? [
                                'available' => false,
                                'used_space' => '0 B',
                                'items' => 0
                            ];
                        ?>
                            <tr data-driver="<?php echo esc_attr($driver); ?>">
                                <td><?php echo esc_html($label); ?></td>
                                <td>
                                    <i class="dashicons <?php echo esc_attr($driver_stats['available'] ? 'dashicons-yes-alt available' : 'dashicons-dismiss unavailable'); ?> cacher-driver-status"></i>
                                </td>
                                <td class="used-space"><?php echo esc_html($driver_stats['used_space']); ?></td>
                                <td class="items"><?php echo esc_html($driver_stats['items']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- PayPal támogatás szekció -->
                <div class="cacher-support-section">
                    <h3><?php esc_html_e('Support the Development!', 'cacher'); ?></h3>
                    <p class="cacher-support-message">
                        <?php esc_html_e('If you find the Cacher plugin useful, please consider supporting the project! With your help, we can develop more useful features and maintain the plugin continuously.', 'cacher'); ?>
                    </p>
                    
                    <div class="cacher-donate-wrapper">
                        <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
                            <input type="hidden" name="cmd" value="_donations" />
                            <input type="hidden" name="business" value="info@hostfix.hu" />
                            <input type="hidden" name="item_name" value="Support Cacher Plugin Development" />
                            <input type="hidden" name="currency_code" value="USD" />
                            <div class="donate-button-container">
                                <button type="submit" class="button paypal-button">
                                    <span class="dashicons dashicons-heart"></span>
                                    <?php esc_html_e('Support with PayPal', 'cacher'); ?>
                                </button>
                                <small class="secure-text">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php esc_html_e('Secure payment through PayPal', 'cacher'); ?>
                                </small>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- A PayPal form után, de még a cacher-support-section div-en belül -->
                <div class="cacher-author-info">
                    <div class="cacher-author-website">
                        <a href="https://hostfix.hu/plugins" target="_blank" class="button button-secondary">
                            <i class="dashicons dashicons-admin-site-alt3"></i>
                            <?php esc_html_e('Visit Plugin Website', 'cacher'); ?>
                        </a>
                    </div>
                    <div class="cacher-author-email">
                        <a href="mailto:plugins@hostfix.hu" class="button button-secondary">
                            <i class="dashicons dashicons-email"></i>
                            <?php esc_html_e('Contact Developer', 'cacher'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alsó eszköztár -->
        <div class="cacher-bottom-toolbar">
            <div class="cacher-controls">
                <button type="submit" name="cacher_action" value="save_settings" class="button button-primary">
                    <i class="dashicons dashicons-saved"></i>
                    <?php esc_html_e('Save Settings', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('Save all cache settings including driver selections and TTL values.', 'cacher'); ?>">
                    </i>
                </button>

                <button type="submit" name="cacher_action" value="clear_cache" class="button">
                    <i class="dashicons dashicons-trash"></i>
                    <?php esc_html_e('Clear Cache', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('Immediately clear all cache content from all drivers.', 'cacher'); ?>">
                    </i>
                </button>

                <button type="submit" name="cacher_action" value="preload_cache" class="button">
                    <i class="dashicons dashicons-update"></i>
                    <?php esc_html_e('Cache Preload', 'cacher'); ?>
                    <i class="dashicons dashicons-editor-help tooltip-trigger" 
                       data-tooltip="<?php esc_attr_e('Pre-generate cache for most visited pages for faster loading.', 'cacher'); ?>">
                    </i>
                </button>
            </div>
        </div>
    </div>
</div>
