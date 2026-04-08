<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\Xml\DomainXmlBuilder;

test('builds minimal domain XML', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->memory(1024)
        ->vcpus(1)
        ->build();

    expect($xml)->toContain('<name>test-vm</name>');
    expect($xml)->toContain('unit="KiB"');
    expect($xml)->toContain('<vcpu>1</vcpu>');
    expect($xml)->toContain('type="kvm"');
    expect($xml)->toContain('<type arch="x86_64" machine="q35">hvm</type>');
});

test('adds disk device', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->disk('/var/lib/libvirt/images/test.qcow2', 'qcow2', 'virtio')
        ->build();

    expect($xml)->toContain('type="qcow2"');
    expect($xml)->toContain('file="/var/lib/libvirt/images/test.qcow2"');
    expect($xml)->toContain('dev="vda"');
    expect($xml)->toContain('bus="virtio"');
});

test('adds cdrom device', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->cdrom('/isos/ubuntu.iso')
        ->build();

    expect($xml)->toContain('device="cdrom"');
    expect($xml)->toContain('file="/isos/ubuntu.iso"');
});

test('adds network interface', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->network('default', 'network', 'virtio')
        ->build();

    expect($xml)->toContain('type="network"');
    expect($xml)->toContain('network="default"');
    expect($xml)->toContain('type="virtio"');
});

test('configures VNC graphics', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->vnc(-1, '127.0.0.1')
        ->build();

    expect($xml)->toContain('type="vnc"');
    expect($xml)->toContain('port="-1"');
    expect($xml)->toContain('autoport="yes"');
    expect($xml)->toContain('address="127.0.0.1"');
});

test('adds 9p filesystem', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->filesystem('/home/user/share', 'hostshare', '9p')
        ->build();

    expect($xml)->toContain('type="mount"');
    expect($xml)->toContain('type="path"');
    expect($xml)->toContain('dir="/home/user/share"');
    expect($xml)->toContain('dir="hostshare"');
    expect($xml)->toContain('accessmode="passthrough"');
});

test('adds virtiofs filesystem', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->filesystem('/home/user/share', 'hostshare', 'virtiofs')
        ->build();

    expect($xml)->toContain('type="virtiofs"');
    expect($xml)->toContain('dir="/home/user/share"');
    expect($xml)->toContain('queue="1024"');
});

test('adds PCI hostdev for GPU passthrough', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->hostdev('0000:06:00.0')
        ->build();

    expect($xml)->toContain('mode="subsystem"');
    expect($xml)->toContain('type="pci"');
    expect($xml)->toContain('managed="yes"');
    expect($xml)->toContain('domain="0x0000"');
    expect($xml)->toContain('bus="0x06"');
    expect($xml)->toContain('slot="0x00"');
    expect($xml)->toContain('function="0x0"');
});

test('enables CPU host passthrough', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->cpuHostPassthrough(true)
        ->build();

    expect($xml)->toContain('mode="host-passthrough"');
});

test('builder is immutable', function () {
    $original = (new DomainXmlBuilder())->name('original');
    $modified = $original->name('modified');

    $originalXml = $original->build();
    $modifiedXml = $modified->build();

    expect($originalXml)->toContain('<name>original</name>');
    expect($modifiedXml)->toContain('<name>modified</name>');
});

test('requires name to build', function () {
    expect(fn () => (new DomainXmlBuilder())->build())
        ->toThrow(\LogicException::class);
});

test('parsePciAddress extracts components', function () {
    $parsed = DomainXmlBuilder::parsePciAddress('0000:06:00.0');

    expect($parsed)->toBe([
        'domain' => '0x0000',
        'bus' => '0x06',
        'slot' => '0x00',
        'function' => '0x0',
    ]);
});

test('parsePciAddress handles different addresses', function () {
    $parsed = DomainXmlBuilder::parsePciAddress('0000:01:02.3');

    expect($parsed)->toBe([
        'domain' => '0x0000',
        'bus' => '0x01',
        'slot' => '0x02',
        'function' => '0x3',
    ]);
});

test('builds XML with multiple disks', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->disk('/disks/first.qcow2', 'qcow2', 'virtio')
        ->disk('/disks/second.qcow2', 'qcow2', 'virtio')
        ->build();

    expect($xml)->toContain('dev="vda"');
    expect($xml)->toContain('dev="vdb"');
    expect($xml)->toContain('file="/disks/first.qcow2"');
    expect($xml)->toContain('file="/disks/second.qcow2"');
});

test('sets boot device', function () {
    $xml = (new DomainXmlBuilder())
        ->name('test-vm')
        ->bootDev('cdrom')
        ->build();

    expect($xml)->toContain('dev="cdrom"');
});
