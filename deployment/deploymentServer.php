#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');
require_once('populateDB.php');

date_default_timezone_set("America/New_York");

// === Get latest version of any bundle by name ===
function getLatestBundleAnyStatus($name) {
    global $mydb;
    echo "[SERVER] Running getLatestBundleAnyStatus()\n";

    $stmt = $mydb->prepare("SELECT name, version, status, size FROM bundles WHERE name = ? ORDER BY version DESC LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc() ?: ["status" => "none", "message" => "No bundles found"];
}

// === Register new bundle ===
function registerBundle($name, $version, $size) {
    global $mydb;
    $status = 'new';
    $stmt = $mydb->prepare("INSERT INTO bundles (name, version, status, size) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sisi", $name, $version, $status, $size);
    $stmt->execute();
    return $stmt->affected_rows > 0
        ? ["status" => "ok", "message" => "Bundle registered"]
        : ["status" => "error", "message" => "Failed to register bundle"];
}

function registerVmIp($env, $role, $ip, $sshUser = 'defaultuser') {
    global $mydb;
    echo "[SERVER] Registering IP for $env.$role => $ip\n";

    $mydb->query("CREATE TABLE IF NOT EXISTS vm_ips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        env VARCHAR(10) NOT NULL,
        role VARCHAR(20) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        ssh_user VARCHAR(100),
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_vm (env, role)
    )");

    $stmt = $mydb->prepare("INSERT INTO vm_ips (env, role, ip, ssh_user)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ip = VALUES(ip), ssh_user = VALUES(ssh_user), last_updated = CURRENT_TIMESTAMP");
    $stmt->bind_param("ssss", $env, $role, $ip, $sshUser);
    $stmt->execute();

    sendSshKeyToVm($env, $role);
    return ["status" => "ok", "message" => "IP registered + SSH key sent"];
}

function sendSshKeyToVm($env, $role) {
    $publicKey = file_get_contents('/home/michael-anthony-rodriguez/.ssh/id_rsa.pub');
    $client = new rabbitMQClient("vm.ini", "$env.$role");
    $client->publish([
        'action' => 'install_ssh_key',
        'key' => $publicKey
    ]);
}

function deployBundleToVm($env, $role, $bundleName, $status = 'new') {
    global $mydb;

    $stmt = $mydb->prepare("SELECT version FROM bundles WHERE name = ? AND status = ? ORDER BY version DESC LIMIT 1");
    $stmt->bind_param("ss", $bundleName, $status);
    $stmt->execute();
    $bundle = $stmt->get_result()->fetch_assoc();
    if (!$bundle) return ["status" => "error", "message" => "Bundle not found"];

    $version = (int)$bundle['version'];
    $filename = "{$bundleName}_v{$version}.tgz";
    $localPath = "/home/michael-anthony-rodriguez/bundles/$filename";

    $stmt = $mydb->prepare("SELECT ip, ssh_user FROM vm_ips WHERE env = ? AND role = ?");
    $stmt->bind_param("ss", $env, $role);
    $stmt->execute();
    $vm = $stmt->get_result()->fetch_assoc();
    if (!$vm) return ["status" => "error", "message" => "VM not found"];

    $scpCmd = "scp -o StrictHostKeyChecking=no $localPath {$vm['ssh_user']}@{$vm['ip']}:/tmp/$filename 2>&1";
    $output = shell_exec($scpCmd);

    if (strpos($output, "Permission denied") !== false) {
        return ["status" => "error", "message" => "SCP failed: $output"];
    }

    $client = new rabbitMQClient("vm.ini", "$env.$role");
    return $client->send_request([
        'action' => 'install_bundle',
        'bundle' => $bundleName,
        'version' => $version
    ]);
}

// === Request processor ===
function requestProcessor($req) {
    switch ($req['action']) {
        case 'get_latest_bundle_any_status':
            return getLatestBundleAnyStatus($req['name']);
        case 'register_bundle':
            return registerBundle($req['name'], $req['version'], $req['size']);
        case 'register_vm_ip':
            return registerVmIp($req['env'], $req['role'], $req['ip'], $req['ssh_user']);
        case 'deploy_bundle_to_vm':
            return deployBundleToVm($req['env'], $req['role'], $req['bundleName'], $req['status']);
        default:
            return ["status" => "error", "message" => "Unknown action"];
    }
}

$server = new rabbitMQServer("deploymentRabbitMQ.ini", "deploymentServer");
$server->process_requests("requestProcessor");
?>
