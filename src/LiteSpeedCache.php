<?php

namespace JazzMan\LsCache;

use JazzMan\CookieSetter\CookieSetter;
use WP_HTTP_Response;

/**
 * Class LiteSpeed.
 */
class LiteSpeedCache
{
    public const X_VARY_COOKIE = '_lscache_vary';
    public const X_CACHE_CONTROL = 'X-LiteSpeed-Cache-Control';
    public const X_VARY_HEADER = 'X-LiteSpeed-Vary';
    public const X_TAGS_HEADER = 'X-LiteSpeed-Tag';
    public const X_PURGE_HEADER = 'X-LiteSpeed-Purge';
    public const CACHE_CONTROL = 'Cache-Control';
    public const NO_CACHE_PARAMS = 'no-cache,must-revalidate,max-age=0';
    public const PUBLIC_LIVE_TIME = MONTH_IN_SECONDS;
    public const PRIVATE_LIVE_TIME = 0;
    public const ENV_REST_API = 'is_rest_api';
    public const ENV_QUERY_STRING = 'is_query_string';
    public const ENV_ADMIN = 'is_admin';
    public const ENV_EDITABLE_REQUEST = 'is_editable_request';
    public const ENV_READABLE_REQUEST = 'is_readable_request';
    public const ENV_WEBP = 'is_webp';
    public const ENV_AJAX = 'is_ajax';
    public const ENV_PUBLIC_CACHE = 'is_public_cache';
    public const ENV_NO_CACHE_PAGES = 'is_no_cache_pages';

    private static string $flushStringRegex = '/(?<prefix>post|term|archive|all|private)(?<separator>-)?(?<value>\d+|[a-z\-\_]+)?$/';

    public function __construct()
    {
        add_filter('wp_headers', [$this, 'wpHeaders']);
        add_filter('nocache_headers', [$this, 'nocacheHeaders']);
        add_action('template_redirect', [$this, 'sendLsCacheHeaders'], 0);
        add_action('wp_login', [$this, 'setCookie']);
        add_action('wp_logout', [$this, 'setCookie']);
        add_action('parse_request', [$this, 'lsCacheFlush']);

        add_action('saved_term', [__CLASS__, 'lsCacheFlushTerm']);
        add_action('deleted_term_relationships', [__CLASS__, 'lsCacheFlushPost']);

        add_action('set_object_terms', [__CLASS__, 'lsCacheFlushPost']);
        add_action('save_post', [__CLASS__, 'lsCacheFlushPost']);
        add_action('delete_post', [__CLASS__, 'lsCacheFlushPost']);
        add_filter('rest_post_dispatch', [$this, 'sendNocacheHeadersOnRestApi']);
    }

    public static function lsCacheFlushTerm(int $termId): void
    {
        self::flushRequest("term-{$termId}");
    }

    public static function flushRequest(string $flush = 'all', bool $blocking = false): void
    {
        $_lscache = true;

        $url = add_query_arg(
            compact('_lscache', 'flush'),
            home_url()
        );

        wp_remote_get($url, compact('blocking'));
    }

    public static function lsCacheFlushPost(int $postId): void
    {
        self::flushRequest("post-{$postId}");
    }

    /**
     * @example:
     * ENV variable: "has_cart"
     * Value: "woocommerce_cart_hash":
     * @code :
     * $vary_cookie["has_cart"] = "woocommerce_cart_hash";
     *
     * @return array<string, string>
     */
    public static function getVaryCookies(): array
    {
        $varyCookies = [
            'is_user_cookie' => USER_COOKIE,
            'is_pass_cookie' => PASS_COOKIE,
            'is_auth_cookie' => AUTH_COOKIE,
            'is_secure_auth_cookie' => SECURE_AUTH_COOKIE,
            'is_logged_in_cookie' => LOGGED_IN_COOKIE,
            'is_recovery_mode_cookie' => RECOVERY_MODE_COOKIE,
            'is_wp_postpass_cookie' => 'wp-postpass_'.COOKIEHASH,
        ];

        return (array) apply_filters('ls_cache_vary_cookie', $varyCookies);
    }

    /**
     * @example:
     * ENV variable: "is_cart_page"
     * Value: "https://site.com/cart/":
     * @code :
     * $pages["is_cart_page"] = "https://site.com/cart/";
     *
     * @return array<string, string>
     */

    public static function getNoCachePages(): array
    {
        return (array) apply_filters('ls_no_cache_pages', []);
    }

    public function sendNocacheHeadersOnRestApi(WP_HTTP_Response $result): WP_HTTP_Response
    {
        $nocache_headers = wp_get_nocache_headers();

        foreach ($nocache_headers as $header => $value) {
            $result->header($header, $value);
        }

        return $result;
    }

