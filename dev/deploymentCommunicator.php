#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

date_default_timezone_set("America/New_York");

// === RabbitMQ Client Call: Get new bundles ===
function requestNewBundlesFromDeployment($target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'get_new_bundles',
        'env' => 'qa'
    ]);

    if (isset($response['bundles'])) {
        echo "New Bundles:\n";
        foreach ($response['bundles'] as $bundle) {
            echo "- {$bundle['name']} v{$bundle['version']}\n";
        }
    } else {
        echo "No new bundles found.\n";
    }
}

// === RabbitMQ Client Call: Send bundle result (pass/fail) ===
function sendBundleResultToDeployment($name, $version, $status, $target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'bundle_result',
        'name' => $name,
        'version' => (int)$version,
        'status' => $status
    ]);

    echo "[DEPLOYMENT] Result sent for $name v$version: $status\n";
    print_r($response);
}

// === RabbitMQ Client Call: Get latest passed bundle by name ===
function requestLatestPassedBundle($bundleName, $target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'get_latest_passed_bundle',
        'name' => $bundleName
    ]);

    if (isset($response['version'])) {
        echo "Latest passed bundle: {$response['name']} v{$response['version']} ({$response['size']} bytes)\n";
    } else {
        echo "No passed bundles found for '{$bundleName}'\n";
    }
}

//Create bundle zip
function createBundleTarball($type, $bundleName) {
    $validTypes = ['frontend', 'backend', 'dmz'];
    if (!in_array($type, $validTypes)) {
        echo "Invalid type: $type (must be frontend, backend, or dmz)\n";
        return;
    }

    $baseDir = "/deploy/bundles/$type/$bundleName";
    $iniPath = "$baseDir/bundle.ini";

    if (!file_exists($iniPath)) {
        echo "Missing bundle.ini at $iniPath\n";
        return;
    }

    // 1. Request latest version (any status) from deployment
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
    $latest = $client->send_request([
        'action' => 'get_latest_bundle_any_status',
        'name' => $bundleName
    ]);
    $latestVersion = isset($latest['version']) ? (int)$latest['version'] : 0;
    $nextVersion = $latestVersion + 1;

    echo "[Zipper] Creating bundle '$bundleName' version $nextVersion\n";

    // 2. Parse bundle.ini and collect files
    $ini = parse_ini_file($iniPath, true);
    $files = $ini['files']['copy'] ?? [];
    if (!is_array($files)) $files = [$files];

    $tempDir = "/tmp/bundle_{$type}_{$bundleName}";
    $bundleFilename = "{$bundleName}_v{$nextVersion}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    shell_exec("rm -rf $tempDir && mkdir -p $tempDir");
    shell_exec("cp $iniPath $tempDir/");

    foreach ($files as $file) {
        $src = "$baseDir/$file";
        if (!file_exists($src)) {
            echo "Skipping missing file: $src\n";
            continue;
        }
        shell_exec("cp $src $tempDir/");
    }

    // 3. Create tarball
    shell_exec("tar -czf $bundlePath -C $tempDir .");
    $size = filesize($bundlePath);

    echo "Bundle created: $bundleFilename (" . number_format($size) . " bytes)\n";

    // 4. Send metadata to deployment server
    $response = $client->send_request([
        'action' => 'register_bundle',
        'name' => $bundleName,
        'version' => $nextVersion,
        'status' => 'new',
        'size' => $size,
        'filename' => $bundleFilename
    ]);

    echo "[DEPLOYMENT] DB registration response:\n";
    print_r($response);

    // 5. SCP tarball to deployment server's bundle archive
    $deployHost = "deployment@100.105.162.20"; // or whatever your deploy host is
    $deployDest = "/deploy/archive/$bundleFilename";

    echo "[SCP] Sending bundle to deployment server...\n";
    $scpResult = shell_exec("scp $bundlePath $deployHost:$deployDest 2>&1");

    if (str_contains($scpResult, "No such file") || str_contains($scpResult, "Permission denied")) {
        echo "SCP failed: $scpResult\n";
    } else {
        echo "Bundle successfully sent to deployment server.\n";
    }

    // Cleanup
    shell_exec("rm -rf $tempDir $bundlePath");
}


function requestLatestAnyStatusBundle($bundleName, $target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'get_latest_bundle_any_status',
        'name' => $bundleName
    ]);

    return $response;
}

// === CLI Entry Point ===
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? null;

    switch ($cmd) {
        case "get_new_bundles":
            requestNewBundlesFromDeployment();
            break;

        case "bundle_result":
            if (isset($argv[2], $argv[3], $argv[4])) {
                sendBundleResultToDeployment($argv[2], $argv[3], $argv[4]);
            } else {
                echo "Usage: php developmentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
            }
            break;

        case "get_latest_passed_bundle":
            if (isset($argv[2])) {
                requestLatestPassedBundle($argv[2]);
            } else {
                echo "Usage: php developmentCommunicator.php get_latest_passed_bundle <bundleName>\n";
            }
            break;

        case "create_bundle":
            if (isset($argv[2], $argv[3])) {
                createBundleTarball($argv[2], $argv[3]);
            } else {
                echo "Usage: php developmentCommunicator.php create_bundle <type> <bundleName>\n";
            }
            break;

        default:
            echo "Usage:\n";
            echo "  php developmentCommunicator.php get_new_bundles\n";
            echo "  php developmentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
            echo "  php developmentCommunicator.php get_latest_passed_bundle <bundleName>\n";
    }
}
?>
