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
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    echo "[INFO] Starting createBundleTarball()\n";

    $validTypes = ['frontend', 'backend', 'dmz'];
    if (!in_array($type, $validTypes)) {
        echo "[ERROR] Invalid type: $type (must be frontend, backend, or dmz)\n";
        return;
    }

    $sourceDirMap = [
        'frontend' => '/var/www/sample',
        'backend'  => '/home/michael-anthony-rodriguez/RabbitMQ/serverBackend',
        'dmz'      => '/home/michael-anthony-rodriguez/RabbitMQ/dmz'
    ];

    $sourceDir = $sourceDirMap[$type];
    echo "[INFO] Source directory resolved: $sourceDir\n";

    if (!is_dir($sourceDir)) {
        echo "[ERROR] Source directory does not exist: $sourceDir\n";
        return;
    }

    $files = scandir($sourceDir);
    if (!$files || count(array_diff($files, ['.', '..'])) === 0) {
        echo "[ERROR] Source directory $sourceDir is empty. Cannot create bundle.\n";
        return;
    }

    echo "[INFO] Connecting to deployment server...\n";
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    echo "[INFO] Requesting latest bundle version...\n";
    $response = $client->send_request([
        'action' => 'get_latest_bundle_any_status',
        'name' => $bundleName
    ]);

    echo "[INFO] Deployment server response:\n";
    print_r($response);

    if (!is_array($response) || empty($response) || !isset($response['version'])) {
        echo "[INFO] No existing bundle found. Starting at version 1.\n";
        $nextVersion = 1;
    } else {
        $latestVersion = (int)$response['version'];
        echo "[INFO] Latest version found: $latestVersion\n";
        $nextVersion = $latestVersion + 1;
    }

    echo "[INFO] Creating tarball for version $nextVersion...\n";

    $bundleFilename = "{$bundleName}_v{$nextVersion}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    $tarCommand = "tar -czf $bundlePath -C $sourceDir . 2>&1";
    echo "[INFO] Running tar command: $tarCommand\n";
    $tarResult = shell_exec($tarCommand);
    echo "[INFO] Tar output:\n$tarResult\n";

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Failed to create tarball.\n";
        return;
    }

    $size = filesize($bundlePath);
    echo "[INFO] Tarball created successfully. Size: $size bytes\n";

    echo "[INFO] Registering bundle with deployment server...\n";
    $registration = $client->send_request([
        'action' => 'register_bundle',
        'name' => $bundleName,
        'version' => $nextVersion,
        'status' => 'new',
        'size' => $size,
        'filename' => $bundleFilename
    ]);

    print_r($registration);

    $deployHost = "michael-anthony-rodriguez@100.105.162.20";
    $deployDest = "/home/michael-anthony-rodriguez/bundles/$bundleFilename";

    echo "[INFO] Sending bundle to deployment server...\n";
    $scpCommand = "scp $bundlePath $deployHost:$deployDest 2>&1";
    $scpResult = shell_exec($scpCommand);
    echo "[INFO] SCP output:\n$scpResult\n";

    if (strpos($scpResult, "No such file") !== false || strpos($scpResult, "Permission denied") !== false) {
        echo "[ERROR] SCP failed.\n";
    } else {
        echo "[INFO] SCP successful.\n";
    }

    echo "[INFO] Cleaning up temp bundle file...\n";
    shell_exec("rm -f $bundlePath");

    echo "[INFO] Finished createBundleTarball()\n";
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
