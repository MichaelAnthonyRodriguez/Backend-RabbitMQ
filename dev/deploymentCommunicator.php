#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

date_default_timezone_set("America/New_York");

// === RabbitMQ Client Call: Get new bundles ===
function requestNewBundlesFromDeployment($target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'get_new_bundles',
        'env' => 'qa'
    ]);

    if (isset($response['bundles'])) {
        echo "New Bundles:";
        foreach ($response['bundles'] as $bundle) {
            echo "- {$bundle['name']} v{$bundle['version']}";
        }
    } else {
        echo "No new bundles found.";
    }
}

// === RabbitMQ Client Call: Send bundle result (pass/fail) ===
function sendBundleResultToDeployment($name, $version, $status, $target = "deploymentServer") {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", $target);
    $response = $client->send_request([
        'action' => 'bundle_result',
        'name' => $name,
        'version' => (int)$version,
        'status' => $status
    ]);

    echo "[DEPLOYMENT] Result sent for $name v$version: $status\n";
    print_r($response);
}

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

// === RabbitMQ Client Call: Create and send a bundle ===
function createBundleTarball($bundleName, $version) {
    $homeDir = getenv('HOME');
    $username = getenv('USER');
    $bundleFilename = "{$bundleName}_v{$version}.tgz";
    $bundlePath = "/tmp/$bundleFilename";

    echo "[COMMUNICATOR] === Building Bundle: $bundleName v$version ===\n";

    // Load config
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

    // Create temp build folder
    $buildDir = "/tmp/bundle_build_$bundleName";
    if (is_dir($buildDir)) {
        shell_exec("rm -rf " . escapeshellarg($buildDir));
    }
    mkdir($buildDir, 0777, true);
    echo "[COMMUNICATOR] Created temporary build folder: $buildDir\n";

    // Now copy files based on config
    foreach ($config['files'] as $fileName => $sourceFolder) {
        // Replace [USER] placeholder with the real user's home directory
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

    // Copy bundle.ini into root
    if (!copy($configPath, "$buildDir/bundle.ini")) {
        echo "[ERROR] Failed to copy bundle.ini into bundle folder.\n";
        return null;
    }
    echo "[COMMUNICATOR] Added bundle.ini to bundle root.\n";

    // Create tar.gz archive
    $command = "tar -czf " . escapeshellarg($bundlePath) . " -C " . escapeshellarg($buildDir) . " .";
    shell_exec($command);

    if (!file_exists($bundlePath)) {
        echo "[ERROR] Failed to create tarball.\n";
        return null;
    }

    echo "[COMMUNICATOR] Tarball created successfully at: $bundlePath\n";

    // Cleanup build directory
    shell_exec("rm -rf " . escapeshellarg($buildDir));
    echo "[COMMUNICATOR] Cleaned up temporary build folder.\n";

    return $bundlePath;
}



function registerBundleMetadata($bundleName, $version, $size) {
    $client = new rabbitMQClient("deploymentRabbitMQ.ini", "deploymentServer");

    try {
        $response = $client->publish([
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

function sendBundleTarball($bundlePath, $bundleFilename) {
    $deployHost = "michael-anthony-rodriguez@100.105.162.20";
    $deployDest = "/home/michael-anthony-rodriguez/bundles/$bundleFilename";

    echo "[COMMUNICATOR] Sending tarball to deployment server...\n";
    $scpCommand = "scp $bundlePath $deployHost:$deployDest 2>&1";
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

// === CLI Entry Point ===
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? null;

    switch ($cmd) {
        case "get_new_bundles":
            requestNewBundlesFromDeployment();
            exit(0);

        case "bundle_result":
            if (isset($argv[2], $argv[3], $argv[4])) {
                sendBundleResultToDeployment($argv[2], $argv[3], $argv[4]);
                exit(0);
            } else {
                echo "Usage: php deploymentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
                exit(1);
            }

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

                // Clean up the tarball locally
                shell_exec("rm -f " . escapeshellarg($bundlePath));
                echo "[CLI] Local tarball cleaned up.\n";

                exit(0);
            } else {
                echo "Usage: php deploymentCommunicator.php create_bundle <bundleName>\n";
                exit(1);
            }

        default:
            echo "Unknown command.\n";
            echo "Available commands:\n";
            echo "  php deploymentCommunicator.php get_new_bundles\n";
            echo "  php deploymentCommunicator.php bundle_result <name> <version> <passed|failed>\n";
            echo "  php deploymentCommunicator.php create_bundle <bundleName>\n";
            exit(1);
    }
}
?>
