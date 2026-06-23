<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_group_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 40);
            $table->date('date');
            $table->string('group_key', 191);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['module', 'date', 'group_key']);
            $table->index(['module', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_group_orders');
    }
};
