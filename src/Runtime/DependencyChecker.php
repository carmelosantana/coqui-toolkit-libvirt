<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Runtime;

/**
 * Checks for required and optional system dependencies.
 *
 * Reports structured status with availability, version info,
 * and install instructions for missing tools.
 */
final class DependencyChecker
{
    /** @var array<string, array{description: string, required: bool, install: string}> */
    private const array DEPENDENCIES = [
        'virsh' => [
            'description' => 'libvirt CLI for VM management',
            'required' => true,
            'install' => 'apt install libvirt-clients',
        ],
        'virt-install' => [
            'description' => 'VM creation utility',
            'required' => true,
            'install' => 'apt install virtinst',
        ],
        'qemu-img' => [
            'description' => 'Disk image management',
            'required' => true,
            'install' => 'apt install qemu-utils',
        ],
        'qemu-system-x86_64' => [
            'description' => 'QEMU/KVM hypervisor',
            'required' => true,
            'install' => 'apt install qemu-system-x86',
        ],
        'virt-xml-validate' => [
            'description' => 'Libvirt XML validation',
            'required' => false,
            'install' => 'apt install libvirt-clients',
        ],
        'websockify' => [
            'description' => 'WebSocket-to-TCP proxy for VNC console',
            'required' => false,
            'install' => 'pip3 install websockify  # or: apt install websockify',
        ],
    ];

    /**
     * Check all dependencies and return a structured report.
     *
     * @return array{
     *     ready: bool,
     *     kvm_available: bool,
     *     available: list<array{name: string, version: string, description: string}>,
     *     missing: list<array{name: string, required: bool, description: string, install: string}>,
     *     summary: string,
     * }
     */
    public function check(): array
    {
        $available = [];
        $missing = [];

        foreach (self::DEPENDENCIES as $binary => $info) {
            $path = $this->which($binary);
            if ($path !== null) {
                $available[] = [
                    'name' => $binary,
                    'version' => $this->getVersion($binary, $path),
                    'description' => $info['description'],
                ];
            } else {
                $missing[] = [
                    'name' => $binary,
                    'required' => $info['required'],
                    'description' => $info['description'],
                    'install' => $info['install'],
                ];
            }
        }

        // Also check for KVM support
        $kvmAvailable = is_readable('/dev/kvm');

        $requiredMissing = array_filter($missing, static fn(array $dep): bool => $dep['required']);
        $ready = $requiredMissing === [] && $kvmAvailable;

        $summaryParts = [];
        if (!$kvmAvailable) {
            $summaryParts[] = '⚠ /dev/kvm not accessible — KVM acceleration unavailable. '
                . 'Ensure KVM kernel modules are loaded and your user has permissions.';
        }
        if ($requiredMissing !== []) {
            $names = implode(', ', array_column($requiredMissing, 'name'));
            $summaryParts[] = "Missing required: {$names}";
        }
        if ($summaryParts === []) {
            $summaryParts[] = 'All required dependencies are available.';
        }

        $optionalMissing = array_filter($missing, static fn(array $dep): bool => !$dep['required']);
        if ($optionalMissing !== []) {
            $names = implode(', ', array_column($optionalMissing, 'name'));
            $summaryParts[] = "Optional missing: {$names}";
        }

        return [
            'ready' => $ready,
            'kvm_available' => $kvmAvailable,
            'available' => $available,
            'missing' => $missing,
            'summary' => implode("\n", $summaryParts),
        ];
    }

    private function which(string $binary): ?string
    {
        $output = [];
        $exitCode = 0;
        exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);

        return ($exitCode === 0 && isset($output[0])) ? trim($output[0]) : null;
    }

    private function getVersion(string $binary, string $path): string
    {
        // Most libvirt tools support --version
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($path) . ' --version 2>&1', $output, $exitCode);

        if ($exitCode === 0 && isset($output[0])) {
            $version = trim($output[0]);
            // virsh outputs "virsh (libvirt) X.Y.Z"
            if (preg_match('/(\d+\.\d+[\.\d]*)/', $version, $matches)) {
                return $matches[1];
            }

            return $version;
        }

        return 'unknown';
    }
}
