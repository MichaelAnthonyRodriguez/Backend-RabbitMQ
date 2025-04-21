#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');     // RabbitMQ class
require_once('mysqlconnect.php');    // Shared MySQLi connection: $mydb
require_once('populateDB.php');      // Bundle schema setup if needed
date_default_timezone_set("America/New_York");

// === Deployment PHP Server for VM Communication ===

// === Handler for Incoming Messages ===
function handleDeploymentMessage($payload) {
    global $mydb;

    if ($payload['action'] === 'get_new_bundles') {
        $env = $payload['env']; // Optional filter for future use

        $stmt = $mydb->prepare("SELECT name, version FROM bundles WHERE status = 'new'");
        $stmt->execute();
        $result = $stmt->get_result();

        $bundles = [];
        while ($row = $result->fetch_assoc()) {
            $bundles[] = $row;
        }

        echo "[DEPLOYMENT] Sent list of new bundles to requester.\n";
        return ['bundles' => $bundles];

    } elseif ($payload['action'] === 'bundle_result') {
        $name = $payload['name'];
        $version = $payload['version'];
        $status = $payload['status'];  // 'passed' or 'failed'

        $stmt = $mydb->prepare("UPDATE bundles SET status = ? WHERE name = ? AND version = ?");
        $stmt->bind_param("ssi", $status, $name, $version);
        $stmt->execute();

        echo "[DEPLOYMENT] Bundle $name v$version marked as $status\n";
    }
}
function notifyQaOfNewBundle($qaTarget) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $qaTarget);
    $client->publish([ 'action' => 'check_for_bundles' ]);
    echo "[DEPLOYMENT] Sent bundle check request to $qaTarget\n";
}

//gets latest passed bundle
function getLatestPassedBundle($name) {
    global $mydb;

    $stmt = $mydb->prepare("
        SELECT name, version, size, created_at
        FROM bundles
        WHERE name = ? AND status = 'passed'
        ORDER BY version DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $name);
    $stmt->execute();

    $result = $stmt->get_result();
    $bundle = $result->fetch_assoc();

    if ($bundle) {
        echo "[DEPLOYMENT] Latest passed bundle for '{$name}': v{$bundle['version']}\n";
        return $bundle;
    } else {
        echo "[DEPLOYMENT] No passed bundles found for '{$name}'\n";
        return null;
    }
}

//request processor
function requestProcessor($request) {
    echo "[DEPLOYMENT] Processing request...\n";
    var_dump($request);

    if (!isset($request['action'])) {
        return "ERROR: unsupported or missing 'action'";
    }

    switch ($request['action']) {
        case "get_new_bundles":
            return handleDeploymentMessage($request);

        case "bundle_result":
            handleDeploymentMessage($request);
            return ["status" => "acknowledged"];

        case "get_latest_passed_bundle":
            if (!isset($request['name'])) {
                return ["status" => "error", "message" => "Missing bundle name"];
            }
            $bundle = getLatestPassedBundle($request['name']);
            return $bundle ? $bundle : ["status" => "not_found"];

        default:
            return ["status" => "error", "message" => "Unsupported action: " . $request['action']];
    }
}


// === Deployment Server Listener ===
    $server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
    echo "deploymentServer BEGIN" . PHP_EOL;
    $server->process_requests("requestProcessor");
?>
