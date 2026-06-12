<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engrais_tasks', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->date('fin_date')->nullable();
            $table->string('assignee_type', 32)->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->string('assignee_label_free')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('remorque_id')->nullable()->constrained('vehicles')->nullOnDelete();
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
            $table->foreignId('pointed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pointed', 'date', 'position'], 'engrais_pointed_date_position_idx');
            $table->index(['date', 'assignee_type', 'assignee_id', 'position'], 'engrais_group_position_idx');
            $table->index(['vehicle_id', 'date'], 'engrais_vehicle_date_idx');
            $table->index('is_boursagri', 'engrais_boursagri_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engrais_tasks');
    }
};
