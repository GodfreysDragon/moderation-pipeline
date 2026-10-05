<?php

use App\Support\PerceptualHash;
use Tests\Support\Images;

function hashOf(string $bytes): string
{
    return PerceptualHash::dhash(imagecreatefromstring($bytes));
}

test('the same picture resized and re-encoded hashes almost identically', function () {
    $original = hashOf(Images::png(640, 480, seed: 7));
    $smallerJpeg = hashOf(Images::jpeg(200, 150, seed: 7));

    expect($original)->toMatch('/^[0-9a-f]{16}$/')
        ->and(PerceptualHash::distance($original, $smallerJpeg))->toBeLessThanOrEqual(6);
});

test('different pictures are far apart', function () {
    expect(PerceptualHash::distance(hashOf(Images::png(seed: 1)), hashOf(Images::png(seed: 2))))->toBeGreaterThan(10);
});

test('distance counts differing bits', function () {
    expect(PerceptualHash::distance('0000000000000000', '0000000000000000'))->toBe(0)
        ->and(PerceptualHash::distance('0000000000000000', 'ffffffffffffffff'))->toBe(64)
        ->and(PerceptualHash::distance('0000000000000001', '0000000000000003'))->toBe(1)
        ->and(PerceptualHash::distance('8000000000000000', '0000000000000000'))->toBe(1);
});

test('malformed hashes are rejected', function () {
    PerceptualHash::distance('xyz', '0000000000000000');
})->throws(InvalidArgumentException::class);
