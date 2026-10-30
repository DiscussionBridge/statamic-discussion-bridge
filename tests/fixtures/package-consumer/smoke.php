<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$package = Composer\InstalledVersions::getInstallPath('codeworkslabs/statamic-discussion-bridge');
if (! is_string($package)) throw new RuntimeException('Addon not installed from ordinary artifact repository');
$hostPackages = Composer\InstalledVersions::getInstalledPackages();
sort($hostPackages);
$hostRoot = Composer\InstalledVersions::getRootPackage();
$hostLoaders = array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders());
sort($hostLoaders);
if (! class_exists(CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider::class)) throw new RuntimeException('Normal addon provider autoload failed');
require_once $package.'/src/Cms/Shared/CmsCodec.php';
$codec = new DiscussionBridge\CmsV4\Codec($package.'/src/Cms/Shared/cms-schema.json');
$url = 'https://é.example/Original%2F?Q=%2F';
$codec->validate('Url', $url);
$afterPackages = Composer\InstalledVersions::getInstalledPackages();
sort($afterPackages);
$afterLoaders = array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders());
sort($afterLoaders);
if ($afterPackages !== $hostPackages || Composer\InstalledVersions::getRootPackage() !== $hostRoot || $afterLoaders !== $hostLoaders) {
    throw new RuntimeException('Private addon runtime changed the actual host Composer package/loader registry');
}
if ($codec->urlAuthority($url)['asciiHost'] !== 'xn--9ca.example' || $url !== 'https://é.example/Original%2F?Q=%2F') throw new RuntimeException('Installed private runtime changed admission or original bytes');
echo json_encode(['package' => Composer\InstalledVersions::getPrettyVersion('codeworkslabs/statamic-discussion-bridge'), 'candidate' => $codec::CANDIDATE_ID, 'normal_provider_autoload' => true, 'private_codec' => 'loaded', 'limits' => 'Minimal Composer consumer only; not Flat/DB/SSG application or installed/public acceptance'], JSON_THROW_ON_ERROR).PHP_EOL;
