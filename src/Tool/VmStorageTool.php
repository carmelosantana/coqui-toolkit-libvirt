<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Libvirt\Runtime\HardwareDiscovery;
use CoquiBot\Toolkits\Libvirt\Runtime\VirshRunner;

/**
 * Disk image management, shared folders, and GPU passthrough.
 */
final class VmStorageTool implements ToolInterface
{
    private ?HardwareDiscovery $hardware = null;

    public function __construct(
        private readonly VirshRunner $virsh,
    ) {}

    public function name(): string
    {
        return 'vm_storage';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage disk images, shared folders, and GPU passthrough.

            Actions:
            - create_disk: Create a new disk image (qcow2/raw)
            - disk_info: Get information about a disk image
            - resize_disk: Resize an existing disk image
            - attach_disk: Attach a disk to a running or defined VM
            - shared_folder: Add a shared filesystem (9p or virtiofs) to a VM
            - gpu_passthrough: List GPUs, check IOMMU, or attach a GPU to a VM
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
            'create_disk' => $this->createDisk($input),
            'disk_info' => $this->diskInfo($input),
            'resize_disk' => $this->resizeDisk($input),
            'attach_disk' => $this->attachDisk($input),
            'shared_folder' => $this->sharedFolder($input),
            'gpu_passthrough' => $this->gpuPassthrough($input),
            default => ToolResult::error("Unknown vm_storage action: '{$action}'"),
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
                            'description' => 'The storage action to perform.',
                            'enum' => [
                                'create_disk', 'disk_info', 'resize_disk',
                                'attach_disk', 'shared_folder', 'gpu_passthrough',
                            ],
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Disk image path. Used with "create_disk", "disk_info", "resize_disk", "attach_disk".',
                        ],
                        'size' => [
                            'type' => 'string',
                            'description' => 'Disk size (e.g. "20G", "50G", "+10G" for resize). Used with "create_disk", "resize_disk".',
                        ],
                        'format' => [
                            'type' => 'string',
                            'description' => 'Disk format. Default: "qcow2". Used with "create_disk".',
                            'enum' => ['qcow2', 'raw', 'vmdk', 'vdi'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'VM name. Used with "attach_disk", "shared_folder".',
                        ],
                        'target' => [
                            'type' => 'string',
                            'description' => 'Target device name (e.g. "vdb", "sda"). Used with "attach_disk".',
                        ],
                        'host_path' => [
                            'type' => 'string',
                            'description' => 'Host directory path to share. Used with "shared_folder".',
                        ],
                        'mount_tag' => [
                            'type' => 'string',
                            'description' => 'Mount tag for the shared filesystem. Used with "shared_folder".',
                        ],
                        'driver' => [
                            'type' => 'string',
                            'description' => 'Shared folder driver: "9p" (default, wider compat) or "virtiofs" (faster). Used with "shared_folder".',
                            'enum' => ['9p', 'virtiofs'],
                        ],
                        'sub_action' => [
                            'type' => 'string',
                            'description' => 'Sub-action for "gpu_passthrough": "list_gpus", "check_iommu", "iommu_group".',
                            'enum' => ['list_gpus', 'check_iommu', 'iommu_group'],
                        ],
                        'pci_address' => [
                            'type' => 'string',
                            'description' => 'PCI address (e.g. "0000:06:00.0"). Used with "gpu_passthrough" sub_action "iommu_group".',
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
    private function createDisk(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for "create_disk".');
        }

        $size = trim((string) ($input['size'] ?? '20G'));
        $format = trim((string) ($input['format'] ?? 'qcow2'));

        // Ensure parent directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = $this->virsh->createDiskImage($path, $size, $format);
        if (!$result->success()) {
            return ToolResult::error("Failed to create disk image:\n" . $result->error());
        }

        $output = "## Disk Created\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Path** | {$path} |\n";
        $output .= "| **Size** | {$size} |\n";
        $output .= "| **Format** | {$format} |\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function diskInfo(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for "disk_info".');
        }

        $result = $this->virsh->diskImageInfo($path);
        if (!$result->success()) {
            return ToolResult::error("Failed to get disk info:\n" . $result->error());
        }

        $json = json_decode($result->output(), true);
        if (!is_array($json)) {
            return ToolResult::success("## Disk Info\n\n```\n" . $result->output() . "\n```");
        }

        $output = "## Disk Info: {$path}\n\n";
        $output .= "| Property | Value |\n|----------|-------|\n";
        $output .= '| **Format** | ' . ($json['format'] ?? 'unknown') . " |\n";
        $output .= '| **Virtual Size** | ' . ($json['virtual-size'] ?? 'unknown') . " bytes |\n";
        $output .= '| **Actual Size** | ' . ($json['actual-size'] ?? 'unknown') . " bytes |\n";

        if (isset($json['virtual-size']) && is_numeric($json['virtual-size'])) {
            $sizeGb = round((int) $json['virtual-size'] / (1024 * 1024 * 1024), 2);
            $output .= "| **Virtual Size (GB)** | {$sizeGb} GiB |\n";
        }
        if (isset($json['actual-size']) && is_numeric($json['actual-size'])) {
            $actualGb = round((int) $json['actual-size'] / (1024 * 1024 * 1024), 2);
            $output .= "| **Actual Size (GB)** | {$actualGb} GiB |\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resizeDisk(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for "resize_disk".');
        }

        $size = trim((string) ($input['size'] ?? ''));
        if ($size === '') {
            return ToolResult::error('The "size" parameter is required for "resize_disk" (e.g. "+10G" or "50G").');
        }

        $result = $this->virsh->resizeDiskImage($path, $size);
        if (!$result->success()) {
            return ToolResult::error("Failed to resize disk:\n" . $result->error());
        }

        return ToolResult::success("Disk '{$path}' resized to {$size}.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function attachDisk(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "attach_disk".');
        }

        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for "attach_disk".');
        }

