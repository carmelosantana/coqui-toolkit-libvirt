<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Libvirt\Runtime\VirshRunner;
use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;
use CoquiBot\Toolkits\Libvirt\Xml\NetworkXmlBuilder;

/**
 * Virtual network management — create, start, stop, destroy, list.
 */
final class VmNetworkTool implements ToolInterface
{
    public function __construct(
        private readonly VirshRunner $virsh,
        private readonly VmStateStore $store,
    ) {}

    public function name(): string
    {
        return 'vm_network';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage libvirt virtual networks.

            Actions:
            - create: Create and start a new virtual network (NAT, isolated, or bridged)
            - start: Start a stopped network
            - stop: Stop a running network
            - destroy: Remove a network definition
            - list: List all virtual networks
            - info: Get details about a specific network
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
            'create' => $this->createNetwork($input),
            'start' => $this->startNetwork($input),
            'stop' => $this->stopNetwork($input),
            'destroy' => $this->destroyNetwork($input),
            'list' => $this->listNetworks(),
            'info' => $this->networkInfo($input),
            default => ToolResult::error("Unknown vm_network action: '{$action}'"),
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
                            'description' => 'The network action to perform.',
                            'enum' => ['create', 'start', 'stop', 'destroy', 'list', 'info'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Network name. Required for all actions except "list".',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Network type. Used with "create". Default: "nat".',
                            'enum' => ['nat', 'isolated', 'bridged'],
                        ],
                        'subnet' => [
                            'type' => 'string',
                            'description' => 'CIDR subnet (e.g. "192.168.100.0/24"). Used with "create" for nat/isolated types.',
                        ],
                        'bridge' => [
                            'type' => 'string',
                            'description' => 'Bridge interface name (e.g. "br0"). Required when type is "bridged".',
                        ],
                        'dhcp' => [
                            'type' => 'boolean',
                            'description' => 'Enable DHCP. Default: true. Used with "create".',
                        ],
                        'autostart' => [
                            'type' => 'boolean',
                            'description' => 'Auto-start network on host boot. Default: true. Used with "create".',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createNetwork(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for creating a network.');
        }

        $type = (string) ($input['type'] ?? 'nat');
        $subnet = (string) ($input['subnet'] ?? '');
        $bridge = (string) ($input['bridge'] ?? '');
        $dhcp = (bool) ($input['dhcp'] ?? true);
        $autostart = (bool) ($input['autostart'] ?? true);

        $builder = (new NetworkXmlBuilder())
            ->name($name)
            ->dhcp($dhcp);

        $builder = match ($type) {
            'nat' => $builder->nat($subnet !== '' ? $subnet : '192.168.122.0/24'),
            'isolated' => $builder->isolated($subnet !== '' ? $subnet : '192.168.200.0/24'),
            'bridged' => $bridge !== ''
                ? $builder->bridged($bridge)
                : throw new \InvalidArgumentException('The "bridge" parameter is required for bridged networks.'),
            default => throw new \InvalidArgumentException("Unknown network type: '{$type}'"),
        };

        $xml = $builder->build();

        // Write XML to temp file
        $xmlDir = $this->virsh->workspacePath() . '/libvirt/xml';
        if (!is_dir($xmlDir)) {
            mkdir($xmlDir, 0755, true);
        }
        $xmlPath = $xmlDir . "/net-{$name}.xml";
        file_put_contents($xmlPath, $xml);

        // Define the network
        $result = $this->virsh->virsh('net-define', [$xmlPath]);
        if (!$result->success()) {
            return ToolResult::error("Failed to define network '{$name}':\n" . $result->error());
        }

        // Start the network
        $startResult = $this->virsh->virsh('net-start', [$name]);
        if (!$startResult->success()) {
            return ToolResult::error(
                "Network defined but failed to start:\n" . $startResult->error(),
            );
        }

        // Set autostart
        if ($autostart) {
            $this->virsh->virsh('net-autostart', [$name]);
        }

        // Track in store
        $this->store->saveNetwork($name, $type, $subnet);

        $output = "## Network Created: {$name}\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Type** | {$type} |\n";
        $output .= "| **State** | Active |\n";
        if ($subnet !== '') {
            $output .= "| **Subnet** | {$subnet} |\n";
        }
        if ($bridge !== '') {
            $output .= "| **Bridge** | {$bridge} |\n";
        }
        $output .= "| **DHCP** | " . ($dhcp ? 'Enabled' : 'Disabled') . " |\n";
        $output .= "| **Autostart** | " . ($autostart ? 'Yes' : 'No') . " |\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function startNetwork(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('net-start', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to start network '{$name}':\n" . $result->error());
        }

        return ToolResult::success("Network '{$name}' started.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stopNetwork(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('net-destroy', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to stop network '{$name}':\n" . $result->error());
        }

        return ToolResult::success("Network '{$name}' stopped.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function destroyNetwork(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        // Stop first if active
        $this->virsh->virsh('net-destroy', [$name], timeout: 5);

        $result = $this->virsh->virsh('net-undefine', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to remove network '{$name}':\n" . $result->error());
        }

        $this->store->deleteNetwork($name);

        return ToolResult::success("Network '{$name}' removed.");
    }

    private function listNetworks(): ToolResult
    {
        $result = $this->virsh->virsh('net-list', ['--all']);
        if (!$result->success()) {
            return ToolResult::error("Failed to list networks:\n" . $result->error());
        }

        $raw = trim($result->output());
        if ($raw === '') {
            return ToolResult::success("## Virtual Networks\n\nNo networks found.");
        }

        return ToolResult::success("## Virtual Networks\n\n```\n{$raw}\n```");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function networkInfo(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "info".');
        }

        $result = $this->virsh->virsh('net-info', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to get info for network '{$name}':\n" . $result->error());
        }

        $info = $result->parseKeyValue();

        $output = "## Network: {$name}\n\n";
        $output .= "| Property | Value |\n|----------|-------|\n";
        foreach ($info as $key => $value) {
            $output .= "| **{$key}** | {$value} |\n";
        }

        // Get DHCP leases if network is active
        $leasesResult = $this->virsh->virsh('net-dhcp-leases', [$name], timeout: 5);
        if ($leasesResult->success() && trim($leasesResult->output()) !== '') {
            $output .= "\n### DHCP Leases\n\n```\n" . trim($leasesResult->output()) . "\n```\n";
        }

        return ToolResult::success($output);
    }
}
