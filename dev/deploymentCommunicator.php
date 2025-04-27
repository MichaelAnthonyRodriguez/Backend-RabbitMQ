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

// === RabbitMQ Client Call: Create and send a bundle ===
function createBundleTarball($type, $bundleName) {
    $validTypes = ['frontend', 'backend', 'dmz'];
    if (!in_array($type, $validTypes)) {
        echo "Invalid type: $type (must be frontend, backend, or dmz)\n";
        return;
    }

    $sourceDirMap = [
        'frontend' => '/var/www/sample',
        'backend'  => '/home/michael-anthony-rodriguez/RabbitMQ/serverBackend',
        'dmz'      => '/home/michael-anthony-rodriguez/RabbitMQ/dmz'
    ];

    $sourceDir = $sourceDirMap[$type];
    echo "[INFO] Source directory: $sourceDir\n";

    if (!is_dir($sourceDir)) {
        echo "[ERROR] Source directory does not exist: $sourceDir";
        return;
    }

    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    echo "[INFO] Requesting latest bundle version...\n";
    $response = $client->send_request([
        'action' => 'get_latest_bundle_any_status',
        'name' => $bundleName
    ]);

    $latestVersion = isset($response['version']) ? (int)$response['version'] : 0;
    $nextVersion = $latestVersion + 1;

    echo "[INFO] Creating bundle '$bundleName' version $nextVersion\n";

    $bundleFilename = "{$bundleName}_v{$nextVersion}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    shell_exec("tar -czf $bundlePath -C $sourceDir .");

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Failed to create tarball.\n";
        return;
    }

    $size = filesize($bundlePath);

    // Register the bundle
    try {
        // Build request
        $newrequest = [
            'action' => 'register_bundle',
            'name' => $bundleName,
            'version' => $nextVersion,
            'status' => 'new',
            'size' => $size
        ];
        var_dump($newrequest);
        echo "[COMMUNICATOR] Registering bundle with deployment server...\n";

        $newclient = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
        echo "[COMMUNICATOR] creating client...\n";
        $newresponse = $newclient->send_request($newrequest);
        echo "[COMMUNICATOR] sending request...\n";
        var_dump($newresponse);
        echo "[COMMUNICATOR] Request sent. waiting for status\n";
    
        if ($newresponse["status"] === "success") {
            echo "[COMMUNICATOR] Bundle registered successfully.\n";
            print_r($registration);
        } else {
            echo "[ERROR] Bundle registration failed: ";
            print_r($registration);
        }
    
    } catch (Exception $e) {
      echo "Error sending message: " . $e->getMessage();
    }

    // Now SCP the bundle
    $deployHost = "michael-anthony-rodriguez@100.105.162.20";
    $deployDest = "/home/michael-anthony-rodriguez/bundles/$bundleFilename";

    echo "[COMMUNICATOR] Sending bundle tarball...\n";
    $scpCommand = "scp $bundlePath $deployHost:$deployDest 2>&1";
    echo "[DEBUG] SCP Command: $scpCommand\n";
    $scpResult = shell_exec($scpCommand);
    echo "[DEBUG] SCP Output:$scpResult\n";

    if (strpos($scpResult, "No such file") !== false || strpos($scpResult, "Permission denied") !== false) {
        echo "[ERROR] SCP failed.\n";
    } else {
        echo "[COMMUNICATOR] Bundle sent successfully.\n";
    }

    // Clean up
    shell_exec("rm -f $bundlePath");
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
                echo "Usage: php deploymentCommunicator.php bundle_result <name> <version> <passed|failed>";
            }
            break;
        case "create_bundle":
            if (isset($argv[2], $argv[3])) {
                createBundleTarball($argv[2], $argv[3]);
            } else {
                echo "Usage: php deploymentCommunicator.php create_bundle <type> <bundleName>";
            }
            break;
        default:
            echo "Unknown command.";
    }
}
?>
