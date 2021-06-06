<?php

namespace JazzMan\LsCache;

/**
 * Class LsCacheFatalErrorHandler.
 */
class LiteSpeedFatalErrorHandler extends \WP_Fatal_Error_Handler
{
    public function __construct()
    {
        add_filter('nocache_headers', [$this, 'nocache_headers']);
    }

    public function handle()
    {
        if (function_exists('nocache_headers')) {
            self::send_nocache_headers();
        }

        parent::handle();
    }

    private static function send_nocache_headers()
    {
        $error_types = [
            E_ERROR,
            E_WARNING,
            E_PARSE,
            E_NOTICE,
            E_CORE_ERROR,
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
            E_USER_WARNING,
            E_USER_NOTICE,
            E_STRICT,
            E_DEPRECATED,
            E_USER_DEPRECATED,
        ];

        $error = error_get_last();

        if (null !== $error && isset($error['type']) && in_array($error['type'], $error_types, true)) {
            nocache_headers();
        }
    }

    /**
     * @return array
     */
    public function nocache_headers(array $headers)
    {
        $headers[LiteSpeedCache::X_CACHE_CONTROL] = LiteSpeedCache::NO_CACHE_PARAMS;
        $headers[LiteSpeedCache::CACHE_CONTROL] = LiteSpeedCache::NO_CACHE_PARAMS;

        return $headers;
    }
}
