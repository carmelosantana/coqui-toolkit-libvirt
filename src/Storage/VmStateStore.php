<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Libvirt\Storage;

use PDO;

/**
 * SQLite-backed state tracking for VMs, networks, and console proxies.
 *
 * Provides metadata persistence across agent sessions — tracks which VMs
 * were created, their configuration hashes, console proxy PIDs, and network
 * definitions. The libvirt daemon is the source of truth for runtime state;
 * this store tracks bot-managed metadata only.
 */
final class VmStateStore
{
    private ?PDO $db = null;

    public function __construct(
        private readonly string $dbPath,
    ) {}

    // ── VMs ──────────────────────────────────────────────────────────

    public function saveVm(
        string $name,
        string $state,
        string $xmlHash = '',
        string $meta = '{}',
    ): void {
        $now = date('c');
        $stmt = $this->db()->prepare(<<<SQL
            INSERT INTO vms (name, state, xml_hash, meta, created_at, updated_at)
            VALUES (:name, :state, :xml_hash, :meta, :now, :now)
            ON CONFLICT(name) DO UPDATE SET
                state      = excluded.state,
                xml_hash   = excluded.xml_hash,
                meta       = excluded.meta,
                updated_at = excluded.updated_at
        SQL);
        $stmt->execute([
            ':name'     => $name,
            ':state'    => $state,
            ':xml_hash' => $xmlHash,
            ':meta'     => $meta,
            ':now'      => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVm(string $name): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM vms WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function deleteVm(string $name): void
    {
        $stmt = $this->db()->prepare('DELETE FROM vms WHERE name = :name');
        $stmt->execute([':name' => $name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVms(): array
    {
        $stmt = $this->db()->query('SELECT * FROM vms ORDER BY created_at DESC');

        return $stmt !== false ? array_values($stmt->fetchAll(PDO::FETCH_ASSOC)) : [];
    }

    // ── Console Proxies ──────────────────────────────────────────────

    public function saveProxy(
        string $vmName,
        int $pid,
        int $wsPort,
        int $vncPort,
        string $meta = '{}',
    ): void {
        $now = date('c');
        $stmt = $this->db()->prepare(<<<SQL
            INSERT INTO console_proxies (vm_name, pid, ws_port, vnc_port, meta, started_at)
            VALUES (:vm_name, :pid, :ws_port, :vnc_port, :meta, :now)
            ON CONFLICT(vm_name) DO UPDATE SET
                pid        = excluded.pid,
                ws_port    = excluded.ws_port,
                vnc_port   = excluded.vnc_port,
                meta       = excluded.meta,
                started_at = excluded.started_at
        SQL);
        $stmt->execute([
            ':vm_name'  => $vmName,
            ':pid'      => $pid,
            ':ws_port'  => $wsPort,
            ':vnc_port' => $vncPort,
            ':meta'     => $meta,
            ':now'      => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProxy(string $vmName): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM console_proxies WHERE vm_name = :vm_name');
        $stmt->execute([':vm_name' => $vmName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function deleteProxy(string $vmName): void
    {
        $stmt = $this->db()->prepare('DELETE FROM console_proxies WHERE vm_name = :vm_name');
        $stmt->execute([':vm_name' => $vmName]);
    }

    // ── Networks ─────────────────────────────────────────────────────

    public function saveNetwork(
        string $name,
        string $type,
        string $subnet = '',
        string $meta = '{}',
    ): void {
        $now = date('c');
        $stmt = $this->db()->prepare(<<<SQL
            INSERT INTO networks (name, type, subnet, meta, created_at)
            VALUES (:name, :type, :subnet, :meta, :now)
            ON CONFLICT(name) DO UPDATE SET
                type   = excluded.type,
                subnet = excluded.subnet,
                meta   = excluded.meta
        SQL);
        $stmt->execute([
            ':name'   => $name,
            ':type'   => $type,
            ':subnet' => $subnet,
            ':meta'   => $meta,
            ':now'    => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNetwork(string $name): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM networks WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function deleteNetwork(string $name): void
    {
        $stmt = $this->db()->prepare('DELETE FROM networks WHERE name = :name');
        $stmt->execute([':name' => $name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNetworks(): array
    {
        $stmt = $this->db()->query('SELECT * FROM networks ORDER BY created_at DESC');

        return $stmt !== false ? array_values($stmt->fetchAll(PDO::FETCH_ASSOC)) : [];
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new PDO("sqlite:{$this->dbPath}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $this->db = $db;

        $this->createTables();

        return $db;
    }

    private function createTables(): void
    {
        $this->db()->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS vms (
                name TEXT PRIMARY KEY,
                state TEXT NOT NULL DEFAULT 'unknown',
                xml_hash TEXT NOT NULL DEFAULT '',
                meta TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db()->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS console_proxies (
                vm_name TEXT PRIMARY KEY,
                pid INTEGER NOT NULL,
                ws_port INTEGER NOT NULL,
                vnc_port INTEGER NOT NULL,
                meta TEXT NOT NULL DEFAULT '{}',
                started_at TEXT NOT NULL,
                FOREIGN KEY (vm_name) REFERENCES vms(name) ON DELETE CASCADE
            )
        SQL);

        $this->db()->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS networks (
                name TEXT PRIMARY KEY,
                type TEXT NOT NULL DEFAULT 'nat',
                subnet TEXT NOT NULL DEFAULT '',
                meta TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL
            )
        SQL);
    }
}
