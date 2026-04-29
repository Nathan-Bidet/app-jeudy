<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a_prevoir_tasks', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('assignee_type', ['user', 'depot']);
            $table->unsignedBigInteger('assignee_id');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->text('task');
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

            $table->index(['date', 'assignee_type', 'assignee_id', 'position'], 'aprev_group_pos_idx');
            $table->index('is_boursagri', 'aprev_is_boursagri_idx');
            $table->index('pointed', 'aprev_pointed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a_prevoir_tasks');
    }
};

