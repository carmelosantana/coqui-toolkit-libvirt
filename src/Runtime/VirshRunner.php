<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Runtime;

/**
 * Core process runner for virsh, virt-install, and virt-xml-validate commands.
 *
 * Resolves CLI binaries, builds commands, and executes them via proc_open()
 * with non-blocking output reads, timeout support, and output truncation.
 *
 * @see PlaywrightRunner in coqui-toolkit-browser for the pattern this follows
 */
final class VirshRunner
{
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_OUTPUT_BYTES = 65_536;

    /** @var array<string, string> Cached binary paths */
    private array $resolvedBinaries = [];

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $connectUri = '',
    ) {}

    /**
     * Execute a virsh command and return the result.
     *
     * @param list<string> $args Arguments for the command
     */
    public function virsh(
        string $subcommand,
        array $args = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): VirshResult {
        $binary = $this->resolveBinary('virsh');
        if ($binary === '') {
            return new VirshResult(127, '', 'virsh not found. Install libvirt-clients (e.g. sudo apt install libvirt-clients).');
        }

        $cmd = $this->buildVirshCommand($binary, $subcommand, $args);

        return $this->execute($cmd, $timeout);
    }

    /**
     * Execute a virt-install command.
     *
     * @param list<string> $args Command-line arguments
     */
    public function virtInstall(
        array $args,
        int $timeout = 120,
    ): VirshResult {
        $binary = $this->resolveBinary('virt-install');
        if ($binary === '') {
            return new VirshResult(127, '', 'virt-install not found. Install virtinst (e.g. sudo apt install virtinst).');
        }

        $parts = [escapeshellarg($binary)];
        if ($this->connectUri !== '') {
            $parts[] = '--connect=' . escapeshellarg($this->connectUri);
        }
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return $this->execute(implode(' ', $parts), $timeout);
    }

    /**
     * Validate a libvirt domain XML file.
     */
    public function validateXml(string $xmlPath): VirshResult
    {
        $binary = $this->resolveBinary('virt-xml-validate');
        if ($binary === '') {
            // Validation is optional — if the tool isn't installed, skip
            return new VirshResult(0, 'virt-xml-validate not available, skipping validation.', '');
        }

        $cmd = escapeshellarg($binary) . ' ' . escapeshellarg($xmlPath) . ' domain';

        return $this->execute($cmd, 10);
    }

    /**
     * Create a disk image using qemu-img.
     */
    public function createDiskImage(
        string $path,
        string $size,
        string $format = 'qcow2',
    ): VirshResult {
        $binary = $this->resolveBinary('qemu-img');
        if ($binary === '') {
            return new VirshResult(127, '', 'qemu-img not found. Install qemu-utils (e.g. sudo apt install qemu-utils).');
        }

        $cmd = escapeshellarg($binary) . ' create'
            . ' -f ' . escapeshellarg($format)
            . ' ' . escapeshellarg($path)
            . ' ' . escapeshellarg($size);

        return $this->execute($cmd, 30);
    }

    /**
     * Get disk image info via qemu-img info.
     */
    public function diskImageInfo(string $path): VirshResult
    {
        $binary = $this->resolveBinary('qemu-img');
        if ($binary === '') {
            return new VirshResult(127, '', 'qemu-img not found.');
        }

        $cmd = escapeshellarg($binary) . ' info --output=json ' . escapeshellarg($path);

        return $this->execute($cmd, 10);
    }

    /**
     * Resize a disk image.
     */
    public function resizeDiskImage(string $path, string $size): VirshResult
    {
        $binary = $this->resolveBinary('qemu-img');
        if ($binary === '') {
            return new VirshResult(127, '', 'qemu-img not found.');
        }

        $cmd = escapeshellarg($binary) . ' resize '
            . escapeshellarg($path) . ' ' . escapeshellarg($size);

        return $this->execute($cmd, 30);
    }

    /**
     * Resolve the path for a CLI binary.
     */
    public function resolveBinary(string $name): string
    {
        if (isset($this->resolvedBinaries[$name])) {
            return $this->resolvedBinaries[$name];
        }

        $which = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($which !== '' && file_exists($which)) {
            $this->resolvedBinaries[$name] = $which;
            return $which;
        }

        return '';
    }

    /**
     * Check if virsh is available.
     */
    public function isAvailable(): bool
    {
        return $this->resolveBinary('virsh') !== '';
    }

    /**
     * Get the workspace path.
     */
    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    /**
     * Get the resolved libvirt connection URI.
     */
    public function resolveConnectUri(): string
    {
        if ($this->connectUri !== '') {
            return $this->connectUri;
        }

        $env = getenv('LIBVIRT_URI');

        return $env !== false && $env !== '' ? $env : 'qemu:///system';
    }

    /**
     * @param list<string> $args
     */
    private function buildVirshCommand(string $binary, string $subcommand, array $args): string
    {
        $parts = [escapeshellarg($binary)];

        $uri = $this->resolveConnectUri();
        $parts[] = '--connect=' . escapeshellarg($uri);

        $parts[] = $subcommand;

        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    private function execute(string $command, int $timeout): VirshResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->workspacePath,
        );

        if (!is_resource($process)) {
            return new VirshResult(1, '', 'Failed to start process: ' . $command);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';

            $stdout .= $out;
            $stderr .= $err;

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15); // SIGTERM
                usleep(100_000);
                proc_terminate($process, 9);  // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new VirshResult(
                    124,
                    $this->truncateOutput($stdout),
                    "Command timed out after {$timeout}s.\n" . $this->truncateOutput($stderr),
                );
            }

            usleep(10_000);
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new VirshResult(
            $exitCode,
            $this->truncateOutput(trim($stdout)),
            $this->truncateOutput(trim($stderr)),
        );
    }

    private function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            . "\n\n[Output truncated at " . self::MAX_OUTPUT_BYTES . ' bytes]';
    }
}