        $target = trim((string) ($input['target'] ?? 'vdb'));

        $args = [
            $name,
            $path,
            $target,
            '--driver', 'qemu',
            '--subdriver', 'qcow2',
            '--persistent',
        ];

        $result = $this->virsh->virsh('attach-disk', $args);
        if (!$result->success()) {
            return ToolResult::error("Failed to attach disk:\n" . $result->error());
        }

        return ToolResult::success("Disk '{$path}' attached to VM '{$name}' as {$target}.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function sharedFolder(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for "shared_folder".');
        }

        $hostPath = trim((string) ($input['host_path'] ?? ''));
        if ($hostPath === '') {
            return ToolResult::error('The "host_path" parameter is required.');
        }

        $mountTag = trim((string) ($input['mount_tag'] ?? ''));
        if ($mountTag === '') {
            return ToolResult::error('The "mount_tag" parameter is required.');
        }

        $driver = (string) ($input['driver'] ?? '9p');

        if (!is_dir($hostPath)) {
            return ToolResult::error("Host path does not exist: {$hostPath}");
        }

        // Use virsh attach-device with inline XML
        if ($driver === 'virtiofs') {
            $fsXml = <<<XML
                <filesystem type='mount' accessmode='passthrough'>
                  <driver type='virtiofs' queue='1024'/>
                  <source dir='{$hostPath}'/>
                  <target dir='{$mountTag}'/>
                </filesystem>
                XML;
        } else {
            $fsXml = <<<XML
                <filesystem type='mount' accessmode='passthrough'>
                  <driver type='path'/>
                  <source dir='{$hostPath}'/>
                  <target dir='{$mountTag}'/>
                </filesystem>
                XML;
        }

        $xmlDir = $this->virsh->workspacePath() . '/libvirt/xml';
        if (!is_dir($xmlDir)) {
            mkdir($xmlDir, 0755, true);
        }
        $xmlPath = $xmlDir . "/fs-{$name}-{$mountTag}.xml";
        file_put_contents($xmlPath, $fsXml);

        // Try attaching (works live if VM is running, or persistent if stopped)
        $result = $this->virsh->virsh('attach-device', [$name, $xmlPath, '--persistent']);
        if (!$result->success()) {
            // If the VM isn't running, try config-only
            $result = $this->virsh->virsh('attach-device', [$name, $xmlPath, '--config']);
        }

        if (!$result->success()) {
            return ToolResult::error("Failed to attach shared folder:\n" . $result->error());
        }

