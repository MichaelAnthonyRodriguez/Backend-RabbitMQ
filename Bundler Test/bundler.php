#!/usr/bin/env php
<?php
/**
 * RabbitMQ Deployment Bundler
 * Uses deploymentRabbitMQ.ini and existing RabbitMQ library
 * 
 * Usage: php bundler.php [frontend|backend|dmz] /path/to/source
 */

// 1. Include required libraries
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

// 2. Validate Input
if ($argc < 3) die("Usage: php bundler.php [frontend|backend|dmz] /path/to/source\n");

$bundleType = $argv[1];
$sourceDir = realpath($argv[2]);

if (!file_exists('deploymentRabbitMQ.ini')) {
    die("Error: Missing deploymentRabbitMQ.ini\n");
}

// 3. Initialize RabbitMQ Clients
$versionClient = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
$deployClient = new rabbitMQClient("deploymentRabbitMQ.ini", $bundleType);

// 4. Get Next Version
echo "🔍 Requesting version for $bundleType bundle...\n";
$versionResponse = $versionClient->send_request([
    'type' => 'version_request',
    'bundle' => basename($sourceDir),
    'bundle_type' => $bundleType
]);

if (!isset($versionResponse['version'])) {
    die("🚨 Failed to get version from deployment system\n");
}
$version = (int)$versionResponse['version'];

// 5. Create Bundle
$bundleName = basename($sourceDir) . ".$bundleType.v$version.zip";
echo "📦 Creating $bundleName...\n";

$zip = new ZipArchive();
if ($zip->open($bundleName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("🚨 Failed to create bundle\n");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($files as $file) {
    $filePath = $file->getRealPath();
    $relativePath = substr($filePath, strlen($sourceDir) + 1);
    
    if ($file->isDir()) {
        $zip->addEmptyDir($relativePath);
    } else {
        $zip->addFile($filePath, $relativePath);
    }
}

if (!$zip->close()) {
    die("🚨 Failed to finalize bundle\n");
}

// 6. Send Deployment Command
echo "🚀 Sending deployment request...\n";
$response = $deployClient->publish([
    'action' => 'deploy',
    'bundle' => basename($sourceDir),
    'type' => $bundleType,
    'version' => $version,
    'path' => realpath($bundleName),
    'timestamp' => time()
]);

if ($response === false) {
    die("🚨 Failed to send deployment request\n");
}

echo "✅ Successfully initiated deployment for " . basename($sourceDir) . " v$version\n";