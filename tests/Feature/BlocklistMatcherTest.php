<?php

use App\Models\BlockedHash;
use App\Moderation\BlocklistMatcher;
use App\Support\PerceptualHash;

/**
 * Flip $bits distinct random bits of a 16-hex-character hash.
 */
function flipBits(string $dhash, int $bits): string
{
    $binary = '';
    foreach (str_split($dhash) as $hex) {
        $binary .= str_pad(base_convert($hex, 16, 2), 4, '0', STR_PAD_LEFT);
    }

    $positions = range(0, 63);
    shuffle($positions);

    foreach (array_slice($positions, 0, $bits) as $i) {
        $binary[$i] = $binary[$i] === '1' ? '0' : '1';
    }

    return implode('', array_map(fn ($nibble) => base_convert($nibble, 2, 16), str_split($binary, 4)));
}

function blockedHash(string $dhash): BlockedHash
{
    return BlockedHash::create(['sha256' => hash('sha256', random_bytes(16)), 'dhash' => $dhash]);
}

test('band columns are filled from the dHash on save', function () {
    $hash = blockedHash('00ff10a0ffffffff');

    expect([$hash->band_0, $hash->band_1, $hash->band_2, $hash->band_3, $hash->band_7])->toBe([0, 255, 16, 160, 255]);
});

test('the indexed lookup finds the same closest match as a full scan', function () {
    mt_srand(42);
    $random = fn () => bin2hex(random_bytes(8));

    // Background noise, plus one planted near-duplicate per distance 0-7.
    for ($i = 0; $i < 400; $i++) {
        blockedHash($random());
    }

    $matcher = new BlocklistMatcher;

    foreach (range(0, 7) as $bits) {
        $target = $random();
        blockedHash(flipBits($target, $bits));

        $fullScan = BlockedHash::query()->get()
            ->map(fn (BlockedHash $h) => PerceptualHash::distance($target, $h->dhash))
            ->min();

        $found = $matcher->nearest($target, maxDistance: 7);

        expect($found['lookup'])->toBe('index')
            ->and($found['distance'])->toBe($fullScan)
            ->and($found['distance'])->toBeLessThanOrEqual($bits)
            // Only rows sharing a band are compared, a small share of the table.
            ->and($found['checked'])->toBeLessThan(60);
    }
});

test('past the band guarantee it falls back to scanning every row', function () {
    $target = 'ffffffffffffffff';
    blockedHash(flipBits($target, 10));
    blockedHash('0000000000000000');

    $found = (new BlocklistMatcher)->nearest($target, maxDistance: 12);

    expect($found['lookup'])->toBe('scan')
        ->and($found['checked'])->toBe(2)
        ->and($found['distance'])->toBe(10);
});
