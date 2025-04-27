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

// === Action Functions ===
function getNewBundles() {
    global $mydb;
    echo "[SERVER] Running getNewBundles()\n";

    $stmt = $mydb->prepare("SELECT name, version FROM bundles WHERE status = 'new'");
    $stmt->execute();
    $result = $stmt->get_result();

    $bundles = [];
    while ($row = $result->fetch_assoc()) {
        $bundles[] = $row;
    }
    return ['bundles' => $bundles];
}

function submitBundleResult($name, $version, $status) {
    global $mydb;
    echo "[SERVER] Running submitBundleResult()\n";

    $stmt = $mydb->prepare("UPDATE bundles SET status = ? WHERE name = ? AND version = ?");
    $stmt->bind_param("ssi", $status, $name, $version);
    $stmt->execute();

    return ["status" => "ok", "message" => "Bundle result updated"];
}

function getLatestBundleAnyStatus($name) {
    global $mydb;
    echo "[SERVER] Running getLatestBundleAnyStatus()\n";

    $stmt = $mydb->prepare("SELECT name, version, status, size FROM bundles WHERE name = ? ORDER BY version DESC LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return $row;
    } else {
        return [];
    }
}

function registerBundle($name, $version, $status, $size) {
    global $mydb;
    echo "[SERVER] Running registerBundle()\n";

    $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sisi", $name, $version, $status, $size);
    $stmt->execute();
    echo "[SERVER] Bundle registered\n";
    return ["status" => "ok", "message" => "Bundle registered"];
}

// === Request Processor ===
function requestProcessor($request) {
    echo "[SERVER] Processing request...\n";
    var_dump($request);

    if (!isset($request['action'])) {
        echo "[SERVER] ERROR: No action provided.\n";
        return ["status" => "error", "message" => "No action provided"];
    }

    switch ($request['action']) {
        case 'get_new_bundles':
            return getNewBundles();
        case 'bundle_result':
            return submitBundleResult($request['name'], $request['version'], $request['status']);
        case 'get_latest_bundle_any_status':
            return getLatestBundleAnyStatus($request['name']);
        case 'register_bundle':
            return registerBundle($request['name'], $request['version'], $request['status'], $request['size']);
    }
    return ["returnCode" => '0', "message" => "Server received request and processed"];
}

// === Start Deployment Server ===
$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
echo "[SERVER] Deployment Server is starting...\n";
$server->process_requests("requestProcessor");

?>
