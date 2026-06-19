<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'cotations.view',
        'cotations.manage',
        'cotations.admin',
    ];

    public function up(): void
    {
        Schema::create('cotation_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 40);
            $table->string('key', 80)->unique();
            $table->string('label', 160);
            $table->decimal('value', 12, 4)->nullable();
            $table->string('unit', 24)->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['section', 'sort_order']);
        });

        $now = now();

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', $this->permissions)
                ->where('guard_name', 'web')
                ->pluck('id')
                ->all();

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $adminRole->id,
                ]);
            }
        }

        $defaults = [
            ['section' => 'transport', 'key' => 'depart_depot', 'label' => 'Départ dépôt', 'unit' => '€/t', 'sort_order' => 10],
            ['section' => 'transport', 'key' => 'rendu_camion_complet', 'label' => 'Rendu camion complet', 'unit' => '€/t', 'sort_order' => 20],
            ['section' => 'transport', 'key' => 'rendu_plus_10t', 'label' => 'Rendu > 10 tonnes', 'unit' => '€/t', 'sort_order' => 30],
            ['section' => 'transport', 'key' => 'rendu_plus_5t', 'label' => 'Rendu > 5 tonnes', 'unit' => '€/t', 'sort_order' => 40],
            ['section' => 'transport', 'key' => 'rendu_plus_3t', 'label' => 'Rendu > 3 tonnes', 'unit' => '€/t', 'sort_order' => 50],
            ['section' => 'transport_grid', 'key' => 'transport_grid_config', 'label' => 'Tableau prix des transports', 'unit' => null, 'sort_order' => 10],
            ['section' => 'fuel_grid', 'key' => 'fuel_grid_config', 'label' => 'Tableau prix carburant', 'unit' => null, 'sort_order' => 10],
            ['section' => 'fuel', 'key' => 'prix_carburant', 'label' => 'Prix carburant', 'unit' => '€/L', 'sort_order' => 10],
        ];

        foreach ($defaults as $row) {
            DB::table('cotation_settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    ...$row,
                    'value' => null,
                    'note' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cotation_settings');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds !== []) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'web')
            ->delete();
    }
};
