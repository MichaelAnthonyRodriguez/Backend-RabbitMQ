#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

date_default_timezone_set("America/New_York");

// === Get CLI Arguments ===
$env = $argv[1] ?? null;
$role = $argv[2] ?? null;

if (!$env || !$role) {
    echo "Usage: php server.php <QA|PROD> <frontend|backend|dmz>\n";
    exit(1);
}

$section = "{$env}.{$role}";
echo "[VM SERVER] Starting listener for: $section using vm.ini\n";

// === Send VM IP to Deployment Server ===
$ip = getHostByName(getHostName());
echo "[VM SERVER] Reporting IP to deployment server: $ip\n";

$client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
$client->publish([
    'action' => 'register_vm_ip',
    'env' => $env,
    'role' => $role,
    'ip' => $ip
]);

function installBundle($bundleName, $version) {
    echo "[VM SERVER] installBundle() called for $bundleName v$version\n";

    // TODO: Implement SCP + extraction + systemd bounce logic here
    // For now, just acknowledge it
    return [
        "status" => "ok",
        "message" => "Install triggered for $bundleName v$version"
    ];
}

 
// === Request Processor ===
function requestProcessor($request) {
    echo "[VM SERVER] Processing request...\n";
    var_dump($request);

    if (!isset($request['action'])) {
        return ["status" => "error", "message" => "Unsupported message type"];
    }

    switch ($request['action']) {
        case 'install_bundle':
            return installBundle($request['bundle'], $request['version']);

        default:
            echo "[VM SERVER] Unknown action received.\n";
            return ["status" => "error", "message" => "Unknown action"];
    }
}


// === Start VM Server ===
$server = new rabbitMQServer("vm.ini", $section);
$server->process_requests("requestProcessor");
?>
