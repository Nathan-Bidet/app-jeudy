<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ldt_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->enum('assignee_type', ['user', 'depot', 'none'])->default('none');
            $table->unsignedBigInteger('assignee_id')->default(0);
            $table->string('assignee_label');
            $table->json('phones')->nullable();
            $table->longText('tasks_text');
            $table->longText('comments_text')->nullable();
            $table->text('vehicles_text')->nullable();
            $table->json('indicators')->nullable();
            $table->boolean('is_all_pointed')->default(false);

            $table->boolean('sms_sent')->default(false);
            $table->dateTime('sms_sent_at')->nullable();
            $table->foreignId('sms_sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('source_task_ids');
            $table->json('color_style')->nullable();
            $table->dateTime('updated_from_source_at');
            $table->timestamps();

            $table->unique(['date', 'assignee_type', 'assignee_id'], 'ldt_entries_date_assignee_unique');
            $table->index(['date', 'assignee_type', 'assignee_id'], 'ldt_entries_date_assignee_idx');
            $table->index('date', 'ldt_entries_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldt_entries');
    }
};
