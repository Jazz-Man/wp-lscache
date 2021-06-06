<?php

namespace JazzMan\LsCache;

use JazzMan\CookieSetter\CookieSetter;

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
    public const PRIVATE_LIVE_TIME = MINUTE_IN_SECONDS;
    private static $flush_string_regex = '/(?<prefix>post|term|archive|all)(?<separator>-)?(?<value>\d+|[a-z\-\_]+)?$/';

    public function __construct()
    {
        add_filter('wp_headers', [$this, 'wp_headers']);
        add_filter('nocache_headers', [$this, 'nocache_headers']);
        add_action('template_redirect', [$this, 'send_ls_cache_headers'], 0);
        add_action('wp_login', [$this, 'set_cookie']);
        add_action('wp_logout', [$this, 'set_cookie']);
        add_action('parse_request', [$this, 'lscache_flush']);

        add_action('saved_term', [__CLASS__, 'lscache_flush_term']);
        add_action('deleted_term_relationships', [__CLASS__, 'lscache_flush_post']);

        add_action('set_object_terms', [__CLASS__, 'lscache_flush_post']);
        add_action('save_post', [__CLASS__, 'lscache_flush_post']);
        add_action('delete_post', [__CLASS__, 'lscache_flush_post']);
        add_filter('rest_post_dispatch', [$this, 'send_nocache_headers_on_resr_api']);
    }

    public static function lscache_flush_term(int $term_id): void
    {
        self::flush_request("term-{$term_id}");
    }

    private static function flush_request(string $flush_string): void
    {
        $_lscache = true;

        $url = add_query_arg(
            compact('_lscache', 'flush_string'),
            home_url()
        );

        wp_remote_get(
            $url,
            [
                'blocking' => false,
            ]
        );
    }

    public static function lscache_flush_post(int $post_id): void
    {
        self::flush_request("post-{$post_id}");
    }

    public function send_nocache_headers_on_resr_api(\WP_HTTP_Response $result): \WP_HTTP_Response
    {
        $nocache_headers = wp_get_nocache_headers();

        foreach ($nocache_headers as $header => $value) {
            $result->header($header, $value);
        }

        return $result;
    }

    public function lscache_flush(\WP $wp)
    {
        $data = app_get_request_data();

        $is_flush = $data->getBoolean('_lscache');
        $flush_string = $data->get('flush_string', false);
        if ($is_flush && $flush_string) {
            preg_match(self::$flush_string_regex, $flush_string, $result);

            if ( ! headers_sent() && ( ! empty($result) && ! empty($result['prefix']))) {
                if ('all' === $result['prefix']) {
                    self::purge_all();
                } else {
                    $tag = (array) $result['prefix'];

                    if ($result['separator']) {
                        $tag[] = $result['separator'];
                    }

                    if ($result['value']) {
                        $tag[] = $result['value'];
                    }

                    self::purge_tags(implode('', $tag));
                }
            }
        }
    }

    public function set_cookie()
    {
        $cookie = 'wp_login' === current_action() ? md5(self::get_vary_value()) : null;

        CookieSetter::setcookie(
            self::X_VARY_COOKIE,
            $cookie,
            [
                'samesite' => 'Strict',
            ]
        );
    }

    public static function get_vary_value(): ?string
    {
        static $vary_value;

        if (null === $vary_value) {
            $host = \parse_url(home_url(), PHP_URL_HOST);

            $vary_value = \str_replace(['.', ':'], '_', $host);
        }

        return apply_filters('ls_cache_vary_value', $vary_value);
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
    public static function get_vary_cookie(): array
    {
        return (array) apply_filters('ls_cache_vary_cookie', []);
    }

    public static function is_ls_cache_enable(): bool
    {
        return (bool) apply_filters('ls_cache_enable', true);
    }

    public function send_ls_cache_headers()
    {
        $send_nocache =
            ! self::is_ls_cache_enable()
            || ( ! app_is_rest() && ! empty(filter_input(INPUT_SERVER, 'QUERY_STRING')));

        $send_nocache = (bool) apply_filters('ls_cache_send_nocache', $send_nocache);

        if ($send_nocache) {
            nocache_headers();
        } else {
            $object = get_queried_object();

            $cache_tag = false;

            if ($object instanceof \WP_Post) {
                $cache_tag = [
                    'post',
                    sprintf('post-%s', $object->post_type),
                    sprintf('post-%d', $object->ID),
                ];
            } elseif ($object instanceof \WP_Term) {
                $cache_tag = [
                    'term',
                    sprintf('term-%s', $object->taxonomy),
                    sprintf('term-%d', $object->term_id),
                ];
            } elseif ($object instanceof \WP_Post_Type) {
                $cache_tag = [
                    'archive',
                    sprintf('archive-%s', $object->name),
                ];
            }

            if ($cache_tag) {
                self::set_tags_header($cache_tag);
            }
        }
    }

    public function nocache_headers(array $headers): array
    {
        if (self::is_ls_cache_enable()) {
            $value = wp_doing_ajax() ? self::NO_CACHE_PARAMS : sprintf(
                'private,max-age=%d',
                self::PRIVATE_LIVE_TIME
            );
        } else {
            $value = self::NO_CACHE_PARAMS;
        }

        $headers[self::X_CACHE_CONTROL] = $value;
        $headers[self::CACHE_CONTROL] = $value;

        return $headers;
    }

    /**
     * @param string[] $headers
     *
     * @return string[]
     */
    public function wp_headers(array $headers)
    {
        $wp_last_modified = mysql2date('D, d M Y H:i:s', get_lastpostmodified('GMT'), false);

        if ( ! $wp_last_modified) {
            $wp_last_modified = \gmdate('D, d M Y H:i:s');
        }

        $wp_last_modified .= ' GMT';

        $wp_etag = '"'.\md5($wp_last_modified).'"';

        $headers['Last-Modified'] = $wp_last_modified;
        $headers['ETag'] = $wp_etag;

        $vary_data = [
            sprintf('value=%s', self::get_vary_value()),
        ];

        $vary_cookie = (array) apply_filters('ls_cache_vary_cookie', []);

        if ( ! empty($vary_cookie)) {
            foreach ($vary_cookie as $env => $cookie) {
                $vary_data[] = sprintf('cookie=%s', $cookie);
            }
        }

        $headers[self::X_VARY_HEADER] = implode(',', $vary_data);

        if ( ! is_user_logged_in()) {
            $headers[self::CACHE_CONTROL] = sprintf(
                'max-age=%d,public',
                self::PUBLIC_LIVE_TIME
            );
        }

        $http_if_none_match = filter_input(INPUT_SERVER, 'HTTP_IF_NONE_MATCH');
        $http_if_modified_since = filter_input(INPUT_SERVER, 'HTTP_IF_MODIFIED_SINCE');
        // Support for conditional GET.
        if (isset($http_if_none_match)) {
            $client_etag = wp_unslash($http_if_none_match);
        } else {
            $client_etag = false;
        }

        $client_last_modified = $http_if_modified_since ? app_trim_string($http_if_modified_since) : '';
        // If string is empty, return 0. If not, attempt to parse into a timestamp.
        $client_modified_timestamp = $client_last_modified ? \strtotime($client_last_modified) : 0;

        // Make a timestamp for our most recent modification..
        $wp_modified_timestamp = \strtotime($wp_last_modified);

        if (($client_last_modified && $client_etag) ?
            (($client_modified_timestamp >= $wp_modified_timestamp) && ($client_etag === $wp_etag)) :
            (($client_modified_timestamp >= $wp_modified_timestamp) || ($client_etag === $wp_etag))) {
            $code = 304;

            \header(
                sprintf(
                    '%s %d %s',
                    wp_get_server_protocol(),
                    $code,
                    get_status_header_desc($code)
                ),
                true,
                $code
            );

            exit;
        }

        return $headers;
    }

    /**
     * @param string[] $tags
     */
    private static function set_tags_header(array $tags)
    {
        header(
            sprintf(
                '%s: %s',
                self::X_TAGS_HEADER,
                implode(', ', $tags)
            )
        );
    }

    private static function purge_all()
    {
        self::clear_caching_headers();
        header(sprintf('%s: *', self::X_PURGE_HEADER));
    }

    /**
     * @param array|string $tags
     */
    private static function purge_tags($tags)
    {
        self::clear_caching_headers();

        $tags_list = [];

        foreach ((array) $tags as $tag) {
            $tags_list[] = sprintf('tag=%s', (string) $tag);
        }

        header(
            sprintf(
                '%s: %s',
                self::X_PURGE_HEADER,
                implode(', ', $tags_list)
            )
        );
    }

    private static function clear_caching_headers(): void
    {
        header_remove(self::X_CACHE_CONTROL);
        header_remove(self::X_VARY_HEADER);
        header_remove(self::X_TAGS_HEADER);
    }
}
