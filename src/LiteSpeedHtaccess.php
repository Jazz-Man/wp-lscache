<?php

namespace JazzMan\LsCache;

use JazzMan\AutoloadInterface\AutoloadInterface;
use Tivie\HtaccessParser\Token\Block;
use Tivie\HtaccessParser\Token\Comment;
use Tivie\HtaccessParser\Token\Directive;
use Tivie\HtaccessParser\Token\WhiteLine;

/**
 * Class LsHtaccess.
 */
class LiteSpeedHtaccess implements AutoloadInterface
{
    private Block $block;

    public function load()
    {
        add_filter('mod_rewrite_rules', [$this, 'generateHtaccess']);
    }

    /**
     * @param mixed $rules
     *
     * @return mixed
     */
    public function generateHtaccess($rules)
    {
        if (LiteSpeedCache::isLsCacheEnable() && function_exists('insert_with_markers')) {
            try {
                $root_dir = app_locate_root_dir();

                $htaccess = "{$root_dir}/.htaccess";

                $content = implode(
                    "\n",
                    [
                        $this->getHtaccess(),
                    ]
                );

                insert_with_markers($htaccess, 'LiteSpeed', $content);
            } catch (\Exception $e) {
                app_error_log($e, self::class);
            }
        }

        return $rules;
    }

    public static function removeHtaccessRules()
    {
        if (LiteSpeedCache::isLsCacheEnable()) {
            try {
                $root_dir = app_locate_root_dir();

                $htaccess = "{$root_dir}/.htaccess";

                insert_with_markers($htaccess, 'LiteSpeed', '#disables');
            } catch (\Exception $e) {
                app_error_log($e, self::class);
            }
        }
    }

