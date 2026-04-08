<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\Runtime\VirshResult;

test('success returns true for exit code 0', function () {
    $result = new VirshResult(0, 'output', '');

    expect($result->success())->toBeTrue();
    expect($result->output())->toBe('output');
    expect($result->error())->toBe('');
});

test('success returns false for non-zero exit code', function () {
    $result = new VirshResult(1, '', 'error message');

    expect($result->success())->toBeFalse();
    expect($result->output())->toBe('');
    expect($result->error())->toBe('error message');
});

test('error falls back to exit code message when stderr is empty', function () {
    $result = new VirshResult(127, '', '');

    expect($result->error())->toBe('Command failed with exit code 127');
});

test('parseKeyValue extracts key-value pairs', function () {
    $stdout = <<<OUTPUT
        Id:             5
        Name:           test-vm
        UUID:           abc-123
        OS Type:        hvm
        State:          running
        CPU(s):         2
        Max memory:     2097152 KiB
        Used memory:    2097152 KiB
        OUTPUT;

    $result = new VirshResult(0, $stdout, '');
    $parsed = $result->parseKeyValue();

    expect($parsed)->toHaveKey('Name', 'test-vm');
    expect($parsed)->toHaveKey('State', 'running');
    expect($parsed)->toHaveKey('CPU(s)', '2');
    expect($parsed)->toHaveKey('OS Type', 'hvm');
});

test('parseKeyValue handles empty output', function () {
    $result = new VirshResult(0, '', '');

    expect($result->parseKeyValue())->toBe([]);
});

test('parseTable parses virsh list output', function () {
    $stdout = <<<OUTPUT
         Id   Name       State
        ---------------------
         1    vm-one     running
         -    vm-two     shut off
        OUTPUT;

    $result = new VirshResult(0, $stdout, '');
    $rows = $result->parseTable();

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toHaveKey('Name', 'vm-one');
    expect($rows[0])->toHaveKey('State', 'running');
    expect($rows[1])->toHaveKey('Name', 'vm-two');
    expect($rows[1])->toHaveKey('State', 'shut off');
});

test('parseTable returns empty for insufficient lines', function () {
    $result = new VirshResult(0, 'header only', '');

    expect($result->parseTable())->toBe([]);
});

test('parseTable skips separator lines', function () {
    $stdout = <<<OUTPUT
         Name       State
        ===========================
         my-vm      running
        OUTPUT;

    $result = new VirshResult(0, $stdout, '');
    $rows = $result->parseTable();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['Name'])->toBe('my-vm');
});
