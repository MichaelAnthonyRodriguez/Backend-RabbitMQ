#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

date_default_timezone_set("America/New_York");

$env = $argv[1] ?? null;
$role = $argv[2] ?? null;

if (!$env || !$role) {
    echo "Usage: php server.php <QA|PROD> <frontend|backend|dmz>\n";
    exit(1);
}

$section = "{$env}.{$role}";
echo "[VM SERVER] Starting listener for: $section\n";

// Get Tailscale IP
$ip = trim(shell_exec("tailscale ip | head -n 1"));
$sshUser = get_current_user();

$client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
$client->publish([
    'action' => 'register_vm_ip',
    'env' => $env,
    'role' => $role,
    'ip' => $ip,
    'ssh_user' => $sshUser
]);

function installBundle($bundleName, $version) {
    $filename = "{$bundleName}_v{$version}.tgz";
    $remotePath = "/home/" . get_current_user() . "/bundles/$filename";
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
    $config = parse_ini_file($iniPath, true);
    foreach ($config['files'] as $filename => $targetDir) {
        $source = "$extractDir/" . basename($filename);
        $targetDir = str_replace("[USER]", get_current_user(), $targetDir);
        $dest = rtrim($targetDir, '/') . '/' . basename($filename);
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        copy($source, $dest);
    }

    if (isset($config['restart'])) {
        foreach ($config['restart'] as $service) {
            shell_exec("sudo systemctl restart " . escapeshellarg($service));
        }
    }

    unlink($localTmp);
    shell_exec("rm -rf " . escapeshellarg($extractDir));
    return ["status" => "ok", "message" => "Bundle installed"];
}

function installSshKey($publicKey) {
    $home = getenv("HOME");
    $sshDir = "$home/.ssh";
    $authKeys = "$sshDir/authorized_keys";
    if (!is_dir($sshDir)) mkdir($sshDir, 0700, true);
    if (strpos(@file_get_contents($authKeys), trim($publicKey)) === false) {
        file_put_contents($authKeys, trim($publicKey) . "\n", FILE_APPEND | LOCK_EX);
        chmod($authKeys, 0600);
        chown($authKeys, get_current_user());
    }
    return ["status" => "ok", "message" => "SSH key installed"];
}

function requestProcessor($request) {
    switch ($request['action']) {
        case 'install_bundle': return installBundle($request['bundle'], $request['version']);
        case 'install_ssh_key': return installSshKey($request['key']);
        default: return ["status" => "error", "message" => "Unknown action"];
    }
}

$server = new rabbitMQServer("vm.ini", $section);
$server->process_requests("requestProcessor");
