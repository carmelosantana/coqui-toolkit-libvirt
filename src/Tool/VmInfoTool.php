<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Libvirt\Runtime\VirshRunner;
use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;

/**
 * Query VM and host information — list, status, domxml, hardware, snapshots.
 */
final class VmInfoTool implements ToolInterface
{
    public function __construct(
        private readonly VirshRunner $virsh,
        private readonly VmStateStore $store,
    ) {}

    public function name(): string
    {
        return 'vm_info';
    }

    public function description(): string
    {
        return <<<'DESC'
            Query VM and host information.

            Actions:
            - info: Detailed info about a specific VM (state, CPU, memory, IPs)
            - list: List all VMs (running, stopped, defined)
            - domxml: Get the raw libvirt domain XML for a VM
            - hardware: Host hardware info (CPUs, RAM, GPUs, IOMMU)
            - snapshots: List snapshots for a VM
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
            'info' => $this->vmInfo($input),
            'list' => $this->listVms(),
            'domxml' => $this->domXml($input),
            'hardware' => $this->hardwareInfo(),
            'snapshots' => $this->listSnapshots($input),
            default => ToolResult::error("Unknown vm_info action: '{$action}'"),
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
                            'description' => 'The info action to perform.',
                            'enum' => ['info', 'list', 'domxml', 'hardware', 'snapshots'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'VM name. Required for "info", "domxml", "snapshots".',
                        ],
                        'all' => [
                            'type' => 'boolean',
                            'description' => 'Include all VMs (running + stopped + defined). Default: true. Used with "list".',
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
    private function vmInfo(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "info".');
        }

        $result = $this->virsh->virsh('dominfo', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to get info for VM '{$name}':\n" . $result->error());
        }

        $info = $result->parseKeyValue();

        $output = "## VM: {$name}\n\n";
        $output .= "| Property | Value |\n|----------|-------|\n";
        foreach ($info as $key => $value) {
            $output .= "| **{$key}** | {$value} |\n";
        }

        // Try to get IP addresses via domifaddr
        $ifResult = $this->virsh->virsh('domifaddr', [$name], timeout: 5);
        if ($ifResult->success() && trim($ifResult->output()) !== '') {
            $output .= "\n### Network Interfaces\n\n";
            $output .= "```\n" . trim($ifResult->output()) . "\n```\n";
        }

        // Add bot-tracked metadata if available
        $stored = $this->store->getVm($name);
        if ($stored !== null && isset($stored['meta'])) {
            $meta = json_decode((string) $stored['meta'], true);
            if (is_array($meta) && $meta !== []) {
                $output .= "\n### Bot Metadata\n\n";
                if (isset($meta['disk_path'])) {
                    $output .= "- **Disk Path:** {$meta['disk_path']}\n";
                }
                if (isset($meta['iso']) && $meta['iso'] !== '') {
                    $output .= "- **ISO:** {$meta['iso']}\n";
                }
                if (isset($meta['pci_devices']) && $meta['pci_devices'] !== []) {
                    $output .= "- **PCI Passthrough:** " . implode(', ', $meta['pci_devices']) . "\n";
                }
            }
        }

        return ToolResult::success($output);
    }

    private function listVms(): ToolResult
    {
        $result = $this->virsh->virsh('list', ['--all']);
        if (!$result->success()) {
            return ToolResult::error("Failed to list VMs:\n" . $result->error());
        }

        $rows = $result->parseTable();

        if ($rows === []) {
            return ToolResult::success("## Virtual Machines\n\nNo VMs found.");
        }

        $output = "## Virtual Machines\n\n";
        $output .= "| ID | Name | State |\n|----|------|-------|\n";

        foreach ($rows as $row) {
            $id = $row['Id'] ?? $row['id'] ?? '-';
            $name = $row['Name'] ?? $row['name'] ?? '-';
            $state = $row['State'] ?? $row['state'] ?? '-';
            $output .= "| {$id} | `{$name}` | {$state} |\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function domXml(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "domxml".');
        }

        $result = $this->virsh->virsh('dumpxml', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to get XML for VM '{$name}':\n" . $result->error());
        }

        return ToolResult::success("## Domain XML: {$name}\n\n```xml\n" . trim($result->output()) . "\n```");
    }

    private function hardwareInfo(): ToolResult
    {
        // Get virsh nodeinfo
        $nodeResult = $this->virsh->virsh('nodeinfo', []);

        $output = "## Host Hardware\n\n";

        if ($nodeResult->success()) {
            $info = $nodeResult->parseKeyValue();
            $output .= "### Node Info\n\n";
            $output .= "| Property | Value |\n|----------|-------|\n";
            foreach ($info as $key => $value) {
                $output .= "| **{$key}** | {$value} |\n";
            }
            $output .= "\n";
        }

        // Get virsh capabilities summary
        $capsResult = $this->virsh->virsh('capabilities', [], timeout: 10);
        if ($capsResult->success()) {
            // Just note that capabilities are available, don't dump the full XML
            $output .= "Libvirt capabilities available. Use `virsh capabilities` for full XML.\n\n";
        }

        // Get storage pools
        $poolResult = $this->virsh->virsh('pool-list', ['--all']);
        if ($poolResult->success() && trim($poolResult->output()) !== '') {
            $output .= "### Storage Pools\n\n```\n" . trim($poolResult->output()) . "\n```\n\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listSnapshots(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "snapshots".');
        }

        $result = $this->virsh->virsh('snapshot-list', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to list snapshots for VM '{$name}':\n" . $result->error());
        }

        $raw = trim($result->output());
        if ($raw === '') {
            return ToolResult::success("## Snapshots: {$name}\n\nNo snapshots found.");
        }

        return ToolResult::success("## Snapshots: {$name}\n\n```\n{$raw}\n```");
    }
}
