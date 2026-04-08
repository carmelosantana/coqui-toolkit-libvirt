<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Runtime;

use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;

/**
 * Manages noVNC + websockify console proxy processes.
 *
 * Spawns websockify as a detached background process that bridges WebSocket
 * connections to a VM's VNC port. Tracks PIDs and proxy metadata in VmStateStore.
 */
final class ConsoleProxyRunner
{
    private const int DEFAULT_WS_PORT_START = 6080;
    private const int MAX_PORT_SCAN = 50;
    private const int STOP_TIMEOUT_MS = 2000;
    private const int STOP_CHECK_INTERVAL_MS = 50;

    public function __construct(
        private readonly string $workspacePath,
        private readonly VmStateStore $store,
        private readonly VirshRunner $virsh,
    ) {}

    /**
     * Start a websockify proxy for a VM's VNC display.
     *
     * @return array{success: false, message: string}|array{success: true, message: string, url: string, ws_port: int, vnc_port: int}
     */
    public function start(string $vmName, int $wsPort = 0): array
    {
        // Check if a proxy is already running for this VM
        $existing = $this->store->getProxy($vmName);
        if ($existing !== null && $this->isProcessAlive((int) $existing['pid'])) {
            return [
                'success' => false,
                'message' => "Console proxy already running for '{$vmName}' on port {$existing['ws_port']}.",
                'url' => "http://127.0.0.1:{$existing['ws_port']}/vnc.html?autoconnect=true",
                'ws_port' => (int) $existing['ws_port'],
            ];
        }

        // Get VNC port from virsh
        $vncPort = $this->resolveVncPort($vmName);
        if ($vncPort === null) {
            return [
                'success' => false,
                'message' => "Could not determine VNC port for '{$vmName}'. "
                    . 'Ensure the VM is running and has VNC graphics configured.',
            ];
        }

        // Find available WebSocket port
        if ($wsPort === 0) {
            $wsPort = $this->findAvailablePort(self::DEFAULT_WS_PORT_START);
            if ($wsPort === null) {
                return [
                    'success' => false,
                    'message' => 'Could not find an available port for the WebSocket proxy.',
                ];
            }
        }

        // Resolve websockify binary
        $websockify = $this->resolveWebsockify();
        if ($websockify === null) {
            return [
                'success' => false,
                'message' => "websockify not found. Install it with: pip3 install websockify\n"
                    . 'Or: apt install websockify',
            ];
        }

        // Resolve noVNC web directory (optional — serves the noVNC client)
        $novncDir = $this->resolveNoVncDir();

        // Ensure proxy directory exists
        $proxyDir = $this->proxyDir();
        if (!is_dir($proxyDir)) {
            mkdir($proxyDir, 0755, true);
        }

        $logFile = $proxyDir . "/{$vmName}.log";

        // Build websockify command
        $webFlag = $novncDir !== null ? ' --web=' . escapeshellarg($novncDir) : '';
        $command = sprintf(
            'nohup %s %d 127.0.0.1:%d%s > %s 2>&1 & echo $!',
            escapeshellarg($websockify),
            $wsPort,
            $vncPort,
            $webFlag,
            escapeshellarg($logFile),
        );

        $pid = $this->spawnProcess($command);
        if ($pid === null) {
            return ['success' => false, 'message' => 'Failed to start websockify process.'];
        }

        // Wait briefly and verify
        usleep(300_000);

        if (!$this->isProcessAlive($pid)) {
            $logContent = is_file($logFile) ? (file_get_contents($logFile) ?: 'No log output.') : 'No log output.';

            return [
                'success' => false,
                'message' => "websockify exited immediately.\n{$logContent}",
            ];
        }

        // Persist proxy info
        $this->store->saveProxy($vmName, $pid, $wsPort, $vncPort, json_encode([
            'started_at' => date('c'),
            'novnc_dir' => $novncDir,
            'log_file' => $logFile,
        ], JSON_THROW_ON_ERROR));

        $url = $novncDir !== null
            ? "http://127.0.0.1:{$wsPort}/vnc.html?autoconnect=true"
            : "ws://127.0.0.1:{$wsPort}";

        return [
            'success' => true,
            'message' => "Console proxy started for '{$vmName}'.",
            'url' => $url,
            'ws_port' => $wsPort,
            'vnc_port' => $vncPort,
        ];
    }

