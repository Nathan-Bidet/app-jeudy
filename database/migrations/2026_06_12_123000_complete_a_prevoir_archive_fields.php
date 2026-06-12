<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks_archive', function (Blueprint $table): void {
            $table->date('fin_date')->nullable();
            $table->unsignedBigInteger('remorque_id')->nullable();
            $table->text('loading_place')->nullable();
            $table->text('delivery_place')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks_archive', function (Blueprint $table): void {
            $table->dropColumn([
                'fin_date',
                'remorque_id',
                'loading_place',
                'delivery_place',
            ]);
        });
    }
};
