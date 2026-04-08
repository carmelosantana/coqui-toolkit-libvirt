<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Libvirt\Runtime\DependencyChecker;

test('check returns structured report', function () {
    $checker = new DependencyChecker();
    $report = $checker->check();

    expect($report)->toHaveKey('ready');
    expect($report)->toHaveKey('kvm_available');
    expect($report)->toHaveKey('available');
    expect($report)->toHaveKey('missing');
    expect($report)->toHaveKey('summary');

    expect($report['available'])->toBeArray();
    expect($report['missing'])->toBeArray();
    expect($report['summary'])->toBeString();

    // Every entry should have required keys
    foreach ($report['available'] as $dep) {
        expect($dep)->toHaveKey('name');
        expect($dep)->toHaveKey('version');
        expect($dep)->toHaveKey('description');
    }

    foreach ($report['missing'] as $dep) {
        expect($dep)->toHaveKey('name');
        expect($dep)->toHaveKey('required');
        expect($dep)->toHaveKey('description');
        expect($dep)->toHaveKey('install');
    }
});

test('total deps equals available plus missing', function () {
    $checker = new DependencyChecker();
    $report = $checker->check();

    // We know there are 6 dependencies defined
    $total = count($report['available']) + count($report['missing']);
    expect($total)->toBe(6);
});
