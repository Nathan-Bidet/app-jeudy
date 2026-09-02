<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validation à deux niveaux des journées d'heures.
 *
 * Le module Heures n'avait aucun circuit de validation : une journée était
 * saisie puis exportée. Les colonnes ajoutées ici sont donc les premières de
 * ce genre sur `hour_sheets`, et elles reprennent exactement le vocabulaire des
 * congés pour que le service partagé travaille indifféremment sur les deux.
 *
 * `status` est volontairement NULLABLE, et NULL a un sens précis : « journée
 * saisie avant la mise en place de la validation ». Ces lignes ne sont ni
 * validées ni refusées — elles n'ont jamais été soumises à qui que ce soit.
 * Les compter comme « en attente » remplirait les files des valideurs d'un
 * arriéré fictif ; les marquer « validées » affirmerait une validation qui n'a
 * pas eu lieu. NULL dit la seule chose vraie, et les files de validation
 * filtrent naturellement dessus. Toute réouverture d'une de ces journées la
 * fait entrer normalement dans le circuit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_sheets', function (Blueprint $table): void {
            $table->string('status')->nullable()->after('is_continuous_day');

            $table->foreignId('validation_group_id')->nullable()->after('status')
                ->constrained('validation_groups')->nullOnDelete();
            $table->string('validation_group_name')->nullable()->after('validation_group_id');

            $table->foreignId('validator_1_id')->nullable()->after('validation_group_name')
                ->constrained('users')->nullOnDelete();
            $table->string('validator_1_label')->nullable()->after('validator_1_id');
            $table->dateTime('validator_1_decided_at')->nullable()->after('validator_1_label');
            $table->foreignId('validator_1_decided_by_id')->nullable()->after('validator_1_decided_at')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('validator_2_id')->nullable()->after('validator_1_decided_by_id')
                ->constrained('users')->nullOnDelete();
            $table->string('validator_2_label')->nullable()->after('validator_2_id');
            $table->dateTime('validator_2_decided_at')->nullable()->after('validator_2_label');
            $table->foreignId('validator_2_decided_by_id')->nullable()->after('validator_2_decided_at')
                ->constrained('users')->nullOnDelete();

            $table->text('refusal_reason')->nullable()->after('validator_2_decided_by_id');

            $table->index(['validator_1_id', 'status']);
            $table->index(['validator_2_id', 'status']);
        });

        // Rien à reprendre : les journées existantes gardent status = NULL,
        // c'est-à-dire « antérieures au circuit de validation ».
    }

    public function down(): void
    {
        Schema::table('hour_sheets', function (Blueprint $table): void {
            $table->dropIndex(['validator_1_id', 'status']);
            $table->dropIndex(['validator_2_id', 'status']);
            $table->dropConstrainedForeignId('validation_group_id');
            $table->dropConstrainedForeignId('validator_1_id');
            $table->dropConstrainedForeignId('validator_1_decided_by_id');
            $table->dropConstrainedForeignId('validator_2_id');
            $table->dropConstrainedForeignId('validator_2_decided_by_id');
            $table->dropColumn([
                'status',
                'validation_group_name',
                'validator_1_label',
                'validator_1_decided_at',
                'validator_2_label',
                'validator_2_decided_at',
                'refusal_reason',
            ]);
        });
    }
};
