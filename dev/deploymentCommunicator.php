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
        echo "📦 New Bundles:\n";
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

        default:
            echo "Usage:\n";
            echo "  php developmentCommunicator.php get_new_bundles\n";
            echo "  php developmentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
            echo "  php developmentCommunicator.php get_latest_passed_bundle <bundleName>\n";
    }
}
?>
