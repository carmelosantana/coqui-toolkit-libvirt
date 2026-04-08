<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Xml;

/**
 * Fluent builder for libvirt domain XML definitions.
 *
 * Generates valid XML for virsh define/create. Supports common VM configurations
 * including disks, networks, VNC graphics, shared folders (9p/virtiofs),
 * and PCI device passthrough for GPU passthrough scenarios.
 */
final class DomainXmlBuilder
{
    private string $name = '';
    private int $memoryMb = 2048;
    private int $vcpus = 2;
    private string $osType = 'hvm';
    private string $arch = 'x86_64';
    private string $machine = 'q35';
    private string $bootDev = 'hd';
    private string $emulator = '/usr/bin/qemu-system-x86_64';

    /** @var list<array{path: string, format: string, bus: string, dev: string, type: string}> */
    private array $disks = [];

    /** @var list<array{type: string, source: string, model: string}> */
    private array $networks = [];

    /** @var array{type: string, port: int, listen: string, autoport: bool, websocket: int}|null */
    private ?array $graphics = null;

    /** @var list<array{source: string, target: string, driver: string, accessmode: string, readonly: bool}> */
    private array $filesystems = [];

    /** @var list<array{domain: string, bus: string, slot: string, function: string}> */
    private array $hostdevs = [];

    private bool $cpuHostPassthrough = true;

    /** @var list<string> */
    private array $cdromPaths = [];

    private int $diskIndex = 0;

    public function name(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }

    public function memory(int $mb): self
    {
        $clone = clone $this;
        $clone->memoryMb = $mb;
        return $clone;
    }

    public function vcpus(int $count): self
    {
        $clone = clone $this;
        $clone->vcpus = $count;
        return $clone;
    }

    public function machine(string $machine): self
    {
        $clone = clone $this;
        $clone->machine = $machine;
        return $clone;
    }

    public function bootDev(string $dev): self
    {
        $clone = clone $this;
        $clone->bootDev = $dev;
        return $clone;
    }

    public function emulator(string $path): self
    {
        $clone = clone $this;
        $clone->emulator = $path;
        return $clone;
    }

    public function cpuHostPassthrough(bool $enabled): self
    {
        $clone = clone $this;
        $clone->cpuHostPassthrough = $enabled;
        return $clone;
    }

    /**
     * Add a disk image.
     */
    public function disk(
        string $path,
        string $format = 'qcow2',
        string $bus = 'virtio',
    ): self {
        $clone = clone $this;
        $dev = $bus === 'virtio' ? 'vd' : 'sd';
        $dev .= chr(97 + $clone->diskIndex); // vda, vdb, vdc...
        $clone->disks[] = [
            'path' => $path,
            'format' => $format,
            'bus' => $bus,
            'dev' => $dev,
            'type' => 'file',
        ];
        $clone->diskIndex++;
        return $clone;
    }

    /**
     * Add a CD-ROM drive (ISO image).
     */
    public function cdrom(string $isoPath): self
    {
        $clone = clone $this;
        $clone->cdromPaths[] = $isoPath;
        return $clone;
    }

    /**
     * Add a network interface.
     */
    public function network(
        string $source = 'default',
        string $type = 'network',
        string $model = 'virtio',
    ): self {
        $clone = clone $this;
        $clone->networks[] = [
            'type' => $type,
            'source' => $source,
            'model' => $model,
        ];
        return $clone;
    }

    /**
     * Add VNC graphics.
     */
    public function vnc(
        int $port = -1,
        string $listen = '127.0.0.1',
        bool $autoport = true,
        int $websocket = -1,
    ): self {
        $clone = clone $this;
        $clone->graphics = [
            'type' => 'vnc',
            'port' => $port,
            'listen' => $listen,
            'autoport' => $autoport,
            'websocket' => $websocket,
        ];
        return $clone;
    }

    /**
     * Add a shared filesystem (9p or virtiofs).
     */
    public function filesystem(
        string $sourcePath,
        string $mountTag,
        string $driver = '9p',
        string $accessmode = 'passthrough',
        bool $readonly = false,
    ): self {
        $clone = clone $this;
        $clone->filesystems[] = [
            'source' => $sourcePath,
            'target' => $mountTag,
            'driver' => $driver,
            'accessmode' => $accessmode,
            'readonly' => $readonly,
        ];
        return $clone;
    }

