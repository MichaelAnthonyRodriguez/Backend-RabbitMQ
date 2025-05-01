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

// Get Tailscale IP and current user
$ip = trim(shell_exec("tailscale ip | head -n 1"));
$sshUser = get_current_user();

// Register this VM's IP and user with the deployment server
$client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
try {
    $client->publish([
        'action'   => 'register_vm_ip',
        'env'      => $env,
        'role'     => $role,
        'ip'       => $ip,
        'ssh_user' => $sshUser
    ]);
    if ($ip) {
        echo "[VM SERVER] VM IP $ip registered for $env.$role (user: $sshUser)\n";
    } else {
        echo "[VM SERVER] Warning: Could not determine Tailscale IP for $env.$role\n";
    }
} catch (Exception $e) {
    echo "[VM SERVER] ERROR: Failed to register VM IP with deployment server: " . $e->getMessage() . "\n";
    // Continue to run even if registration fails (will retry on next startup)
}

// === Install bundle function ===
function installBundle($bundleName, $version) {
    global $env, $role;
    echo "[VM {$env}.{$role}] Received request to install bundle '$bundleName' v$version...\n";

    $filename = "{$bundleName}_v{$version}.tgz";
    $remotePath = "/home/" . get_current_user() . "/bundles/$filename";
    $localTmp = "/tmp/$filename";
    $extractDir = "/tmp/{$bundleName}_install";

    // Use bundle file already in /tmp (pushed by deployment server). If not present, attempt to fetch as fallback.
    if (!file_exists($localTmp)) {
        $scpCmd = "scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null michael-anthony-rodriguez@100.105.162.20:$remotePath $localTmp 2>&1";
        $output = shell_exec($scpCmd);
        if (!file_exists($localTmp)) {
            echo "[VM {$env}.{$role}] ERROR: Bundle file $localTmp not found. SCP attempt failed: $output\n";
            return ["status" => "error", "message" => "Bundle file not found"];
        }
        echo "[VM {$env}.{$role}] Fetched bundle from deployment server as fallback.\n";
    }

    // Clean previous extraction directory if any
    shell_exec("rm -rf " . escapeshellarg($extractDir));
    mkdir($extractDir, 0777, true);
    shell_exec("tar -xzf " . escapeshellarg($localTmp) . " -C " . escapeshellarg($extractDir));

    // Parse bundle config and deploy files
    $iniPath = "$extractDir/bundle.ini";
    $config = parse_ini_file($iniPath, true);
    $fileCount = 0;
    if (isset($config['files'])) {
        foreach ($config['files'] as $fname => $targetDir) {
            $sourceFile = "$extractDir/" . basename($fname);
            $targetDir = str_replace("[USER]", get_current_user(), $targetDir);
            $destFile = rtrim($targetDir, '/') . '/' . basename($fname);
            if (!is_dir(dirname($destFile))) {
                mkdir(dirname($destFile), 0755, true);
            }
            if (copy($sourceFile, $destFile)) {
                $fileCount++;
            }
        }
    }
    echo "[VM {$env}.{$role}] Installed $fileCount file(s) from bundle.\n";

    // Restart services if specified
    if (isset($config['restart'])) {
        $services = $config['restart'];
        if (!empty($services)) {
            echo "[VM {$env}.{$role}] Restarting services: " . implode(', ', $services) . "\n";
            foreach ($services as $service) {
                shell_exec("sudo systemctl restart " . escapeshellarg($service));
            }
        }
    }

    // Clean up bundle files
    unlink($localTmp);
    shell_exec("rm -rf " . escapeshellarg($extractDir));
    echo "[VM {$env}.{$role}] Bundle installation completed.\n";
    return ["status" => "ok", "message" => "Bundle installed"];
}

// === Install SSH key function ===
function installSshKey($publicKey) {
    global $env, $role;
    $home = getenv("HOME");
    $sshDir = "$home/.ssh";
    $authKeys = "$sshDir/authorized_keys";

    // Ensure .ssh directory exists with correct permissions
    if (!is_dir($sshDir)) {
        mkdir($sshDir, 0700, true);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($sshDir, get_current_user());
        }
    }

    // Read current authorized_keys (if any) and check for the key
    $authContent = @file_get_contents($authKeys);
    if ($authContent === false) {
        $authContent = "";
    }
    $keyEntry = trim($publicKey);
    if (strpos($authContent, $keyEntry) === false) {
        // Append the public key
        echo "[VM {$env}.{$role}] Adding SSH public key to authorized_keys\n";
        file_put_contents($authKeys, $keyEntry . "\n", FILE_APPEND | LOCK_EX);
        chmod($authKeys, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($authKeys, get_current_user());
        }
        echo "[VM {$env}.{$role}] SSH key added to $authKeys\n";
    } else {
        echo "[VM {$env}.{$role}] SSH key already present in authorized_keys (no changes made)\n";
    }
    return ["status" => "ok", "message" => "SSH key installed"];
}

// === Request processor ===
function requestProcessor($request) {
    global $env, $role;
    echo "[VM {$env}.{$role}] Processing action '{$request['action']}'\n";
    switch ($request['action'] ?? '') {
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
?>
