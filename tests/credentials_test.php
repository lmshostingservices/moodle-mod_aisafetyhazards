<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_aisafetyhazards;

use mod_aisafetyhazards\local\credentials;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * LMS Labs credentials: Central Config (local_aiconfig) first, then this plugin's settings, never mixed.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\credentials
 */
#[CoversClass(credentials::class)]
final class credentials_test extends \advanced_testcase {
    /**
     * Restores the real Central Config lookup.
     */
    protected function tearDown(): void {
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * Simulates Central Config: null means not installed.
     *
     * @param array|null $pair
     */
    protected function central(?array $pair): void {
        credentials::$central = fn() => $pair;
    }

    /**
     * Sets this plugin's standalone pair.
     *
     * @param string $siteid
     * @param string $apikey
     */
    protected function local(string $siteid, string $apikey): void {
        set_config('lmslabssiteid', $siteid, 'mod_aisafetyhazards');
        set_config('lmslabsapikey', $apikey, 'mod_aisafetyhazards');
    }

    /**
     * Central only, local only, and both (central wins).
     */
    public function test_complete_pairs(): void {
        $this->resetAfterTest();
        $this->central(['siteid' => ' central-site ', 'apikey' => 'central-key']);
        $this->assertSame(['siteid' => 'central-site', 'apikey' => 'central-key', 'source' => 'central'], credentials::resolve());
        $this->assertSame('credentials_central', credentials::status());

        $this->central(null);
        $this->local('local-site', 'local-key');
        $this->assertSame(['siteid' => 'local-site', 'apikey' => 'local-key', 'source' => 'local'], credentials::resolve());
        $this->assertSame('credentials_local', credentials::status());

        $this->central(['siteid' => 'central-site', 'apikey' => 'central-key']);
        $this->assertSame('central', credentials::resolve()['source'], 'Central Config wins when both are complete.');
        $this->assertSame('central-key', credentials::resolve()['apikey']);
    }

    /**
     * An incomplete pair is never used and never mixed with the other source.
     */
    public function test_incomplete_pairs_never_mixed(): void {
        $this->resetAfterTest();
        // Incomplete central, complete local: the complete local pair is used as a whole.
        $this->central(['siteid' => 'central-site', 'apikey' => '']);
        $this->local('local-site', 'local-key');
        $this->assertSame(['siteid' => 'local-site', 'apikey' => 'local-key', 'source' => 'local'], credentials::resolve());

        // Central Site ID only and local API key only: nothing is usable.
        $this->local('', 'local-key');
        $this->assertNull(credentials::find());
        $this->assertSame('credentials_centralincomplete', credentials::status());

        // Incomplete local and no Central Config.
        $this->central(null);
        $this->local('local-site', '');
        $this->assertNull(credentials::find());
        $this->assertSame('credentials_none', credentials::status());
        try {
            credentials::resolve();
            $this->fail('An incomplete pair was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('lmslabscredentialsmissing', $e->errorcode);
        }
    }

    /**
     * Without the test hook the real lookup runs: Central Config is optional, and its absence is handled.
     */
    public function test_central_config_not_installed(): void {
        $this->resetAfterTest();
        credentials::$central = null;
        if (class_exists('\\local_aiconfig\\config')) {
            $this->assertIsArray(credentials::central());
        } else {
            $this->assertNull(credentials::central());
            $this->local('local-site', 'local-key');
            $this->assertSame('local', credentials::resolve()['source']);
        }
    }

    /**
     * The LMS Labs balance check uses the resolved pair: central credentials, key in the header only.
     */
    public function test_balance_uses_central_pair(): void {
        $this->resetAfterTest();
        $this->central(['siteid' => 'Central Site', 'apikey' => 'central-secret']);
        $this->local('local-site', 'local-secret');
        $seen = [];
        local\ai\lmslabs::$transport = function ($url, $headers) use (&$seen) {
            $seen = [$url, $headers];
            return [200, json_encode(['creditsRaw' => 5, 'isUnlimited' => false])];
        };
        try {
            $this->assertSame(['unlimited' => false, 'credits' => 5], (new local\ai\lmslabs())->balance());
        } finally {
            local\ai\lmslabs::$transport = null;
        }
        $this->assertSame('https://lms-labs.com/api/credits?siteId=Central%20Site', $seen[0]);
        $this->assertContains('X-API-Key: central-secret', $seen[1]);
        $this->assertStringNotContainsString('secret', $seen[0]);
        $this->central(['siteid' => '', 'apikey' => '']);
        $this->local('', '');
        $this->assertFalse((new local\ai\lmslabs())->is_connected());
        $this->assertNull((new local\ai\lmslabs())->balance());
    }
}
