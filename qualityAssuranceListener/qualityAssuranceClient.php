#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');  // Includes the RabbitMQ Library
date_default_timezone_set("America/New_York");

//Deployment php server for vm communication

//Functions
function qaListener() {
    $server = new rabbitMQServer("qa", "deploymentRabbitMQ.ini");
    $server->process_requests("handleQaMessage");
}


function handleQaMessage($payload) {
    if ($payload['action'] === 'check_for_bundles') {
        // Ask deploy server for bundles to install
        $client = new rabbitMQClient("deploymentServer", "deploymentServer");
        $response = $client->send_request(['action' => 'get_new_bundles']);

        foreach ($response['bundles'] as $bundle) {
            $name = $bundle['name'];
            $version = $bundle['version'];
            $bundleFile = "$name-v$version.tgz";

            // SCP the bundle
            $remotePath = "/deploy/bundles/$bundleFile";
            $localTemp = "/tmp/$bundleFile";
            shell_exec("scp webdev@100.105.162.20:$remotePath $localTemp");

            // Extract and install
            $extractPath = "/tmp/{$name}_v{$version}";
            shell_exec("mkdir -p $extractPath && tar -xzf $localTemp -C $extractPath");

            $ini = parse_ini_file("$extractPath/bundle.ini", true);
            try {
                foreach ($ini['files']['copy'] as $file) {
                    shell_exec("cp $extractPath/$file {$ini['files']['destination']}/$file");
                }

                foreach ($ini['commands']['post_install'] as $cmd) {
                    shell_exec($cmd);
                }

                foreach ($ini['commands']['restart'] as $svc) {
                    shell_exec("systemctl restart $svc");
                }

                // Notify deploy server of success
                $client->publish([
                    'action' => 'bundle_result',
                    'name' => $name,
                    'version' => $version,
                    'status' => 'passed'
                ]);
            } catch (Exception $e) {
                // Notify deploy server of failure
                $client->publish([
                    'action' => 'bundle_result',
                    'name' => $name,
                    'version' => $version,
                    'status' => 'failed'
                ]);
            }
        }
    }
}
?>