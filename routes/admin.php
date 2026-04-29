<?php

use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\AprevoirImportController;
use App\Http\Controllers\Admin\EntitiesController;
use App\Http\Controllers\Admin\EntityFileController;
use App\Http\Controllers\Admin\LeaveSettingsController;
use App\Http\Controllers\Admin\LogsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'twofactor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserManagementController::class, 'index'])
        ->middleware('sector.access:admin.users.view|admin.users.manage')
        ->name('users.index');

    Route::put('/users/{user}', [UserManagementController::class, 'update'])
        ->middleware('sector.access:admin.users.manage')
        ->name('users.update');

    Route::put('/users/{user}/account', [UserManagementController::class, 'updateAccount'])
        ->middleware('sector.access:admin.users.manage')
        ->name('users.account.update');

    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('sector.access:admin.users.manage')
        ->name('users.destroy');

    Route::post('/users', [UserManagementController::class, 'store'])
        ->middleware('sector.access:admin.users.manage')
        ->name('users.store');

    Route::put('/users/{user}/overrides', [UserManagementController::class, 'updateOverrides'])
        ->middleware('sector.access:admin.access.manage')
        ->name('users.overrides.update');

    Route::get('/sectors', [SectorController::class, 'index'])
        ->middleware('sector.access:admin.sectors.view|admin.sectors.manage')
        ->name('sectors.index');

    Route::get('/logs', [LogsController::class, 'index'])
        ->middleware('sector.access:admin.logs.view')
        ->name('logs.index');

    Route::get('/aprevoir-import', [AprevoirImportController::class, 'index'])
        ->name('aprevoir-import.index');
    Route::post('/aprevoir-import/load-legacy', [AprevoirImportController::class, 'loadLegacyData'])
        ->name('aprevoir-import.load-legacy');
    Route::put('/aprevoir-import/mappings', [AprevoirImportController::class, 'updateMappings'])
        ->name('aprevoir-import.mappings.update');
    Route::post('/aprevoir-import/import', [AprevoirImportController::class, 'import'])
        ->name('aprevoir-import.import');

    Route::get('/leaves', [LeaveSettingsController::class, 'index'])
        ->name('leaves.index');
    Route::put('/leaves/user-validators', [LeaveSettingsController::class, 'updateUserValidators'])
        ->name('leaves.user-validators.update');
    Route::put('/leaves/validators', [LeaveSettingsController::class, 'updateValidators'])
        ->name('leaves.validators.update');
    Route::put('/leaves/rh', [LeaveSettingsController::class, 'updateHr'])
        ->name('leaves.rh.update');
    Route::put('/leaves/allowed-creators', [LeaveSettingsController::class, 'updateAllowedCreators'])
        ->name('leaves.allowed-creators.update');
    Route::put('/leaves/allowed-creator-pairs', [LeaveSettingsController::class, 'updateAllowedCreatorPairs'])
        ->name('leaves.allowed-creator-pairs.update');
    Route::post('/leaves/types', [LeaveSettingsController::class, 'storeType'])
        ->name('leaves.types.store');
    Route::put('/leaves/types/{leaveType}', [LeaveSettingsController::class, 'updateType'])
        ->name('leaves.types.update');

    Route::post('/sectors', [SectorController::class, 'store'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.store');

    Route::post('/sectors/{sector}/duplicate', [SectorController::class, 'duplicate'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.duplicate');

    Route::put('/sectors/{sector}', [SectorController::class, 'update'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.update');

    Route::put('/sectors/{sector}/save', [SectorController::class, 'save'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.save');

    Route::put('/sectors/{sector}/permissions', [SectorController::class, 'updatePermissions'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.permissions.update');

    Route::delete('/sectors/{sector}', [SectorController::class, 'destroy'])
        ->middleware('sector.access:admin.sectors.manage')
        ->name('sectors.destroy');
});

Route::middleware(['auth', 'verified', 'twofactor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/entities', [EntitiesController::class, 'index'])->name('entities');

    Route::post('/entities/vehicle-types', [EntitiesController::class, 'storeVehicleType'])->name('entities.vehicle-types.store');
    Route::put('/entities/vehicle-types/{vehicleType}', [EntitiesController::class, 'updateVehicleType'])->name('entities.vehicle-types.update');
    Route::delete('/entities/vehicle-types/{vehicleType}', [EntitiesController::class, 'destroyVehicleType'])->name('entities.vehicle-types.destroy');

    Route::post('/entities/depots', [EntitiesController::class, 'storeDepot'])->name('entities.depots.store');
    Route::put('/entities/depots/{depot}', [EntitiesController::class, 'updateDepot'])->name('entities.depots.update');
    Route::delete('/entities/depots/{depot}', [EntitiesController::class, 'destroyDepot'])->name('entities.depots.destroy');
    Route::post('/entities/depots/{depot}/files', [EntityFileController::class, 'storeDepot'])->name('entities.depots.files.store');
    Route::get('/entities/depots/{depot}/files/{entityFile}/preview', [EntityFileController::class, 'previewDepot'])->name('entities.depots.files.preview');
    Route::get('/entities/depots/{depot}/files/{entityFile}/download', [EntityFileController::class, 'downloadDepot'])->name('entities.depots.files.download');
    Route::delete('/entities/depots/{depot}/files/{entityFile}', [EntityFileController::class, 'destroyDepot'])->name('entities.depots.files.destroy');

    Route::post('/entities/vehicles', [EntitiesController::class, 'storeVehicle'])->name('entities.vehicles.store');
    Route::put('/entities/vehicles/{vehicle}', [EntitiesController::class, 'updateVehicle'])->name('entities.vehicles.update');
    Route::delete('/entities/vehicles/{vehicle}', [EntitiesController::class, 'destroyVehicle'])->name('entities.vehicles.destroy');
    Route::post('/entities/vehicles/{vehicle}/files', [EntityFileController::class, 'storeVehicle'])->name('entities.vehicles.files.store');
    Route::get('/entities/vehicles/{vehicle}/files/{entityFile}/preview', [EntityFileController::class, 'previewVehicle'])->name('entities.vehicles.files.preview');
    Route::get('/entities/vehicles/{vehicle}/files/{entityFile}/download', [EntityFileController::class, 'downloadVehicle'])->name('entities.vehicles.files.download');
    Route::delete('/entities/vehicles/{vehicle}/files/{entityFile}', [EntityFileController::class, 'destroyVehicle'])->name('entities.vehicles.files.destroy');

    Route::post('/entities/garages', [EntitiesController::class, 'storeGarage'])->name('entities.garages.store');
    Route::put('/entities/garages/{garage}', [EntitiesController::class, 'updateGarage'])->name('entities.garages.update');
    Route::delete('/entities/garages/{garage}', [EntitiesController::class, 'destroyGarage'])->name('entities.garages.destroy');
    Route::post('/entities/garages/{garage}/files', [EntityFileController::class, 'storeGarage'])->name('entities.garages.files.store');
    Route::get('/entities/garages/{garage}/files/{entityFile}/preview', [EntityFileController::class, 'previewGarage'])->name('entities.garages.files.preview');
    Route::get('/entities/garages/{garage}/files/{entityFile}/download', [EntityFileController::class, 'downloadGarage'])->name('entities.garages.files.download');
    Route::delete('/entities/garages/{garage}/files/{entityFile}', [EntityFileController::class, 'destroyGarage'])->name('entities.garages.files.destroy');
});
