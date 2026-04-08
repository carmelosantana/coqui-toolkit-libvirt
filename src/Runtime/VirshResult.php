<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Runtime;

/**
 * Immutable result from a virsh/virt-install CLI command execution.
 */
final readonly class VirshResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function success(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return $this->stdout;
    }

    public function error(): string
    {
        if ($this->stderr !== '') {
            return $this->stderr;
        }

        if (!$this->success()) {
            return "Command failed with exit code {$this->exitCode}";
        }

        return '';
    }

    /**
     * Parse key-value output like `virsh dominfo`.
     *
     * Example:
     *   Name:           test-vm
     *   State:          running
     *
     * @return array<string, string>
     */
    public function parseKeyValue(): array
    {
        $result = [];
        foreach (explode("\n", $this->stdout) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Parse tabular output like `virsh list --all`.
     *
     * Skips header separator lines (dashes) and empty lines.
     *
     * @return list<array<string, string>>
     */
    public function parseTable(): array
    {
        $lines = explode("\n", trim($this->stdout));
        if (count($lines) < 2) {
            return [];
        }

        // First line is the header
        $headerLine = array_shift($lines);
        $headers = preg_split('/\s{2,}/', trim($headerLine));
        if ($headers === false || $headers === []) {
            return [];
        }

        $headers = array_map(trim(...), $headers);
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip separator lines and empty lines
            if ($line === '' || preg_match('/^[-=]+$/', $line)) {
                continue;
            }

            $values = preg_split('/\s{2,}/', $line);
            if ($values === false) {
                continue;
            }

            $values = array_map(trim(...), $values);
            $row = [];

            foreach ($headers as $i => $header) {
                $row[$header] = $values[$i] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
