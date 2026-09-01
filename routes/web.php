<?php

use App\Http\Controllers\Admin\AutomationFlowManagementController;
use App\Http\Controllers\Admin\ClientManagementController;
use App\Http\Controllers\Admin\ClientRecordController;
use App\Http\Controllers\Admin\HubspotOwnerManagementController;
use App\Http\Controllers\Admin\LiteAdminController;
use App\Http\Controllers\Admin\MessageRuleManagementController;
use App\Http\Controllers\Admin\PlatformConnectionManagementController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\TrebleTemplateManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
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

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:dashboard.view');

    Route::get('/home', [DashboardController::class, 'index'])->name('home.dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/clients', [LiteAdminController::class, 'clients'])
            ->name('clients')
            ->middleware('permission:clients.manage');
        Route::get('/clients/create', [LiteAdminController::class, 'clientsCreate'])
            ->name('clients.create')
            ->middleware('permission:clients.manage');
        Route::get('/clients/{client}/edit', [LiteAdminController::class, 'clientsEdit'])
            ->name('clients.edit')
            ->middleware('permission:clients.manage');
        Route::post('/clients', [ClientManagementController::class, 'store'])
            ->name('clients.store')
            ->middleware('permission:clients.manage');
        Route::put('/clients/{client}', [ClientManagementController::class, 'update'])
            ->name('clients.update')
            ->middleware('permission:clients.manage');
        Route::delete('/clients/{client}', [ClientManagementController::class, 'destroy'])
            ->name('clients.destroy')
            ->middleware('permission:clients.manage');

        Route::get('/clients/{client}/connections', [LiteAdminController::class, 'clientConnections'])
            ->name('clients.connections')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/connections/create', [LiteAdminController::class, 'clientConnectionsCreate'])
            ->name('clients.connections.create')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/connections/{connection}/edit', [LiteAdminController::class, 'clientConnectionsEdit'])
            ->name('clients.connections.edit')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/connections', [PlatformConnectionManagementController::class, 'store'])
            ->name('clients.connections.store')
            ->middleware('permission:integrations.manage');
        Route::put('/clients/{client}/connections/{connection}', [PlatformConnectionManagementController::class, 'update'])
            ->name('clients.connections.update')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/connections/{connection}/rotate-webhook-secret', [PlatformConnectionManagementController::class, 'rotateWebhookSecret'])
            ->name('clients.connections.rotate-webhook-secret')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/connections/{connection}/revoke-webhook-secret', [PlatformConnectionManagementController::class, 'revokeWebhookSecret'])
            ->name('clients.connections.revoke-webhook-secret')
            ->middleware('permission:integrations.manage');
        Route::delete('/clients/{client}/connections/{connection}', [PlatformConnectionManagementController::class, 'destroy'])
            ->name('clients.connections.destroy')
            ->middleware('permission:integrations.manage');

        Route::get('/clients/{client}/templates', [LiteAdminController::class, 'clientTemplates'])
            ->name('clients.templates')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/templates/create', [LiteAdminController::class, 'clientTemplatesCreate'])
            ->name('clients.templates.create')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/templates/{template}/edit', [LiteAdminController::class, 'clientTemplatesEdit'])
            ->name('clients.templates.edit')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/templates', [TrebleTemplateManagementController::class, 'store'])
            ->name('clients.templates.store')
            ->middleware('permission:integrations.manage');
        Route::put('/clients/{client}/templates/{template}', [TrebleTemplateManagementController::class, 'update'])
            ->name('clients.templates.update')
            ->middleware('permission:integrations.manage');
        Route::delete('/clients/{client}/templates/{template}', [TrebleTemplateManagementController::class, 'destroy'])
            ->name('clients.templates.destroy')
            ->middleware('permission:integrations.manage');

        Route::get('/clients/{client}/owners', [LiteAdminController::class, 'clientOwners'])
            ->name('clients.owners')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/owners/create', [LiteAdminController::class, 'clientOwnersCreate'])
            ->name('clients.owners.create')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/owners/{owner}/edit', [LiteAdminController::class, 'clientOwnersEdit'])
            ->name('clients.owners.edit')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/owners', [HubspotOwnerManagementController::class, 'store'])
            ->name('clients.owners.store')
            ->middleware('permission:integrations.manage');
        Route::put('/clients/{client}/owners/{owner}', [HubspotOwnerManagementController::class, 'update'])
            ->name('clients.owners.update')
            ->middleware('permission:integrations.manage');
        Route::delete('/clients/{client}/owners/{owner}', [HubspotOwnerManagementController::class, 'destroy'])
            ->name('clients.owners.destroy')
            ->middleware('permission:integrations.manage');

        Route::get('/clients/{client}/flows', [LiteAdminController::class, 'clientFlows'])
            ->name('clients.flows')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/flows/create', [LiteAdminController::class, 'clientFlowsCreate'])
            ->name('clients.flows.create')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/flows/{flow}/edit', [LiteAdminController::class, 'clientFlowsEdit'])
            ->name('clients.flows.edit')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/flows', [AutomationFlowManagementController::class, 'store'])
            ->name('clients.flows.store')
            ->middleware('permission:integrations.manage');
        Route::put('/clients/{client}/flows/{flow}', [AutomationFlowManagementController::class, 'update'])
            ->name('clients.flows.update')
            ->middleware('permission:integrations.manage');
        Route::delete('/clients/{client}/flows/{flow}', [AutomationFlowManagementController::class, 'destroy'])
            ->name('clients.flows.destroy')
            ->middleware('permission:integrations.manage');

        Route::get('/clients/{client}/flows/{flow}/rules/create', [LiteAdminController::class, 'clientFlowRulesCreate'])
            ->name('clients.flows.rules.create')
            ->middleware('permission:integrations.manage');
        Route::get('/clients/{client}/flows/{flow}/rules/{rule}/edit', [LiteAdminController::class, 'clientFlowRulesEdit'])
            ->name('clients.flows.rules.edit')
            ->middleware('permission:integrations.manage');
        Route::post('/clients/{client}/flows/{flow}/rules', [MessageRuleManagementController::class, 'store'])
            ->name('clients.flows.rules.store')
            ->middleware('permission:integrations.manage');
        Route::put('/clients/{client}/flows/{flow}/rules/{rule}', [MessageRuleManagementController::class, 'update'])
            ->name('clients.flows.rules.update')
            ->middleware('permission:integrations.manage');
        Route::delete('/clients/{client}/flows/{flow}/rules/{rule}', [MessageRuleManagementController::class, 'destroy'])
            ->name('clients.flows.rules.destroy')
            ->middleware('permission:integrations.manage');

        Route::redirect('/clients/{client}/rules', '/admin/clients/{client}/flows')
            ->name('clients.rules')
            ->middleware('permission:integrations.manage');
        Route::redirect('/clients/{client}/owner-rules', '/admin/clients/{client}/flows')
            ->name('clients.owner-rules')
            ->middleware('permission:integrations.manage');

        Route::get('/clients/{client}/records', [ClientRecordController::class, 'index'])
            ->name('clients.records')
            ->middleware('permission:records.view');

        Route::get('/records', [LiteAdminController::class, 'globalRecords'])
            ->name('records')
            ->middleware('permission:records.view');

        Route::get('/users', [LiteAdminController::class, 'users'])
            ->name('users')
            ->middleware('permission:users.manage');
        Route::get('/users/create', [LiteAdminController::class, 'usersCreate'])
            ->name('users.create')
            ->middleware('permission:users.manage');
        Route::get('/users/{user}/edit', [LiteAdminController::class, 'usersEdit'])
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

        Route::get('/roles', [LiteAdminController::class, 'roles'])
            ->name('roles')
            ->middleware('permission:roles.manage');
        Route::get('/roles/create', [LiteAdminController::class, 'rolesCreate'])
            ->name('roles.create')
            ->middleware('permission:roles.manage');
        Route::get('/roles/{role}/edit', [LiteAdminController::class, 'rolesEdit'])
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
    });
});

Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
