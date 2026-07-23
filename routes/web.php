<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Admin\Extensions\serverbackgrounds\serverbackgroundsExtensionController;

/*
 * Blueprint registers this router from `conf.yml` (requests.routers.web).
 * These routes are mounted under `/extensions/serverbackgrounds`.
 */
Route::get('/server-backgrounds', [serverbackgroundsExtensionController::class, 'index'])
    ->name('blueprint.extensions.serverbackgrounds');

Route::get('/configured-egg-backgrounds', [serverbackgroundsExtensionController::class, 'fetchConfiguredEggBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.api.configured_egg_backgrounds');

Route::get('/configured-server-backgrounds', [serverbackgroundsExtensionController::class, 'fetchConfiguredServerBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.api.configured_server_backgrounds');

Route::get('/api/settings', [serverbackgroundsExtensionController::class, 'getSettings'])
    ->name('blueprint.extensions.serverbackgrounds.api.settings');

Route::middleware('auth')->group(function () {
    Route::get('/user/server-background', [serverbackgroundsExtensionController::class, 'fetchUserServerBackground'])
        ->name('blueprint.extensions.serverbackgrounds.api.user_server_background.fetch');
    Route::post('/user/server-background', [serverbackgroundsExtensionController::class, 'saveUserServerBackground'])
        ->name('blueprint.extensions.serverbackgrounds.api.user_server_background.save');
    Route::get('/api/user-server-backgrounds', [serverbackgroundsExtensionController::class, 'fetchUserServerBackgrounds'])
        ->name('blueprint.extensions.serverbackgrounds.api.user_server_backgrounds');
});

Route::post('/admin/extensions/serverbackgrounds/settings', [serverbackgroundsExtensionController::class, 'updateSettings'])
    ->name('blueprint.extensions.serverbackgrounds.updateSettings');

Route::post('/admin/extensions/serverbackgrounds/bulk-save', [serverbackgroundsExtensionController::class, 'bulkSaveBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.bulkSaveSettings');

Route::post('/admin/extensions/serverbackgrounds/update-delete', [serverbackgroundsExtensionController::class, 'updateAndDeleteBackgroundSettings'])
    ->name('blueprint.extensions.serverbackgrounds.updateAndDeleteSettings');
