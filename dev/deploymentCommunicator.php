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
        echo "New Bundles:";
        foreach ($response['bundles'] as $bundle) {
            echo "- {$bundle['name']} v{$bundle['version']}";
        }
    } else {
        echo "No new bundles found.";
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

function getLatestVersionNumber($bundleName) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $response = $client->send_request([
            'action' => 'get_latest_bundle_any_status',
            'name' => $bundleName
        ]);

        if (isset($response['version'])) {
            echo "[COMMUNICATOR] Latest version found: {$response['version']}\n";
            return (int)$response['version'];
        } else {
            echo "[COMMUNICATOR] No previous version found. Starting fresh.\n";
            return 0;
        }

    } catch (Exception $e) {
        echo "[ERROR] Exception while fetching latest version: " . $e->getMessage() . "\n";
        return null;
    }
}


// === RabbitMQ Client Call: Create and send a bundle ===
function createBundleTarball($type, $bundleName, $newVersion) {
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

    if (!is_dir($sourceDir)) {
        echo "[ERROR] Source directory does not exist: $sourceDir\n";
        return;
    }

    echo "[COMMUNICATOR] Creating bundle '$bundleName' version $newVersion\n";

    $bundleFilename = "{$bundleName}_v{$newVersion}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    shell_exec("tar -czf $bundlePath -C $sourceDir .");

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Failed to create tarball: $bundlePath\n";
        return;
    }

    $size = filesize($bundlePath);

    // Register the bundle
    try {
        echo "[COMMUNICATOR] Registering bundle with deployment server...\n";
        $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

        $registerRequest = [
            'action' => 'register_bundle',
            'name' => $bundleName,
            'version' => $newVersion,
            'status' => 'new',
            'size' => $size
        ];

        $response = $client->send_request($registerRequest);
        echo "[COMMUNICATOR] Registration Response:\n";
        print_r($response);

        if (!isset($response['status']) || $response['status'] !== 'ok') {
            echo "[ERROR] Bundle registration failed.\n";
            return;
        }

        echo "[COMMUNICATOR] Bundle registered successfully.\n";

        // SCP tarball
        $deployHost = "michael-anthony-rodriguez@100.105.162.20";
        $deployDest = "/home/michael-anthony-rodriguez/bundles/$bundleFilename";

        echo "[COMMUNICATOR] Sending bundle tarball...\n";
        $scpCommand = "scp $bundlePath $deployHost:$deployDest 2>&1";
        $scpResult = shell_exec($scpCommand);
        echo "[DEBUG] SCP Output:\n$scpResult\n";

        if (strpos($scpResult, "No such file") !== false || strpos($scpResult, "Permission denied") !== false) {
            echo "[ERROR] SCP failed: $scpResult\n";
        } else {
            echo "[COMMUNICATOR] Bundle transferred successfully.\n";
        }

    } catch (Exception $e) {
        echo "[ERROR] Exception during registration or SCP: " . $e->getMessage() . "\n";
    }

    // Cleanup
    shell_exec("rm -f $bundlePath");
    echo "[COMMUNICATOR] Local tarball cleaned up.\n";
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
                echo "Usage: php deploymentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
            }
            break;

        case "create_bundle":
            if (isset($argv[2], $argv[3])) {
                $type = $argv[2];
                $bundleName = $argv[3];

                echo "[CLI] Getting latest version for '$bundleName'...\n";
                $latestVersion = getLatestVersionNumber($bundleName);

                if ($latestVersion === null) {
                    echo "[ERROR] Could not determine latest version. Aborting.\n";
                    exit(1);
                }

                $newVersion = $latestVersion + 1;
                echo "[CLI] Creating new bundle version: $newVersion\n";

                createBundleTarball($type, $bundleName, $newVersion);

            } else {
                echo "Usage: php deploymentCommunicator.php create_bundle <type> <bundleName>\n";
            }
            break;

        default:
            echo "Unknown command.\n";
    }
}
?>
