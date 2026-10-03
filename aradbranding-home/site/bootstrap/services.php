<?php

declare(strict_types=1);

/* Module service registrations. Controllers are built by Container::make(). */

use App\Core\Cache\Cache;
use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Storage\ImageUploader;
use App\Modules\Pages\PageRouter;
use App\Modules\Pages\PageService;
use App\Modules\Reference\ReferenceData;
use App\Modules\Users\AuthService;
use App\Core\Auth\Gate;
use App\Core\Log\Logger;
use App\Core\Security\Idempotency;
use App\Core\Settings\Settings;
use App\Modules\Pages\PageViewGate;
use App\Modules\Payments\PaymentService;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;
use App\Modules\Proposals\FeedService;
use App\Modules\Proposals\ProposalService;

return static function (Container $c): void {
    $c->singleton(ReferenceData::class, static fn (Container $c) => new ReferenceData($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(AuthService::class, static fn (Container $c) => new AuthService(
        $c->get(Connection::class),
        $c->get(Outbox::class),
        $c->get(ImageUploader::class)
    ));
    $c->singleton(PageRouter::class, static fn (Container $c) => new PageRouter($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(PageService::class, static fn (Container $c) => new PageService(
        $c->get(Connection::class),
        $c->get(Cache::class),
        $c->get(ImageUploader::class)
    ));
    $c->singleton(Settings::class, static fn (Container $c) => new Settings($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(Idempotency::class, static fn (Container $c) => new Idempotency($c->get(Connection::class)));
    $c->singleton(Pricing::class, static fn (Container $c) => new Pricing($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(WalletService::class, static fn (Container $c) => new WalletService(
        $c->get(Connection::class),
        $c->get(Outbox::class),
        $c->get(Idempotency::class)
    ));
    $c->singleton(PageViewGate::class, static fn (Container $c) => new PageViewGate(
        $c->get(Connection::class),
        $c->get(WalletService::class),
        $c->get(Pricing::class),
        $c->get(Settings::class),
        $c->get(Gate::class),
        $c->get(Outbox::class)
    ));
    $c->singleton(PaymentService::class, static fn (Container $c) => new PaymentService(
        $c->get(Connection::class),
        $c->get(WalletService::class),
        $c->get(Pricing::class),
        $c->get(Logger::class)
    ));
    $c->singleton(ProposalService::class, static fn (Container $c) => new ProposalService(
        $c->get(Connection::class),
        $c->get(ImageUploader::class),
        $c->get(WalletService::class),
        $c->get(Pricing::class),
        $c->get(Settings::class),
        $c->get(Outbox::class),
        $c->get(\App\Modules\Letters\LetterService::class),
        $c->get(\App\Modules\Trust\TrustService::class)
    ));
    $c->singleton(FeedService::class, static fn (Container $c) => new FeedService($c->get(Connection::class), $c->get(Settings::class)));
    $c->singleton(\App\Modules\Notifications\NotificationService::class, static fn (Container $c) => new \App\Modules\Notifications\NotificationService($c->get(Connection::class)));
    $c->singleton(\App\Modules\Letters\LetterService::class, static fn (Container $c) => new \App\Modules\Letters\LetterService(
        $c->get(Connection::class),
        $c->get(WalletService::class),
        $c->get(Pricing::class),
        $c->get(Outbox::class),
        $c->get(\App\Modules\Trust\TrustService::class)
    ));
    $c->singleton(\App\Modules\Letters\AnnouncementService::class, static fn (Container $c) => new \App\Modules\Letters\AnnouncementService($c->get(Connection::class)));
    $c->singleton(\App\Modules\Letters\CampaignService::class, static fn (Container $c) => new \App\Modules\Letters\CampaignService(
        $c->get(Connection::class),
        $c->get(WalletService::class),
        $c->get(Pricing::class),
        $c->get(Settings::class),
        $c->get(\App\Core\Queue\DatabaseQueue::class),
        $c->get(\App\Modules\Notifications\NotificationService::class)
    ));
    $c->singleton(\App\Modules\Admin\MetricsService::class, static fn (Container $c) => new \App\Modules\Admin\MetricsService($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(\App\Modules\Admin\ExportService::class, static fn (Container $c) => new \App\Modules\Admin\ExportService($c->get(Connection::class), $c->get(\App\Core\Queue\DatabaseQueue::class)));
    $c->singleton(\App\Modules\System\BackupService::class, static fn (Container $c) => new \App\Modules\System\BackupService($c->get(Connection::class)));
    $c->singleton(\App\Modules\System\SitemapService::class, static fn (Container $c) => new \App\Modules\System\SitemapService($c->get(Connection::class)));
    $c->singleton(\App\Core\Search\SearchProvider::class, static fn (Container $c) => new \App\Core\Search\MysqlSearchProvider($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(\App\Modules\Discover\DiscoverService::class, static fn (Container $c) => new \App\Modules\Discover\DiscoverService($c->get(Connection::class), $c->get(Cache::class)));
    $c->singleton(\App\Modules\Trust\TrustService::class, static fn (Container $c) => new \App\Modules\Trust\TrustService($c->get(Connection::class), $c->get(Settings::class)));
    $c->singleton(\App\Modules\Integrations\ApiKeys::class, static fn (Container $c) => new \App\Modules\Integrations\ApiKeys($c->get(Connection::class)));
    $c->singleton(\App\Core\Update\UpdateManager::class, static fn (Container $c) => new \App\Core\Update\UpdateManager(
        $c->get(Connection::class),
        $c->get(Cache::class),
        $c->get(\App\Core\Config::class)
    ));
};
