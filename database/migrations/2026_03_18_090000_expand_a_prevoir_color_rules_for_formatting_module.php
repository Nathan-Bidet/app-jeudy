<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'a_prevoir_color_rules';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->enum('scope', ['task', 'comment', 'both']);
                $table->enum('match_type', ['contains', 'starts_with', 'regex']);
                $table->string('pattern');
                $table->string('text_color')->nullable();
                $table->string('bg_color')->nullable();
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->boolean('applies_to_a_prevoir')->default(true);
                $table->boolean('applies_to_ldt')->default(true);
                $table->text('description')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('priority', 'aprevoir_color_rules_priority_idx');
                $table->index('is_active', 'aprevoir_color_rules_active_idx');
                $table->index('pattern', 'aprevoir_color_rules_pattern_idx');
            });

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasColumn($tableName, 'name')) {
                $table->string('name')->default('Règle')->after('id');
            }

            if (! Schema::hasColumn($tableName, 'applies_to_a_prevoir')) {
                $table->boolean('applies_to_a_prevoir')->default(true)->after('is_active');
            }

            if (! Schema::hasColumn($tableName, 'applies_to_ldt')) {
                $table->boolean('applies_to_ldt')->default(true)->after('applies_to_a_prevoir');
            }

            if (! Schema::hasColumn($tableName, 'description')) {
                $table->text('description')->nullable()->after('applies_to_ldt');
            }

            if (! Schema::hasColumn($tableName, 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn($tableName, 'updated_by_user_id')) {
                $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            }

            $table->index('priority', 'aprevoir_color_rules_priority_idx');
            $table->index('is_active', 'aprevoir_color_rules_active_idx');
            $table->index('pattern', 'aprevoir_color_rules_pattern_idx');
        });
    }

    public function down(): void
    {
        $tableName = 'a_prevoir_color_rules';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            try {
                $table->dropIndex('aprevoir_color_rules_priority_idx');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('aprevoir_color_rules_active_idx');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('aprevoir_color_rules_pattern_idx');
            } catch (\Throwable) {
            }

            if (Schema::hasColumn($tableName, 'updated_by_user_id')) {
                try {
                    $table->dropForeign(['updated_by_user_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('updated_by_user_id');
            }

            if (Schema::hasColumn($tableName, 'created_by_user_id')) {
                try {
                    $table->dropForeign(['created_by_user_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('created_by_user_id');
            }

            foreach (['description', 'applies_to_ldt', 'applies_to_a_prevoir', 'name'] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
