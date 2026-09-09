<?php

use App\Livewire\AlertsPage;
use App\Livewire\AuditLogPage;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\SetupWizard;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\BackupsPage;
use App\Livewire\ComponentsPage;
use App\Livewire\Dashboard;
use App\Livewire\DatabasesPage;
use App\Livewire\LogsPage;
use App\Livewire\ProcessesPage;
use App\Livewire\SecurityPage;
use App\Livewire\ServicesPage;
use App\Livewire\SettingsPage;
use App\Livewire\UpdatesPage;
use App\Livewire\VhostsPage;
use App\Http\Controllers\TerminalAuthController;
use App\Http\Controllers\TerminalSessionController;
use App\Http\Controllers\VhostFilesController;
use App\Livewire\VhostFilesPage;
use App\Livewire\VhostTerminalPage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/setup', SetupWizard::class)->name('setup')->middleware('guest');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/two-factor/challenge', TwoFactorChallenge::class)->name('two-factor.challenge');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::get('/internal/terminal/auth/{sessionId}', TerminalAuthController::class)
    ->middleware('auth')
    ->name('terminal.auth');

Route::middleware(['auth', '2fa'])->group(function () {
    Route::get('/two-factor/setup', TwoFactorSetup::class)->name('two-factor.setup');
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/alerts', AlertsPage::class)->name('alerts');
    Route::get('/updates', UpdatesPage::class)->name('updates');
    Route::get('/vhosts', VhostsPage::class)->name('vhosts');
    Route::get('/vhosts/{domain}/terminal', VhostTerminalPage::class)->name('vhosts.terminal');
    Route::get('/vhosts/{domain}/files', VhostFilesPage::class)->name('vhosts.files');
    Route::get('/vhosts/{domain}/files/download', [VhostFilesController::class, 'download'])->name('vhosts.files.download');
    Route::post('/vhosts/{domain}/files/zip', [VhostFilesController::class, 'zip'])->name('vhosts.files.zip');
    Route::post('/terminal/heartbeat/{sessionId}', [TerminalSessionController::class, 'heartbeat'])->name('terminal.heartbeat');
    Route::post('/terminal/stop/{sessionId}', [TerminalSessionController::class, 'stop'])->name('terminal.stop');
    Route::get('/databases', DatabasesPage::class)->name('databases');
    Route::get('/backups', BackupsPage::class)->name('backups');
    Route::get('/services', ServicesPage::class)->name('services');
    Route::get('/processes', ProcessesPage::class)->name('processes');
    Route::get('/logs', LogsPage::class)->name('logs');
    Route::get('/security', SecurityPage::class)->name('security');
    Route::get('/components', ComponentsPage::class)->name('components');
    Route::get('/settings', SettingsPage::class)->name('settings');
    Route::get('/audit', AuditLogPage::class)->name('audit');
});
