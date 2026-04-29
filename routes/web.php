<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AprevoirController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\CalendarCategoryController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\FormattingRuleController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HourSheetController;
use App\Http\Controllers\LdtController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityProfileController;
use App\Http\Controllers\DirectoryController;
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
        return Inertia::render('Security/ProfileEdit', [
            'mustVerifyEmail' => request()->user() instanceof MustVerifyEmail,
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
    Route::patch('/a-prevoir/tasks/{task}/position', [AprevoirController::class, 'updatePosition'])
        ->middleware('sector.access:a_prevoir.update')
        ->name('a_prevoir.tasks.position');

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
        ->name('hours.export');
    Route::post('/activities/hours', [HourSheetController::class, 'store'])
        ->middleware('sector.access:heures.create')
        ->name('hours.store');
    Route::post('/activities/leaves', [LeaveRequestController::class, 'store'])
        ->name('leaves.store');
    Route::post('/leaves/{id}/approve', [LeaveRequestController::class, 'approve'])
        ->name('leaves.approve');
    Route::post('/leaves/{id}/refuse', [LeaveRequestController::class, 'refuse'])
        ->name('leaves.refuse');
    Route::post('/leaves/{id}/propose-modification', [LeaveRequestController::class, 'proposeModification'])
        ->name('leaves.propose_modification');
    Route::post('/leaves/{id}/accept-modification', [LeaveRequestController::class, 'acceptProposedModification'])
        ->name('leaves.accept_modification');
    Route::post('/leaves/{id}/refuse-modification', [LeaveRequestController::class, 'refuseProposedModification'])
        ->name('leaves.refuse_modification');
    Route::delete('/leaves/{id}', [LeaveRequestController::class, 'destroy'])
        ->name('leaves.destroy');
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read_all');
    Route::get('/notifications/latest', [NotificationController::class, 'latest'])
        ->name('notifications.latest');
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

    Route::get('/tasks/archive', [ArchiveController::class, 'index'])
        ->middleware('sector.access:task.archive.view')
        ->name('task.archive.index');
    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('sector.access:calendar.view')
        ->name('calendar.index');
    Route::get('/calendar/leaves/export', [CalendarController::class, 'exportLeavesCsv'])
        ->middleware('sector.access:calendar.view')
        ->name('calendar.leaves.export');
    Route::post('/calendar/events', [CalendarEventController::class, 'store'])
        ->middleware('sector.access:calendar.event.manage')
        ->name('calendar.events.store');
    Route::put('/calendar/events/{calendarEvent}', [CalendarEventController::class, 'update'])
        ->middleware('sector.access:calendar.event.manage')
        ->name('calendar.events.update');
    Route::delete('/calendar/events/{calendarEvent}', [CalendarEventController::class, 'destroy'])
        ->middleware('sector.access:calendar.event.manage')
        ->name('calendar.events.destroy');
    Route::post('/calendar/categories', [CalendarCategoryController::class, 'store'])
        ->middleware('sector.access:calendar.category.manage')
        ->name('calendar.categories.store');
    Route::put('/calendar/categories/{calendarCategory}', [CalendarCategoryController::class, 'update'])
        ->middleware('sector.access:calendar.category.manage')
        ->name('calendar.categories.update');
    Route::delete('/calendar/categories/{calendarCategory}', [CalendarCategoryController::class, 'destroy'])
        ->middleware('sector.access:calendar.category.manage')
        ->name('calendar.categories.destroy');
    Route::post('/calendar/feeds', [CalendarFeedController::class, 'store'])
        ->middleware('sector.access:calendar.feed.manage')
        ->name('calendar.feeds.store');
    Route::put('/calendar/feeds/{calendarFeed}', [CalendarFeedController::class, 'update'])
        ->middleware('sector.access:calendar.feed.manage')
        ->name('calendar.feeds.update');
    Route::delete('/calendar/feeds/{calendarFeed}', [CalendarFeedController::class, 'destroy'])
        ->middleware('sector.access:calendar.feed.manage')
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
    Route::post('/annuaire/{user}/files', [UserFileController::class, 'store'])->name('directory.files.store');
    Route::get('/annuaire/{user}/files/{userFile}/preview', [UserFileController::class, 'preview'])->name('directory.files.preview');
    Route::get('/annuaire/{user}/files/{userFile}/download', [UserFileController::class, 'download'])->name('directory.files.download');
    Route::put('/annuaire/{user}/files/{userFile}/rename', [UserFileController::class, 'rename'])->name('directory.files.rename');
    Route::delete('/annuaire/{user}/files/{userFile}', [UserFileController::class, 'destroy'])->name('directory.files.destroy');
    Route::get('/annuaire/{user}', [DirectoryController::class, 'show'])->name('directory.show');
});

require __DIR__.'/twofactor.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
