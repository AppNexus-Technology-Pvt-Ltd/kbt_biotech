<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\AssetPageController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\InstallationPageController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StatusPageController;
use App\Support\Home;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(Auth::check() ? Home::urlFor(Auth::user()) : route('login')));

/* ------------------------------------------------------------------ guests */
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

// OTP step: the user is half-authenticated (session holds a pending login), so no `guest`/`auth`.
Route::prefix('otp')->middleware('throttle:otp')->group(function () {
    Route::get('/', [OtpController::class, 'show'])->name('otp.show');
    Route::post('/send', [OtpController::class, 'send'])->name('otp.send');
    Route::post('/verify', [OtpController::class, 'verify'])->name('otp.verify');
});

Route::get('/session-expired', [StatusPageController::class, 'sessionExpired'])->name('session.expired');

/* --------------------------------------------------------- authenticated */
Route::middleware(['auth', 'active', 'nostore'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/access-denied', [StatusPageController::class, 'accessDenied'])->name('access.denied');

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::get('/profile/photo/{user}', [ProfileController::class, 'servePhoto'])->name('profile.photo');
    Route::post('/facility', [FacilityController::class, 'switch'])->name('facility.switch');

    // Organization & system setup (Step 2). Reading needs organization.view, changing needs organization.manage (enforced on the API).
    Route::prefix('organization')->name('org.')->middleware('can:organization.view')->group(function () {
        Route::get('/', fn () => redirect()->route('org.index', 'facilities'));
        Route::get('/facilities/create', [OrganizationController::class, 'facilityForm'])->middleware('can:organization.manage')->name('facilities.create');
        Route::get('/facilities/{facility}/edit', [OrganizationController::class, 'facilityForm'])->middleware('can:organization.manage')->name('facilities.edit');
        Route::get('/facilities/{facility}/image', [OrganizationController::class, 'facilityImage'])->name('facilities.image');
        Route::get('/{page}', [OrganizationController::class, 'index'])
            ->whereIn('page', ['facilities', 'buildings', 'departments', 'locations', 'equipment-categories', 'manufacturers', 'staff', 'shifts', 'on-call-roster', 'priority-levels'])->name('index');
    });

    // Asset Registry (Step 3). `/assets` is the asset dashboard.
    Route::prefix('assets')->name('assets.')->middleware('can:assets.view')->group(function () {
        Route::get('/', [AssetPageController::class, 'dashboard'])->name('dashboard');
        Route::get('/register', [AssetPageController::class, 'register'])->name('register');
        Route::get('/labels', [AssetPageController::class, 'labels'])->name('labels');
        Route::get('/scan', [AssetPageController::class, 'scan'])->name('scan');
        Route::get('/import', [AssetPageController::class, 'import'])->middleware('can:assets.create')->name('import');
        Route::get('/reports', [AssetPageController::class, 'reports'])->middleware('can:reports.view')->name('reports');
        Route::get('/transfers', [AssetPageController::class, 'transfers'])->name('transfers');
        Route::get('/lifecycle', [AssetPageController::class, 'lifecycle'])->name('lifecycle');
        Route::get('/{asset}/transfer', [AssetPageController::class, 'transferForm'])->whereNumber('asset')->middleware('can:assets.edit')->name('transfer');
        Route::get('/{asset}/status', [AssetPageController::class, 'statusForm'])->whereNumber('asset')->middleware('can:assets.edit')->name('status');
        Route::get('/{asset}/dispose', [AssetPageController::class, 'disposeForm'])->whereNumber('asset')->middleware('can:assets.approve')->name('dispose');
        Route::get('/create', [AssetPageController::class, 'create'])->middleware('can:assets.create')->name('create');
        Route::get('/{asset}', [AssetPageController::class, 'show'])->whereNumber('asset')->name('show');
        Route::get('/{asset}/edit', [AssetPageController::class, 'edit'])->whereNumber('asset')->middleware('can:assets.edit')->name('edit');
        Route::get('/{asset}/image', [AssetPageController::class, 'image'])->whereNumber('asset')->name('image');
        Route::get('/{asset}/documents/{document}', [AssetPageController::class, 'downloadDocument'])->whereNumber(['asset', 'document'])->name('documents.download');
    });

    // Installation & Commissioning (Step 4)
    Route::prefix('installations')->name('installations.')->middleware('can:installations.view')->group(function () {
        Route::get('/', [InstallationPageController::class, 'dashboard'])->name('dashboard');
        Route::get('/jobs', [InstallationPageController::class, 'jobs'])->name('jobs');
        Route::get('/create', [InstallationPageController::class, 'create'])->middleware('can:installations.manage')->name('create');
        Route::get('/reports', [InstallationPageController::class, 'reports'])->name('reports');
        Route::get('/{job}', [InstallationPageController::class, 'show'])->whereNumber('job')->name('show');
        Route::get('/{job}/certificate', [InstallationPageController::class, 'certificate'])->whereNumber('job')->name('certificate');
        Route::get('/{job}/certificate.pdf', [InstallationPageController::class, 'certificatePdf'])->whereNumber('job')->name('certificate.pdf');
        Route::get('/{job}/signature', [InstallationPageController::class, 'signature'])->whereNumber('job')->name('signature');
        Route::get('/{job}/documents/{document}', [InstallationPageController::class, 'downloadDocument'])->whereNumber(['job', 'document'])->name('documents.download');
    });

    // Module landing pages, each guarded by its own permission.
    foreach (config('kbt.modules') as $slug => [, $path, $permission]) {
        if (in_array($slug, ['assets', 'installations'], true)) {
            continue;
        }
        Route::get($path, [ModuleController::class, 'show'])->defaults('module', $slug)
            ->middleware('can:'.$permission)->name('module.'.$slug);
    }
});

