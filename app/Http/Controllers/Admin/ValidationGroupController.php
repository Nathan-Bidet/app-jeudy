<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ValidationGroupRequest;
use App\Models\ValidationGroup;
use App\Services\AuditLogService;
use App\Services\Validation\ValidationGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD des groupes de validation, pilotés depuis ADMIN - CONGÉS.
 *
 * La logique métier (transactions, unicité d'appartenance) vit dans
 * ValidationGroupService ; l'autorisation dans ValidationGroupPolicy.
 */
class ValidationGroupController extends Controller
{
    public function __construct(
        private readonly ValidationGroupService $validationGroups,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function store(ValidationGroupRequest $request): RedirectResponse
    {
        $this->authorize('create', ValidationGroup::class);

        $group = $this->validationGroups->create($request->groupAttributes());

        $this->auditLogService->log([
            'action' => 'create_validation_group',
            'module' => 'leaves',
            'description' => sprintf('Groupe de validation « %s » créé', $group->name),
            'payload' => [
                'validation_group_id' => (int) $group->id,
                'validator_1_id' => $group->validator_1_id,
                'validator_2_id' => $group->validator_2_id,
                'member_count' => $group->members()->count(),
            ],
        ]);

        return back()->with('success', 'Groupe de validation créé.');
    }

    public function update(ValidationGroupRequest $request, ValidationGroup $validationGroup): RedirectResponse
    {
        $this->authorize('update', $validationGroup);

        $group = $this->validationGroups->update($validationGroup, $request->groupAttributes());

        $this->auditLogService->log([
            'action' => 'update_validation_group',
            'module' => 'leaves',
            'description' => sprintf('Groupe de validation « %s » modifié', $group->name),
            'payload' => [
                'validation_group_id' => (int) $group->id,
                'validator_1_id' => $group->validator_1_id,
                'validator_2_id' => $group->validator_2_id,
                'member_count' => $group->members()->count(),
            ],
        ]);

        return back()->with('success', 'Groupe de validation mis à jour.');
    }

    public function destroy(Request $request, ValidationGroup $validationGroup): RedirectResponse
    {
        $this->authorize('delete', $validationGroup);

        $name = $validationGroup->name;
        $memberCount = $validationGroup->memberships()->count();

        $this->validationGroups->delete($validationGroup);

        $this->auditLogService->log([
            'action' => 'delete_validation_group',
            'module' => 'leaves',
            'description' => sprintf('Groupe de validation « %s » supprimé', $name),
            'payload' => [
                'name' => $name,
                'freed_member_count' => $memberCount,
            ],
        ]);

        return back()->with('success', 'Groupe de validation supprimé. Ses membres sont de nouveau affectables.');
    }
}