        $mountCmd = $driver === 'virtiofs'
            ? "mount -t virtiofs {$mountTag} /mnt/{$mountTag}"
            : "mount -t 9p {$mountTag} /mnt/{$mountTag} -o trans=virtio,version=9p2000.L";

        $output = "## Shared Folder Added\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **VM** | {$name} |\n";
        $output .= "| **Host Path** | {$hostPath} |\n";
        $output .= "| **Mount Tag** | {$mountTag} |\n";
        $output .= "| **Driver** | {$driver} |\n";
        $output .= "\n**Mount inside guest:**\n```bash\nmkdir -p /mnt/{$mountTag}\n{$mountCmd}\n```";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function gpuPassthrough(array $input): ToolResult
    {
        $subAction = (string) ($input['sub_action'] ?? 'list_gpus');

        return match ($subAction) {
            'list_gpus' => $this->listGpus(),
            'check_iommu' => $this->checkIommu(),
            'iommu_group' => $this->iommuGroup($input),
            default => ToolResult::error("Unknown gpu_passthrough sub_action: '{$subAction}'"),
        };
    }

    private function listGpus(): ToolResult
    {
        $gpus = $this->hardware()->discoverGpus();

        if ($gpus === []) {
            return ToolResult::success("## GPUs\n\nNo GPUs detected via lspci.");
        }

        $output = "## Detected GPUs\n\n";
        $output .= "| Address | Name | Vendor | Driver | IOMMU Group |\n";
        $output .= "|---------|------|--------|--------|-------------|\n";

        foreach ($gpus as $gpu) {
            $output .= "| `{$gpu['address']}` | {$gpu['name']} | {$gpu['vendor_id']}:{$gpu['device_id']} | {$gpu['driver']} | {$gpu['iommu_group']} |\n";
        }

        $output .= "\n**For GPU passthrough:** The GPU's driver should be `vfio-pci` (not the host GPU driver). ";
        $output .= "Check IOMMU status with sub_action `check_iommu`.";

        return ToolResult::success($output);
    }

    private function checkIommu(): ToolResult
    {
        $hw = $this->hardware();
        $enabled = $hw->isIommuEnabled();
        $vfio = $hw->isVfioAvailable();
        $cpu = $hw->getCpuInfo();

        $output = "## IOMMU Status\n\n";
        $output .= "| Check | Status |\n|-------|--------|\n";
        $output .= '| **IOMMU Enabled** | ' . ($enabled ? 'Yes' : 'No') . " |\n";
        $output .= '| **VFIO Module** | ' . ($vfio ? 'Loaded' : 'Not loaded') . " |\n";
        $output .= '| **CPU Virtualization** | ' . ($cpu['vt_supported'] ? 'Supported' : 'Not detected') . " |\n";

        if (!$enabled) {
            $output .= "\n### Enable IOMMU\n\n";
            $output .= "1. Enable VT-d/AMD-Vi in BIOS\n";
            $output .= "2. Add kernel parameter: `intel_iommu=on` (Intel) or `amd_iommu=on` (AMD)\n";
            $output .= "3. Reboot\n";
        }

        if (!$vfio) {
            $output .= "\n### Load VFIO\n\n```bash\nmodprobe vfio-pci\n```\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function iommuGroup(array $input): ToolResult
    {
        $pciAddress = trim((string) ($input['pci_address'] ?? ''));
        if ($pciAddress === '') {
            return ToolResult::error('The "pci_address" parameter is required.');
        }

        $hw = $this->hardware();
        $devices = $hw->getIommuGroupDevices($pciAddress);

        if ($devices === []) {
            return ToolResult::error("No IOMMU group found for PCI address '{$pciAddress}'.");
        }

        $group = $hw->getDeviceIommuGroup($pciAddress);

        $output = "## IOMMU Group {$group} (for {$pciAddress})\n\n";
        $output .= "All devices in this group must be passed through together:\n\n";
        $output .= "| Address | Description |\n|---------|-------------|\n";

        foreach ($devices as $device) {
            $output .= "| `{$device['address']}` | {$device['description']} |\n";
        }

        return ToolResult::success($output);
    }

    private function hardware(): HardwareDiscovery
    {
        return $this->hardware ??= new HardwareDiscovery();
    }
}
