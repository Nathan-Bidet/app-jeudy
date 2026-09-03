<?php

use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\AnnonceAccessController;
use App\Http\Controllers\Admin\AprevoirImportController;
use App\Http\Controllers\Admin\CotationAccessController;
use App\Http\Controllers\Admin\EntitiesController;
use App\Http\Controllers\Admin\EntityFileController;
use App\Http\Controllers\Admin\LeaveSettingsController;
use App\Http\Controllers\Admin\LogsController;
use App\Http\Controllers\Admin\ValidationGroupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'twofactor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserManagementController::class, 'index'])
        ->middleware('sector.access:admin.users.view|admin.users.manage')
        ->name('users.index');

    Route::put('/users/{user}', [UserManagementController::class, 'update'])
        ->middleware('sector.access:admin.users.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('users.update');

    Route::put('/users/{user}/account', [UserManagementController::class, 'updateAccount'])
        ->middleware('sector.access:admin.users.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('users.account.update');

    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('sector.access:admin.users.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('users.destroy');

    Route::post('/users', [UserManagementController::class, 'store'])
        ->middleware('sector.access:admin.users.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('users.store');

    Route::put('/users/{user}/overrides', [UserManagementController::class, 'updateOverrides'])
        ->middleware('sector.access:admin.access.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('users.overrides.update');

    Route::get('/sectors', [SectorController::class, 'index'])
        ->middleware('sector.access:admin.sectors.view|admin.sectors.manage')
        ->name('sectors.index');

    Route::get('/logs', [LogsController::class, 'index'])
        ->middleware('sector.access:admin.logs.view')
        ->name('logs.index');

    Route::get('/cotations', [CotationAccessController::class, 'index'])
        ->middleware('sector.access:cotations.admin')
        ->name('cotations.index');

    Route::put('/cotations', [CotationAccessController::class, 'update'])
        ->middleware('sector.access:cotations.admin')
        ->middleware('throttle:admin-sensitive')
        ->name('cotations.update');

    Route::get('/annonces', [AnnonceAccessController::class, 'index'])
        ->middleware('sector.access:annonces.manage')
        ->name('annonces.index');

    Route::put('/annonces', [AnnonceAccessController::class, 'update'])
        ->middleware('sector.access:annonces.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('annonces.update');

    Route::get('/aprevoir-import', [AprevoirImportController::class, 'index'])
        ->name('aprevoir-import.index');
    Route::post('/aprevoir-import/load-legacy', [AprevoirImportController::class, 'loadLegacyData'])
        ->middleware('throttle:admin-sensitive')
        ->name('aprevoir-import.load-legacy');
    Route::put('/aprevoir-import/mappings', [AprevoirImportController::class, 'updateMappings'])
        ->middleware('throttle:admin-sensitive')
        ->name('aprevoir-import.mappings.update');
    Route::post('/aprevoir-import/import', [AprevoirImportController::class, 'import'])
        ->middleware('throttle:admin-sensitive')
        ->name('aprevoir-import.import');

    Route::get('/leaves', [LeaveSettingsController::class, 'index'])
        ->name('leaves.index');

    // Date d'effet du système de validation, commune aux Congés et aux Heures.
    // Même protection que le reste de la page : rôle admin, vérifié dans le
    // contrôleur, et throttle sur les écritures sensibles.
    Route::put('/leaves/validation-effective-date', [LeaveSettingsController::class, 'updateValidationEffectiveDate'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.validation-effective-date.update');

    // Groupes de validation — partagés par les modules Congés et Heures.
    // L'accès est refermé côté serveur par ValidationGroupPolicy, jamais par
    // le seul masquage des boutons côté React.
    Route::post('/leaves/validation-groups', [ValidationGroupController::class, 'store'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.validation-groups.store');
    Route::put('/leaves/validation-groups/{validationGroup}', [ValidationGroupController::class, 'update'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.validation-groups.update');
    Route::delete('/leaves/validation-groups/{validationGroup}', [ValidationGroupController::class, 'destroy'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.validation-groups.destroy');
    Route::put('/leaves/validators', [LeaveSettingsController::class, 'updateValidators'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.validators.update');
    Route::put('/leaves/rh', [LeaveSettingsController::class, 'updateHr'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.rh.update');
    Route::put('/leaves/allowed-creators', [LeaveSettingsController::class, 'updateAllowedCreators'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.allowed-creators.update');
    Route::put('/leaves/allowed-creator-pairs', [LeaveSettingsController::class, 'updateAllowedCreatorPairs'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.allowed-creator-pairs.update');
    Route::post('/leaves/types', [LeaveSettingsController::class, 'storeType'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.types.store');
    Route::put('/leaves/types/{leaveType}', [LeaveSettingsController::class, 'updateType'])
        ->middleware('throttle:admin-sensitive')
        ->name('leaves.types.update');

    Route::post('/sectors', [SectorController::class, 'store'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.store');

    Route::post('/sectors/{sector}/duplicate', [SectorController::class, 'duplicate'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.duplicate');

    Route::put('/sectors/{sector}', [SectorController::class, 'update'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.update');

    Route::put('/sectors/{sector}/save', [SectorController::class, 'save'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.save');

    Route::put('/sectors/{sector}/permissions', [SectorController::class, 'updatePermissions'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.permissions.update');

    Route::delete('/sectors/{sector}', [SectorController::class, 'destroy'])
        ->middleware('sector.access:admin.sectors.manage')
        ->middleware('throttle:admin-sensitive')
        ->name('sectors.destroy');
});

Route::middleware(['auth', 'verified', 'twofactor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/entities', [EntitiesController::class, 'index'])->name('entities');

    Route::post('/entities/vehicle-types', [EntitiesController::class, 'storeVehicleType'])->middleware('throttle:admin-sensitive')->name('entities.vehicle-types.store');
    Route::put('/entities/vehicle-types/{vehicleType}', [EntitiesController::class, 'updateVehicleType'])->middleware('throttle:admin-sensitive')->name('entities.vehicle-types.update');
    Route::delete('/entities/vehicle-types/{vehicleType}', [EntitiesController::class, 'destroyVehicleType'])->middleware('throttle:admin-sensitive')->name('entities.vehicle-types.destroy');

    Route::post('/entities/depots', [EntitiesController::class, 'storeDepot'])->middleware('throttle:admin-sensitive')->name('entities.depots.store');
    Route::put('/entities/depots/{depot}', [EntitiesController::class, 'updateDepot'])->middleware('throttle:admin-sensitive')->name('entities.depots.update');
    Route::delete('/entities/depots/{depot}', [EntitiesController::class, 'destroyDepot'])->middleware('throttle:admin-sensitive')->name('entities.depots.destroy');
    Route::post('/entities/depots/{depot}/files', [EntityFileController::class, 'storeDepot'])->middleware('throttle:file-uploads')->name('entities.depots.files.store');
    Route::get('/entities/depots/{depot}/files/{entityFile}/preview', [EntityFileController::class, 'previewDepot'])->name('entities.depots.files.preview');
    Route::get('/entities/depots/{depot}/files/{entityFile}/download', [EntityFileController::class, 'downloadDepot'])->name('entities.depots.files.download');
    Route::delete('/entities/depots/{depot}/files/{entityFile}', [EntityFileController::class, 'destroyDepot'])->middleware('throttle:admin-sensitive')->name('entities.depots.files.destroy');

    Route::post('/entities/vehicles', [EntitiesController::class, 'storeVehicle'])->middleware('throttle:admin-sensitive')->name('entities.vehicles.store');
    Route::put('/entities/vehicles/{vehicle}', [EntitiesController::class, 'updateVehicle'])->middleware('throttle:admin-sensitive')->name('entities.vehicles.update');
    Route::delete('/entities/vehicles/{vehicle}', [EntitiesController::class, 'destroyVehicle'])->middleware('throttle:admin-sensitive')->name('entities.vehicles.destroy');
    Route::post('/entities/vehicles/{vehicle}/files', [EntityFileController::class, 'storeVehicle'])->middleware('throttle:file-uploads')->name('entities.vehicles.files.store');
    Route::get('/entities/vehicles/{vehicle}/files/{entityFile}/preview', [EntityFileController::class, 'previewVehicle'])->name('entities.vehicles.files.preview');
    Route::get('/entities/vehicles/{vehicle}/files/{entityFile}/download', [EntityFileController::class, 'downloadVehicle'])->name('entities.vehicles.files.download');
    Route::delete('/entities/vehicles/{vehicle}/files/{entityFile}', [EntityFileController::class, 'destroyVehicle'])->middleware('throttle:admin-sensitive')->name('entities.vehicles.files.destroy');

    Route::post('/entities/garages', [EntitiesController::class, 'storeGarage'])->middleware('throttle:admin-sensitive')->name('entities.garages.store');
    Route::put('/entities/garages/{garage}', [EntitiesController::class, 'updateGarage'])->middleware('throttle:admin-sensitive')->name('entities.garages.update');
    Route::delete('/entities/garages/{garage}', [EntitiesController::class, 'destroyGarage'])->middleware('throttle:admin-sensitive')->name('entities.garages.destroy');
    Route::post('/entities/garages/{garage}/files', [EntityFileController::class, 'storeGarage'])->middleware('throttle:file-uploads')->name('entities.garages.files.store');
    Route::get('/entities/garages/{garage}/files/{entityFile}/preview', [EntityFileController::class, 'previewGarage'])->name('entities.garages.files.preview');
    Route::get('/entities/garages/{garage}/files/{entityFile}/download', [EntityFileController::class, 'downloadGarage'])->name('entities.garages.files.download');
    Route::delete('/entities/garages/{garage}/files/{entityFile}', [EntityFileController::class, 'destroyGarage'])->middleware('throttle:admin-sensitive')->name('entities.garages.files.destroy');
});
