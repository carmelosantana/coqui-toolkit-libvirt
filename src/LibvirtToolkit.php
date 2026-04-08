<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Libvirt\Runtime\ConsoleProxyRunner;
use CoquiBot\Toolkits\Libvirt\Runtime\DependencyChecker;
use CoquiBot\Toolkits\Libvirt\Runtime\VirshRunner;
use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;
use CoquiBot\Toolkits\Libvirt\Tool\VmConsoleTool;
use CoquiBot\Toolkits\Libvirt\Tool\VmInfoTool;
use CoquiBot\Toolkits\Libvirt\Tool\VmLifecycleTool;
use CoquiBot\Toolkits\Libvirt\Tool\VmNetworkTool;
use CoquiBot\Toolkits\Libvirt\Tool\VmStorageTool;

/**
 * Libvirt virtualization toolkit for Coqui.
 *
 * Provides tools for VM lifecycle management, console access, networking,
 * storage, snapshots, shared folders, and GPU passthrough — all via virsh
 * and virt-install CLI wrappers.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class LibvirtToolkit implements ToolkitInterface
{
    private readonly VirshRunner $virsh;
    private readonly VmStateStore $store;
    private readonly ConsoleProxyRunner $console;
    private readonly DependencyChecker $deps;

    public function __construct(
        string $workspacePath,
        ?VirshRunner $virsh = null,
        ?VmStateStore $store = null,
        ?ConsoleProxyRunner $console = null,
        ?DependencyChecker $deps = null,
    ) {
        $this->virsh = $virsh ?? new VirshRunner($workspacePath);
        $this->store = $store ?? new VmStateStore($workspacePath . '/libvirt/state.db');
        $this->deps = $deps ?? new DependencyChecker();
        $this->console = $console ?? new ConsoleProxyRunner($workspacePath, $this->store, $this->virsh);
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            new VmLifecycleTool($this->virsh, $this->store, $this->deps),
            new VmInfoTool($this->virsh, $this->store),
            new VmConsoleTool($this->console),
            new VmNetworkTool($this->virsh, $this->store),
            new VmStorageTool($this->virsh),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <LIBVIRT-TOOLKIT-GUIDELINES>
            ## Virtualization — VM Management via libvirt

            You have 5 tools for managing virtual machines, networks, storage, and console access.

            ### Tool Overview

            | Tool | Purpose | Key Actions |
            |------|---------|-------------|
            | `vm_lifecycle` | Create, destroy, start, stop VMs | create, start, stop, reboot, destroy, delete, snapshot, restore, check_deps |
            | `vm_info` | Query VM and host information | info, list, domxml, hardware, snapshots |
            | `vm_console` | Web console via noVNC | open, close, status |
            | `vm_network` | Virtual network management | create, start, stop, destroy, list, info |
            | `vm_storage` | Disk images and shared folders | create_disk, disk_info, resize_disk, attach_disk, shared_folder, gpu_passthrough |

            ### Workflow: Create and Use a VM

            1. **Check dependencies**: `vm_lifecycle` action `check_deps` — ensures virsh, qemu, virt-install are available
            2. **Create a disk**: `vm_storage` action `create_disk` — creates a qcow2 disk image
            3. **Create the VM**: `vm_lifecycle` action `create` — define VM with CPU, RAM, disk, networking
            4. **Start the VM**: `vm_lifecycle` action `start`
            5. **Open console**: `vm_console` action `open` — returns a noVNC URL for browser-based console
            6. **Check status**: `vm_info` action `info` — shows VM state, CPU, memory, IPs

            ### Creating VMs

            The `create` action accepts:
            - `name` (required): VM name
            - `memory` (default: 2048): RAM in MiB
            - `vcpus` (default: 2): Virtual CPUs
            - `disk_path`: Path to an existing disk image (create with `vm_storage` first)
            - `disk_size` (default: "20G"): Only used if `disk_path` not provided (creates inline)
            - `iso`: Path to an ISO for installation
            - `network` (default: "default"): Network name
            - `os_variant`: OS optimize hint (e.g. "ubuntu22.04", "win11")

            ### GPU Passthrough

            Use `vm_storage` action `gpu_passthrough`:
            1. Action `gpu_passthrough` with `sub_action: "list_gpus"` — discover available GPUs and IOMMU groups
            2. Action `gpu_passthrough` with `sub_action: "check_iommu"` — verify IOMMU is enabled
            3. When creating a VM, pass `pci_devices` array with PCI addresses (e.g. "0000:06:00.0")

            **Requires**: IOMMU enabled in BIOS + kernel params, GPU not in use by host.

            ### Shared Folders

            Use `vm_storage` action `shared_folder` with:
            - `host_path`: Directory on the host to share
            - `mount_tag`: Tag to use inside the guest for mounting
            - `driver`: "9p" (default, wider compat) or "virtiofs" (faster, needs memfd)

            Guest mounting:
            - 9p: `mount -t 9p mount_tag /mnt/share -o trans=virtio,version=9p2000.L`
            - virtiofs: `mount -t virtiofs mount_tag /mnt/share`

            ### Networking

            Default network "default" provides NAT with DHCP. Create custom networks with `vm_network`:
            - `nat`: NAT network with custom subnet
            - `isolated`: No external access, inter-VM only
            - `bridged`: Uses host bridge interface

            ### Snapshots

            Use `vm_lifecycle` actions:
            - `snapshot` with `name` and `snapshot_name`
            - `restore` with `name` and `snapshot_name`
            - Query with `vm_info` action `snapshots`

            ### Security Notes
            - VMs use NAT networking by default (isolated from host network)
            - VNC binds to 127.0.0.1 only
            - Console proxies bind to localhost
            - Disk images are stored in the workspace directory
            - GPU passthrough requires explicit IOMMU configuration

            ### Common OS Variants
            `ubuntu22.04`, `ubuntu24.04`, `debian12`, `fedora40`, `centos-stream9`, `win10`, `win11`, `generic`
            </LIBVIRT-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
