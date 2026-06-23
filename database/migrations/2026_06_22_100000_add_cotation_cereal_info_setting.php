<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('cotation_settings')->updateOrInsert(
            ['key' => 'cereal_info_html'],
            [
                'section' => 'cereal_info',
                'label' => 'Information cotations céréales',
                'value' => null,
                'unit' => null,
                'note' => null,
                'sort_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('cotation_settings')
            ->where('key', 'cereal_info_html')
            ->delete();
    }
};
