<?php
// KutBlog - Backwards-compatibility bridge for Newsletter plugin
if (!defined('ROOT_PATH')) {
    require_once dirname(__DIR__) . '/core/config.php';
}

$pluginFile = dirname(__DIR__) . '/plugins/newsletter/newsletter.php';
if (file_exists($pluginFile)) {
    require_once $pluginFile;
}
