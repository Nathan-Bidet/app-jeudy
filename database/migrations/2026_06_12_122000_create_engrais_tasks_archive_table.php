<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engrais_tasks_archive', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_task_id')->nullable();
            $table->date('date')->nullable();
            $table->date('fin_date')->nullable();
            $table->string('assignee_type', 32)->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->string('assignee_label_free')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('remorque_id')->nullable();
            $table->text('task');
            $table->text('loading_place')->nullable();
            $table->text('delivery_place')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_direct')->default(false);
            $table->boolean('is_boursagri')->default(false);
            $table->string('boursagri_contract_number')->nullable();
            $table->json('indicators')->nullable();
            $table->boolean('pointed')->default(false);
            $table->dateTime('pointed_at')->nullable();
            $table->unsignedBigInteger('pointed_by_user_id')->nullable();
            $table->integer('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->dateTime('archived_at');
            $table->boolean('archived_by_system')->default(true);
            $table->timestamps();

            $table->index('date');
            $table->index('archived_at');
            $table->index('original_task_id');
            $table->index(['date', 'assignee_type', 'assignee_id'], 'engrais_archive_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engrais_tasks_archive');
    }
};
