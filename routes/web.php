<?php

use App\Http\Controllers\Admin\CategoryManagementController;
use App\Http\Controllers\Admin\ConfigManagementController;
use App\Http\Controllers\Admin\EventManagementController;
use App\Http\Controllers\Admin\EventTriggerManagementController;
use App\Http\Controllers\Admin\PlatformManagementController;
use App\Http\Controllers\Admin\PropertyManagementController;
use App\Http\Controllers\Admin\PropertyRelationshipManagementController;
use App\Http\Controllers\Admin\RecordManagementController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AdminPanelController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/home', [DashboardController::class, 'index'])->name('home.dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:dashboard.view');

    Route::get('/events/{event}', [EventController::class, 'show'])
        ->name('events.show')
        ->middleware('permission:events.view');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/events', [AdminPanelController::class, 'events'])
            ->name('events')
            ->middleware('permission:events.manage');
        Route::get('/events/create', [AdminPanelController::class, 'eventsCreate'])
            ->name('events.create')
            ->middleware('permission:events.manage');
        Route::get('/events/{event}/edit', [AdminPanelController::class, 'eventsEdit'])
            ->name('events.edit')
            ->middleware('permission:events.manage');
        Route::post('/events', [EventManagementController::class, 'store'])
            ->name('events.store')
            ->middleware('permission:events.manage');
        Route::post('/events/{event}/execute-now', [EventManagementController::class, 'executeNow'])
            ->name('events.execute-now')
            ->middleware('permission:events.manage');
        Route::put('/events/{event}', [EventManagementController::class, 'update'])
            ->name('events.update')
            ->middleware('permission:events.manage');
        Route::delete('/events/{event}', [EventManagementController::class, 'destroy'])
            ->name('events.destroy')
            ->middleware('permission:events.manage');
        Route::get('/events/{event}/triggers', [EventTriggerManagementController::class, 'show'])
            ->name('events.triggers')
            ->middleware('permission:events.manage');
        Route::put('/events/{event}/triggers', [EventTriggerManagementController::class, 'update'])
            ->name('events.triggers.update')
            ->middleware('permission:events.manage');
        Route::get('/events/{event}/relationships', [AdminPanelController::class, 'eventRelationships'])
            ->name('events.relationships')
            ->middleware('permission:events.manage');
        Route::post('/events/{event}/relationships', [PropertyRelationshipManagementController::class, 'store'])
            ->name('events.relationships.store')
            ->middleware('permission:events.manage');
        Route::put('/events/{event}/relationships/{relationship}', [PropertyRelationshipManagementController::class, 'update'])
            ->name('events.relationships.update')
            ->middleware('permission:events.manage');
        Route::delete('/events/{event}/relationships/{relationship}', [PropertyRelationshipManagementController::class, 'destroy'])
            ->name('events.relationships.destroy')
            ->middleware('permission:events.manage');

        Route::get('/platforms', [AdminPanelController::class, 'platforms'])
            ->name('platforms')
            ->middleware('permission:platforms.manage');
        Route::get('/platforms/create', [AdminPanelController::class, 'platformsCreate'])
            ->name('platforms.create')
            ->middleware('permission:platforms.manage');
        Route::get('/platforms/{platform}/edit', [AdminPanelController::class, 'platformsEdit'])
            ->name('platforms.edit')
            ->middleware('permission:platforms.manage');
        Route::post('/platforms', [PlatformManagementController::class, 'store'])
            ->name('platforms.store')
            ->middleware('permission:platforms.manage');
        Route::post('/platforms/{platform}/test-connection', [PlatformManagementController::class, 'testConnection'])
            ->name('platforms.test-connection')
            ->middleware('permission:platforms.manage');
        Route::put('/platforms/{platform}', [PlatformManagementController::class, 'update'])
            ->name('platforms.update')
            ->middleware('permission:platforms.manage');
        Route::delete('/platforms/{platform}', [PlatformManagementController::class, 'destroy'])
            ->name('platforms.destroy')
            ->middleware('permission:platforms.manage');

        Route::get('/properties', [AdminPanelController::class, 'properties'])
            ->name('properties')
            ->middleware('permission:properties.manage');
        Route::get('/properties/create', [AdminPanelController::class, 'propertiesCreate'])
            ->name('properties.create')
            ->middleware('permission:properties.manage');
        Route::get('/properties/{property}/edit', [AdminPanelController::class, 'propertiesEdit'])
            ->name('properties.edit')
            ->middleware('permission:properties.manage');
        Route::post('/properties', [PropertyManagementController::class, 'store'])
            ->name('properties.store')
            ->middleware('permission:properties.manage');
        Route::put('/properties/{property}', [PropertyManagementController::class, 'update'])
            ->name('properties.update')
            ->middleware('permission:properties.manage');
        Route::delete('/properties/{property}', [PropertyManagementController::class, 'destroy'])
            ->name('properties.destroy')
            ->middleware('permission:properties.manage');

        Route::get('/records', [AdminPanelController::class, 'records'])
            ->name('records')
            ->middleware('permission:records.view');
        Route::post('/records/cleanup', [RecordManagementController::class, 'cleanup'])
            ->name('records.cleanup')
            ->middleware('permission:records.manage');

        Route::get('/users', [AdminPanelController::class, 'users'])
            ->name('users')
            ->middleware('permission:users.manage');
        Route::get('/users/create', [AdminPanelController::class, 'usersCreate'])
            ->name('users.create')
            ->middleware('permission:users.manage');
        Route::get('/users/{user}/edit', [AdminPanelController::class, 'usersEdit'])
            ->name('users.edit')
            ->middleware('permission:users.manage');
        Route::post('/users', [UserManagementController::class, 'store'])
            ->name('users.store')
            ->middleware('permission:users.manage');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])
            ->name('users.update')
            ->middleware('permission:users.manage');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])
            ->name('users.destroy')
            ->middleware('permission:users.manage');

        Route::get('/roles', [AdminPanelController::class, 'roles'])
            ->name('roles')
            ->middleware('permission:roles.manage');
        Route::get('/roles/create', [AdminPanelController::class, 'rolesCreate'])
            ->name('roles.create')
            ->middleware('permission:roles.manage');
        Route::get('/roles/{role}/edit', [AdminPanelController::class, 'rolesEdit'])
            ->name('roles.edit')
            ->middleware('permission:roles.manage');
        Route::post('/roles', [RoleManagementController::class, 'store'])
            ->name('roles.store')
            ->middleware('permission:roles.manage');
        Route::put('/roles/{role}', [RoleManagementController::class, 'update'])
            ->name('roles.update')
            ->middleware('permission:roles.manage');
        Route::delete('/roles/{role}', [RoleManagementController::class, 'destroy'])
            ->name('roles.destroy')
            ->middleware('permission:roles.manage');

        Route::get('/categories', [AdminPanelController::class, 'categories'])
            ->name('categories')
            ->middleware('permission:categories.manage');
        Route::get('/categories/create', [AdminPanelController::class, 'categoriesCreate'])
            ->name('categories.create')
            ->middleware('permission:categories.manage');
        Route::get('/categories/{category}/edit', [AdminPanelController::class, 'categoriesEdit'])
            ->name('categories.edit')
            ->middleware('permission:categories.manage');
        Route::post('/categories', [CategoryManagementController::class, 'store'])
            ->name('categories.store')
            ->middleware('permission:categories.manage');
        Route::put('/categories/{category}', [CategoryManagementController::class, 'update'])
            ->name('categories.update')
            ->middleware('permission:categories.manage');
        Route::delete('/categories/{category}', [CategoryManagementController::class, 'destroy'])
            ->name('categories.destroy')
            ->middleware('permission:categories.manage');

        Route::get('/configs', [AdminPanelController::class, 'configs'])
            ->name('configs')
            ->middleware('permission:configs.manage');
        Route::get('/configs/create', [AdminPanelController::class, 'configsCreate'])
            ->name('configs.create')
            ->middleware('permission:configs.manage');
        Route::get('/configs/{config}/edit', [AdminPanelController::class, 'configsEdit'])
            ->name('configs.edit')
            ->middleware('permission:configs.manage');
        Route::post('/configs', [ConfigManagementController::class, 'store'])
            ->name('configs.store')
            ->middleware('permission:configs.manage');
        Route::put('/configs/{config}', [ConfigManagementController::class, 'update'])
            ->name('configs.update')
            ->middleware('permission:configs.manage');
        Route::delete('/configs/{config}', [ConfigManagementController::class, 'destroy'])
            ->name('configs.destroy')
            ->middleware('permission:configs.manage');
    });
});

Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
Route::prefix('webhook')->group(base_path('routes/webhooks.php'));
