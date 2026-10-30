<?php

$root = dirname(__DIR__);
$vendor = getenv('DISCUSSIONBRIDGE_NATIVE_TEST_VENDOR') ?: $root.'/vendor';
if (! is_file($vendor.'/autoload.php')) {
    throw new RuntimeException('Run composer install before the native addon tests. An explicit DISCUSSIONBRIDGE_NATIVE_TEST_VENDOR may select isolated test dependencies.');
}
$loader = require $vendor.'/autoload.php';
$loader->setPsr4('CodeWorksLabs\\DiscussionBridgeStatamic\\', $root.'/src');
$loader->setPsr4('CodeWorksLabs\\DiscussionBridgeStatamic\\Tests\\', $root.'/tests');
foreach (['CodeWorksLabs\\DiscussionBridgeStatamic\\ServiceProvider', 'CodeWorksLabs\\DiscussionBridgeStatamic\\Cms\\PrivateState'] as $class) {
    $file = realpath((new ReflectionClass($class))->getFileName());
    if (! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', realpath($root)).'/src/')) {
        throw new RuntimeException('Native fixture autoload escaped the clean V4 Statamic source.');
    }
}
