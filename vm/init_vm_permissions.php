#!/usr/bin/php
<?php
if (posix_geteuid() !== 0) {
    echo "This script must be run with sudo: sudo php init_vm_permissions.php <env> [role]\n";
    exit(1);
}

$args = $argv;
array_shift($args);

if (count($args) < 1) {
    echo "Usage: sudo php init_vm_permissions.php <env> [role]\n";
    exit(1);
}

$env = strtolower($args[0]);
$role = isset($args[1]) ? strtolower($args[1]) : null;

$validEnvs = ['dev', 'deployment', 'qa', 'prod'];
$validRoles = ['frontend', 'backend', 'dmz'];

if (!in_array($env, $validEnvs)) {
    echo "[ERROR] Invalid environment. Use one of: dev, deployment, qa, prod\n";
    exit(1);
}

$vmUser = getenv('SUDO_USER') ?: getenv('USER');
$vmHome = "/home/$vmUser";

$serviceFiles = [];

if ($env === 'deployment') {
    $serviceFiles = ['deployment-vm.service', 'deployment-server.service'];
} elseif ($env === 'dev') {
    $serviceFiles = ['dev-vm.service'];
} else {
    if (!$role || !in_array($role, $validRoles)) {
        echo "[ERROR] For QA/PROD, provide a valid role: frontend, backend, or dmz\n";
        exit(1);
    }

    $prefix = "{$env}-{$role}";
    $serviceFiles = ["$prefix-vm.service", "$prefix.service"];

    if ($role === 'backend') {
        $serviceFiles[] = "backend-server.service";
    }
}

foreach ($serviceFiles as $serviceName) {
    $sourcePath = "$vmHome/Cinemaniacs/dev/systemd/$serviceName";
    $targetPath = "/etc/systemd/system/$serviceName";

    if (!file_exists($sourcePath)) {
        echo "[ERROR] Service file not found at $sourcePath\n";
        continue;
    }

    if (!copy($sourcePath, $targetPath)) {
        echo "[ERROR] Failed to copy $serviceName to /etc/systemd/system\n";
        continue;
    }

    shell_exec("chown root:root $targetPath");
    shell_exec("chmod 644 $targetPath");
    shell_exec("systemctl daemon-reexec");
    shell_exec("systemctl daemon-reload");
    shell_exec("systemctl enable $serviceName");

    if (str_contains($serviceName, '-vm.service')) {
        shell_exec("systemctl start $serviceName");
        echo "[INIT] $serviceName installed, enabled, and started (root).\n";
    } else {
        shell_exec("runuser -l $vmUser -c 'systemctl --user daemon-reexec'");
        shell_exec("runuser -l $vmUser -c 'systemctl --user daemon-reload'");
        shell_exec("runuser -l $vmUser -c 'systemctl --user enable $serviceName'");
        shell_exec("runuser -l $vmUser -c 'systemctl --user start $serviceName'");
        echo "[INIT] $serviceName installed, enabled, and started (as $vmUser).\n";
    }
}
?>
