<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\Storage\VmStateStore;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-' . uniqid() . '.db';
    $this->store = new VmStateStore($this->dbPath);
});

afterEach(function () {
    if (isset($this->dbPath) && is_file($this->dbPath)) {
        @unlink($this->dbPath);
        // WAL files
        @unlink($this->dbPath . '-wal');
        @unlink($this->dbPath . '-shm');
    }
});

// ── VM Tests ─────────────────────────────────────────────────────

test('saves and retrieves a VM', function () {
    $this->store->saveVm('test-vm', 'running', 'abc123', '{"cpu": 2}');

    $vm = $this->store->getVm('test-vm');

    expect($vm)->not->toBeNull();
    expect($vm['name'])->toBe('test-vm');
    expect($vm['state'])->toBe('running');
    expect($vm['xml_hash'])->toBe('abc123');
    expect($vm['meta'])->toBe('{"cpu": 2}');
});

test('updates existing VM on conflict', function () {
    $this->store->saveVm('test-vm', 'defined');
    $this->store->saveVm('test-vm', 'running');

    $vm = $this->store->getVm('test-vm');

    expect($vm['state'])->toBe('running');
});

test('lists all VMs', function () {
    $this->store->saveVm('vm-a', 'running');
    $this->store->saveVm('vm-b', 'stopped');

    $vms = $this->store->listVms();

    expect($vms)->toHaveCount(2);
});

test('deletes a VM', function () {
    $this->store->saveVm('test-vm', 'running');
    $this->store->deleteVm('test-vm');

    expect($this->store->getVm('test-vm'))->toBeNull();
});

test('returns null for non-existent VM', function () {
    expect($this->store->getVm('does-not-exist'))->toBeNull();
});

// ── Console Proxy Tests ──────────────────────────────────────────

test('saves and retrieves a proxy', function () {
    $this->store->saveVm('test-vm', 'running');
    $this->store->saveProxy('test-vm', 12345, 6080, 5900);

    $proxy = $this->store->getProxy('test-vm');

    expect($proxy)->not->toBeNull();
    expect($proxy['pid'])->toBe(12345);
    expect($proxy['ws_port'])->toBe(6080);
    expect($proxy['vnc_port'])->toBe(5900);
});

test('deletes a proxy', function () {
    $this->store->saveVm('test-vm', 'running');
    $this->store->saveProxy('test-vm', 12345, 6080, 5900);
    $this->store->deleteProxy('test-vm');

    expect($this->store->getProxy('test-vm'))->toBeNull();
});

// ── Network Tests ────────────────────────────────────────────────

test('saves and retrieves a network', function () {
    $this->store->saveNetwork('test-net', 'nat', '192.168.100.0/24');

    $network = $this->store->getNetwork('test-net');

    expect($network)->not->toBeNull();
    expect($network['name'])->toBe('test-net');
    expect($network['type'])->toBe('nat');
    expect($network['subnet'])->toBe('192.168.100.0/24');
});

test('lists all networks', function () {
    $this->store->saveNetwork('net-a', 'nat');
    $this->store->saveNetwork('net-b', 'isolated');

    $networks = $this->store->listNetworks();

    expect($networks)->toHaveCount(2);
});

test('deletes a network', function () {
    $this->store->saveNetwork('test-net', 'nat');
    $this->store->deleteNetwork('test-net');

    expect($this->store->getNetwork('test-net'))->toBeNull();
});

test('database is created lazily', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-lazy-' . uniqid() . '.db';
    $store = new VmStateStore($dbPath);

    // DB file should not exist yet
    expect(is_file($dbPath))->toBeFalse();

    // Trigger lazy creation
    $store->listVms();

    expect(is_file($dbPath))->toBeTrue();

    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});
