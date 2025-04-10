#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');  // Includes the RabbitMQ Library
require_once('mysqlconnect.php');  // Sets up the MySQLi connection ($mydb)
require_once('populateDB.php');   // Populates the database with schema
date_default_timezone_set("America/New_York");

//Deployment php server for vm communication

//Functions
function notifyQaOfNewBundle($qaTarget) {
    $client = new rabbitMQClient($qaTarget, "deploymentRabbitMQ.ini");
    $client->publish([ 'action' => 'check_for_bundles' ]);
    echo "[DEPLOYMENT] Sent bundle check request to $qaTarget\n";
}

// === Deployment Server Listener ===
function deploymentListener() {
    $server = new rabbitMQServer("deploymentServer", "deploymentRabbitMQ.ini");
    $server->process_requests("handleDeploymentMessage");
}

function handleDeploymentMessage($payload) {
    if ($payload['action'] === 'get_new_bundles') {
        $pdo = new PDO("mysql:host=localhost;dbname=deploy", "root", "password");
        $stmt = $pdo->query("SELECT name, version FROM bundles WHERE status = 'new'");
        $bundles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['bundles' => $bundles];
    } elseif ($payload['action'] === 'bundle_result') {
        $name = $payload['name'];
        $version = $payload['version'];
        $status = $payload['status'];
        $pdo = new PDO("mysql:host=localhost;dbname=deploy", "root", "password");
        $stmt = $pdo->prepare("UPDATE bundles SET status = ? WHERE name = ? AND version = ?");
        $stmt->execute([$status, $name, $version]);
        echo "[DEPLOYMENT] Bundle $name v$version marked as $status\n";
    }
}
?>