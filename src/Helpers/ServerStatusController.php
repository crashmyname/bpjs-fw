<?php
namespace Bpjs\Framework\Helpers;
class ServerStatusController
{
    public static function getStatus()
    {
        $cpuLoad = shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2 + $4}'");
        $memoryUsage = shell_exec("free -m | awk '/Mem:/ { print $3 }'");
        $diskUsage = shell_exec("df -h | awk \"\$NF==\\\"/\\\"{printf \\\"%d\\\", \$5}\"");

        return [
            'cpu_load' => trim($cpuLoad),
            'memory_usage' => trim($memoryUsage),
            'disk_usage' => trim($diskUsage)
        ];
    }
}

