# Coqui Libvirt Toolkit

Libvirt/KVM virtualization toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Wraps `virsh`, `virt-install`, and `qemu-img` to give agents full VM management capabilities including lifecycle control, disk management, virtual networking, GPU passthrough, shared folders, snapshots, and browser-based console access via noVNC.

## Requirements

- PHP 8.4+
- libvirt (`virsh`, `virt-install`, `virt-xml-validate`)
- QEMU/KVM (`qemu-system-x86_64`, `qemu-img`)
- KVM kernel modules loaded (`/dev/kvm` accessible)
- websockify (optional — for web console)
- noVNC (optional — for browser-based console UI)

### Installing Dependencies (Debian/Ubuntu)

```bash
# Core
sudo apt install libvirt-daemon-system qemu-kvm virtinst qemu-utils

# Web console (optional)
pip3 install websockify
sudo apt install novnc
```

## Installation

```bash
composer require coquibot/coqui-toolkit-libvirt
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Credentials

| Key | Description |
|-----|-------------|
| `LIBVIRT_URI` | Libvirt connection URI (default: `qemu:///system`). Set to `qemu:///session` for unprivileged access or `qemu+ssh://user@host/system` for remote hosts. |

Set via the Coqui `credentials` tool or in your workspace `.env`:

```bash
LIBVIRT_URI=qemu:///system
```

## Tools Provided

### `vm_lifecycle`

Manage VM lifecycle — create, start, stop, reboot, destroy, delete, snapshot, restore.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `create`, `start`, `stop`, `force_stop`, `destroy`, `reboot`, `delete`, `suspend`, `resume`, `snapshot`, `restore`, `delete_snapshot`, `check_deps` |
| `name` | string | * | VM name. Required for all actions except `check_deps`. |
| `memory` | integer | No | RAM in MiB (default: 2048). Used with `create`. |
| `vcpus` | integer | No | Virtual CPUs (default: 2). Used with `create`. |
| `disk_path` | string | No | Path to existing disk image. Used with `create`. |
| `disk_size` | string | No | Disk size (e.g. `20G`). Used with `create` when `disk_path` is not given. |
| `iso` | string | No | Path to ISO for CD-ROM. Used with `create` for OS installation. |
| `network` | string | No | Network name (default: `default`). Used with `create`. |
| `os_variant` | string | No | OS variant hint (e.g. `ubuntu22.04`, `win11`). Used with `create`. |
| `pci_devices` | array | No | PCI addresses for GPU passthrough (e.g. `["0000:06:00.0"]`). Used with `create`. |
| `shared_folders` | array | No | Shared folders: `[{"host_path": "...", "mount_tag": "...", "driver": "9p"}]`. Used with `create`. |
| `boot_dev` | enum | No | Boot device: `hd` (default) or `cdrom`. Used with `create`. |
| `snapshot_name` | string | No | Snapshot name. Used with `snapshot`, `restore`, `delete_snapshot`. |
| `snapshot_description` | string | No | Snapshot description. Used with `snapshot`. |
| `remove_storage` | boolean | No | Remove disk images when deleting. Used with `delete`. |

### `vm_info`

Query VM and host information.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `info`, `list`, `domxml`, `hardware`, `snapshots` |
| `name` | string | * | VM name. Required for `info`, `domxml`, `snapshots`. |
| `all` | boolean | No | Include all VM states. Default: true. Used with `list`. |

### `vm_console`

Manage web console access to VMs via noVNC + websockify.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `open`, `close`, `status` |
| `name` | string | Yes | VM name. |
| `ws_port` | integer | No | WebSocket port (auto-detected from 6080 if omitted). Used with `open`. |

### `vm_network`

Manage libvirt virtual networks.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `create`, `start`, `stop`, `destroy`, `list`, `info` |
| `name` | string | * | Network name. Required for all actions except `list`. |
| `type` | enum | No | `nat` (default), `isolated`, `bridged`. Used with `create`. |
| `subnet` | string | No | CIDR subnet (e.g. `192.168.100.0/24`). Used with `create`. |
| `bridge` | string | No | Bridge interface name. Required when type is `bridged`. |
| `dhcp` | boolean | No | Enable DHCP. Default: true. Used with `create`. |
| `autostart` | boolean | No | Auto-start on host boot. Default: true. Used with `create`. |

