#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

date_default_timezone_set("America/New_York");

// === Get latest version number for a bundle ===
function getLatestVersionNumber($bundleName) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $response = $client->send_request([
            'action' => 'get_latest_bundle_any_status',
            'name' => $bundleName
        ]);

        if (isset($response['version'])) {
            echo "[COMMUNICATOR] Latest version found: {$response['version']}\n";
            return (int)$response['version'];
        } else {
            echo "[COMMUNICATOR] No previous version found.\n";
            return 0;
        }
    } catch (Exception $e) {
        echo "[ERROR] Exception while getting latest version: " . $e->getMessage() . "\n";
        return null;
    }
}

// === Get latest bundle by status (new or passed) ===
function getLatestBundleByStatus($bundleName, $status = 'new') {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $response = $client->send_request([
            'action' => 'get_latest_bundle_any_status',
            'name' => $bundleName
        ]);
    } catch (Exception $e) {
        echo "[ERROR] Exception while retrieving bundle info: " . $e->getMessage() . "\n";
        return null;
    }

    if (!isset($response['version']) || !isset($response['status'])) {
        echo "[ERROR] No bundles found for $bundleName\n";
        return null;
    }

    if ($response['status'] !== $status) {
        echo "[ERROR] Latest bundle is not marked as '$status'\n";
        return null;
    }

    return (int)$response['version'];
}

// === Build bundle tarball from config ===
function createBundleTarball($bundleName, $version) {
    $homeDir = getenv('HOME');
    $username = getenv('USER');
    $bundleFilename = "{$bundleName}_v{$version}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    echo "[COMMUNICATOR] === Building Bundle: $bundleName v$version ===\n";

    $configPath = __DIR__ . "/configs/{$bundleName}.ini";
    if (!file_exists($configPath)) {
        echo "[ERROR] Config file not found: $configPath\n";
        return null;
    }

    $config = parse_ini_file($configPath, true);
    if (!$config || !isset($config['files'])) {
        echo "[ERROR] Invalid config or missing [files] section.\n";
        return null;
    }

    $buildDir = "/tmp/bundle_build_$bundleName";
    if (is_dir($buildDir)) {
        shell_exec("rm -rf " . escapeshellarg($buildDir));
    }
    mkdir($buildDir, 0777, true);
    echo "[COMMUNICATOR] Created temporary build folder: $buildDir\n";

    foreach ($config['files'] as $fileName => $sourceFolder) {
        $sourceFolder = str_replace("[USER]", $username, $sourceFolder);
        $sourceFolder = str_replace("~", $homeDir, $sourceFolder);
        $fullSourcePath = rtrim($sourceFolder, '/') . '/' . ltrim($fileName, '/');

        if (!file_exists($fullSourcePath)) {
            echo "[WARNING] Source file missing: $fullSourcePath\n";
            continue;
        }

        $destinationFile = "$buildDir/" . basename($fileName);

        if (copy($fullSourcePath, $destinationFile)) {
            echo "[COMMUNICATOR] Copied: $fullSourcePath -> $destinationFile\n";
        } else {
            echo "[ERROR] Failed to copy: $fullSourcePath\n";
        }
    }

    if (!copy($configPath, "$buildDir/bundle.ini")) {
        echo "[ERROR] Failed to copy bundle.ini into bundle folder.\n";
        return null;
    }

    echo "[COMMUNICATOR] Added bundle.ini to bundle root.\n";

    $command = "tar -czf " . escapeshellarg($bundlePath) . " -C " . escapeshellarg($buildDir) . " .";
    shell_exec($command);

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Failed to create tarball.\n";
        return null;
    }

    echo "[COMMUNICATOR] Tarball created successfully at: $bundlePath\n";

    shell_exec("rm -rf " . escapeshellarg($buildDir));
    echo "[COMMUNICATOR] Cleaned up temporary build folder.\n";

    return $bundlePath;
}

// === SCP the bundle tarball to deployment server ===
function sendBundleTarball($bundlePath, $bundleFilename) {
    // === Manually configurable variables ===
    $deployUser = "michael-anthony-rodriguez";
    $deployHostIp = "100.105.162.20";
    // ========================================

    $deployHost = "$deployUser@$deployHostIp";
    $deployDest = "/home/$deployUser/bundles/$bundleFilename";

    echo "[COMMUNICATOR] Sending tarball to deployment server at $deployHost...\n";
    $scpCommand = "scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $bundlePath $deployHost:$deployDest 2>&1";
    $scpResult = shell_exec($scpCommand);

    echo "[DEBUG] SCP Output:\n$scpResult\n";

    if (strpos($scpResult, "No such file") !== false || strpos($scpResult, "Permission denied") !== false) {
        echo "[ERROR] SCP failed: $scpResult\n";
        return false;
    } else {
        echo "[COMMUNICATOR] Bundle transferred successfully.\n";
        return true;
    }
}


