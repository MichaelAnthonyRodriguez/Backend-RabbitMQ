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

// === Install Bundle
function installBundle($bundleName, $version) {
    echo "[VM SERVER] installBundle() called for $bundleName v$version\n";

    $filename = "{$bundleName}_v{$version}.tgz";
    $remotePath = "/home/michael-anthony-rodriguez/bundles/$filename";
    $localTmp = "/tmp/$filename";
    $extractDir = "/tmp/{$bundleName}_install";

    $scp = "scp michael-anthony-rodriguez@100.105.162.20:$remotePath $localTmp 2>&1";
    $output = shell_exec($scp);

    if (!file_exists($localTmp)) {
        return ["status" => "error", "message" => "SCP failed: $output"];
    }

    shell_exec("rm -rf " . escapeshellarg($extractDir));
    mkdir($extractDir, 0777, true);
    shell_exec("tar -xzf " . escapeshellarg($localTmp) . " -C " . escapeshellarg($extractDir));

    $iniPath = "$extractDir/bundle.ini";
    if (!file_exists($iniPath)) {
        return ["status" => "error", "message" => "Missing bundle.ini"];
    }

    $config = parse_ini_file($iniPath, true);
    if (!$config || !isset($config['files'])) {
        return ["status" => "error", "message" => "Invalid bundle.ini"];
    }

    foreach ($config['files'] as $filename => $targetDir) {
        $source = "$extractDir/" . basename($filename);
        $dest = rtrim($targetDir, '/') . '/' . basename($filename);

        if (file_exists($source)) {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy($source, $dest);
        }
    }

    if (isset($config['restart'])) {
        foreach ($config['restart'] as $label => $service) {
            shell_exec("sudo systemctl restart " . escapeshellarg($service));
        }
    }

    unlink($localTmp);
    shell_exec("rm -rf " . escapeshellarg($extractDir));

    return ["status" => "ok", "message" => "Bundle installed"];
}

// === SSH Key Installer
function installSshKey($publicKey) {
    $home = getenv("HOME");
    $sshDir = "$home/.ssh";
    $authKeys = "$sshDir/authorized_keys";

    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
    }

    if (strpos(@file_get_contents($authKeys), trim($publicKey)) === false) {
        file_put_contents($authKeys, trim($publicKey) . "\n", FILE_APPEND | LOCK_EX);
        chmod($authKeys, 0600);
        chown($authKeys, get_current_user());
        echo "[VM SERVER] Public key installed to authorized_keys\n";
    } else {
        echo "[VM SERVER] SSH key already exists in authorized_keys\n";
    }

    // === Give VM user write access to /var/www/sample
    shell_exec("sudo chown -R $USER:www-data /var/www/sample");
    shell_exec("sudo chmod -R 775 /var/www/sample");

    return ["status" => "ok", "message" => "SSH key installed and permissions configured"];
}

// === Request Processor
function requestProcessor($request) {
    switch ($request['action']) {
        case 'install_bundle':
            return installBundle($request['bundle'], $request['version']);        
        case 'install_ssh_key':
            return installSshKey($request['key']);
        default:
            return ["status" => "error", "message" => "Unknown action"];
    }
}

$server = new rabbitMQServer("vm.ini", $section);
$server->process_requests("requestProcessor");
