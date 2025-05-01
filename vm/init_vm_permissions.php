#!/usr/bin/php
<?php
// === Ensure script is run as root ===
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php\n";
    exit(1);
}

// === Define constants ===
$deploymentUser = 'michael-anthony-rodriguez';
$deploymentHost = '100.105.162.20';
$deploymentKeyPath = '/home/' . $deploymentUser . '/.ssh/id_rsa.pub';
$vmWebDir = '/var/www/sample';
$vmUser = getenv('SUDO_USER') ?: getenv('USER'); // Who invoked sudo
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";

// === Step 1: Set permissions on /var/www/sample ===
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
    echo "[INIT] Created directory: $vmWebDir\n";
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");
echo "[INIT] Set ownership and permissions on $vmWebDir\n";

// === Step 2: Install public SSH key ===
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
    echo "[INIT] Created SSH directory: $vmSshDir\n";
}

// Fetch public key from deployment server
$publicKey = trim(shell_exec("ssh $deploymentUser@$deploymentHost 'cat ~/.ssh/id_rsa.pub'"));

if (!$publicKey) {
    echo "[ERROR] Could not retrieve public key from deployment server\n";
    exit(1);
}

// Append key if not present
if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] Deployment SSH key added to $authKeysFile\n";
} else {
    echo "[INIT] Deployment key already present in $authKeysFile\n";
}

echo "[INIT] VM initialization complete.\n";
