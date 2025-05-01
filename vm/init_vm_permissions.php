#!/usr/bin/php
<?php
// === Ensure script is run as root ===
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php\n";
    exit(1);
}

// === Constants ===
$deploymentUser = 'michael-anthony-rodriguez';
$deploymentHost = '100.105.162.20';
$deploymentKeyPath = "/home/$deploymentUser/.ssh/id_rsa.pub";

$vmUser = getenv('SUDO_USER') ?: getenv('USER');
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";
$vmWebDir = "/var/www/sample";

// === Ensure SSH is installed and running ===
shell_exec("apt update && apt install -y openssh-server");
shell_exec("systemctl enable ssh");
shell_exec("systemctl start ssh");
echo "[INIT] OpenSSH server installed and running.\n";

// === Set /var/www/sample permissions ===
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");
echo "[INIT] Web directory permissions set.\n";

// === Set up authorized_keys ===
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
}
$publicKey = trim(shell_exec("ssh -o StrictHostKeyChecking=no $deploymentUser@$deploymentHost 'cat ~/.ssh/id_rsa.pub'"));
if (!$publicKey) {
    echo "[ERROR] Could not fetch public key from deployment server.\n";
    exit(1);
}
if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] Public key installed.\n";
} else {
    echo "[INIT] Public key already present.\n";
}
echo "[INIT] VM initialization complete.\n";
?>