// === Publish bundle metadata to deployment server ===
function registerBundleMetadata($bundleName, $version, $size) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $client->publish([
            'action' => 'register_bundle',
            'name' => $bundleName,
            'version' => $version,
            'status' => 'new',
            'size' => $size
        ]);

        echo "Bundle registered\n";
        return true;
    } catch (Exception $e) {
        echo "[ERROR] Exception while registering bundle: " . $e->getMessage() . "\n";
        return false;
    }
}

// === Trigger bundle install on VM ===
function triggerInstallOnVm($env, $role, $bundleName, $status = 'new') {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    echo "[COMMUNICATOR] Instructing deployment server to deploy bundle '$bundleName' (status: $status) to $env.$role\n";

    try {
        $response = $client->send_request([
            'action' => 'deploy_bundle_to_vm',
            'env'    => $env,
            'role'   => $role,
            'bundleName' => $bundleName,
            'status' => $status
        ]);
    } catch (Exception $e) {
        echo "[ERROR] Exception during bundle deployment request: " . $e->getMessage() . "\n";
        return ["status" => "error", "message" => "Deployment request failed"];
    }

    echo "[COMMUNICATOR] Deployment server response:\n";
    print_r($response);
    return $response;
}

//update bundle status pass or fail
function markBundleStatus($bundleName, $newStatus) {
    if (!in_array($newStatus, ['passed', 'failed'])) {
        echo "[ERROR] Status must be either 'passed' or 'failed'\n";
        exit(1);
    }

    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");
    $client->publish([
        'action' => 'mark_bundle_status',
        'name' => $bundleName,
        'status' => $newStatus
    ]);

    echo "[CLI] Bundle status update request published for '$bundleName' -> '$newStatus'\n";
}


// === CLI Entry Point ===
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? null;

    switch ($cmd) {
        // Usage: install_bundle <QA|PROD> <role> <bundleName> <new|passed>
        case "install_bundle":
            if (isset($argv[2], $argv[3], $argv[4], $argv[5])) {
                $env = $argv[2];
                $role = $argv[3];
                $bundleName = $argv[4];
                $statusType = strtolower($argv[5]);

                if (!in_array($statusType, ['new', 'passed'])) {
                    echo "[ERROR] Invalid status type. Use 'new' or 'passed'.\n";
                    exit(1);
                }

                $result = triggerInstallOnVm($env, $role, $bundleName, $statusType);
                if (!$result || (isset($result['status']) && $result['status'] === 'error')) {
                    exit(1);
                }
                exit(0);
            } else {
                echo "Usage: php deploymentCommunicator.php install_bundle <QA|PROD> <role> <bundleName> <new|passed>\n";
                exit(1);
            }

        // Usage: create_bundle <bundleName>
        case "create_bundle":
            if (isset($argv[2])) {
                $bundleName = $argv[2];

                echo "[CLI] Getting latest version for bundle '$bundleName'...\n";
                $latestVersion = getLatestVersionNumber($bundleName);

                if ($latestVersion === null) {
                    echo "[ERROR] Cannot determine latest version for $bundleName.\n";
                    exit(1);
                }

                $newVersion = $latestVersion + 1;
                echo "[CLI] New version will be: $newVersion\n";

                $bundlePath = createBundleTarball($bundleName, $newVersion);
                if ($bundlePath === null) {
                    echo "[ERROR] Bundle tarball creation failed.\n";
                    exit(1);
                }

                if (!sendBundleTarball($bundlePath, basename($bundlePath))) {
                    echo "[ERROR] Failed to SCP bundle tarball to deployment server.\n";
                    exit(1);
                }

                $size = filesize($bundlePath);

                if (!registerBundleMetadata($bundleName, $newVersion, $size)) {
                    echo "[ERROR] Failed to register bundle metadata in deployment server.\n";
                    exit(1);
                }

                shell_exec("rm -f " . escapeshellarg($bundlePath));
                echo "[CLI] Local tarball cleaned up.\n";
                exit(0);
            } else {
                echo "Usage: php deploymentCommunicator.php create_bundle <bundleName>\n";
                exit(1);
            }
            
        case "mark_bundle":
            if (isset($argv[2], $argv[3])) {
                $bundleName = $argv[2];
                $newStatus = strtolower($argv[3]);
                markBundleStatus($bundleName, $newStatus);
                exit(0);
            } else {
                echo "Usage: php deploymentCommunicator.php mark_bundle <bundleName> <passed|failed>\n";
                exit(1);
            }
            
        default:
            echo "Unknown command.\n";
            echo "Usage:\n";
            echo "  php deploymentCommunicator.php create_bundle <bundleName>\n";
            echo "  php deploymentCommunicator.php install_bundle <QA|PROD> <frontend|backend|dmz> <bundleName> <new|passed>\n";
            echo "  php deploymentCommunicator.php mark_bundle <bundleName> <passed|failed>\n";

            exit(1);
    }
}
?>
