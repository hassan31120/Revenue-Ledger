<?php

declare(strict_types=1);

use App\Support\Money;

it('formats minor units for display', function (int $minor, string $expected) {
    expect(Money::format($minor, 'EGP'))->toBe($expected);
})->with([
    [10050, 'EGP 100.50'],
    [0, 'EGP 0.00'],
    [5, 'EGP 0.05'],
    [99, 'EGP 0.99'],
    [100, 'EGP 1.00'],
    [-2500, '-EGP 25.00'],
    [-1, '-EGP 0.01'],
    [179999, 'EGP 1799.99'],
]);

it('parses a major-unit string without ever touching a float', function (string $input, int $expected) {
    expect(Money::fromMajorString($input))->toBe($expected);
})->with([
    ['100.50', 10050],
    ['100.5', 10050],
    ['100', 10000],
    ['0.01', 1],
    ['0', 0],
    ['-25.00', -2500],
    ['1799.99', 179999],
]);

it('refuses input it cannot represent exactly', function (string $input) {
    expect(fn () => Money::fromMajorString($input))->toThrow(InvalidArgumentException::class);
})->with([
    '100.505',   // more precision than a minor unit can hold
    'abc',
    '',
    '1,000.00',
    '1e3',
]);

it('round-trips every value through format and parse', function () {
    foreach ([0, 1, 99, 100, 10050, 179999, 999999999] as $minor) {
        $formatted = Money::format($minor, 'EGP');
        $parsed = Money::fromMajorString(str_replace('EGP ', '', $formatted));

        expect($parsed)->toBe($minor);
    }
});
