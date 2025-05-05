#!/usr/bin/php
<?php
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php <ENV> <ROLE> [sql] [web]\n";
    exit(1);
}

// === CLI flags ===
$args = $argv;
array_shift($args);
$env = strtoupper($args[0] ?? '');
$role = strtolower($args[1] ?? '');

if (!in_array($env, ['QA', 'PROD', 'DEV', 'DEPLOYMENT']) || 
    !in_array($role, ['frontend', 'backend', 'dmz']) && $env !== 'DEV' && $env !== 'DEPLOYMENT') {
    echo "Usage: sudo php init_vm_permissions.php <QA|PROD|DEV|DEPLOYMENT> <frontend|backend|dmz> [sql] [web]\n";
    exit(1);
}

$startMysql = in_array('sql', $args);
$startApache = in_array('web', $args);

$deploymentUser = 'og62';
$deploymentHost = '100.98.103.91';
$vmUser = getenv('SUDO_USER') ?: getenv('USER');
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";
$vmWebDir = "/var/www/html";

// === Install SSH ===
shell_exec("apt install -y openssh-server openssh-client");

// === Start Tailscale and RabbitMQ ===
shell_exec("systemctl start tailscaled");
shell_exec("systemctl start rabbitmq-server");
echo "[INIT] Tailscale and RabbitMQ started.\n";

// === Conditional Starts ===
if ($startMysql) {
    shell_exec("systemctl start mysql");
    echo "[INIT] MySQL started.\n";
}

if ($startApache) {
    shell_exec("systemctl start apache2");
    echo "[INIT] Apache2 started.\n";
}

shell_exec("systemctl enable ssh");
shell_exec("systemctl start ssh");
echo "[INIT] SSH started.\n";

// === Disable auto-start ===
foreach (['tailscaled', 'mysql', 'rabbitmq-server', 'apache2'] as $svc) {
    shell_exec("systemctl disable $svc");
    echo "[INIT] Disabled autostart for $svc\n";
}

// === Web dir setup ===
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");

// === SSH Key Setup ===
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
}

$tmpKey = "/tmp/deployment_key.pub";
$scpStatus = shell_exec("scp -o StrictHostKeyChecking=no $deploymentUser@$deploymentHost:/home/$deploymentUser/.ssh/id_rsa.pub $tmpKey 2>&1");
if (!file_exists($tmpKey)) {
    echo "[ERROR] Failed to fetch SSH key:\n$scpStatus\n";
    exit(1);
}
$publicKey = trim(file_get_contents($tmpKey));
unlink($tmpKey);

if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] SSH key installed.\n";
} else {
    echo "[INIT] SSH key already present.\n";
}

// === Register systemd service ===
$serviceName = strtolower("{$env}-{$role}.service");
$sourceFile = "$vmHome/Cinemaniacs/dev/systemd/$serviceName";
$targetFile = "/etc/systemd/system/$serviceName";

if (file_exists($sourceFile)) {
    copy($sourceFile, $targetFile);
    shell_exec("chmod 644 $targetFile");
    echo "[INIT] Installed system service $serviceName\n";

    shell_exec("systemctl daemon-reexec");
    shell_exec("systemctl daemon-reload");
    shell_exec("systemctl enable $serviceName");
    shell_exec("systemctl start $serviceName");
    echo "[INIT] Enabled and started $serviceName\n";
} else {
    echo "[WARNING] Service file not found: $sourceFile\n";
}

?>
