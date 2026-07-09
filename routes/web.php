<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AprevoirController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\CalendarCategoryController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CotationController;
use App\Http\Controllers\FormattingRuleController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HourSheetController;
use App\Http\Controllers\LdtController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityProfileController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\EngraisController;
use App\Http\Controllers\TaskFuelController;
use App\Http\Controllers\TaskTiersController;
use App\Http\Controllers\TasksDataController;
use App\Http\Controllers\UserFileController;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', 'twofactor'])
    ->name('dashboard');

Route::middleware(['auth', 'twofactor'])->group(function () {
    Route::get('/profile', function () {
        $user = request()->user();

        return Inertia::render('Security/ProfileEdit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'isTotpEnabled' => ! empty($user?->totp_secret) && ! empty($user?->totp_enabled_at),
            'status' => session('status'),
        ]);
    })->name('profile.edit');

    Route::get('/settings', fn () => redirect()->route('profile.edit'))->name('settings.index');

    Route::patch('/profile', [SecurityProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified', 'twofactor'])->group(function () {
    Route::get('/a-prevoir', [AprevoirController::class, 'index'])
        ->middleware('sector.access:a_prevoir.view')
        ->name('a_prevoir.index');
    Route::post('/a-prevoir/tasks', [AprevoirController::class, 'store'])
        ->middleware('sector.access:a_prevoir.create')
        ->name('a_prevoir.tasks.store');
    Route::put('/a-prevoir/tasks/{task}', [AprevoirController::class, 'update'])
        ->middleware('sector.access:a_prevoir.update')
        ->name('a_prevoir.tasks.update');
    Route::delete('/a-prevoir/tasks/{task}', [AprevoirController::class, 'destroy'])
        ->middleware('sector.access:a_prevoir.delete')
        ->name('a_prevoir.tasks.destroy');
    Route::patch('/a-prevoir/tasks/{task}/point', [AprevoirController::class, 'point'])
        ->middleware('sector.access:a_prevoir.point')
        ->name('a_prevoir.tasks.point');
    Route::patch('/a-prevoir/tasks/{task}/partial-point', [AprevoirController::class, 'partialPoint'])
        ->middleware('sector.access:a_prevoir.partial_point')
        ->name('a_prevoir.tasks.partial-point');
    Route::patch('/a-prevoir/tasks/{task}/position', [AprevoirController::class, 'updatePosition'])
        ->middleware('sector.access:a_prevoir.update')
        ->name('a_prevoir.tasks.position');
    Route::patch('/a-prevoir/groups/position', [AprevoirController::class, 'updateGroupOrder'])
        ->middleware('sector.access:a_prevoir.update')
        ->name('a_prevoir.groups.position');
    Route::get('/a-prevoir/tasks-data', [AprevoirController::class, 'tasksData'])
        ->middleware('sector.access:a_prevoir.view')
        ->name('a_prevoir.tasks.data');

    Route::get('/engrais', [EngraisController::class, 'index'])
        ->middleware('sector.access:engrais.view')
        ->name('engrais.index');
    Route::post('/engrais/tasks', [EngraisController::class, 'store'])
        ->middleware('sector.access:engrais.create')
        ->name('engrais.tasks.store');
    Route::put('/engrais/tasks/{task}', [EngraisController::class, 'update'])
        ->middleware('sector.access:engrais.update')
        ->name('engrais.tasks.update');
    Route::delete('/engrais/tasks/{task}', [EngraisController::class, 'destroy'])
        ->middleware('sector.access:engrais.delete')
        ->name('engrais.tasks.destroy');
    Route::patch('/engrais/tasks/{task}/point', [EngraisController::class, 'point'])
        ->middleware('sector.access:engrais.point')
        ->name('engrais.tasks.point');
    Route::patch('/engrais/tasks/{task}/position', [EngraisController::class, 'updatePosition'])
        ->middleware('sector.access:engrais.update')
        ->name('engrais.tasks.position');
    Route::patch('/engrais/groups/position', [EngraisController::class, 'updateGroupOrder'])
        ->middleware('sector.access:engrais.update')
        ->name('engrais.groups.position');
    Route::get('/engrais/tasks-data', [EngraisController::class, 'tasksData'])
        ->middleware('sector.access:engrais.view')
        ->name('engrais.tasks.data');

    Route::get('/ldt', [LdtController::class, 'index'])
        ->middleware('sector.access:ldt.view')
        ->name('ldt.index');
    Route::patch('/ldt/{entry}/sms', [LdtController::class, 'markSms'])
        ->middleware('sector.access:ldt.sms')
        ->name('ldt.entries.sms');

    Route::get('/activities/leaves', [LeaveRequestController::class, 'index'])
        ->name('leaves.index');
    Route::get('/activities/hours', [HourSheetController::class, 'index'])
        ->middleware('sector.access:heures.view')
        ->name('hours.index');
    Route::get('/activities/hours/export', [HourSheetController::class, 'export'])
        ->middleware('sector.access:heures.export')
        ->middleware('throttle:hours-export')
        ->name('hours.export');
    Route::post('/activities/hours', [HourSheetController::class, 'store'])
        ->middleware('sector.access:heures.create')
        ->middleware('throttle:hours-actions')
        ->name('hours.store');
    Route::post('/activities/leaves', [LeaveRequestController::class, 'store'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.store');
    Route::post('/leaves/{id}/approve', [LeaveRequestController::class, 'approve'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.approve');
    Route::post('/leaves/{id}/refuse', [LeaveRequestController::class, 'refuse'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.refuse');
    Route::post('/leaves/{id}/propose-modification', [LeaveRequestController::class, 'proposeModification'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.propose_modification');
    Route::post('/leaves/{id}/accept-modification', [LeaveRequestController::class, 'acceptProposedModification'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.accept_modification');
    Route::post('/leaves/{id}/refuse-modification', [LeaveRequestController::class, 'refuseProposedModification'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.refuse_modification');
    Route::delete('/leaves/{id}', [LeaveRequestController::class, 'destroy'])
        ->middleware('throttle:leave-actions')
        ->name('leaves.destroy');
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read_all');
    Route::get('/notifications/latest', [NotificationController::class, 'latest'])
        ->name('notifications.latest');
    Route::delete('/notifications/{notificationId}', [NotificationController::class, 'destroy'])
        ->name('notifications.destroy');
    Route::get('/global-search', [GlobalSearchController::class, 'index'])
        ->name('global-search');

    Route::get('/tasks/formatting', [FormattingRuleController::class, 'index'])
        ->middleware('sector.access:task.formatting.view')
        ->name('task.formatting.index');
    Route::post('/tasks/formatting', [FormattingRuleController::class, 'store'])
        ->middleware('sector.access:task.formatting.manage')
        ->name('task.formatting.store');
    Route::put('/tasks/formatting/{formattingRule}', [FormattingRuleController::class, 'update'])
        ->middleware('sector.access:task.formatting.manage')
        ->name('task.formatting.update');
    Route::patch('/tasks/formatting/reorder', [FormattingRuleController::class, 'reorder'])
        ->middleware('sector.access:task.formatting.manage')
        ->name('task.formatting.reorder');
    Route::delete('/tasks/formatting/{formattingRule}', [FormattingRuleController::class, 'destroy'])
        ->middleware('sector.access:task.formatting.manage')
        ->name('task.formatting.destroy');

    Route::get('/tasks/data', [TasksDataController::class, 'index'])
        ->middleware('sector.access:task.data.view')
        ->name('task.data.index');

    Route::get('/tasks/fuel', [TaskFuelController::class, 'index'])
        ->middleware('sector.access:task.fuel.view')
        ->name('task.fuel.index');
    Route::post('/tasks/fuel', [TaskFuelController::class, 'store'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.store');
    Route::patch('/tasks/fuel/{delivery}/point', [TaskFuelController::class, 'point'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.point');
    Route::patch('/tasks/fuel/{delivery}', [TaskFuelController::class, 'update'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.update');
    Route::delete('/tasks/fuel/{delivery}', [TaskFuelController::class, 'destroy'])
        ->middleware('sector.access:task.fuel.delete')
        ->name('task.fuel.destroy');
    Route::get('/tasks/fuel/tiers-search', [TaskFuelController::class, 'tiersSearch'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.tiers-search');
    Route::get('/tasks/fuel/new-clients', [TaskFuelController::class, 'newClients'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.new-clients');
    Route::post('/tasks/fuel/new-clients', [TaskFuelController::class, 'storeNewClient'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.new-clients.store');
    Route::patch('/tasks/fuel/new-clients/{newClient}/validate', [TaskFuelController::class, 'validateNewClient'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.new-clients.validate');
    Route::post('/tasks/fuel/options', [TaskFuelController::class, 'storeOption'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.options.store');
    Route::put('/tasks/fuel/options/{option}', [TaskFuelController::class, 'updateOption'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.options.update');
    Route::delete('/tasks/fuel/options/{option}', [TaskFuelController::class, 'destroyOption'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.options.destroy');

    Route::post('/tasks/fuel/recurrings', [TaskFuelController::class, 'storeRecurring'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.recurrings.store');
    Route::put('/tasks/fuel/recurrings/{recurring}', [TaskFuelController::class, 'updateRecurring'])
        ->middleware('sector.access:task.fuel.update')
        ->name('task.fuel.recurrings.update');
    Route::delete('/tasks/fuel/recurrings/{recurring}', [TaskFuelController::class, 'destroyRecurring'])
        ->middleware('sector.access:task.fuel.delete')
        ->name('task.fuel.recurrings.destroy');

    Route::get('/tasks/tiers', [TaskTiersController::class, 'index'])
        ->middleware('sector.access:task.tiers.view')
        ->name('task.tiers.index');
    Route::get('/tasks/tiers/records', [TaskTiersController::class, 'records'])
        ->middleware('sector.access:task.tiers.view')
        ->name('task.tiers.records');
    Route::post('/tasks/tiers/records', [TaskTiersController::class, 'storeRecord'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.records.store');
    Route::put('/tasks/tiers/records/{record}', [TaskTiersController::class, 'updateRecord'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.records.update');
    Route::delete('/tasks/tiers/records/{record}', [TaskTiersController::class, 'destroyRecord'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.records.destroy');
    Route::post('/tasks/tiers/columns', [TaskTiersController::class, 'storeColumn'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.columns.store');
    Route::put('/tasks/tiers/columns/{columnKey}', [TaskTiersController::class, 'updateColumn'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.columns.update');
    Route::delete('/tasks/tiers/columns/{columnKey}', [TaskTiersController::class, 'destroyColumn'])
        ->middleware('sector.access:task.tiers.update')
        ->name('task.tiers.columns.destroy');
    Route::post('/tasks/tiers/preview-header', [TaskTiersController::class, 'previewHeader'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.preview-header');
    Route::post('/tasks/tiers/import-config', [TaskTiersController::class, 'storeImportConfig'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import-config.store');
    Route::post('/tasks/tiers/import', [TaskTiersController::class, 'import'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import');
    Route::get('/tasks/tiers/import-history', [TaskTiersController::class, 'importHistory'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import.history');
    Route::get('/tasks/tiers/import/{importJob}/status', [TaskTiersController::class, 'importStatus'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import.status');
    Route::get('/tasks/tiers/import/{importJob}/report', [TaskTiersController::class, 'importReport'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import.report');
    Route::post('/tasks/tiers/import/{importJob}/resolve', [TaskTiersController::class, 'resolveImportError'])
        ->middleware('sector.access:task.tiers.import')
        ->name('task.tiers.import.resolve');
    Route::delete('/tasks/tiers/data', [TaskTiersController::class, 'destroyData'])
        ->middleware('sector.access:task.tiers.delete')
        ->name('task.tiers.data.destroy');

    Route::get('/tasks/archive', [ArchiveController::class, 'index'])
        ->middleware('sector.access:task.archive.view')
        ->name('task.archive.index');
    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('sector.access:calendar.view')
        ->name('calendar.index');
    Route::get('/cotations', [CotationController::class, 'index'])
        ->middleware('sector.access:cotations.cereals.view|cotations.cereals.edit|cotations.fuel.view|cotations.fuel.edit')
        ->name('cotations.index');
    Route::get('/cotations/market-data', [CotationController::class, 'marketData'])
        ->middleware('sector.access:cotations.cereals.view|cotations.cereals.edit')
        ->name('cotations.market-data');
    Route::get('/cotations/export-pdf', [CotationController::class, 'exportPdf'])
        ->middleware('sector.access:cotations.cereals.edit')
        ->name('cotations.export-pdf');
    Route::get('/cotations/export-fuel-pdf', [CotationController::class, 'exportFuelPdf'])
        ->middleware('sector.access:cotations.fuel.edit')
        ->name('cotations.export-fuel-pdf');
    Route::get('/cotations/fuel-history', [CotationController::class, 'fuelHistory'])
        ->middleware('sector.access:cotations.fuel.history.view')
        ->name('cotations.fuel-history');
    Route::put('/cotations/settings', [CotationController::class, 'updateSettings'])
        ->middleware('sector.access:cotations.cereals.edit')
        ->middleware('throttle:admin-sensitive')
        ->name('cotations.settings.update');
    Route::put('/cotations/fuel-settings', [CotationController::class, 'updateFuelSettings'])
        ->middleware('sector.access:cotations.fuel.edit')
        ->middleware('throttle:admin-sensitive')
        ->name('cotations.fuel-settings.update');

    Route::get('/annonces', [AnnouncementController::class, 'index'])
        ->name('annonces.index');
    Route::post('/annonces', [AnnouncementController::class, 'store'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.store');
    Route::put('/annonces/{announcement}', [AnnouncementController::class, 'update'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.update');
    Route::delete('/annonces/{announcement}', [AnnouncementController::class, 'destroy'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.destroy');
    Route::post('/annonces/{announcement}/duplicate', [AnnouncementController::class, 'duplicate'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.duplicate');
    Route::post('/annonces/{announcement}/poll-response', [AnnouncementController::class, 'respondPoll'])
        ->name('annonces.poll-response');
    Route::post('/annonces/groups', [AnnouncementController::class, 'storeGroup'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.groups.store');
    Route::put('/annonces/groups/{group}', [AnnouncementController::class, 'updateGroup'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.groups.update');
    Route::delete('/annonces/groups/{group}', [AnnouncementController::class, 'destroyGroup'])
        ->middleware('sector.access:annonces.create')
        ->name('annonces.groups.destroy');
    Route::get('/calendar/leaves/export', [CalendarController::class, 'exportLeavesCsv'])
        ->middleware('sector.access:calendar.view')
        ->middleware('throttle:calendar-export')
        ->name('calendar.leaves.export');
    Route::post('/calendar/events', [CalendarEventController::class, 'store'])
        ->middleware('sector.access:calendar.event.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.events.store');
    Route::put('/calendar/events/{calendarEvent}', [CalendarEventController::class, 'update'])
        ->middleware('sector.access:calendar.event.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.events.update');
    Route::delete('/calendar/events/{calendarEvent}', [CalendarEventController::class, 'destroy'])
        ->middleware('sector.access:calendar.event.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.events.destroy');
    Route::post('/calendar/categories', [CalendarCategoryController::class, 'store'])
        ->middleware('sector.access:calendar.category.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.categories.store');
    Route::put('/calendar/categories/{calendarCategory}', [CalendarCategoryController::class, 'update'])
        ->middleware('sector.access:calendar.category.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.categories.update');
    Route::delete('/calendar/categories/{calendarCategory}', [CalendarCategoryController::class, 'destroy'])
        ->middleware('sector.access:calendar.category.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.categories.destroy');
    Route::post('/calendar/feeds', [CalendarFeedController::class, 'store'])
        ->middleware('sector.access:calendar.feed.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.feeds.store');
    Route::put('/calendar/feeds/{calendarFeed}', [CalendarFeedController::class, 'update'])
        ->middleware('sector.access:calendar.feed.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.feeds.update');
    Route::delete('/calendar/feeds/{calendarFeed}', [CalendarFeedController::class, 'destroy'])
        ->middleware('sector.access:calendar.feed.manage')
        ->middleware('throttle:calendar-actions')
        ->name('calendar.feeds.destroy');
    Route::post('/tasks/archive/{archivedTask}/restore', [ArchiveController::class, 'restore'])
        ->middleware('sector.access:task.archive.manage')
        ->name('task.archive.restore');
    Route::put('/tasks/data/jeudy/{user}', [TasksDataController::class, 'updateJeudy'])
        ->middleware('sector.access:task.data.jeudy.manage')
        ->name('task.data.jeudy.update');

    Route::post('/tasks/data/transporters', [TasksDataController::class, 'storeTransporter'])
        ->middleware('sector.access:task.data.transporters.manage')
        ->name('task.data.transporters.store');
    Route::put('/tasks/data/transporters/{transporter}', [TasksDataController::class, 'updateTransporter'])
        ->middleware('sector.access:task.data.transporters.manage')
        ->name('task.data.transporters.update');
    Route::delete('/tasks/data/transporters/{transporter}', [TasksDataController::class, 'destroyTransporter'])
        ->middleware('sector.access:task.data.transporters.manage')
        ->name('task.data.transporters.destroy');

    Route::post('/tasks/data/depots', [TasksDataController::class, 'storeDepot'])
        ->middleware('sector.access:task.data.depots.manage')
        ->name('task.data.depots.store');
    Route::put('/tasks/data/depots/{depot}', [TasksDataController::class, 'updateDepot'])
        ->middleware('sector.access:task.data.depots.manage')
        ->name('task.data.depots.update');
    Route::delete('/tasks/data/depots/{depot}', [TasksDataController::class, 'destroyDepot'])
        ->middleware('sector.access:task.data.depots.manage')
        ->name('task.data.depots.destroy');

    Route::post('/tasks/data/vehicles', [TasksDataController::class, 'storeVehicle'])
        ->middleware('sector.access:task.data.depots.manage')
        ->name('task.data.vehicles.store');
    Route::put('/tasks/data/vehicles/{vehicle}', [TasksDataController::class, 'updateVehicle'])
        ->middleware('sector.access:task.data.depots.manage')
        ->name('task.data.vehicles.update');
});

Route::middleware(['auth', 'verified', 'twofactor'])->group(function () {
    Route::get('/annuaire', [DirectoryController::class, 'index'])->name('directory.index');
    Route::get('/annuaire/{user}/vcard', [DirectoryController::class, 'vcard'])->name('directory.vcard');
    Route::get('/annuaire/{user}/edit', [DirectoryController::class, 'edit'])->name('directory.edit');
    Route::put('/annuaire/{user}', [DirectoryController::class, 'update'])->name('directory.update');
    Route::post('/annuaire/{user}/files', [UserFileController::class, 'store'])
        ->middleware('throttle:file-uploads')
        ->name('directory.files.store');
    Route::get('/annuaire/{user}/files/{userFile}/preview', [UserFileController::class, 'preview'])->name('directory.files.preview');
    Route::get('/annuaire/{user}/files/{userFile}/download', [UserFileController::class, 'download'])->name('directory.files.download');
    Route::put('/annuaire/{user}/files/{userFile}/rename', [UserFileController::class, 'rename'])->name('directory.files.rename');
    Route::delete('/annuaire/{user}/files/{userFile}', [UserFileController::class, 'destroy'])->name('directory.files.destroy');
    Route::get('/annuaire/{user}', [DirectoryController::class, 'show'])->name('directory.show');
});

require __DIR__.'/twofactor.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