    /**
     * Add a PCI host device for passthrough (e.g. GPU).
     *
     * @param string $pciAddress Full PCI address like "0000:06:00.0"
     */
    public function hostdev(string $pciAddress): self
    {
        $parsed = self::parsePciAddress($pciAddress);
        if ($parsed === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->hostdevs[] = $parsed;
        return $clone;
    }

    /**
     * Build the complete domain XML string.
     */
    public function build(): string
    {
        if ($this->name === '') {
            throw new \LogicException('Domain name is required. Call name() before build().');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $domain = $dom->createElement('domain');
        $domain->setAttribute('type', 'kvm');
        $dom->appendChild($domain);

        // Name
        $domain->appendChild($dom->createElement('name', $this->name));

        // Memory
        $memory = $dom->createElement('memory', (string) ($this->memoryMb * 1024));
        $memory->setAttribute('unit', 'KiB');
        $domain->appendChild($memory);

        $currentMemory = $dom->createElement('currentMemory', (string) ($this->memoryMb * 1024));
        $currentMemory->setAttribute('unit', 'KiB');
        $domain->appendChild($currentMemory);

        // VCPUs
        $domain->appendChild($dom->createElement('vcpu', (string) $this->vcpus));

        // OS
        $os = $dom->createElement('os');
        $type = $dom->createElement('type', $this->osType);
        $type->setAttribute('arch', $this->arch);
        $type->setAttribute('machine', $this->machine);
        $os->appendChild($type);
        $boot = $dom->createElement('boot');
        $boot->setAttribute('dev', $this->bootDev);
        $os->appendChild($boot);
        $domain->appendChild($os);

        // Features
        $features = $dom->createElement('features');
        $features->appendChild($dom->createElement('acpi'));
        $features->appendChild($dom->createElement('apic'));
        $domain->appendChild($features);

        // CPU
        if ($this->cpuHostPassthrough) {
            $cpu = $dom->createElement('cpu');
            $cpu->setAttribute('mode', 'host-passthrough');
            $cpu->setAttribute('check', 'none');
            $domain->appendChild($cpu);
        }

        // Devices
        $devices = $dom->createElement('devices');
        $devices->appendChild($dom->createElement('emulator', $this->emulator));

        // Disks
        foreach ($this->disks as $diskConfig) {
            $this->addDiskElement($dom, $devices, $diskConfig);
        }

        // CD-ROMs
        $cdromIdx = 0;
        foreach ($this->cdromPaths as $isoPath) {
            $this->addCdromElement($dom, $devices, $isoPath, $cdromIdx++);
        }

        // Networks
        if ($this->networks === []) {
            // Add default network
            $this->addNetworkElement($dom, $devices, [
                'type' => 'network',
                'source' => 'default',
                'model' => 'virtio',
            ]);
        } else {
            foreach ($this->networks as $netConfig) {
                $this->addNetworkElement($dom, $devices, $netConfig);
            }
        }

        // Graphics
        $gfx = $this->graphics ?? [
            'type' => 'vnc',
            'port' => -1,
            'listen' => '127.0.0.1',
            'autoport' => true,
            'websocket' => -1,
        ];
        $this->addGraphicsElement($dom, $devices, $gfx);

        // Video
        $video = $dom->createElement('video');
        $model = $dom->createElement('model');
        $model->setAttribute('type', 'virtio');
        $video->appendChild($model);
        $devices->appendChild($video);

        // Console
        $console = $dom->createElement('console');
        $console->setAttribute('type', 'pty');
        $target = $dom->createElement('target');
        $target->setAttribute('type', 'serial');
        $target->setAttribute('port', '0');
        $console->appendChild($target);
        $devices->appendChild($console);

        // Input devices
        $mouse = $dom->createElement('input');
        $mouse->setAttribute('type', 'tablet');
        $mouse->setAttribute('bus', 'usb');
        $devices->appendChild($mouse);

        // Filesystems
        foreach ($this->filesystems as $fsConfig) {
            $this->addFilesystemElement($dom, $devices, $fsConfig);
        }

        // Host devices (PCI passthrough)
        foreach ($this->hostdevs as $hostdevConfig) {
            $this->addHostdevElement($dom, $devices, $hostdevConfig);
        }

        // Memory balloon
        $memballoon = $dom->createElement('memballoon');
        $memballoon->setAttribute('model', 'virtio');
        $devices->appendChild($memballoon);

        // RNG device for entropy
        $rng = $dom->createElement('rng');
        $rng->setAttribute('model', 'virtio');
        $backend = $dom->createElement('backend', '/dev/urandom');
        $backend->setAttribute('model', 'random');
        $rng->appendChild($backend);
        $devices->appendChild($rng);

        $domain->appendChild($devices);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * Parse a PCI address string like "0000:06:00.0" into components.
     *
     * @return array{domain: string, bus: string, slot: string, function: string}|null
     */
    public static function parsePciAddress(string $address): ?array
    {
        if (!preg_match('/^([0-9a-f]{4}):([0-9a-f]{2}):([0-9a-f]{2})\.([0-9a-f])$/i', $address, $m)) {
            return null;
        }

        return [
            'domain' => '0x' . $m[1],
            'bus' => '0x' . $m[2],
            'slot' => '0x' . $m[3],
            'function' => '0x' . $m[4],
        ];
    }

    /**
     * @param array{path: string, format: string, bus: string, dev: string, type: string} $config
     */
    private function addDiskElement(\DOMDocument $dom, \DOMElement $devices, array $config): void
    {
        $disk = $dom->createElement('disk');
        $disk->setAttribute('type', $config['type']);
        $disk->setAttribute('device', 'disk');

        $driver = $dom->createElement('driver');
        $driver->setAttribute('name', 'qemu');
        $driver->setAttribute('type', $config['format']);
        $driver->setAttribute('cache', 'writeback');
        $driver->setAttribute('discard', 'unmap');
        $disk->appendChild($driver);

        $source = $dom->createElement('source');
        $source->setAttribute('file', $config['path']);
        $disk->appendChild($source);

        $target = $dom->createElement('target');
        $target->setAttribute('dev', $config['dev']);
        $target->setAttribute('bus', $config['bus']);
        $disk->appendChild($target);

        $devices->appendChild($disk);
    }

    private function addCdromElement(\DOMDocument $dom, \DOMElement $devices, string $isoPath, int $index): void
    {
        $disk = $dom->createElement('disk');
        $disk->setAttribute('type', 'file');
        $disk->setAttribute('device', 'cdrom');

        $driver = $dom->createElement('driver');
        $driver->setAttribute('name', 'qemu');
        $driver->setAttribute('type', 'raw');
        $disk->appendChild($driver);

        $source = $dom->createElement('source');
        $source->setAttribute('file', $isoPath);
        $disk->appendChild($source);

        $target = $dom->createElement('target');
        $target->setAttribute('dev', 'sdc' . ($index > 0 ? (string) $index : ''));
        $target->setAttribute('bus', 'sata');
        $disk->appendChild($target);

        $readonly = $dom->createElement('readonly');
        $disk->appendChild($readonly);

        $devices->appendChild($disk);
    }

    /**
     * @param array{type: string, source: string, model: string} $config
     */
    private function addNetworkElement(\DOMDocument $dom, \DOMElement $devices, array $config): void
    {
        $iface = $dom->createElement('interface');
        $iface->setAttribute('type', $config['type']);

        $source = $dom->createElement('source');
        if ($config['type'] === 'network') {
            $source->setAttribute('network', $config['source']);
        } elseif ($config['type'] === 'bridge') {
            $source->setAttribute('bridge', $config['source']);
        }
        $iface->appendChild($source);

        $model = $dom->createElement('model');
        $model->setAttribute('type', $config['model']);
        $iface->appendChild($model);

        $devices->appendChild($iface);
    }

    /**
     * @param array{type: string, port: int, listen: string, autoport: bool, websocket: int} $config
     */
    private function addGraphicsElement(\DOMDocument $dom, \DOMElement $devices, array $config): void
    {
        $graphics = $dom->createElement('graphics');
        $graphics->setAttribute('type', $config['type']);
        $graphics->setAttribute('port', (string) $config['port']);
        $graphics->setAttribute('autoport', $config['autoport'] ? 'yes' : 'no');

        if ($config['websocket'] > 0) {
            $graphics->setAttribute('websocket', (string) $config['websocket']);
        }

        $listen = $dom->createElement('listen');
        $listen->setAttribute('type', 'address');
        $listen->setAttribute('address', $config['listen']);
        $graphics->appendChild($listen);

        $devices->appendChild($graphics);
    }

    /**
     * @param array{source: string, target: string, driver: string, accessmode: string, readonly: bool} $config
     */
    private function addFilesystemElement(\DOMDocument $dom, \DOMElement $devices, array $config): void
    {
        $fs = $dom->createElement('filesystem');
        $fs->setAttribute('type', 'mount');

        if ($config['driver'] !== 'virtiofs') {
            $fs->setAttribute('accessmode', $config['accessmode']);
        }

        $driver = $dom->createElement('driver');
        $driver->setAttribute('type', $config['driver'] === 'virtiofs' ? 'virtiofs' : 'path');

        if ($config['driver'] === 'virtiofs') {
            $driver->setAttribute('queue', '1024');
        }

        $fs->appendChild($driver);

        $source = $dom->createElement('source');
        $source->setAttribute('dir', $config['source']);
        $fs->appendChild($source);

        $target = $dom->createElement('target');
        $target->setAttribute('dir', $config['target']);
        $fs->appendChild($target);

        if ($config['readonly']) {
            $fs->appendChild($dom->createElement('readonly'));
        }

        $devices->appendChild($fs);
    }

    /**
     * @param array{domain: string, bus: string, slot: string, function: string} $config
     */
    private function addHostdevElement(\DOMDocument $dom, \DOMElement $devices, array $config): void
    {
        $hostdev = $dom->createElement('hostdev');
        $hostdev->setAttribute('mode', 'subsystem');
        $hostdev->setAttribute('type', 'pci');
        $hostdev->setAttribute('managed', 'yes');

        $source = $dom->createElement('source');
        $address = $dom->createElement('address');
        $address->setAttribute('domain', $config['domain']);
        $address->setAttribute('bus', $config['bus']);
        $address->setAttribute('slot', $config['slot']);
        $address->setAttribute('function', $config['function']);
        $source->appendChild($address);
        $hostdev->appendChild($source);

        $devices->appendChild($hostdev);
    }
}
