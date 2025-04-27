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
            echo "[COMMUNICATOR] No previous version found.\n";
            return 0;
        }
    } catch (Exception $e) {
        echo "[ERROR] Exception while getting latest version: " . $e->getMessage() . "\n";
        return null;
    }
}


// === RabbitMQ Client Call: Create and send a bundle ===
function createBundleTarball($type, $bundleName, $version) {
    $sourceDirMap = [
        'frontend' => '/var/www/sample',
        'backend' => '/home/michael-anthony-rodriguez/RabbitMQ/serverBackend',
        'dmz' => '/home/michael-anthony-rodriguez/RabbitMQ/dmz'
    ];

    if (!isset($sourceDirMap[$type])) {
        echo "[ERROR] Invalid type '$type'\n";
        return null;
    }

    $sourceDir = $sourceDirMap[$type];

    if (!is_dir($sourceDir)) {
        echo "[ERROR] Source directory does not exist: $sourceDir\n";
        return null;
    }

    $bundleFilename = "{$bundleName}_v{$version}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    echo "[COMMUNICATOR] Creating tarball: $bundlePath\n";

    shell_exec("tar -czf $bundlePath -C $sourceDir .");

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Tarball creation failed.\n";
        return null;
    }

    return $bundlePath;
}

function registerBundleMetadata($bundleName, $version, $size) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $response = $client->send_request([
            'action' => 'register_bundle',
            'name' => $bundleName,
            'version' => $version,
            'status' => 'new',
            'size' => $size
        ]);

        echo "[COMMUNICATOR] Register response:\n";
        print_r($response);

        return (isset($response['status']) && $response['status'] === 'ok');

    } catch (Exception $e) {
        echo "[ERROR] Exception while registering bundle: " . $e->getMessage() . "\n";
        return false;
    }
}

function sendBundleTarball($bundlePath, $bundleFilename) {
    $deployHost = "michael-anthony-rodriguez@100.105.162.20";
    $deployDest = "/home/michael-anthony-rodriguez/bundles/$bundleFilename";

    echo "[COMMUNICATOR] Sending tarball to deployment server...\n";
    $scpCommand = "scp $bundlePath $deployHost:$deployDest 2>&1";
    $scpResult = shell_exec($scpCommand);

    echo "[DEBUG] SCP Output:\n$scpResult\n";

    if (strpos($scpResult, "No such file") !== false || strpos($scpResult, "Permission denied") !== false) {
        echo "[ERROR] SCP failed: $scpResult\n";
        return false;
    } else {
        echo "[COMMUNICATOR] Bundle transferred successfully.\n";
        return true;
    }
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
                    echo "[ERROR] Cannot get latest version.\n";
                    exit(1);
                }
        
                $newVersion = $latestVersion + 1;
                echo "[CLI] New version will be: $newVersion\n";
        
                $bundlePath = createBundleTarball($type, $bundleName, $newVersion);
        
                if ($bundlePath === null) {
                    echo "[ERROR] Bundle tarball creation failed.\n";
                    exit(1);
                }
        
                $size = filesize($bundlePath);
        
                if (!registerBundleMetadata($bundleName, $newVersion, $size)) {
                    echo "[ERROR] Failed to register bundle metadata.\n";
                    exit(1);
                }
        
                if (!sendBundleTarball($bundlePath, basename($bundlePath))) {
                    echo "[ERROR] Failed to send bundle tarball.\n";
                    exit(1);
                }
        
                // Cleanup
                shell_exec("rm -f $bundlePath");
                echo "[COMMUNICATOR] Local tarball cleaned up.\n";
            
                } else {
                    echo "Usage: php deploymentCommunicator.php create_bundle <type> <bundleName>\n";
                }
                break;            

        default:
            echo "Unknown command.\n";
    }
}
?>
