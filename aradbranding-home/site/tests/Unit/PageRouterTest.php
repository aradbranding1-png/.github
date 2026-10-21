<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Cache\Cache;
use App\Core\Cache\FileStore;
use App\Core\Db\Connection;
use App\Core\Log\Logger;
use App\Modules\Pages\PageRouter;
use PHPUnit\Framework\TestCase;

/** Routing order from the architecture doc. The route set is pre-seeded in cache: no database needed. */
final class PageRouterTest extends TestCase
{
    private const IRAN = 1, GERMANY = 2, FRANCE = 3, TURKEY = 4;
    private const FA = 10, EN = 11, DE = 12, FR = 13, TR = 14;
    private const P_FA = 100, P_EN = 101, P_DE = 102;

    private PageRouter $router;
    private FileStore $store;

    protected function setUp(): void
    {
        $this->store = new FileStore(sys_get_temp_dir() . '/sadt-router-' . bin2hex(random_bytes(4)));
        $cache = new Cache($this->store, 't');
        $cache->put('routes:7:v0', [
            'pages' => [
                self::P_FA => ['id' => self::P_FA, 'language_id' => self::FA, 'code' => 'fa', 'version' => 1],
                self::P_EN => ['id' => self::P_EN, 'language_id' => self::EN, 'code' => 'en', 'version' => 1],
                self::P_DE => ['id' => self::P_DE, 'language_id' => self::DE, 'code' => 'de', 'version' => 1],
            ],
            'rules' => [
                ['country_id' => self::GERMANY, 'language_id' => self::EN, 'target' => self::P_DE], // exact
                ['country_id' => null, 'language_id' => self::TR, 'target' => self::P_EN],          // language
                ['country_id' => self::TURKEY, 'language_id' => null, 'target' => self::P_EN],      // country
                ['country_id' => null, 'language_id' => null, 'target' => self::P_FA],              // default
            ],
            'owner_version' => 0,
        ], 3600);
        $db = new Connection(['host' => 'unused', 'port' => 3306, 'database' => 'x', 'username' => 'x', 'password' => 'x'], new Logger(sys_get_temp_dir()));
        $this->router = new PageRouter($db, $cache);
    }

    protected function tearDown(): void
    {
        $this->store->flush();
    }

    public function testExactRuleWins(): void
    {
        self::assertSame(self::P_DE, $this->router->resolve(7, self::GERMANY, self::EN));
    }

    public function testImplicitSameLanguagePage(): void
    {
        self::assertSame(self::P_DE, $this->router->resolve(7, self::GERMANY, self::DE));
        self::assertSame(self::P_FA, $this->router->resolve(7, self::IRAN, self::FA));
        self::assertSame(self::P_EN, $this->router->resolve(7, self::IRAN, self::EN));
    }

    public function testLanguageRuleBeatsCountryRule(): void
    {
        self::assertSame(self::P_EN, $this->router->resolve(7, self::FRANCE, self::TR));
    }

    public function testCountryRule(): void
    {
        self::assertSame(self::P_EN, $this->router->resolve(7, self::TURKEY, self::FR));
    }

    public function testFallsBackToOwnersDefaultNeverToEnglish(): void
    {
        self::assertSame(self::P_FA, $this->router->resolve(7, self::FRANCE, self::FR));
        self::assertSame(self::P_FA, $this->router->resolve(7, null, null));
    }

    public function testPageInLanguage(): void
    {
        self::assertSame(self::P_EN, $this->router->pageInLanguage(7, 'en'));
        self::assertNull($this->router->pageInLanguage(7, 'fr'));
    }
}