    /**
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private function addComment(string $text, bool $whiteLine = true)
    {
        if ($whiteLine) {
            $this->block->addChild(new WhiteLine());
        }
        $this->block->addChild(self::getComment($text));
    }

    /**
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private static function getComment(string $text): Comment
    {
        return (new Comment())->setText($text);
    }

    /**
     * @param array|string $arguments
     *
     * @throws \Tivie\HtaccessParser\Exception\DomainException
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private function addDirective(string $name, $arguments)
    {
        $this->block->addChild(new Directive($name, (array) $arguments));
    }

    /**
     * @throws \Tivie\HtaccessParser\Exception\DomainException
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private function rewriteRule(array $arguments = [])
    {
        $this->addDirective('RewriteRule', $arguments);
    }

    /**
     * @param array|string $arguments
     *
     * @throws \Tivie\HtaccessParser\Exception\DomainException
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private function rewriteCond($arguments = null)
    {
        $this->addDirective('RewriteCond', (array) $arguments);
    }

    private function getNoCacheControl(?string $envName = null): string
    {
        return sprintf(
            '[E="cache-control:%s"%s]',
            LiteSpeedCache::NO_CACHE_PARAMS,
            $envName ? sprintf(',E="%s:true"', $envName) : ''
        );
    }

    private static function getPublicCacheControl(?string $envName = null): string
    {
        return sprintf(
            '[E="cache-control:max-age=%d,public"%s]',
            LiteSpeedCache::PUBLIC_LIVE_TIME,
            $envName ? sprintf(',E="%s:true"', $envName) : ''
        );
    }

    /**
     * @throws \Tivie\HtaccessParser\Exception\DomainException
     * @throws \Tivie\HtaccessParser\Exception\InvalidArgumentException
     */
    private function getHtaccess(): string
    {
        $this->block = new Block('IfModule', 'LiteSpeed');

        $this->addComment('Enable RewriteEngine and CacheLookup');
        $this->addDirective('RewriteEngine', 'on');
        $this->addDirective('CacheLookup', 'on');

        $this->addComment('Enable auto flush private cache by POST request');
        $this->rewriteRule(['.* -', '[E="cache-control:autoflush"]']);

        $this->addComment('Enable Vary Cookies');
        $this->rewriteRule(['.? -', sprintf('[E="cache-vary:%s"]', LiteSpeedCache::X_VARY_COOKIE)]);

        $this->addComment('Enable Vary Value');
        $this->rewriteRule(['.* -', sprintf('[E="cache-control:vary=%s"]', LiteSpeedCache::getVaryValue())]);

        $varyCookies = LiteSpeedCache::getVaryCookies();

        if ( ! empty($varyCookies)) {
            $this->addComment('Start check vary cookie');

            foreach ($varyCookies as $envName => $cookie) {
                $this->rewriteCond(['%{HTTP_COOKIE}', sprintf('(^|\s+)%s=(.*?)(;|$)', $cookie), '[nc]']);
                $this->rewriteRule(
                    [
                        '.* -',
                        sprintf('[E="cache-vary:%%{ENV:LSCACHE_VARY_COOKIE},%s",E="%s:true"]', $cookie, $envName),
                    ]
                );
            }
            $this->addComment('End check vary cookie', false);
        }

        $getNoCachePages = LiteSpeedCache::getNoCachePages();

        if (!empty($getNoCachePages)){

            $this->addComment('Start No Cache Pages');

            foreach ($getNoCachePages as $pageEnvName => $pageUrl){
                $url = wp_make_link_relative($pageUrl);
                $url = ltrim($url,'/');

                $this->rewriteCond([
                    '%{REQUEST_URI}',
                    sprintf(
                        '^\/%s([^/]*)$',
                        untrailingslashit($url)),
                    '[nc]'
                ]);

                $env = sprintf(
                    '[E="cache-control:%s",E="%s:true",E="%s:true"]',
                    LiteSpeedCache::NO_CACHE_PARAMS,
                    $pageEnvName,
                    LiteSpeedCache::ENV_NO_CACHE_PAGES
                );

                $this->rewriteRule(['.* -', $env]);
            }

            $this->addComment('End No Cache Pages', false);

        }

        $this->addComment('Start check AJAX Request');
        $this->rewriteCond(['%{REQUEST_URI}', '^(.*?)\/wp-admin\/admin-ajax\.php(.*?)$', '[nc,or]']);
        $this->rewriteCond(['%{HTTP:X-Requested-With}', 'XMLHttpRequest', '[nc]']);
        $this->rewriteRule(['.* -', self::getNoCacheControl(LiteSpeedCache::ENV_AJAX)]);

        $this->addComment('End check AJAX Request');

        $this->addComment('Start REST API Request');
        $this->rewriteCond(['%{REQUEST_URI}', '^\/wp-json\/(.*?)$', '[nc]']);
        $this->rewriteRule(['.* -', self::getNoCacheControl(LiteSpeedCache::ENV_REST_API)]);
        $this->addComment('End REST API Request');

        $this->addComment('Start QUERY_STRING check');
        $this->rewriteCond([self::escapeEnv(LiteSpeedCache::ENV_REST_API), '!true']);
        $this->rewriteCond(['%{QUERY_STRING}', '^(.+)$', '[nc]']);
        $this->rewriteRule(['.* -', self::getNoCacheControl(LiteSpeedCache::ENV_QUERY_STRING)]);
        $this->addComment('End QUERY_STRING check');

        $this->addComment('Start check wp-admin area');
        $this->rewriteCond([self::escapeEnv(LiteSpeedCache::ENV_AJAX), '!true']);
        $this->rewriteCond(['%{REQUEST_URI}', '^.*?\/wp-admin\/[a-z\-]+\.php(.*?)$', '[nc]']);
        $this->rewriteRule(['.* -', self::getNoCacheControl(LiteSpeedCache::ENV_ADMIN)]);
        $this->addComment('End check wp-admin area');

        $this->addComment('Start check request method type');
        $this->rewriteCond(['%{REQUEST_METHOD}', '^HEAD|GET$']);
        $this->rewriteRule(['.* -', sprintf('[E="%s:true"]', LiteSpeedCache::ENV_READABLE_REQUEST)]);

        $this->rewriteCond(['%{REQUEST_METHOD}', '^POST|PUT|PATCH$']);
        $this->rewriteRule(['.* -', self::getNoCacheControl(LiteSpeedCache::ENV_EDITABLE_REQUEST)]);
        $this->addComment('End check request method type');

        $webp_env = sprintf('[E="%s:true"]', LiteSpeedCache::ENV_WEBP);

        $this->addComment('Start check WebP support');
        $this->rewriteCond(['%{HTTP_USER_AGENT}', 'Chrome', '[or]']);
        $this->rewriteCond(['%{HTTP_ACCEPT}', 'image/webp']);
        $this->rewriteRule(['.* -', $webp_env]);
        $this->rewriteCond(['%{HTTP_USER_AGENT}', '.*Version/(\d{2}).*Safari']);
        $this->rewriteRule(['.* -', $webp_env, '"expr=%1 >= 13"']);
        $this->rewriteCond(['%{HTTP_USER_AGENT}', 'Firefox/(\d+)']);
        $this->rewriteRule(['.* -', $webp_env, '"expr=%1 >= 65"']);
        $this->addComment('End check WebP support');

        $this->addComment('Enable public cache');
        $this->rewriteCond([self::escapeEnv(LiteSpeedCache::ENV_READABLE_REQUEST), 'true']);
        $this->rewriteCond([self::escapeEnv(LiteSpeedCache::ENV_AJAX), '!true']);
        $this->rewriteCond([self::escapeEnv(LiteSpeedCache::ENV_NO_CACHE_PAGES), '!true']);
        $this->rewriteRule(['.* -', self::getPublicCacheControl(LiteSpeedCache::ENV_PUBLIC_CACHE)]);

        $this->addComment('Start Drop QUERY_STRING');

        foreach (['fbclid', 'gclid', 'utm*', '_ga'] as $queryString) {
            $this->addDirective('CacheKeyModify', sprintf('-qs:%s', $queryString));
        }
        $this->addComment('End Drop QUERY_STRING');

        return (string) $this->block;
    }

    private static function escapeEnv(string $env): string
    {
        return sprintf('%%{ENV:%s}', $env);
    }
}
