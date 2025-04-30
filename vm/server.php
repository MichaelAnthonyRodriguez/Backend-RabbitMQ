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

// === Get Tailscale IP ===
$ip = trim(shell_exec("tailscale ip | head -n 1"));
echo "[VM SERVER] Reporting Tailscale IP to deployment server: $ip\n";

// === Report IP to Deployment Server ===
$client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
$client->publish([
    'action' => 'register_vm_ip',
    'env' => $env,
    'role' => $role,
    'ip' => $ip
]);

// === Handle Bundle Installation ===
function installBundle($bundleName, $version) {
    echo "[VM SERVER] installBundle() called for $bundleName v$version\n";

    $filename = "{$bundleName}_v{$version}.tgz";
    $remotePath = "/home/michael-anthony-rodriguez/bundles/$filename";
    $localTmp = "/tmp/$filename";
    $extractDir = "/tmp/{$bundleName}_install";

    // 1. SCP the file from deployment
    $scpCommand = "scp michael-anthony-rodriguez@100.105.162.20:$remotePath $localTmp 2>&1";
    $output = shell_exec($scpCommand);

    if (!file_exists($localTmp)) {
        echo "[ERROR] SCP failed: $output\n";
        return ["status" => "error", "message" => "Failed to download bundle"];
    }
    echo "[VM SERVER] Bundle downloaded to $localTmp\n";

    // 2. Extract the tarball
    shell_exec("rm -rf " . escapeshellarg($extractDir));
    mkdir($extractDir, 0777, true);
    shell_exec("tar -xzf " . escapeshellarg($localTmp) . " -C " . escapeshellarg($extractDir));
    echo "[VM SERVER] Bundle extracted to $extractDir\n";

    // 3. Parse bundle.ini
    $iniPath = "$extractDir/bundle.ini";
    if (!file_exists($iniPath)) {
        return ["status" => "error", "message" => "Missing bundle.ini"];
    }
    $config = parse_ini_file($iniPath, true);
    if (!$config || !isset($config['files'])) {
        return ["status" => "error", "message" => "Invalid bundle.ini"];
    }

    // 4. Copy each file to target path
    foreach ($config['files'] as $filename => $targetDir) {
        $source = "$extractDir/" . basename($filename);
        $dest = rtrim($targetDir, '/') . '/' . basename($filename);

        if (!file_exists($source)) {
            echo "[WARNING] Source file missing: $source\n";
            continue;
        }

        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }

        if (copy($source, $dest)) {
            echo "[VM SERVER] Installed: $filename -> $dest\n";
        } else {
            echo "[ERROR] Failed to copy $filename\n";
        }
    }

    // 5. Restart processes from [restart]
    if (isset($config['restart'])) {
        foreach ($config['restart'] as $label => $service) {
            echo "[VM SERVER] Restarting: $service\n";
            shell_exec("sudo systemctl restart " . escapeshellarg($service));
        }
    }

    // 6. Cleanup
    unlink($localTmp);
    shell_exec("rm -rf " . escapeshellarg($extractDir));

    return ["status" => "ok", "message" => "Bundle installed successfully"];
}


// === Handle SSH Key Installation ===
function installSshKey($publicKey) {
    $home = getenv("HOME");
    $sshDir = "$home/.ssh";
    $authKeys = "$sshDir/authorized_keys";

    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
        echo "[VM SERVER] Created ~/.ssh directory\n";
    }

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

// === Start RabbitMQ Server Listener ===
$server = new rabbitMQServer("vm.ini", $section);
$server->process_requests("requestProcessor");
