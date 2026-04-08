<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Runtime;

/**
 * Discovers host hardware capabilities relevant to virtualization.
 *
 * Detects GPUs via lspci, IOMMU groups via sysfs, and checks VFIO availability.
 * All operations are read-only and safe to run at any time.
 */
final class HardwareDiscovery
{
    /**
     * Discover all VGA/3D display controllers on the host.
     *
     * @return list<array{address: string, vendor_id: string, device_id: string, name: string, driver: string, iommu_group: string}>
     */
    public function discoverGpus(): array
    {
        $output = trim((string) shell_exec('lspci -Dnn 2>/dev/null'));
        if ($output === '') {
            return [];
        }

        $gpus = [];
        foreach (explode("\n", $output) as $line) {
            // Match VGA compatible controller or 3D controller
            if (!preg_match('/\b(VGA compatible controller|3D controller|Display controller)\b/i', $line)) {
                continue;
            }

            // Parse: 0000:06:00.0 VGA compatible controller: NVIDIA ... [10de:13c2] (rev a1)
            if (!preg_match('/^(\S+)\s+.*\[([0-9a-f]{4}):([0-9a-f]{4})\]/i', $line, $matches)) {
                continue;
            }

            $address = $matches[1];
            $vendorId = $matches[2];
            $deviceId = $matches[3];

            // Extract the device name (between "controller: " and " [vendor:device]")
            $name = '';
            if (preg_match('/controller:\s*(.+?)\s*\[' . preg_quote($vendorId, '/') . '/', $line, $nameMatch)) {
                $name = trim($nameMatch[1]);
            }

            $gpus[] = [
                'address' => $address,
                'vendor_id' => $vendorId,
                'device_id' => $deviceId,
                'name' => $name,
                'driver' => $this->getDeviceDriver($address),
                'iommu_group' => $this->getDeviceIommuGroup($address),
            ];
        }

        return $gpus;
    }

    /**
     * Get all IOMMU groups and their devices.
     *
     * @return array<string, list<array{address: string, description: string}>>
     */
    public function getIommuGroups(): array
    {
        $groups = [];
        $basePath = '/sys/kernel/iommu_groups';

        if (!is_dir($basePath)) {
            return [];
        }

        $groupDirs = glob($basePath . '/*/devices/*');
        if ($groupDirs === false) {
            return [];
        }

        foreach ($groupDirs as $devicePath) {
            // /sys/kernel/iommu_groups/13/devices/0000:06:00.0
            $parts = explode('/', $devicePath);
            $groupId = $parts[4] ?? '';
            $pciAddress = basename($devicePath);

            $description = trim((string) shell_exec(
                'lspci -nns ' . escapeshellarg($pciAddress) . ' 2>/dev/null',
            ));

            $groups[$groupId][] = [
                'address' => $pciAddress,
                'description' => $description,
            ];
        }

        ksort($groups, SORT_NUMERIC);

        return $groups;
    }

    /**
     * Check if IOMMU is enabled on the host.
     */
    public function isIommuEnabled(): bool
    {
        // Check sysfs for IOMMU groups
        if (is_dir('/sys/class/iommu') && count(scandir('/sys/class/iommu') ?: []) > 2) {
            return true;
        }

        // Fallback: check dmesg
        $dmesg = trim((string) shell_exec('dmesg 2>/dev/null | grep -i -c "IOMMU"'));

        return (int) $dmesg > 0;
    }

    /**
     * Check if the VFIO kernel modules are loaded.
     */
    public function isVfioAvailable(): bool
    {
        $lsmod = trim((string) shell_exec('lsmod 2>/dev/null | grep -c vfio_pci'));

        return (int) $lsmod > 0;
    }

    /**
     * Get the kernel driver currently bound to a PCI device.
     */
    public function getDeviceDriver(string $pciAddress): string
    {
        $driverLink = "/sys/bus/pci/devices/{$pciAddress}/driver";

        if (!is_link($driverLink)) {
            return '';
        }

        $target = readlink($driverLink);

        return $target !== false ? basename($target) : '';
    }

    /**
     * Get the IOMMU group for a PCI device.
     */
    public function getDeviceIommuGroup(string $pciAddress): string
    {
        $iommuLink = "/sys/bus/pci/devices/{$pciAddress}/iommu_group";

        if (!is_link($iommuLink)) {
            return '';
        }

        $target = readlink($iommuLink);

        return $target !== false ? basename($target) : '';
    }

    /**
     * Get all PCI devices in the same IOMMU group as the specified device.
     *
     * @return list<array{address: string, description: string}>
     */
    public function getIommuGroupDevices(string $pciAddress): array
    {
        $group = $this->getDeviceIommuGroup($pciAddress);
        if ($group === '') {
            return [];
        }

        $devicesPath = "/sys/kernel/iommu_groups/{$group}/devices";
        if (!is_dir($devicesPath)) {
            return [];
        }

        $devices = [];
        $entries = scandir($devicesPath) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $description = trim((string) shell_exec(
                'lspci -nns ' . escapeshellarg($entry) . ' 2>/dev/null',
            ));

            $devices[] = [
                'address' => $entry,
                'description' => $description,
            ];
        }

        return $devices;
    }

    /**
     * Get host CPU virtualization capabilities.
     *
     * @return array{vt_supported: bool, cpu_model: string, cores: int, threads: int}
     */
    public function getCpuInfo(): array
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';

        $vtSupported = str_contains($cpuinfo, 'vmx') || str_contains($cpuinfo, 'svm');

        $model = '';
        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $match)) {
            $model = trim($match[1]);
        }

        $coreCount = substr_count($cpuinfo, 'processor');
        $physicalCores = 0;
        if (preg_match('/cpu cores\s*:\s*(\d+)/i', $cpuinfo, $match)) {
            $physicalCores = (int) $match[1];
        }

        return [
            'vt_supported' => $vtSupported,
            'cpu_model' => $model,
            'cores' => $physicalCores > 0 ? $physicalCores : $coreCount,
            'threads' => $coreCount,
        ];
    }

    /**
     * Get total and available RAM on the host.
     *
     * @return array{total_mb: int, available_mb: int}
     */
    public function getMemoryInfo(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';

        $totalKb = 0;
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $match)) {
            $totalKb = (int) $match[1];
        }

        $availableKb = 0;
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $match)) {
            $availableKb = (int) $match[1];
        }

        return [
            'total_mb' => (int) round($totalKb / 1024),
            'available_mb' => (int) round($availableKb / 1024),
        ];
    }
}