    /**
     * Stop the console proxy for a VM.
     *
     * @return array{success: bool, message: string}
     */
    public function stop(string $vmName): array
    {
        $proxy = $this->store->getProxy($vmName);
        if ($proxy === null) {
            return ['success' => true, 'message' => "No console proxy found for '{$vmName}'."];
        }

        $pid = (int) $proxy['pid'];
        if ($this->isProcessAlive($pid)) {
            posix_kill($pid, SIGTERM);

            $waited = 0;
            while ($waited < self::STOP_TIMEOUT_MS) {
                usleep(self::STOP_CHECK_INTERVAL_MS * 1000);
                $waited += self::STOP_CHECK_INTERVAL_MS;
                if (!$this->isProcessAlive($pid)) { // @phpstan-ignore booleanNot.alwaysFalse
                    break;
                }
            }

            if ($this->isProcessAlive($pid)) { // @phpstan-ignore if.alwaysTrue
                posix_kill($pid, SIGKILL);
                usleep(100_000);
            }
        }

        $this->store->deleteProxy($vmName);

        return ['success' => true, 'message' => "Console proxy stopped for '{$vmName}'."];
    }

    /**
     * Get status of a VM's console proxy.
     *
     * @return array{running: false}|array{running: true, url: string, ws_port: int, vnc_port: int, pid: int}
     */
    public function status(string $vmName): array
    {
        $proxy = $this->store->getProxy($vmName);
        if ($proxy === null) {
            return ['running' => false];
        }

        $pid = (int) $proxy['pid'];
        if (!$this->isProcessAlive($pid)) {
            $this->store->deleteProxy($vmName);

            return ['running' => false];
        }

        $wsPort = (int) $proxy['ws_port'];

        return [
            'running' => true,
            'url' => "http://127.0.0.1:{$wsPort}/vnc.html?autoconnect=true",
            'ws_port' => $wsPort,
            'vnc_port' => (int) $proxy['vnc_port'],
            'pid' => $pid,
        ];
    }

    /**
     * Determine the VNC port for a running VM.
     */
    private function resolveVncPort(string $vmName): ?int
    {
        $result = $this->virsh->virsh('vncdisplay', [$vmName]);
        if (!$result->success()) {
            return null;
        }

        $display = trim($result->output());
        // vncdisplay returns something like ":0" or "127.0.0.1:0"
        if (preg_match('/:(\d+)$/', $display, $matches)) {
            // VNC display number maps to port 5900 + N
            return 5900 + (int) $matches[1];
        }

        return null;
    }

    private function resolveWebsockify(): ?string
    {
        foreach (['websockify', '/usr/bin/websockify', '/usr/local/bin/websockify'] as $candidate) {
            $output = [];
            $exitCode = 0;
            exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0 && isset($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }

    private function resolveNoVncDir(): ?string
    {
        $candidates = [
            '/usr/share/novnc',
            '/usr/share/noVNC',
            '/usr/local/share/novnc',
            '/usr/local/share/noVNC',
            '/snap/novnc/current/usr/share/novnc',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir) && is_file($dir . '/vnc.html')) {
                return $dir;
            }
        }

        return null;
    }

    private function findAvailablePort(int $startPort): ?int
    {
        for ($port = $startPort; $port < $startPort + self::MAX_PORT_SCAN; $port++) {
            if ($this->isPortAvailable('127.0.0.1', $port)) {
                return $port;
            }
        }

        return null;
    }

    private function isPortAvailable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket !== false) {
            fclose($socket);

            return false;
        }

        return true;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    private function spawnProcess(string $command): ?int
    {
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !isset($output[0])) {
            return null;
        }

        $pid = (int) trim($output[0]);

        return $pid > 0 ? $pid : null;
    }

    private function proxyDir(): string
    {
        return $this->workspacePath . '/libvirt/proxies';
    }
}
