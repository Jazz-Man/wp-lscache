<?php

/*
Plugin Name: WP LiteSpeed Advanced Cache Drop-In
Plugin URI: https://github.com/Jazz-Man/wp-lscache
Description: WP LiteSpeed Cache
Version: v1.0.0
Author: Vasyl Sokolyk
*/

use JazzMan\LsCache\LiteSpeedCache;

function wp_cache_postload()
{
    new LiteSpeedCache();
}