/* -------------------------------------------------------------- JSON API */
Route::prefix('api')->name('api.')->middleware(['auth', 'active', 'nostore', 'throttle:api'])->group(function () {
    Route::get('/auth/me', [Api\MeController::class, 'me'])->name('me');
    Route::get('/permissions', [Api\MeController::class, 'permissions'])->name('permissions');

    Route::get('/dashboard', [DashboardController::class, 'data'])->middleware('can:dashboard.view')->name('dashboard');
    Route::get('/search', Api\SearchController::class)->name('search');

    Route::prefix('assets')->name('assets.')->middleware('can:assets.view')->group(function () {
        Route::get('/dashboard', [Api\AssetController::class, 'dashboard'])->name('dashboard');
        Route::get('/options', [Api\AssetController::class, 'options'])->name('options');
        Route::get('/next-tag', [Api\AssetController::class, 'nextTag'])->middleware('can:assets.create')->name('next-tag');
        Route::get('/export', [Api\AssetController::class, 'export'])->middleware('throttle:10,1')->name('export');
        // Labels, scan, import, reports (Step 3, screens 5-7, 11)
        Route::post('/labels', [Api\AssetToolsController::class, 'labels'])->name('labels');
        Route::get('/lookup', [Api\AssetToolsController::class, 'lookup'])->middleware('throttle:60,1')->name('lookup');
        Route::get('/import/template', [Api\AssetToolsController::class, 'importTemplate'])->middleware('can:assets.create')->name('import.template');
        Route::post('/import/validate', [Api\AssetToolsController::class, 'importValidate'])->middleware(['can:assets.create', 'throttle:20,1'])->name('import.validate');
        Route::post('/import/confirm', [Api\AssetToolsController::class, 'importConfirm'])->middleware('can:assets.create')->name('import.confirm');
        Route::get('/reports/{type}', [Api\AssetToolsController::class, 'report'])->middleware(['can:reports.view', 'throttle:30,1'])->name('reports');

        // Transfers, lifecycle, retirement / disposal (Step 3, screens 8-10)
        Route::get('/transfers', [Api\AssetWorkflowController::class, 'transfers'])->name('transfers');
        Route::get('/lifecycle', [Api\AssetWorkflowController::class, 'lifecycle'])->name('lifecycle');
        Route::post('/{id}/transfers', [Api\AssetWorkflowController::class, 'requestTransfer'])->whereNumber('id')->middleware('can:assets.edit')->name('transfers.store');
        Route::post('/transfers/{id}/approve', [Api\AssetWorkflowController::class, 'approve'])->whereNumber('id')->middleware('can:assets.approve')->name('transfers.approve');
        Route::post('/transfers/{id}/reject', [Api\AssetWorkflowController::class, 'reject'])->whereNumber('id')->middleware('can:assets.approve')->name('transfers.reject');
        Route::post('/transfers/{id}/cancel', [Api\AssetWorkflowController::class, 'cancel'])->whereNumber('id')->middleware('can:assets.edit')->name('transfers.cancel');
        Route::post('/{id}/status', [Api\AssetWorkflowController::class, 'changeStatus'])->whereNumber('id')->middleware('can:assets.edit')->name('status');
        Route::post('/{id}/dispose', [Api\AssetWorkflowController::class, 'dispose'])->whereNumber('id')->middleware('can:assets.approve')->name('dispose');

        Route::get('/', [Api\AssetController::class, 'index'])->name('index');
        Route::get('/{id}', [Api\AssetController::class, 'show'])->whereNumber('id')->name('show');
        Route::get('/{id}/history', [Api\AssetController::class, 'history'])->whereNumber('id')->name('history');
        Route::get('/{id}/maintenance', [Api\AssetController::class, 'maintenance'])->whereNumber('id')->name('maintenance');
        Route::get('/{id}/documents', [Api\AssetController::class, 'documents'])->whereNumber('id')->name('documents');
        Route::post('/', [Api\AssetController::class, 'store'])->middleware('can:assets.create')->name('store');
        Route::put('/{id}', [Api\AssetController::class, 'update'])->whereNumber('id')->middleware('can:assets.edit')->name('update');
        Route::delete('/{id}', [Api\AssetController::class, 'destroy'])->whereNumber('id')->middleware('can:assets.delete')->name('destroy');
        Route::post('/{id}/documents', [Api\AssetController::class, 'uploadDocument'])->whereNumber('id')->middleware('can:assets.edit')->name('documents.store');
        Route::delete('/{id}/documents/{docId}', [Api\AssetController::class, 'deleteDocument'])->whereNumber(['id', 'docId'])->middleware('can:assets.edit')->name('documents.destroy');
    });

    Route::prefix('installations')->name('installations.')->middleware('can:installations.view')->group(function () {
        $c = Api\InstallationController::class;
        Route::get('/dashboard', [$c, 'dashboard'])->name('dashboard');
        Route::get('/options', [$c, 'options'])->name('options');
        Route::get('/reports/{type}', [$c, 'report'])->middleware('throttle:30,1')->name('reports');
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/{id}', [$c, 'show'])->whereNumber('id')->name('show');
        Route::get('/{id}/checklist', [$c, 'checklist'])->whereNumber('id')->name('checklist');
        Route::get('/{id}/documents', [$c, 'documents'])->whereNumber('id')->name('documents');
        Route::middleware('can:installations.manage')->group(function () use ($c) {
            Route::post('/', [$c, 'store'])->name('store');
            Route::put('/{id}', [$c, 'update'])->whereNumber('id')->name('update');
            Route::delete('/{id}', [$c, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post('/{id}/submit', [$c, 'submit'])->whereNumber('id')->name('submit');
            Route::post('/{id}/request-approval', [$c, 'requestApproval'])->whereNumber('id')->name('request-approval');
            Route::post('/{id}/cancel', [$c, 'cancel'])->whereNumber('id')->name('cancel');
            Route::post('/{id}/checklist', [$c, 'addItem'])->whereNumber('id')->name('checklist.store');
            Route::put('/{id}/checklist/{itemId}', [$c, 'updateItem'])->whereNumber(['id', 'itemId'])->name('checklist.update');
            Route::delete('/{id}/checklist/{itemId}', [$c, 'deleteItem'])->whereNumber(['id', 'itemId'])->name('checklist.destroy');
            Route::post('/{id}/documents', [$c, 'uploadDocument'])->whereNumber('id')->name('documents.store');
            Route::delete('/{id}/documents/{docId}', [$c, 'deleteDocument'])->whereNumber(['id', 'docId'])->name('documents.destroy');
            Route::post('/{id}/handover', [$c, 'handover'])->whereNumber('id')->name('handover');
        });
        Route::middleware('can:installations.approve')->group(function () use ($c) {
            Route::post('/{id}/approve', [$c, 'approve'])->whereNumber('id')->name('approve');
            Route::post('/{id}/reject', [$c, 'reject'])->whereNumber('id')->name('reject');
        });
    });

    Route::prefix('org')->name('org.')->group(function () {
        Route::get('/options', Api\Org\OptionsController::class)->middleware('can:organization.view')->name('options');
        Route::get('/staff', [Api\Org\StaffController::class, 'index'])->middleware('can:organization.view')->name('staff.index');
        Route::put('/staff/{id}', [Api\Org\StaffController::class, 'update'])->whereNumber('id')->middleware('can:organization.manage')->name('staff.update');
        foreach ([
            'facilities' => Api\Org\FacilityController::class,
            'buildings' => Api\Org\BuildingController::class,
            'departments' => Api\Org\DepartmentController::class,
            'locations' => Api\Org\LocationController::class,
            'equipment-categories' => Api\Org\EquipmentCategoryController::class,
            'manufacturers' => Api\Org\ManufacturerController::class,
            'shifts' => Api\Org\ShiftController::class,
            'shift-assignments' => Api\Org\ShiftAssignmentController::class,
            'priority-levels' => Api\Org\PriorityLevelController::class,
        ] as $segment => $controller) {
            Route::middleware('can:organization.view')->group(function () use ($segment, $controller) {
                Route::get("/$segment", [$controller, 'index'])->name("$segment.index");
                Route::get("/$segment/{id}", [$controller, 'show'])->whereNumber('id')->name("$segment.show");
            });
            Route::middleware('can:organization.manage')->group(function () use ($segment, $controller) {
                Route::post("/$segment", [$controller, 'store'])->name("$segment.store");
                Route::put("/$segment/{id}", [$controller, 'update'])->whereNumber('id')->name("$segment.update");
                Route::patch("/$segment/{id}/status", [$controller, 'status'])->whereNumber('id')->name("$segment.status");
                Route::delete("/$segment/{id}", [$controller, 'destroy'])->whereNumber('id')->name("$segment.destroy");
            });
        }
    });

    Route::get('/notifications', [Api\NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read', [Api\NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('/user/profile', [ProfileController::class, 'data'])->name('profile.show');
    Route::put('/user/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/user/profile/photo', [ProfileController::class, 'photo'])->name('profile.photo');
    Route::put('/user/password', [ProfileController::class, 'changePassword'])->name('password');
    Route::put('/user/two-factor', [ProfileController::class, 'twoFactor'])->name('two-factor');

    Route::get('/user/sessions', [Api\SessionController::class, 'index'])->name('sessions');
    Route::delete('/user/sessions/{id}', [Api\SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::delete('/user/sessions', [Api\SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
});
