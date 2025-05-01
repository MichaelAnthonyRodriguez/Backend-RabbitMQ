#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');
require_once('populateDB.php');


date_default_timezone_set("America/New_York");

function handleDeploymentMessage($payload) {
    global $mydb;

    echo "[SERVER] --- New Incoming Message ---\n";

    if (empty($payload)) {
        echo "[SERVER] ERROR: Empty payload received.\n";
        return ["status" => "error", "message" => "Empty payload"];
    }

    echo "[SERVER] Payload Received:\n";
    print_r($payload);

    if (function_exists('ackCurrentMessage')) {
        ackCurrentMessage();
    }

    if (!isset($payload['action'])) {
        echo "[SERVER] ERROR: No 'action' specified in payload.\n";
        return ["status" => "error", "message" => "No action specified"];
    }

    $action = $payload['action'];
    echo "[SERVER] Action Requested: $action\n";

    switch ($action) {
        case 'get_new_bundles':
            echo "[SERVER] Handling 'get_new_bundles'\n";
            $stmt = $mydb->prepare("SELECT name, version FROM bundles WHERE status = 'new'");
            if (!$stmt->execute()) {
                echo "[SERVER] ERROR: Failed to query bundles.\n";
                return ["status" => "error", "message" => "DB query failed"];
            }
            $result = $stmt->get_result();
            $bundles = [];
            while ($row = $result->fetch_assoc()) {
                $bundles[] = $row;
            }
            echo "[SERVER] Found Bundles:\n";
            print_r($bundles);
            return ['bundles' => $bundles];

        case 'bundle_result':
            echo "[SERVER] Handling 'bundle_result'\n";
            $name = $payload['name'];
            $version = $payload['version'];
            $status = $payload['status'];

            $stmt = $mydb->prepare("UPDATE bundles SET status = ? WHERE name = ? AND version = ?");
            $stmt->bind_param("ssi", $status, $name, $version);

            if (!$stmt->execute()) {
                echo "[SERVER] ERROR: Failed to update bundle result.\n";
                return ["status" => "error", "message" => "Failed to update bundle result"];
            }

            echo "[SERVER] Bundle '$name' version $version updated to status '$status'.\n";
            return ["status" => "ok", "message" => "Bundle result updated"];

        case 'get_latest_bundle_any_status':
            echo "[SERVER] Handling 'get_latest_bundle_any_status'\n";
            $name = $payload['name'];

            $stmt = $mydb->prepare("SELECT name, version, status, size FROM bundles WHERE name = ? ORDER BY version DESC LIMIT 1");
            $stmt->bind_param("s", $name);

            if (!$stmt->execute()) {
                echo "[SERVER] ERROR: Failed to query latest bundle.\n";
                return ["status" => "error", "message" => "Failed to fetch latest bundle"];
            }

            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                echo "[SERVER] Latest Bundle Found:\n";
                print_r($row);
                return $row;
            } else {
                echo "[SERVER] No bundle found for name: $name\n";
                return [];
            }

        case 'register_bundle':
            echo "[SERVER] Handling 'register_bundle'\n";
            $name = $payload['name'];
            $version = $payload['version'];
            $status = $payload['status'];
            $size = $payload['size'];

            $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sisi", $name, $version, $status, $size);

            if (!$stmt->execute()) {
                echo "[SERVER] ERROR: Failed to register bundle.\n";
                return ["status" => "error", "message" => "Failed to register bundle"];
            }

            echo "[SERVER] Bundle '$name' version $version registered successfully.\n";
            return ["status" => "ok", "message" => "Bundle registered"];

        default:
            echo "[SERVER] ERROR: Unknown action '$action'\n";
            return ["status" => "error", "message" => "Unknown action: $action"];
    }
}

echo "[SERVER] === Deployment Server Listener Starting ===\n";

$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
$server->process_requests('handleDeploymentMessage');

?>
