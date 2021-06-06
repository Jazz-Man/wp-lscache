<?php

/*
Plugin Name: WP LiteSpeed Cache
Description: WP LiteSpeed Cache
Version: v1.0.0
Plugin URI: https://github.com/Jazz-Man/wp-lscache
Author: Vasyl Sokolyk
Text Domain: wp-lscache
Domain Path: /languages
*/

use JazzMan\LsCache\LiteSpeedCache;
use JazzMan\LsCache\LiteSpeedHtaccess;

/**
 * Class WPLiteSpeedCache.
 */
class WPLiteSpeedCache
{
    /**
     * @var string
     */
    private $page;

    /**
     * @var string
     */
    private $pageSlug = 'wp-lscache';

    /**
     * @var string
     */
    private $baseOptionsPage;

    /**
     * @var string[]
     */
    private $actions;

    /**
     * @var string
     */
    private $capability;
    /**
     * @var string
     */
    private $rootDir;
    /**
     * @var string
     */
    private $cacheDropinFile;

    /**
     * @var string
     */
    private $wpCacheDropinFile;
    /**
     * @var string
     */
    private $errorHandlerFile;
    /**
     * @var string
     */
    private $wpErrorHandlerFile;

    public function __construct()
    {
        $this->rootDir = plugin_dir_path(__FILE__);

        register_activation_hook(__FILE__, [$this, 'flushCache']);
        register_deactivation_hook(__FILE__, [$this, 'onDeactivation']);

        app_autoload_classes([
            LiteSpeedHtaccess::class,
        ]);

        $isMultisite = is_multisite();

        $this->actions = ['enable-cache', 'disable-cache', 'flush-cache', 'update-dropin'];

        $this->errorHandlerFile = "{$this->rootDir}include/fatal-error-handler.php";
        $this->wpErrorHandlerFile = WP_CONTENT_DIR.'/fatal-error-handler.php';

        $this->cacheDropinFile = "{$this->rootDir}include/advanced-cache.php";
        $this->wpCacheDropinFile = WP_CONTENT_DIR.'/advanced-cache.php';

        $this->capability = $isMultisite ? 'manage_network_options' : 'manage_options';

        $adminMenu = $isMultisite ? 'network_admin_menu' : 'admin_menu';

        $screen = "settings_page_{$this->pageSlug}";

        $this->baseOptionsPage = $isMultisite ? 'settings.php' : 'options-general.php';

        $this->page = "{$this->baseOptionsPage}?page={$this->pageSlug}";

        add_action($adminMenu, [$this, 'addAdminMenuPage']);

        add_action('plugins_loaded', [$this, 'loadPluginTextdomain']);

        add_action("load-{$screen}", [$this, 'doAdminActions']);
        add_action("load-{$screen}", [$this, 'addAdminPageNotices']);

        add_action('admin_notices', [$this, 'showAdminNotices']);

        $filter = sprintf('%splugin_action_links_%s', $isMultisite ? 'network_admin_' : '', plugin_basename(__FILE__));
        add_filter($filter, [$this, 'addPluginActionsLinks']);
    }

    public function addAdminMenuPage()
    {
        // add sub-page to "Settings"
        add_submenu_page(
            $this->baseOptionsPage,
            __('WP LiteSpeed Cache', 'wp-lscache'),
            __('WP LiteSpeed Cache', 'wp-lscache'),
            $this->capability,
            $this->pageSlug,
            [$this, 'showAdminPage']
        );
    }

    public function showAdminPage(): void
    {
        $request = $this->verifyNonceFromRequest();

        // request filesystem credentials?
        if ( ! empty($request)) {
            $url = $this->getNonceUrl($request['action']);
            if (false === $this->initFilesystem($url)) {
                return; // request filesystem credentials
            }
        }

        printf(
            '<div class="wrap"><h1>%s</h1></div>',
            __('WP LiteSpeed Cache', 'wp-object-cache'),
        );
    }

    public function loadPluginTextdomain()
    {
        load_plugin_textdomain('wp-lscache', false, dirname(plugin_basename(__FILE__)).'/languages/');
    }

    public function showAdminNotices(): void
    {
        // only show admin notices to users with the right capability
        if ( ! current_user_can($this->capability)) {
            return;
        }

        $advancedCache = $this->validateDropin();

        if (is_wp_error($advancedCache)) {
            $this->printNotice($advancedCache->get_error_message());
        }
    }

