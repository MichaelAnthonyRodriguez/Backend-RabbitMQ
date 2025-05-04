#!/usr/bin/php
<?php
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php\n";
    exit(1);
}

$deploymentUser = 'michael-anthony-rodriguez';
$deploymentHost = '100.105.162.20';
$vmUser = getenv('SUDO_USER') ?: getenv('USER');
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";
$vmWebDir = "/var/www/sample";

// Install both SSH server and client
shell_exec("Aapt install -y openssh-server openssh-client");

// Start SSH service
shell_exec("systemctl enable ssh");
shell_exec("systemctl start ssh");
echo "[INIT] OpenSSH installed and running.\n";

// Create web dir
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");
echo "[INIT] Web directory permissions set.\n";

// Set up SSH dir
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
}

// Try fetching public key via scp (better for key transfer than ssh/echo)
$tmpKey = "/tmp/deployment_key.pub";
$scpStatus = shell_exec("scp -o StrictHostKeyChecking=no $deploymentUser@$deploymentHost:/home/$deploymentUser/.ssh/id_rsa.pub $tmpKey 2>&1");
if (!file_exists($tmpKey)) {
    echo "[ERROR] Failed to copy public key from deployment server:\n$scpStatus\n";
    exit(1);
}
$publicKey = trim(file_get_contents($tmpKey));
unlink($tmpKey);

// Install key if not already present
if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] Deployment public key installed.\n";
} else {
    echo "[INIT] Public key already exists.\n";
}

echo "[INIT] VM initialization complete.\n";

echo "[INIT] Syncing systemd service files...\n";

$sourceSystemdDir = "/home/$vmUser/dev/systemd";  // or adjust if located elsewhere
$targetSystemdDir = "/home/$vmUser/.config/systemd/user";

// Create user systemd directory if missing
if (!is_dir($targetSystemdDir)) {
    mkdir($targetSystemdDir, 0755, true);
    shell_exec("chown -R $vmUser:$vmUser $targetSystemdDir");
}

// Copy each service file
$serviceFiles = glob("$sourceSystemdDir/*.service");
foreach ($serviceFiles as $file) {
    $filename = basename($file);
    copy($file, "$targetSystemdDir/$filename");
    shell_exec("chown $vmUser:$vmUser $targetSystemdDir/$filename");
    echo "[INIT] Copied $filename to systemd user directory\n";
}

// Reload systemd as the VM user
shell_exec("runuser -l $vmUser -c 'systemctl --user daemon-reexec'");
shell_exec("runuser -l $vmUser -c 'systemctl --user daemon-reload'");
echo "[INIT] systemd --user daemon reloaded for $vmUser\n";


?>
