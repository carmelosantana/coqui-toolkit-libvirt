<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\Xml\NetworkXmlBuilder;

test('builds NAT network XML', function () {
    $xml = (new NetworkXmlBuilder())
        ->name('test-net')
        ->nat('192.168.100.0/24')
        ->build();

    expect($xml)->toContain('<name>test-net</name>');
    expect($xml)->toContain('mode="nat"');
    expect($xml)->toContain('address="192.168.100.1"');
    expect($xml)->toContain('netmask="255.255.255.0"');
    expect($xml)->toContain('start="192.168.100.2"');
});

test('builds isolated network XML', function () {
    $xml = (new NetworkXmlBuilder())
        ->name('isolated-net')
        ->isolated('10.10.0.0/24')
        ->build();

    expect($xml)->toContain('<name>isolated-net</name>');
    // Isolated networks have no <forward> element
    expect($xml)->not->toContain('mode="nat"');
    expect($xml)->not->toContain('mode="bridge"');
    expect($xml)->toContain('address="10.10.0.1"');
});

test('builds bridged network XML', function () {
    $xml = (new NetworkXmlBuilder())
        ->name('bridge-net')
        ->bridged('br0')
        ->build();

    expect($xml)->toContain('<name>bridge-net</name>');
    expect($xml)->toContain('mode="bridge"');
    expect($xml)->toContain('name="br0"');
    // Bridged networks don't have IP/DHCP config
    expect($xml)->not->toContain('<dhcp>');
});

test('disables DHCP', function () {
    $xml = (new NetworkXmlBuilder())
        ->name('no-dhcp')
        ->nat()
        ->dhcp(false)
        ->build();

    expect($xml)->not->toContain('<dhcp>');
    expect($xml)->not->toContain('<range');
});

test('requires name to build', function () {
    expect(fn () => (new NetworkXmlBuilder())->nat()->build())
        ->toThrow(\LogicException::class);
});

test('builder is immutable', function () {
    $original = (new NetworkXmlBuilder())->name('original');
    $modified = $original->name('modified');

    $originalXml = $original->nat()->build();
    $modifiedXml = $modified->nat()->build();

    expect($originalXml)->toContain('<name>original</name>');
    expect($modifiedXml)->toContain('<name>modified</name>');
});

test('default NAT subnet is 192.168.122.0/24', function () {
    $xml = (new NetworkXmlBuilder())
        ->name('default-nat')
        ->nat()
        ->build();

    expect($xml)->toContain('address="192.168.122.1"');
    expect($xml)->toContain('netmask="255.255.255.0"');
});

test('generates unique bridge name from network name', function () {
    $xml1 = (new NetworkXmlBuilder())->name('net-a')->nat()->build();
    $xml2 = (new NetworkXmlBuilder())->name('net-b')->nat()->build();

    // Bridge names should differ (based on md5 of name)
    expect($xml1)->toContain('name="virbr-');
    expect($xml2)->toContain('name="virbr-');

    // Extract bridge names and verify they're different
    preg_match('/name="(virbr-[a-f0-9]+)"/', $xml1, $m1);
    preg_match('/name="(virbr-[a-f0-9]+)"/', $xml2, $m2);
    expect($m1[1])->not->toBe($m2[1]);
});