    private function isCacheDropinExists(): bool
    {
        return is_readable($this->wpCacheDropinFile);
    }

    private function isErrorHandlerExists(): bool
    {
        return is_readable($this->wpErrorHandlerFile);
    }

    private function getNonceUrl(string $action): string
    {
        return wp_nonce_url(
            network_admin_url(add_query_arg('action', $action, $this->page)),
            $action
        );
    }

    /**
     * @return bool|\WP_Error
     */
    private function validateDropin()
    {
        if ($this->isCacheDropinExists()) {
            $url = $this->getNonceUrl('update-dropin');

            $dropin = get_plugin_data($this->wpCacheDropinFile);
            $plugin = get_plugin_data($this->cacheDropinFile);

            if (version_compare($dropin['Version'], $plugin['Version'], '<')) {
                $message = sprintf(
                    __(
                        '<strong>The "%s" drop-in is outdated.</strong> Please <a href="%s">update it now</a>.',
                        'wp-lscache'
                    ),
                    $plugin['Name'],
                    $url
                );

                return new WP_Error('ls-version', $message);
            }

            return true;
        }
        $enableUrl = $this->getNonceUrl('enable-cache');

        $message = sprintf(
            __(
                    '<strong>WP LiteSpeed Cache is not used.</strong> To use WP LiteSpeed Cache , <a href="%s">please enable it now</a>.',
                    'wp-lscache'
                ),
            $enableUrl
        );

        return new WP_Error('ls-version', $message);
    }

    private function printNotice(string $message): void
    {
        printf('<div class="update-nag notice notice-warning">%s</div>', $message);
    }

    /**
     * @param string[] $actions
     *
     * @return string[]
     */
    public function addPluginActionsLinks(array $actions): array
    {
        $links = [
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(network_admin_url($this->page)),
                esc_attr($this->getLinkLabel('settings'))
            ),
        ];

        if ( ! $this->isCacheDropinExists() || ! $this->isErrorHandlerExists()) {
            $links[] = $this->getLink('enable-cache');
        }

        if ($this->validateDropin()) {
            $links[] = $this->getLink();
            $links[] = $this->getLink('disable-cache');
        }

