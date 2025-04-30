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

//install bundle from deployment
function installBundle($bundleName, $version) {
    echo "[VM SERVER] installBundle() called for $bundleName v$version\n";

    // TODO: Implement SCP + extraction + systemd bounce logic here
    // For now, just acknowledge it
    return [
        "status" => "ok",
        "message" => "Install triggered for $bundleName v$version"
    ];
}

//get ssh key from deployment server for scp
function installSshKey($publicKey) {
    $home = getenv("HOME");
    $sshDir = "$home/.ssh";
    $authKeys = "$sshDir/authorized_keys";

    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
        echo "[VM SERVER] Created ~/.ssh directory\n";
    }

    // Append key if it's not already present
    if (strpos(@file_get_contents($authKeys), trim($publicKey)) === false) {
        file_put_contents($authKeys, trim($publicKey) . "\n", FILE_APPEND | LOCK_EX);
        chmod($authKeys, 0600);
        chown($authKeys, get_current_user());
        echo "[VM SERVER] Public key added to authorized_keys\n";
        return ["status" => "ok", "message" => "SSH key installed"];
    } else {
        echo "[VM SERVER] Key already present\n";
        return ["status" => "noop", "message" => "Key already exists"];
    }
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

        case 'install_ssh_key':
            return installSshKey($request['key']);
            
        default:
            echo "[VM SERVER] Unknown action received.\n";
            return ["status" => "error", "message" => "Unknown action"];
    }
}


// === Start VM Server ===
$server = new rabbitMQServer("vm.ini", $section);
$server->process_requests("requestProcessor");
?>
