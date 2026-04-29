<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_ldt_plan', function (Blueprint $table): void {
            $table->id();
            $table->date('date_day')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('driver_free')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('vehicle_free')->nullable();
            $table->text('task')->nullable();
            $table->text('comments')->nullable();
            $table->boolean('flag_paper')->nullable();
            $table->boolean('flag_direct')->nullable();
            $table->boolean('flag_boursagri')->nullable();
            $table->string('boursagri_contract')->nullable();
            $table->string('fin')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('sms_livre')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->text('import_error')->nullable();
            $table->string('import_batch_id')->nullable();

            $table->index('imported_at');
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_ldt_plan');
    }
};