    public function lsCacheFlush(\WP $wp): void
    {
        $data = app_get_request_data();

        $isFlush = $data->getBoolean('_lscache');
        $flushString = $data->get('flush', false);
        if ($isFlush && $flushString) {
            preg_match(self::$flushStringRegex, $flushString, $result);

            if ( ! headers_sent() && ( ! empty($result) && ! empty($result['prefix']))) {
                if ('private' === $result['prefix']) {
                    self::purgePrivateCache();
                } else {
                    $tag = (array) $result['prefix'];

                    if ( ! empty($result['separator'])) {
                        $tag[] = $result['separator'];
                    }

                    if ( ! empty($result['value'])) {
                        $tag[] = $result['value'];
                    }

                    self::purgeTags(implode('', $tag));
                }
            }
        }
    }

    /**
     * @param array|string $tags
     */
    private static function purgeTags($tags)
    {
        $tags_list = [];

        foreach ((array) $tags as $tag) {
            $tags_list[] = sprintf('tag=%s', (string) $tag);
        }

        header_remove(self::X_CACHE_CONTROL);
        header_remove(self::X_VARY_HEADER);
        header_remove(self::X_TAGS_HEADER);

        header(
            sprintf(
                '%s: %s',
                self::X_PURGE_HEADER,
                implode(', ', $tags_list)
            )
        );

        self::purgePrivateCache();
    }

    private static function purgePrivateCache()
    {
        header(sprintf('%s: private, *', self::X_PURGE_HEADER));
    }

    public function setCookie()
    {
        $cookie = 'wp_login' === current_action() ? md5(self::getVaryValue()) : null;

        CookieSetter::setcookie(
            self::X_VARY_COOKIE,
            $cookie
//            [
//                'samesite' => 'Strict',
//            ]
        );

//        self::flushRequest("private");

//        self::purgePrivateCache();
    }

    public static function getVaryValue(): ?string
    {
        static $varyValue;

        if (null === $varyValue) {
            $host = \parse_url(home_url(), PHP_URL_HOST);

            $varyValue = \str_replace(['.', ':'], '_', $host);
        }

        return apply_filters('ls_cache_vary_value', $varyValue);
    }

    public function sendLsCacheHeaders()
    {
        $sendNocache =
            ! self::isLsCacheEnable()
            || ( ! app_is_rest() && self::isQueryString());

        $sendNocache = (bool) apply_filters('ls_cache_send_nocache', $sendNocache);

        if ($sendNocache) {
            nocache_headers();
        } else {
            $object = get_queried_object();

            $cache_tag = [
                'all',
            ];

            if ($object instanceof \WP_Post) {
                $cache_tag[] = 'post';
                $cache_tag[] = sprintf('post-%s', $object->post_type);
                $cache_tag[] = sprintf('post-%d', $object->ID);
            } elseif ($object instanceof \WP_Term) {
                $cache_tag[] = 'term';
                $cache_tag[] = sprintf('term-%s', $object->taxonomy);
                $cache_tag[] = sprintf('term-%d', $object->term_id);
            } elseif ($object instanceof \WP_Post_Type) {
                $cache_tag[] = 'archive';
                $cache_tag[] = sprintf('archive-%s', $object->name);
            }

            self::setTagsHeader($cache_tag);
        }
    }

    public static function isLsCacheEnable(): bool
    {
        return (bool) apply_filters('ls_cache_enable', true);
    }

    private static function isQueryString(): bool
    {
        return (bool) filter_input(INPUT_SERVER, 'QUERY_STRING');
    }

    /**
     * @param string[] $tags
     */
    private static function setTagsHeader(array $tags)
    {
        header(
            sprintf(
                '%s: %s',
                self::X_TAGS_HEADER,
                implode(', ', $tags)
            )
        );
    }

    public function nocacheHeaders(array $headers): array
    {
        $headers[self::X_CACHE_CONTROL] = self::NO_CACHE_PARAMS;
        $headers[self::CACHE_CONTROL] = self::NO_CACHE_PARAMS;

        return $headers;
    }

    /**
     * @param string[] $headers
     *
     * @return string[]
     */
    public function wpHeaders(array $headers): array
    {
        $varyData = [
            sprintf('value=%s', self::getVaryValue()),
        ];

        $varyCookie = self::getVaryCookies();

        if ( ! empty($varyCookie)) {
            foreach ($varyCookie as $env => $cookie) {
                $varyData[] = sprintf('cookie=%s', $cookie);
            }
        }

        $headers[self::X_VARY_HEADER] = implode(',', $varyData);

        if (filter_input(INPUT_SERVER,self::ENV_PUBLIC_CACHE,FILTER_VALIDATE_BOOLEAN)) {
            $cache_control = sprintf('max-age=%d,public', self::PUBLIC_LIVE_TIME);
        }else{
            $cache_control = self::NO_CACHE_PARAMS;
        }

        $headers[self::X_CACHE_CONTROL] = $cache_control;
        $headers[self::CACHE_CONTROL] = $cache_control;

        return $headers;
    }
}
