#!/usr/bin/php
<?php
// === Ensure script is run as root ===
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php\n";
    exit(1);
}

// === Identify local VM user ===
$vmUser = getenv('SUDO_USER') ?: getenv('USER'); // The actual user running the sudo
$vmHome = "/home/$vmUser";
$vmSshDir = "$vmHome/.ssh";
$authKeysFile = "$vmSshDir/authorized_keys";
$vmWebDir = '/var/www/sample';

// === Prompt for deployment username and IP (optional)
$deploymentUser = readline("[INPUT] Enter deployment SSH username: ");
$deploymentHost = readline("[INPUT] Enter deployment server IP: ");
$deploymentTarget = "$deploymentUser@$deploymentHost";

// === Step 1: Install OpenSSH server ===
echo "[INIT] Installing openssh-server...\n";
shell_exec("apt update && apt install -y openssh-server");

// === Step 2: Enable and start SSH service ===
echo "[INIT] Enabling and starting ssh service...\n";
shell_exec("systemctl enable ssh");
shell_exec("systemctl start ssh");

// === Step 3: Set permissions on /var/www/sample ===
if (!is_dir($vmWebDir)) {
    mkdir($vmWebDir, 0775, true);
    echo "[INIT] Created directory: $vmWebDir\n";
}
shell_exec("chown -R $vmUser:www-data $vmWebDir");
shell_exec("chmod -R 775 $vmWebDir");
echo "[INIT] Set ownership and permissions on $vmWebDir\n";

// === Step 4: Setup ~/.ssh and authorized_keys
if (!is_dir($vmSshDir)) {
    mkdir($vmSshDir, 0700, true);
    chown($vmSshDir, $vmUser);
    echo "[INIT] Created SSH directory: $vmSshDir\n";
}

// === Step 5: Fetch deployment public key
echo "[INIT] Fetching SSH key from $deploymentTarget...\n";
$publicKey = trim(shell_exec("ssh $deploymentTarget 'cat ~/.ssh/id_rsa.pub'"));

if (!$publicKey) {
    echo "[ERROR] Could not retrieve public key from deployment server.\n";
    exit(1);
}

// === Step 6: Write the key if it's not already there
if (!file_exists($authKeysFile) || strpos(file_get_contents($authKeysFile), $publicKey) === false) {
    file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND | LOCK_EX);
    chmod($authKeysFile, 0600);
    chown($authKeysFile, $vmUser);
    echo "[INIT] Deployment SSH key added to $authKeysFile\n";
} else {
    echo "[INIT] Deployment key already present in $authKeysFile\n";
}

echo "[INIT] VM initialization complete.\n";