### `vm_storage`

Manage disk images, shared folders, and GPU passthrough.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `create_disk`, `disk_info`, `resize_disk`, `attach_disk`, `shared_folder`, `gpu_passthrough` |
| `path` | string | No | Disk image path. Used with `create_disk`, `disk_info`, `resize_disk`, `attach_disk`. |
| `size` | string | No | Disk size (e.g. `20G`, `+10G`). Used with `create_disk`, `resize_disk`. |
| `format` | enum | No | `qcow2` (default), `raw`, `vmdk`, `vdi`. Used with `create_disk`. |
| `name` | string | No | VM name. Used with `attach_disk`, `shared_folder`. |
| `target` | string | No | Target device (e.g. `vdb`). Used with `attach_disk`. |
| `host_path` | string | No | Host directory to share. Used with `shared_folder`. |
| `mount_tag` | string | No | Mount tag for shared filesystem. Used with `shared_folder`. |
| `driver` | enum | No | `9p` (default, wider compat) or `virtiofs` (faster). Used with `shared_folder`. |
| `sub_action` | enum | No | `list_gpus`, `check_iommu`, `iommu_group`. Used with `gpu_passthrough`. |
| `pci_address` | string | No | PCI address (e.g. `0000:06:00.0`). Used with `iommu_group` sub-action. |

## Agent Workflow

### Creating a VM

```
1. vm_lifecycle  action: check_deps          → verify system requirements
2. vm_storage    action: create_disk          → create a qcow2 disk image
3. vm_network    action: create               → create a virtual network (or use "default")
4. vm_lifecycle  action: create               → define the VM with CPU, RAM, disk, network, ISO
5. vm_lifecycle  action: start                → boot the VM
6. vm_console    action: open                 → get a noVNC URL for browser-based console
```

### Shared Folders (9p)

Mount a host directory inside the guest:

```
vm_storage  action: shared_folder  name: my-vm  host_path: /data/shared  mount_tag: hostshare
```

Inside the guest:

```bash
mount -t 9p -o trans=virtio hostshare /mnt/shared
```

### GPU Passthrough

```
1. vm_storage  action: gpu_passthrough  sub_action: check_iommu    → verify IOMMU/VFIO
2. vm_storage  action: gpu_passthrough  sub_action: list_gpus      → find GPU PCI addresses
3. vm_storage  action: gpu_passthrough  sub_action: iommu_group    → check IOMMU group isolation
4. vm_lifecycle action: create ... pci_devices: ["0000:06:00.0"]   → create VM with GPU attached
```

**Prerequisites:** IOMMU enabled in BIOS + kernel (`intel_iommu=on` or `amd_iommu=on`), GPU bound to `vfio-pci` driver.

### Snapshots

```
vm_lifecycle  action: snapshot         name: my-vm  snapshot_name: before-update
vm_lifecycle  action: restore          name: my-vm  snapshot_name: before-update
vm_lifecycle  action: delete_snapshot  name: my-vm  snapshot_name: before-update
vm_info       action: snapshots        name: my-vm
```

## Standalone Usage

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\LibvirtToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = LibvirtToolkit::fromEnv();

foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . PHP_EOL;
}

// Check system dependencies
$lifecycle = $toolkit->tools()[0];
$result = $lifecycle->execute(['action' => 'check_deps']);
echo $result->content;
```

## Architecture

The toolkit wraps CLI tools rather than using PHP libvirt bindings (which are abandoned/minimal):

| Class | Purpose |
|-------|---------|
| `VirshRunner` | Core CLI wrapper — `virsh`, `virt-install`, `qemu-img` via `proc_open()` |
| `HardwareDiscovery` | Host hardware detection — GPUs, IOMMU, CPU, RAM via sysfs/procfs |
| `ConsoleProxyRunner` | noVNC + websockify background process manager |
| `DependencyChecker` | System dependency verification |
| `DomainXmlBuilder` | Fluent immutable builder for libvirt domain XML |
| `NetworkXmlBuilder` | Fluent builder for libvirt network XML |
| `VmStateStore` | SQLite-backed metadata persistence |

## Development

```bash
git clone https://github.com/AgentCoqui/coqui-toolkit-libvirt.git
cd coqui-toolkit-libvirt
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
