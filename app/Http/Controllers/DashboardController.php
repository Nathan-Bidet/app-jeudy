<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardDataService $dashboardDataService
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $user->loadMissing('sector:id,name');

        return Inertia::render('Dashboard/Index', [
            'dashboard' => $this->dashboardDataService->buildForUser($user),
            'viewer' => [
                'is_admin' => (bool) $user->hasRole('admin'),
                'sector_name' => $user->sector?->name,
            ],
        ]);
    }
}
