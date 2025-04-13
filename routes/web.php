<?php
Route::get('/server-backgrounds', [Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController::class, 'index'])->name('blueprint.extensions.serverbackgrounds');
Route::get('/configured-egg-backgrounds', [Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController::class, 'fetchConfiguredEggBackgrounds'])->name('blueprint.extensions.serverbackgrounds.wrapper.styles.import');
Route::get('/configured-server-backgrounds', [Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController::class, 'fetchConfiguredServerBackgrounds'])->name('blueprint.extensions.serverbackgrounds.wrapper.styles.import');
Route::post('/admin/extensions/{identifier}/bulk-save', [Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController::class, 'bulkSaveBackgrounds'])->name('blueprint.extensions.serverbackgrounds.bulkSaveSettings');
Route::post('/admin/extensions/{identifier}/update-delete', [Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}\{identifier}ExtensionController::class, 'updateAndDeleteBackgroundSettings'])->name('blueprint.extensions.serverbackgrounds.updateAndDeleteSettings');
?>
