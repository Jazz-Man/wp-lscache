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
    /**
     * @var string[]
     */
    private array $loggedInCookie;

    private Block $block;

    private WhiteLine $whiteLine;

    public function load()
    {
        $this->loggedInCookie = [
            LOGGED_IN_COOKIE,
            SECURE_AUTH_COOKIE,
            'wp-postpass_'.COOKIEHASH,
        ];

        $this->whiteLine = new WhiteLine();

        add_action('generate_rewrite_rules', [$this, 'generateHtaccess']);
    }

    public function generateHtaccess()
    {
        if (LiteSpeedCache::isLsCacheEnable()) {
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
            $this->block->addChild($this->whiteLine);
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

    private function getPrivatCacheControl(?string $envName = null): string
    {
        return sprintf(
            '[E="cache-control:max-age=%d,private"%s]',
            LiteSpeedCache::PRIVATE_LIVE_TIME,
            $envName ? sprintf(',E="%s:true"', $envName) : ''
        );
    }

    private function getPublicCacheControl(?string $envName = null): string
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

        $this->addComment('Start check logged in cookie');

        foreach ($this->loggedInCookie as $cookie) {
            $this->rewriteCond(sprintf('%%{HTTP_COOKIE} (^|\s+)%s=(.*?)(;|$) [nc]', $cookie));

            $this->rewriteRule(
                [
                    '.* -',
                    sprintf('[E="cache-vary:%%{ENV:LSCACHE_VARY_COOKIE},%s",E="is_logged_in:true"]', $cookie),
                ]
            );
        }
        $this->addComment('End check logged in cookie', false);

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

        $this->addComment('Start check AJAX Request');
        $this->rewriteCond(['%{REQUEST_URI}', '^(.*?)\/wp-admin\/admin-ajax\.php(.*?)$', '[nc,or]']);
        $this->rewriteCond(['%{HTTP:X-Requested-With}', 'XMLHttpRequest', '[nc]']);
        $this->rewriteRule(['.* -', $this->getNoCacheControl('is_ajax')]);

        $this->addComment('End check AJAX Request');

        $this->addComment('Start REST API Request');
        $this->rewriteCond(['%{REQUEST_URI}', '^\/wp-json\/(.*?)$', '[nc]']);
        $this->rewriteRule(['.* -', $this->getPrivatCacheControl('is_rest_api')]);
        $this->addComment('End REST API Request');

        $this->addComment('Start QUERY_STRING check');
        $this->rewriteCond(['%{ENV:is_rest_api}', '!true']);
        $this->rewriteCond(['%{QUERY_STRING}', '^(.+)$', '[nc]']);
        $this->rewriteRule(['.* -', $this->getPrivatCacheControl('is_query_string')]);
        $this->addComment('End QUERY_STRING check');
        $this->addComment('Start check wp-admin area');
        $this->rewriteCond(['%{ENV:is_ajax}', '!true']);
        $this->rewriteCond(['%{REQUEST_URI}', '^.*?\/wp-admin\/[a-z\-]+\.php(.*?)$', '[nc]']);
        $this->rewriteRule(['.* -', $this->getNoCacheControl('is_admin')]);
        $this->addComment('End check wp-admin area');

        $this->addComment('Start check request method type');
        $this->rewriteCond(['%{REQUEST_METHOD}', '^HEAD|GET$']);
        $this->rewriteRule(['.* -', '[E="is_readable_request:true"]']);
        $this->rewriteCond(['%{REQUEST_METHOD}', '^POST|PUT|PATCH$']);
        $this->rewriteRule(['.* -', $this->getNoCacheControl('is_editable_request')]);
        $this->addComment('End check request method type');

        $this->addComment('Start check WebP support');
        $this->rewriteCond(['%{HTTP_USER_AGENT}', 'Chrome', '[or]']);
        $this->rewriteCond(['%{HTTP_ACCEPT}', 'image/webp']);
        $this->rewriteRule(['.* -', '[E="is_webp:true"]']);
        $this->rewriteCond(['%{HTTP_USER_AGENT}', '.*Version/(\d{2}).*Safari']);
        $this->rewriteRule(['.* -', '[E="is_webp:true"]', '"expr=%1 >= 13"']);
        $this->rewriteCond(['%{HTTP_USER_AGENT}', 'Firefox/(\d+)']);
        $this->rewriteRule(['.* -', '[E="is_webp:true"]', '"expr=%1 >= 65"']);
        $this->addComment('End check WebP support');

        $this->addComment('Enable public cache');
        $this->rewriteCond(['%{ENV:is_readable_request}', 'true']);
        $this->rewriteCond(['%{ENV:is_ajax}', '!true']);
        $this->rewriteRule(['.* -', $this->getPublicCacheControl()]);

        $this->addComment('Start Drop QUERY_STRING');

        foreach (['fbclid', 'gclid', 'utm*', '_ga'] as $queryString) {
            $this->addDirective('CacheKeyModify', sprintf('-qs:%s', $queryString));
        }
        $this->addComment('End Drop QUERY_STRING');

        return (string) $this->block;
    }
}
