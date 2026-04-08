<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Libvirt\Runtime\ConsoleProxyRunner;

/**
 * Web console access via noVNC + websockify.
 */
final class VmConsoleTool implements ToolInterface
{
    public function __construct(
        private readonly ConsoleProxyRunner $console,
    ) {}

    public function name(): string
    {
        return 'vm_console';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage web console access to VMs via noVNC + websockify.

            Actions:
            - open: Start a websockify proxy and return the noVNC URL for browser-based console access
            - close: Stop the console proxy for a VM
            - status: Check if a console proxy is running for a VM
            DESC;
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        return match ($action) {
            'open' => $this->openConsole($input),
            'close' => $this->closeConsole($input),
            'status' => $this->consoleStatus($input),
            default => ToolResult::error("Unknown vm_console action: '{$action}'"),
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The console action to perform.',
                            'enum' => ['open', 'close', 'status'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'VM name. Required for all actions.',
                        ],
                        'ws_port' => [
                            'type' => 'integer',
                            'description' => 'WebSocket port for the proxy. Auto-detected starting from 6080 if not specified. Used with "open".',
                        ],
                    ],
                    'required' => ['action', 'name'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function openConsole(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $wsPort = (int) ($input['ws_port'] ?? 0);

        $result = $this->console->start($name, $wsPort);

        if (!$result['success']) {
            return ToolResult::error("## Console Open Failed\n\n" . $result['message']);
        }

        $output = "## Console Opened: {$name}\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **URL** | {$result['url']} |\n";
        $output .= "| **WebSocket Port** | {$result['ws_port']} |\n";
        $output .= "| **VNC Port** | {$result['vnc_port']} |\n";
        $output .= "\nOpen the URL in a browser to access the VM console.";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function closeConsole(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->console->stop($name);

        return $result['success']
            ? ToolResult::success($result['message'])
            : ToolResult::error($result['message']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function consoleStatus(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $status = $this->console->status($name);

        if (!$status['running']) {
            return ToolResult::success("No console proxy running for VM '{$name}'.");
        }

        $output = "## Console Status: {$name}\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Status** | Running |\n";
        $output .= "| **URL** | {$status['url']} |\n";
        $output .= "| **WebSocket Port** | {$status['ws_port']} |\n";
        $output .= "| **VNC Port** | {$status['vnc_port']} |\n";
        $output .= "| **PID** | {$status['pid']} |\n";

        return ToolResult::success($output);
    }
}
