<?php

use App\Models\BlockedHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocked_hashes', function (Blueprint $table) {
            for ($i = 0; $i < BlockedHash::BANDS; $i++) {
                $table->unsignedTinyInteger("band_{$i}")->default(0)->index();
            }
        });

        DB::table('blocked_hashes')->select(['id', 'dhash'])->orderBy('id')->each(function (object $row) {
            $bands = [];

            foreach (BlockedHash::bands($row->dhash) as $i => $value) {
                $bands["band_{$i}"] = $value;
            }

            DB::table('blocked_hashes')->where('id', $row->id)->update($bands);
        });
    }

    public function down(): void
    {
        Schema::table('blocked_hashes', function (Blueprint $table) {
            for ($i = 0; $i < BlockedHash::BANDS; $i++) {
                $table->dropIndex("blocked_hashes_band_{$i}_index");
                $table->dropColumn("band_{$i}");
            }
        });
    }
};
