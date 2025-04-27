#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');
require_once('populateDB.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set("America/New_York");

// === Handler for Incoming Messages ===
function handleDeploymentMessage($payload) {
    global $mydb;

    echo "[SERVER] Received message:\n";
    print_r($payload);

    if (!isset($payload['action'])) {
        echo "[SERVER] Error: No action specified\n";
        return ["status" => "error", "message" => "No action provided"];
    }

    switch ($payload['action']) {
        case 'get_new_bundles':
            echo "[SERVER] Action: get_new_bundles\n";
            $env = $payload['env'] ?? 'qa';

            $stmt = $mydb->prepare("SELECT name, version FROM bundles WHERE status = 'new'");
            $stmt->execute();
            $result = $stmt->get_result();

            $bundles = [];
            while ($row = $result->fetch_assoc()) {
                $bundles[] = $row;
            }

            echo "[SERVER] Found bundles:\n";
            print_r($bundles);

            return ['bundles' => $bundles];

        case 'bundle_result':
            echo "[SERVER] Action: bundle_result\n";

            $name = $payload['name'];
            $version = $payload['version'];
            $status = $payload['status'];

            $stmt = $mydb->prepare("UPDATE bundles SET status = ? WHERE name = ? AND version = ?");
            $stmt->bind_param("ssi", $status, $name, $version);
            $stmt->execute();

            echo "[SERVER] Updated bundle '$name' version $version to status '$status'\n";
            return ["status" => "ok", "message" => "Bundle result updated"];

        case 'get_latest_bundle_any_status':
            echo "[SERVER] Action: get_latest_bundle_any_status\n";

            $name = $payload['name'];

            $stmt = $mydb->prepare("SELECT name, version, status, size FROM bundles WHERE name = ? ORDER BY version DESC LIMIT 1");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                echo "[SERVER] Latest bundle found:\n";
                print_r($row);
                return $row;
            } else {
                echo "[SERVER] No bundles found for '$name'\n";
                return [];
            }

        case 'register_bundle':
            echo "[SERVER] Action: register_bundle\n";

            $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sisi", $payload['name'], $payload['version'], $payload['status'], $payload['size']);
            $stmt->execute();

            echo "[SERVER] Registered new bundle: {$payload['name']} version {$payload['version']}\n";
            return ["status" => "ok", "message" => "Bundle registered"];

        default:
            echo "[SERVER] Unknown action '{$payload['action']}'\n";
            return ["status" => "error", "message" => "Unknown action"];
    }
}

// === Deployment Server Listener ===
echo "[SERVER] Starting deployment server listener...\n";

$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
$server->process_requests("handleDeploymentMessage");
?>
