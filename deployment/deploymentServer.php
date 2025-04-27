#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set("America/New_York");

// === Main Request Processor ===
function requestProcessor($request) {
    global $mydb;

    echo "Received request:\n";
    print_r($request);

    if (!isset($request['action'])) {
        return ["status" => "error", "message" => "No action specified"];
    }

    switch ($request['action']) {
        case "get_latest_bundle_any_status":
            $bundleName = $request['name'] ?? '';
            if (empty($bundleName)) {
                return ["status" => "error", "message" => "No bundle name provided"];
            }

            $stmt = $mydb->prepare("SELECT name, version, status, size FROM bundles WHERE name = ? ORDER BY version DESC LIMIT 1");
            $stmt->bind_param("s", $bundleName);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                echo "Found bundle: \n";
                print_r($row);
                return $row;
            } else {
                echo "No bundle found for name: $bundleName\n";
                return []; // Return empty array so the client starts version 1
            }

        case "register_bundle":
            $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sisi", $request['name'], $request['version'], $request['status'], $request['size']);
            $stmt->execute();
            return ["status" => "ok", "message" => "Bundle registered"];

        default:
            return ["status" => "error", "message" => "Unknown action: " . $request['action']];
    }
}

// === Server Listen Loop ===
$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
$server->process_requests('requestProcessor');

?>
