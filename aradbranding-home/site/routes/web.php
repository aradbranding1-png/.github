<?php

declare(strict_types=1);

use App\Core\Http\Router;
use App\Install\InstallerController;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Ops\OpsController;
use App\Modules\Pages\PageController;
use App\Modules\Pages\PublicPageController;
use App\Modules\System\HealthController;
use App\Modules\System\HomeController;
use App\Modules\Users\AccountController;
use App\Modules\Users\AuthController;
use App\Modules\Payments\PaymentController;
use App\Modules\Wallet\WalletController;
use App\Modules\Proposals\ProposalController;
use App\Modules\Admin\SettingsController;
use App\Modules\Letters\ConnectionController;
use App\Modules\Letters\LetterController;
use App\Modules\Notifications\NotificationController;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index'], ['session:optional']);

    // Guests
    $r->group('', ['session', 'guest'], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin']);
        $r->post('/login', [AuthController::class, 'login'], ['csrf', 'throttle:login']);
        $r->get('/register', [AuthController::class, 'showRegister']);
        $r->post('/register', [AuthController::class, 'register'], ['csrf', 'throttle:register']);
    });

    // Signed-in users
    $r->group('', ['session', 'auth', 'csrf'], static function (Router $r): void {
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/dashboard', [DashboardController::class, 'index']);
        $r->get('/reports', [\App\Modules\Dashboard\ReportsController::class, 'index']);

        $r->get('/account', [AccountController::class, 'show']);
        $r->post('/account', [AccountController::class, 'updateProfile']);
        $r->post('/account/password', [AccountController::class, 'updatePassword'], ['throttle:login']);

        $r->get('/pages', [PageController::class, 'index']);
        $r->get('/pages/new', [PageController::class, 'create']);
        $r->post('/pages', [PageController::class, 'store']);
        $r->post('/pages/routes', [PageController::class, 'addRule']);
        $r->post('/pages/routes/{rule:\d+}/delete', [PageController::class, 'deleteRule']);
        $r->get('/pages/{uid:[0-9a-z]{26}}/edit', [PageController::class, 'edit']);
        $r->post('/pages/{uid:[0-9a-z]{26}}', [PageController::class, 'update']);
        $r->post('/pages/{uid:[0-9a-z]{26}}/toggle', [PageController::class, 'toggle']);
        $r->post('/pages/{uid:[0-9a-z]{26}}/default', [PageController::class, 'makeDefault']);

        $r->get('/wallet', [WalletController::class, 'index']);
        $r->post('/wallet/buy', [WalletController::class, 'buy'], ['throttle:payment']);
        $r->get('/payments/test', [PaymentController::class, 'testGateway']);
        $r->get('/payments/{uid:[0-9a-z]{26}}', [PaymentController::class, 'show']);
        $r->post('/p/{handle:[a-z0-9-]{3,32}}/{lang:[a-z]{2,3}}/unlock', [PublicPageController::class, 'unlock']);
        $r->post('/account/interests', [AccountController::class, 'updateInterests']);

        $r->get('/proposals', [ProposalController::class, 'feed']);
        $r->get('/proposals/more', [ProposalController::class, 'feedMore']);
        $r->get('/proposals/mine', [ProposalController::class, 'mine']);
        $r->get('/proposals/received', [ProposalController::class, 'received']);
        $r->get('/proposals/new', [ProposalController::class, 'create']);
        $r->post('/proposals', [ProposalController::class, 'store'], ['throttle:proposal']);
        $r->get('/proposals/send', [ProposalController::class, 'sendForm']);
        $r->post('/proposals/send', [ProposalController::class, 'send'], ['throttle:proposal']);
        $r->get('/proposals/{uid:[0-9a-z]{26}}', [ProposalController::class, 'show']);
        $r->get('/proposals/{uid:[0-9a-z]{26}}/edit', [ProposalController::class, 'edit']);
        $r->post('/proposals/{uid:[0-9a-z]{26}}', [ProposalController::class, 'update']);
        $r->post('/proposals/{uid:[0-9a-z]{26}}/publish', [ProposalController::class, 'publish']);
        $r->post('/proposals/{uid:[0-9a-z]{26}}/unpublish', [ProposalController::class, 'unpublish']);
        $r->post('/proposals/{uid:[0-9a-z]{26}}/delete', [ProposalController::class, 'destroy']);

        $r->get('/discover', [\App\Modules\Discover\DiscoverController::class, 'index']);
        $r->get('/discover/country/{id:\d+}', [\App\Modules\Discover\DiscoverController::class, 'country']);
        $r->get('/search', [\App\Modules\Discover\DiscoverController::class, 'search'], ['throttle:search']);

        $r->get('/letters', [LetterController::class, 'index']);
        $r->get('/letters/new', [LetterController::class, 'compose']);
        $r->post('/letters/new', [LetterController::class, 'sendPrivate'], ['throttle:letter']);
        $r->get('/letters/send', [LetterController::class, 'sendNew']);
        $r->post('/letters/send', [LetterController::class, 'sendQuote'], ['throttle:letter']);
        $r->get('/letters/public/new', static fn () => \App\Core\Http\Response::redirect('/letters/send', 301));
        $r->get('/letters/official/{uid:[0-9a-z]{26}}', [LetterController::class, 'official']);
        $r->get('/letters/public/{uid:[0-9a-z]{26}}', [LetterController::class, 'campaign']);
        $r->post('/letters/public/{uid:[0-9a-z]{26}}/send', [LetterController::class, 'publicConfirm'], ['throttle:letter']);
        $r->get('/letters/{uid:[0-9a-z]{26}}', [LetterController::class, 'show']);
        $r->post('/letters/{uid:[0-9a-z]{26}}/reply', [LetterController::class, 'reply'], ['throttle:letter']);
        $r->post('/letters/{uid:[0-9a-z]{26}}/archive', [LetterController::class, 'archive']);
        $r->get('/notifications', [NotificationController::class, 'index']);
        $r->get('/notifications/peek', [NotificationController::class, 'peek']);
        $r->post('/notifications/read', [NotificationController::class, 'readAll']);
        $r->get('/connections', [ConnectionController::class, 'index']);

        // ---- Admin (each route checks its own permission; data scope is applied inside) ----
        $r->get('/admin', [\App\Modules\Admin\DashboardAdminController::class, 'dashboard']);
        $r->get('/admin/reports', [\App\Modules\Admin\DashboardAdminController::class, 'reports'], ['can:reports.view']);
        $r->get('/admin/finance', [\App\Modules\Admin\DashboardAdminController::class, 'finance'], ['can:payments.view']);
        $r->get('/admin/api', [\App\Modules\Admin\ApiAdminController::class, 'index'], ['can:settings.manage']);
        $r->post('/admin/api', [\App\Modules\Admin\ApiAdminController::class, 'create'], ['can:settings.manage']);
        $r->post('/admin/api/{id:\d+}/revoke', [\App\Modules\Admin\ApiAdminController::class, 'revoke'], ['can:settings.manage']);
        $r->get('/admin/wallet', [\App\Modules\Admin\WalletAdminController::class, 'index'], ['can:wallet.view']);
        $r->post('/admin/wallet/{id:\d+}', [\App\Modules\Admin\WalletAdminController::class, 'adjust'], ['can:wallet.view']);
        $r->get('/admin/users', [\App\Modules\Admin\UserAdminController::class, 'index'], ['can:users.view']);
        $r->get('/admin/users/{id:\d+}', [\App\Modules\Admin\UserAdminController::class, 'show'], ['can:users.view']);
        $r->post('/admin/users/{id:\d+}/status', [\App\Modules\Admin\UserAdminController::class, 'setStatus'], ['can:users.edit']);
        $r->post('/admin/users/{id:\d+}/verify', [\App\Modules\Admin\UserAdminController::class, 'verify'], ['can:users.edit']);
        $r->post('/admin/users/{id:\d+}/wallet', [\App\Modules\Admin\UserAdminController::class, 'wallet'], ['can:wallet.view']);
        $r->post('/admin/users/{id:\d+}/roles', [\App\Modules\Admin\UserAdminController::class, 'roles'], ['can:roles.manage']);
        $r->post('/admin/users/{id:\d+}/impersonate', [\App\Modules\Admin\UserAdminController::class, 'impersonate'], ['can:users.impersonate']);
        $r->post('/admin/impersonate/stop', [\App\Modules\Admin\UserAdminController::class, 'stopImpersonating']);
        $r->get('/admin/content', [\App\Modules\Admin\ContentAdminController::class, 'index']);
        $r->post('/admin/content/pages/{id:\d+}', [\App\Modules\Admin\ContentAdminController::class, 'page'], ['can:pages.approve']);
        $r->post('/admin/content/proposals/{id:\d+}', [\App\Modules\Admin\ContentAdminController::class, 'proposal'], ['can:proposals.moderate']);
        $r->get('/admin/roles', [\App\Modules\Admin\RolesController::class, 'index'], ['can:roles.manage']);
        $r->post('/admin/roles', [\App\Modules\Admin\RolesController::class, 'create'], ['can:roles.manage']);
        $r->get('/admin/roles/{id:\d+}', [\App\Modules\Admin\RolesController::class, 'edit'], ['can:roles.manage']);
        $r->post('/admin/roles/{id:\d+}', [\App\Modules\Admin\RolesController::class, 'save'], ['can:roles.manage']);
        $r->get('/admin/audit', [\App\Modules\Admin\AuditController::class, 'index'], ['can:audit.view']);
        $r->get('/admin/exports', [\App\Modules\Admin\ExportController::class, 'index'], ['can:reports.export']);
        $r->post('/admin/exports', [\App\Modules\Admin\ExportController::class, 'create'], ['can:reports.export']);
        $r->get('/admin/exports/{uid:[0-9a-z]{26}}', [\App\Modules\Admin\ExportController::class, 'download'], ['can:reports.export']);

        $r->get('/admin/backups', [\App\Modules\Admin\BackupController::class, 'index'], ['can:backup.manage']);
        $r->post('/admin/backups', [\App\Modules\Admin\BackupController::class, 'create'], ['can:backup.manage']);
        $r->get('/admin/backups/{name:[a-z]+-[0-9]{8}-[0-9]{6}(?:-[0-9A-Za-z.-]+)?}/{file:database\.sql\.gz|code\.zip}', [\App\Modules\Admin\BackupController::class, 'download'], ['can:backup.manage']);
        $r->post('/admin/backups/{name:[a-z]+-[0-9]{8}-[0-9]{6}(?:-[0-9A-Za-z.-]+)?}/delete', [\App\Modules\Admin\BackupController::class, 'delete'], ['can:backup.manage']);

        $r->get('/admin/settings', [SettingsController::class, 'show'], ['can:settings.manage']);
        $r->get('/admin/letters', [\App\Modules\Admin\OfficialLetterController::class, 'index'], ['can:letters.official']);
        $r->post('/admin/letters', [\App\Modules\Admin\OfficialLetterController::class, 'store'], ['can:letters.official']);
        $r->post('/admin/letters/{uid:[0-9a-z]{26}}/delete', [\App\Modules\Admin\OfficialLetterController::class, 'destroy'], ['can:letters.official']);
        $r->get('/admin/system-update', [\App\Modules\Admin\SystemUpdateController::class, 'show'], ['can:updates.manage']);
        $r->post('/admin/system-update', [\App\Modules\Admin\SystemUpdateController::class, 'upload'], ['can:updates.manage']);
        $r->get('/updates', [\App\Modules\Admin\ReleaseNotesController::class, 'index']);
        $r->post('/updates', [\App\Modules\Admin\ReleaseNotesController::class, 'store'], ['can:updates.manage']);
        $r->post('/updates/{id:\d+}/toggle', [\App\Modules\Admin\ReleaseNotesController::class, 'toggle'], ['can:updates.manage']);
        $r->post('/admin/settings', [SettingsController::class, 'update'], ['can:settings.manage']);
        $r->get('/admin/home', [\App\Modules\Admin\HomeAdminController::class, 'show'], ['can:settings.manage']);
        $r->post('/admin/home', [\App\Modules\Admin\HomeAdminController::class, 'update'], ['can:settings.manage']);
        $r->post('/admin/home/reset', [\App\Modules\Admin\HomeAdminController::class, 'reset'], ['can:settings.manage']);
    });

    // Payment gateways return the user here (signed; does not depend on the session).
    $r->get('/payments/callback/{gateway:[a-z0-9_]{2,32}}', [PaymentController::class, 'callback'], ['throttle:payment']);

    // Public business pages
    $r->get('/p/{handle:[a-z0-9-]{3,32}}', [PublicPageController::class, 'show'], ['session:optional']);
    $r->get('/p/{handle:[a-z0-9-]{3,32}}/{lang:[a-z]{2,3}}', [PublicPageController::class, 'show'], ['session:optional']);

    // Operations (token protected, for hosts without SSH)
    $r->get('/health/live', [HealthController::class, 'live']);
    $r->get('/health', [HealthController::class, 'full'], ['throttle:default']);
    $r->post('/ops/migrate', [OpsController::class, 'migrate'], ['throttle:install']);
    $r->post('/ops/promote-admin', [OpsController::class, 'promoteAdmin'], ['throttle:install']);
    $r->post('/ops/wallet-credit', [OpsController::class, 'walletCredit'], ['throttle:install']);
    $r->post('/ops/metrics-backfill', [OpsController::class, 'backfillMetrics'], ['throttle:install']);

    $r->get('/install', [InstallerController::class, 'show'], ['throttle:install']);
    $r->post('/install', [InstallerController::class, 'install'], ['throttle:install']);
};
