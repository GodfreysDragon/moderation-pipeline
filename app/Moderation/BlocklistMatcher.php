<?php

namespace App\Moderation;

use App\Models\BlockedHash;
use App\Support\PerceptualHash;

/**
 * Finds the closest blocked image to an upload's dHash.
 *
 * Up to BANDS - 1 bits of difference, the indexed band columns narrow the search
 * to rows that share at least one band; past that the guarantee no longer holds,
 * so it falls back to checking every row.
 */
class BlocklistMatcher
{
    /**
     * @return array{hash: BlockedHash|null, distance: int|null, checked: int, lookup: 'index'|'scan'}
     */
    public function nearest(string $dhash, int $maxDistance): array
    {
        $columns = ['id', 'dhash', 'category', 'source_submission_id'];

        if ($maxDistance < BlockedHash::BANDS) {
            $candidates = BlockedHash::query()
                ->select($columns)
                ->where(function ($query) use ($dhash) {
                    foreach (BlockedHash::bands($dhash) as $i => $value) {
                        $query->orWhere("band_{$i}", $value);
                    }
                })
                ->get();

            return [...$this->closest($dhash, $candidates), 'lookup' => 'index'];
        }

        return [...$this->closest($dhash, BlockedHash::query()->select($columns)->lazyById(1000)), 'lookup' => 'scan'];
    }

    /**
     * @param  iterable<BlockedHash>  $hashes
     * @return array{hash: BlockedHash|null, distance: int|null, checked: int}
     */
    private function closest(string $dhash, iterable $hashes): array
    {
        $best = null;
        $distance = null;
        $checked = 0;

        foreach ($hashes as $hash) {
            $checked++;
            $d = PerceptualHash::distance($dhash, $hash->dhash);

            if ($distance === null || $d < $distance) {
                $distance = $d;
                $best = $hash;
            }
        }

        return ['hash' => $best, 'distance' => $distance, 'checked' => $checked];
    }
}
