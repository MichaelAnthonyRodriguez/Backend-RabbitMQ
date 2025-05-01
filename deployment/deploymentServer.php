#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');
require_once('populateDB.php');

date_default_timezone_set("America/New_York");

// === Reset vm_ips table on startup ===
$mydb->query("DROP TABLE IF EXISTS vm_ips");

$mydb->query("
    CREATE TABLE vm_ips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        env VARCHAR(10) NOT NULL,
        role VARCHAR(20) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        ssh_user VARCHAR(50) NOT NULL DEFAULT 'root',
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_vm (env, role)
    )
");

echo "[SERVER] vm_ips table reset on startup.\n";

// === Action: Get latest version of any bundle by name ===
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
        return ["status" => "none", "message" => "No bundles found"];
    }
}

// === Action: Register new bundle ===
function registerBundle($name, $version, $size) {
    global $mydb;
    echo "[SERVER] Running registerBundle()\n";

    $status = 'new';
    $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sisi", $name, $version, $status, $size);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo "[SERVER] Bundle $name v$version registered successfully.\n";
        return ["status" => "ok", "message" => "Bundle registered"];
    } else {
        echo "[SERVER] Bundle registration failed.\n";
        return ["status" => "error", "message" => "Failed to register bundle"];
    }
}

// === Action: Register VM IP and SSH key
function registerVmIp($env, $role, $ip) {
    global $mydb;
    echo "[SERVER] Registering IP for $env.$role => $ip\n";

    $sshUser = 'root';

    $stmt = $mydb->prepare("
        INSERT INTO vm_ips (env, role, ip, ssh_user)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ip = VALUES(ip), ssh_user = VALUES(ssh_user), last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("ssss", $env, $role, $ip, $sshUser);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo "[SERVER] IP updated for $env.$role\n";
        sendSshKeyToVm($env, $role);
        return ["status" => "ok", "message" => "IP registered + SSH key sent"];
    } else {
        echo "[SERVER] No change to IP for $env.$role\n";
        return ["status" => "noop", "message" => "IP unchanged"];
    }
}

// === Send SSH key to VM
function sendSshKeyToVm($env, $role) {
    echo "[DEPLOYMENT] Preparing to send SSH key to $env.$role\n";
    $publicKey = file_get_contents('/home/michael-anthony-rodriguez/.ssh/id_rsa.pub');
    $client = new rabbitMQClient("vm.ini", "$env.$role");

    $client->publish([
        'action' => 'install_ssh_key',
        'key' => $publicKey
    ]);

    echo "[DEPLOYMENT] ✅ SSH key sent to $env.$role\n";
}

// === Deploy Bundle to VM
function deployBundleToVm($env, $role, $bundleName, $status = 'new') {
    global $mydb;

    echo "[DEPLOYMENT] Looking for latest '$status' bundle of '$bundleName'...\n";

    $stmt = $mydb->prepare("SELECT version FROM bundles WHERE name = ? AND status = ? ORDER BY version DESC LIMIT 1");
    $stmt->bind_param("ss", $bundleName, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $bundle = $result->fetch_assoc();

    if (!$bundle) {
        echo "[DEPLOYMENT] No '$status' bundle found for $bundleName\n";
        return ["status" => "error", "message" => "Bundle not found"];
    }

    $version = (int)$bundle['version'];
    $filename = "{$bundleName}_v{$version}.tgz";
    $localPath = "/home/michael-anthony-rodriguez/bundles/$filename";

    if (!file_exists($localPath)) {
        echo "[DEPLOYMENT] Bundle file not found: $localPath\n";
        return ["status" => "error", "message" => "Local bundle file missing"];
    }

    echo "[DEPLOYMENT] Found bundle version $version\n";

    // Get VM IP and SSH user
    $ipQuery = $mydb->prepare("SELECT ip, ssh_user FROM vm_ips WHERE env = ? AND role = ?");
    $ipQuery->bind_param("ss", $env, $role);
    $ipQuery->execute();
    $ipResult = $ipQuery->get_result();
    $ipRow = $ipResult->fetch_assoc();

    if (!$ipRow) {
        echo "[DEPLOYMENT] No IP registered for $env.$role\n";
        return ["status" => "error", "message" => "VM IP not registered"];
    }

    $vmIp = $ipRow['ip'];
    $sshUser = $ipRow['ssh_user'];
    $targetPath = "/tmp/$filename";

    echo "[DEPLOYMENT] SCPing bundle to $sshUser@$vmIp...\n";
    $scpCommand = "scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $localPath $sshUser@$vmIp:$targetPath 2>&1";
    $scpOutput = shell_exec($scpCommand);
    echo "[SCP OUTPUT]\n$scpOutput\n";

    if (strpos($scpOutput, "Permission denied") !== false || strpos($scpOutput, "No such file") !== false) {
        return ["status" => "error", "message" => "SCP failed: $scpOutput"];
    }

    // Trigger install on VM
    $client = new rabbitMQClient("vm.ini", "$env.$role");
    $response = $client->send_request([
        'action' => 'install_bundle',
        'bundle' => $bundleName,
        'version' => $version
    ]);

    echo "[DEPLOYMENT] Install triggered on $env.$role for $bundleName v$version\n";
    return $response;
}

// === Request Processor ===
function requestProcessor($request) {
    echo "[SERVER] Processing request...\n";
    var_dump($request);

    if (!isset($request['action'])) {
        return ["status" => "error", "message" => "Unsupported message type"];
    }

    switch ($request['action']) {
        case 'get_latest_bundle_any_status':
            return getLatestBundleAnyStatus($request['name']);

        case 'register_bundle':
            return registerBundle($request['name'], $request['version'], $request['size']);

        case 'register_vm_ip':
            return registerVmIp($request['env'], $request['role'], $request['ip']);

        case 'push_ssh_key':
            return sendSshKeyToVm($request['env'], $request['role']);

        case 'deploy_bundle_to_vm':
            return deployBundleToVm($request['env'], $request['role'], $request['bundleName'], $request['status']);

        default:
            return ["status" => "error", "message" => "Unknown action"];
    }
}

// === Start Server ===
$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
echo "[SERVER] Deployment Server is starting..." . PHP_EOL;
$server->process_requests("requestProcessor");
