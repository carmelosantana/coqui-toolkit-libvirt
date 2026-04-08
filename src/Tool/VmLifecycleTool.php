<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Libvirt\Runtime\DependencyChecker;
use CoquiBot\Toolkits\Libvirt\Runtime\VirshRunner;
use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;
use CoquiBot\Toolkits\Libvirt\Xml\DomainXmlBuilder;

/**
 * VM lifecycle management — create, start, stop, destroy, snapshot, restore.
 */
final class VmLifecycleTool implements ToolInterface
{
    public function __construct(
        private readonly VirshRunner $virsh,
        private readonly VmStateStore $store,
        private readonly DependencyChecker $deps,
    ) {}

    public function name(): string
    {
        return 'vm_lifecycle';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage VM lifecycle — create, start, stop, reboot, destroy, delete, snapshot, restore.

            Actions:
            - create: Create a new VM with specified CPU, RAM, disk, and network configuration
            - start: Start a stopped/defined VM
            - stop: Graceful shutdown (ACPI)
            - force_stop: Force power off
            - reboot: Graceful reboot
            - destroy: Force stop (same as virsh destroy — does NOT delete)
            - delete: Undefine a VM and optionally remove its disk images
            - suspend: Pause a running VM
            - resume: Resume a suspended VM
            - snapshot: Create a snapshot of a VM
            - restore: Restore a VM to a snapshot
            - delete_snapshot: Delete a snapshot
            - check_deps: Check for required system dependencies
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
            'create' => $this->createVm($input),
            'start' => $this->startVm($input),
            'stop' => $this->stopVm($input),
            'force_stop', 'destroy' => $this->destroyVm($input),
            'reboot' => $this->rebootVm($input),
            'delete' => $this->deleteVm($input),
            'suspend' => $this->suspendVm($input),
            'resume' => $this->resumeVm($input),
            'snapshot' => $this->createSnapshot($input),
            'restore' => $this->restoreSnapshot($input),
            'delete_snapshot' => $this->deleteSnapshot($input),
            'check_deps' => $this->checkDeps(),
            default => ToolResult::error("Unknown vm_lifecycle action: '{$action}'"),
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
                            'description' => 'The lifecycle action to perform.',
                            'enum' => [
                                'create', 'start', 'stop', 'force_stop', 'destroy',
                                'reboot', 'delete', 'suspend', 'resume',
                                'snapshot', 'restore', 'delete_snapshot', 'check_deps',
                            ],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'VM name. Required for all actions except check_deps.',
                        ],
                        'memory' => [
                            'type' => 'integer',
                            'description' => 'RAM in MiB (default: 2048). Used with "create".',
                        ],
                        'vcpus' => [
                            'type' => 'integer',
                            'description' => 'Virtual CPUs (default: 2). Used with "create".',
                        ],
                        'disk_path' => [
                            'type' => 'string',
                            'description' => 'Path to existing disk image. Used with "create". Create one first with vm_storage.',
                        ],
                        'disk_size' => [
                            'type' => 'string',
                            'description' => 'Disk size (e.g. "20G", "50G"). Used with "create" when disk_path is not given.',
                        ],
                        'iso' => [
                            'type' => 'string',
                            'description' => 'Path to ISO for CD-ROM. Used with "create" for OS installation.',
                        ],
                        'network' => [
                            'type' => 'string',
                            'description' => 'Network name (default: "default"). Used with "create".',
                        ],
                        'os_variant' => [
                            'type' => 'string',
                            'description' => 'OS variant hint for optimization (e.g. "ubuntu22.04", "win11"). Used with "create".',
                        ],
                        'pci_devices' => [
                            'type' => 'array',
                            'description' => 'PCI addresses for GPU passthrough (e.g. ["0000:06:00.0"]). Used with "create".',
                            'items' => ['type' => 'string'],
                        ],
                        'shared_folders' => [
                            'type' => 'array',
                            'description' => 'Shared folders to mount. Each item: {"host_path": "...", "mount_tag": "...", "driver": "9p|virtiofs"}. Used with "create".',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'host_path' => ['type' => 'string'],
                                    'mount_tag' => ['type' => 'string'],
                                    'driver' => ['type' => 'string', 'enum' => ['9p', 'virtiofs']],
                                ],
                            ],
                        ],
                        'boot_dev' => [
                            'type' => 'string',
                            'description' => 'Boot device: "hd" (default) or "cdrom". Used with "create".',
                            'enum' => ['hd', 'cdrom'],
                        ],
                        'snapshot_name' => [
                            'type' => 'string',
                            'description' => 'Snapshot name. Used with "snapshot", "restore", "delete_snapshot".',
                        ],
                        'snapshot_description' => [
                            'type' => 'string',
                            'description' => 'Snapshot description. Used with "snapshot".',
                        ],
                        'remove_storage' => [
                            'type' => 'boolean',
                            'description' => 'Remove disk images when deleting a VM. Default: false. Used with "delete".',
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
    private function createVm(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for creating a VM.');
        }

        $memory = (int) ($input['memory'] ?? 2048);
        $vcpus = (int) ($input['vcpus'] ?? 2);
        $diskPath = trim((string) ($input['disk_path'] ?? ''));
        $diskSize = trim((string) ($input['disk_size'] ?? '20G'));
        $iso = trim((string) ($input['iso'] ?? ''));
        $network = trim((string) ($input['network'] ?? 'default'));
        $osVariant = trim((string) ($input['os_variant'] ?? ''));
        $bootDev = trim((string) ($input['boot_dev'] ?? ''));
        /** @var list<string> $pciDevices */
        $pciDevices = is_array($input['pci_devices'] ?? null) ? $input['pci_devices'] : [];
        /** @var list<array{host_path: string, mount_tag: string, driver?: string}> $sharedFolders */
        $sharedFolders = is_array($input['shared_folders'] ?? null) ? $input['shared_folders'] : [];

        // Create disk if not provided
        if ($diskPath === '') {
            $diskDir = $this->virsh->workspacePath() . '/libvirt/disks';
            if (!is_dir($diskDir)) {
                mkdir($diskDir, 0755, true);
            }
            $diskPath = $diskDir . "/{$name}.qcow2";

            if (!is_file($diskPath)) {
                $diskResult = $this->virsh->createDiskImage($diskPath, $diskSize, 'qcow2');
                if (!$diskResult->success()) {
                    return ToolResult::error("Failed to create disk image: " . $diskResult->error());
                }
            }
        }

        // Build domain XML
        $builder = (new DomainXmlBuilder())
            ->name($name)
            ->memory($memory)
            ->vcpus($vcpus)
            ->disk($diskPath, 'qcow2', 'virtio')
            ->network($network, 'network', 'virtio')
            ->vnc(-1, '127.0.0.1');

        if ($iso !== '') {
            $builder = $builder->cdrom($iso);
            if ($bootDev === '' || $bootDev === 'cdrom') {
                $builder = $builder->bootDev('cdrom');
            }
        }

        if ($bootDev !== '' && ($iso === '' || $bootDev !== 'cdrom')) {
            $builder = $builder->bootDev($bootDev);
        }

        // Add PCI passthrough devices
        foreach ($pciDevices as $pciAddr) {
            $builder = $builder->hostdev((string) $pciAddr);
        }

        // If we have PCI devices, enable CPU host-passthrough
        if ($pciDevices !== []) {
            $builder = $builder->cpuHostPassthrough(true);
        }

        // Add shared folders
        foreach ($sharedFolders as $folder) {
            $hostPath = (string) $folder['host_path'];
            $mountTag = (string) $folder['mount_tag'];
            $driver = (string) ($folder['driver'] ?? '9p');
            if ($hostPath !== '' && $mountTag !== '') {
                $builder = $builder->filesystem($hostPath, $mountTag, $driver);
            }
        }

        $xml = $builder->build();

        // Write XML to temp file for virsh define
        $xmlDir = $this->virsh->workspacePath() . '/libvirt/xml';
        if (!is_dir($xmlDir)) {
            mkdir($xmlDir, 0755, true);
        }
        $xmlPath = $xmlDir . "/{$name}.xml";
        file_put_contents($xmlPath, $xml);

        // Optional: validate XML
        $this->virsh->validateXml($xmlPath);

        // Define the VM
        $result = $this->virsh->virsh('define', [$xmlPath]);
        if (!$result->success()) {
            return ToolResult::error("Failed to define VM '{$name}':\n" . $result->error());
        }

        // Track in store
        $this->store->saveVm($name, 'defined', md5($xml), json_encode([
            'memory' => $memory,
            'vcpus' => $vcpus,
            'disk_path' => $diskPath,
            'iso' => $iso,
            'network' => $network,
            'os_variant' => $osVariant,
            'pci_devices' => $pciDevices,
            'shared_folders' => $sharedFolders,
        ], JSON_THROW_ON_ERROR));

        $output = "## VM Created: {$name}\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **State** | Defined (not running) |\n";
        $output .= "| **Memory** | {$memory} MiB |\n";
        $output .= "| **vCPUs** | {$vcpus} |\n";
        $output .= "| **Disk** | {$diskPath} |\n";
        $output .= "| **Network** | {$network} |\n";

        if ($iso !== '') {
            $output .= "| **ISO** | {$iso} |\n";
        }
        if ($pciDevices !== []) {
            $output .= "| **PCI Passthrough** | " . implode(', ', $pciDevices) . " |\n";
        }
        if ($sharedFolders !== []) {
            $tags = array_map(static fn(array $f): string => (string) $f['mount_tag'], $sharedFolders);
            $output .= "| **Shared Folders** | " . implode(', ', $tags) . " |\n";
        }

        $output .= "\nUse `vm_lifecycle` action `start` with name `{$name}` to boot the VM.";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function startVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('start', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to start VM '{$name}':\n" . $result->error());
        }

        $this->store->saveVm($name, 'running');

        return ToolResult::success("VM '{$name}' started successfully.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stopVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('shutdown', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to shutdown VM '{$name}':\n" . $result->error());
        }

        $this->store->saveVm($name, 'shutting-down');

        return ToolResult::success("Shutdown signal sent to VM '{$name}'. Use `vm_info` to check when it has stopped.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function destroyVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('destroy', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to force-stop VM '{$name}':\n" . $result->error());
        }

        $this->store->saveVm($name, 'stopped');

        return ToolResult::success("VM '{$name}' force-stopped (destroyed). The VM definition is preserved.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rebootVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('reboot', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to reboot VM '{$name}':\n" . $result->error());
        }

        return ToolResult::success("Reboot signal sent to VM '{$name}'.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $removeStorage = (bool) ($input['remove_storage'] ?? false);

        // First destroy if running
        $this->virsh->virsh('destroy', [$name], timeout: 5);

        // Undefine with or without storage removal
        $args = $removeStorage
            ? [$name, '--remove-all-storage', '--nvram']
            : [$name, '--nvram'];

        $result = $this->virsh->virsh('undefine', $args);
        if (!$result->success()) {
            // Retry without --nvram (not all VMs have NVRAM)
            $args = $removeStorage
                ? [$name, '--remove-all-storage']
                : [$name];
            $result = $this->virsh->virsh('undefine', $args);
        }

        if (!$result->success()) {
            return ToolResult::error("Failed to delete VM '{$name}':\n" . $result->error());
        }

        $this->store->deleteVm($name);

        $msg = "VM '{$name}' deleted (undefined).";
        if ($removeStorage) {
            $msg .= ' Associated storage volumes were removed.';
        }

        return ToolResult::success($msg);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function suspendVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('suspend', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to suspend VM '{$name}':\n" . $result->error());
        }

        $this->store->saveVm($name, 'paused');

        return ToolResult::success("VM '{$name}' suspended (paused).");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resumeVm(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $result = $this->virsh->virsh('resume', [$name]);
        if (!$result->success()) {
            return ToolResult::error("Failed to resume VM '{$name}':\n" . $result->error());
        }

        $this->store->saveVm($name, 'running');

        return ToolResult::success("VM '{$name}' resumed.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createSnapshot(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $snapshotName = trim((string) ($input['snapshot_name'] ?? ''));
        if ($snapshotName === '') {
            $snapshotName = 'snap-' . date('Ymd-His');
        }

        $description = trim((string) ($input['snapshot_description'] ?? ''));

        $args = [$name, '--name', $snapshotName];
        if ($description !== '') {
            $args[] = '--description';
            $args[] = $description;
        }

        $result = $this->virsh->virsh('snapshot-create-as', $args);
        if (!$result->success()) {
            return ToolResult::error("Failed to create snapshot:\n" . $result->error());
        }

        return ToolResult::success("Snapshot '{$snapshotName}' created for VM '{$name}'.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function restoreSnapshot(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $snapshotName = trim((string) ($input['snapshot_name'] ?? ''));
        if ($snapshotName === '') {
            return ToolResult::error('The "snapshot_name" parameter is required for restore.');
        }

        $result = $this->virsh->virsh('snapshot-revert', [$name, $snapshotName]);
        if (!$result->success()) {
            return ToolResult::error("Failed to restore snapshot:\n" . $result->error());
        }

        return ToolResult::success("VM '{$name}' restored to snapshot '{$snapshotName}'.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteSnapshot(array $input): ToolResult
    {
        $name = $this->requireName($input);
        if ($name === null) {
            return ToolResult::error('The "name" parameter is required.');
        }

        $snapshotName = trim((string) ($input['snapshot_name'] ?? ''));
        if ($snapshotName === '') {
            return ToolResult::error('The "snapshot_name" parameter is required for delete_snapshot.');
        }

        $result = $this->virsh->virsh('snapshot-delete', [$name, $snapshotName]);
        if (!$result->success()) {
            return ToolResult::error("Failed to delete snapshot:\n" . $result->error());
        }

        return ToolResult::success("Snapshot '{$snapshotName}' deleted from VM '{$name}'.");
    }

    private function checkDeps(): ToolResult
    {
        $report = $this->deps->check();

        $output = "## Dependency Check\n\n";

        if ($report['ready']) {
            $output .= "**Status:** Ready — all required dependencies available.\n\n";
        } else {
            $output .= "**Status:** Not ready — missing required dependencies.\n\n";
        }

        $output .= "**KVM:** " . ($report['kvm_available'] ? 'Available (/dev/kvm accessible)' : 'Not available') . "\n\n";

        if ($report['available'] !== []) {
            $output .= "### Available\n\n";
            $output .= "| Tool | Version | Description |\n|------|---------|-------------|\n";
            foreach ($report['available'] as $dep) {
                $output .= "| `{$dep['name']}` | {$dep['version']} | {$dep['description']} |\n";
            }
            $output .= "\n";
        }

        if ($report['missing'] !== []) {
            $output .= "### Missing\n\n";
            $output .= "| Tool | Required | Description | Install |\n|------|----------|-------------|---------|   \n";
            foreach ($report['missing'] as $dep) {
                $req = $dep['required'] ? 'Yes' : 'Optional';
                $output .= "| `{$dep['name']}` | {$req} | {$dep['description']} | `{$dep['install']}` |\n";
            }
        }

        return $report['ready'] ? ToolResult::success($output) : ToolResult::error($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireName(array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
