#!/usr/bin/php
<?php
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php [sql]\n";
    exit(1);
}

$startMysql = ($argv[1] ?? '') === 'sql';

$deploymentUser = 'michael-anthony-rodriguez';
$deploymentHost = '100.105.162.20';
$vmUser = getenv('SUDO_USER') ?: getenv('USER');
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";
$vmWebDir = "/var/www/html";

// === Install only SSH server/client ===
shell_exec("apt install -y openssh-server openssh-client");

// === Start Tailscale first ===
shell_exec("systemctl start tailscaled");
echo "[INIT] Tailscale started.\n";

// === Start RabbitMQ next ===
shell_exec("systemctl start rabbitmq-server");
echo "[INIT] RabbitMQ started.\n";

// === Optionally start MySQL ===
if ($startMysql) {
    shell_exec("systemctl start mysql");
    echo "[INIT] MySQL started.\n";
}

// === Start SSH last ===
shell_exec("systemctl enable ssh");
shell_exec("systemctl start ssh");
echo "[INIT] SSH installed and running.\n";

// === Disable autostart for specific services ===
$disableOnBoot = ["tailscaled", "mysql", "rabbitmq-server", "apache2"];
foreach ($disableOnBoot as $svc) {
    shell_exec("systemctl disable $svc");
    echo "[INIT] Disabled autostart for $svc\n";
}

// === Web directory setup ===
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");
echo "[INIT] Web directory permissions set.\n";

// === SSH Key Installation ===
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
}

$tmpKey = "/tmp/deployment_key.pub";
$scpStatus = shell_exec("scp -o StrictHostKeyChecking=no $deploymentUser@$deploymentHost:/home/$deploymentUser/.ssh/id_rsa.pub $tmpKey 2>&1");
if (!file_exists($tmpKey)) {
    echo "[ERROR] Failed to copy public key from deployment server:\n$scpStatus\n";
    exit(1);
}
$publicKey = trim(file_get_contents($tmpKey));
unlink($tmpKey);

if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] Deployment public key installed.\n";
} else {
    echo "[INIT] Public key already exists.\n";
}

// === Sync systemd service files ===
echo "[INIT] Syncing systemd service files...\n";

$sourceSystemdDir = "$vmHome/Cinemaniacs/dev/systemd";
$targetSystemdDir = "$vmHome/.config/systemd/user";

if (!is_dir($targetSystemdDir)) {
    mkdir($targetSystemdDir, 0755, true);
    shell_exec("chown -R $vmUser:$vmUser $targetSystemdDir");
}

$serviceFiles = glob("$sourceSystemdDir/*.service");
foreach ($serviceFiles as $file) {
    $filename = basename($file);
    $targetPath = "$targetSystemdDir/$filename";
    copy($file, $targetPath);
    shell_exec("chown $vmUser:$vmUser $targetPath");
    echo "[INIT] Copied $filename to $targetPath\n";
}

$uid = trim(shell_exec("id -u $vmUser"));
$envPrefix = "XDG_RUNTIME_DIR=/run/user/$uid";
shell_exec("runuser -l $vmUser -c '$envPrefix systemctl --user daemon-reexec'");
shell_exec("runuser -l $vmUser -c '$envPrefix systemctl --user daemon-reload'");
echo "[INIT] systemd --user daemon reloaded for $vmUser\n";
?>