        return array_merge($links, $actions);
    }

    /**
     * @return string|void
     */
    private function getLinkLabel(string $action = 'enable-cache')
    {
        switch ($action) {
            default:
            case 'enable-cache':
                $label = __('Enable LiteSpeed Cache', 'wp-lscache');

                break;

            case 'disable-cache':
                $label = __('Disable LiteSpeed Cache', 'wp-lscache');

                break;

            case 'flush-cache':
                $label = __('Flush LiteSpeed Cache', 'wp-lscache');

                break;

            case 'settings':
                $label = __('Settings', 'wp-lscache');

                break;
        }

        return $label;
    }

    private function getLink(string $action = 'flush-cache', array $linkAttr = []): string
    {
        return sprintf(
            '<a href="%s" %s>%s</a>',
            $this->getNonceUrl($action),
            app_add_attr_to_el($linkAttr),
            esc_html($this->getLinkLabel($action))
        );
    }

    /**
     * @return array|false
     */
    private function verifyNonceFromRequest()
    {
        $actionsList = $this->actions;

        $request = filter_input_array(
            INPUT_GET,
            [
                'action' => [
                    'filter' => FILTER_CALLBACK,
                    'options' => function ($action) use ($actionsList) {
                        return in_array((string) $action, $actionsList, true) ? $action : false;
                    },
                ],
                '_wpnonce' => [
                    'filter' => FILTER_DEFAULT,
                    'flags' => FILTER_REQUIRE_SCALAR,
                ],
            ]
        );

        return wp_verify_nonce($request['_wpnonce'], $request['action']) ? $request : false;
    }

    private function initFilesystem(string $url, bool $silent = false): bool
    {
        if ($silent) {
            ob_start();
        }

        $credentials = request_filesystem_credentials($url);

        if (false === $credentials) {
            if ($silent) {
                ob_end_clean();
            }

            return false;
        }

        if ( ! WP_Filesystem($credentials)) {
            request_filesystem_credentials($url);

            if ($silent) {
                ob_end_clean();
            }

            return false;
        }

        return true;
    }

    public function doAdminActions()
    {
        // @var \WP_Filesystem_Direct $wp_filesystem

        global $wp_filesystem;

        $request = $this->verifyNonceFromRequest();

        if ( ! empty($request)) {
            $url = $this->getNonceUrl($request['action']);

            if ('flush-cache' === $request['action']) {
                $message = 'cache-flushed';
                LiteSpeedCache::flushRequest('all', true);
            }

            if ($this->initFilesystem($url, true)) {
                switch ($request['action']) {
                    case 'enable-cache':
                        $cacheResult = $wp_filesystem->copy($this->cacheDropinFile, $this->wpCacheDropinFile, true);
                        $errorResult = $wp_filesystem->copy($this->errorHandlerFile, $this->wpErrorHandlerFile, true);

                        $updated = $cacheResult || $errorResult;

                        $message = $updated ? 'cache-enabled' : 'enable-cache-failed';

                        if ($updated) {
                            flush_rewrite_rules();
                            $this->flushCache();
                        }

                        break;

                    case 'disable-cache':
                        $cacheResult = $wp_filesystem->delete($this->wpCacheDropinFile);
                        $errorResult = $wp_filesystem->delete($this->wpErrorHandlerFile);

                        $disabled = $cacheResult || $errorResult;

                        $message = $disabled ? 'cache-disabled' : 'disable-cache-failed';

                        if ($disabled) {
                            LiteSpeedHtaccess::removeHtaccessRules();
                        }

                        break;

                    case 'update-dropin':
                        $cacheResult = $wp_filesystem->copy($this->cacheDropinFile, $this->wpCacheDropinFile, true);
                        $errorResult = $wp_filesystem->copy($this->errorHandlerFile, $this->wpErrorHandlerFile, true);

                        $updated = $cacheResult || $errorResult;

                        $message = $updated ? 'dropin-updated' : 'update-dropin-failed';

                        if ($updated) {
                            $this->flushCache();
                        }

                        break;
                }
            }

            // redirect if status `$message` was set
            if (isset($message)) {
                wp_safe_redirect(network_admin_url(add_query_arg('message', $message, $this->page)));

                exit(0);
            }
        }
    }

    public function addAdminPageNotices()
    {
        $message_code = filter_input(INPUT_GET, 'message');

        $message = false;

        $error = false;

        // show action success/failure messages
        if ( ! empty($message_code)) {
            switch ($message_code) {
                case 'cache-enabled':
                    $message = __('LiteSpeed Cache enabled.', 'wp-lscache');

                    break;

                case 'enable-cache-failed':
                    $error = __('LiteSpeed Cache could not be enabled.', 'wp-lscache');

                    break;

                case 'cache-disabled':
                    $message = __('LiteSpeed Cache disabled.', 'wp-lscache');

                    break;

                case 'disable-cache-failed':
                    $error = __('LiteSpeed Cache could not be disabled.', 'wp-lscache');

                    break;

                case 'cache-flushed':
                    $message = __('LiteSpeed Cache flushed.', 'wp-lscache');

                    break;

                case 'flush-cache-failed':
                    $error = __('LiteSpeed Cache could not be flushed.', 'wp-lscache');

                    break;

                case 'dropin-updated':
                    $message = __('Updated LiteSpeed Cache drop-in.', 'wp-lscache');

                    break;

                case 'update-dropin-failed':
                    $error = __('LiteSpeed Cache drop-in could not be updated.', 'wp-lscache');

                    break;
            }

            add_settings_error('', $this->pageSlug, $message ?? $error, isset($message) ? 'updated' : 'error');
        }
    }

    public function onDeactivation(string $plugin)
    {
        // @var \WP_Filesystem_Direct $wp_filesystem

        global $wp_filesystem;

        if ($plugin === plugin_basename(__FILE__)) {
            if ($this->validateDropin() && $this->initFilesystem('', true)) {
                $this->flushCache();
                $wp_filesystem->delete($this->wpErrorHandlerFile);
                $wp_filesystem->delete($this->wpCacheDropinFile);
            }
        }
    }

    public function flushCache()
    {
        LiteSpeedCache::flushRequest('all', true);
    }
}

new WPLiteSpeedCache();
