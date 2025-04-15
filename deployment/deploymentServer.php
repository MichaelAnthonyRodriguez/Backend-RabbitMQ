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
$client = new rabbitMQClient($qaTarget, "deploymentRabbitMQ.ini");
$client->publish([ 'action' => 'check_for_bundles' ]);
echo "[DEPLOYMENT] Sent bundle check request to $qaTarget\n";


// === Deployment Server Listener ===
$server = new rabbitMQServer("deploymentServer", "deploymentRabbitMQ.ini");
$server->process_requests("handleDeploymentMessage");

?>
