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

use mod_aisafetyhazards\local\ai\lmslabs;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the LMS Labs connection (no network).
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\ai\lmslabs
 */
#[CoversClass(lmslabs::class)]
final class lmslabs_test extends \advanced_testcase {
    /**
     * Resets the transport.
     */
    protected function tearDown(): void {
        lmslabs::$transport = null;
        lmslabs::$posttransport = null;
        parent::tearDown();
    }

    /**
     * Pictures and drafts each need their site switch and a complete credential pair.
     */
    public function test_switches(): void {
        $this->resetAfterTest();
        set_config('aiimages', 1, 'mod_aisafetyhazards');
        set_config('aidrafts', 1, 'mod_aisafetyhazards');
        $p = new lmslabs();
        $this->assertFalse($p->can_generate(), 'No credentials.');
        $this->assertFalse($p->can_draft(), 'No credentials.');
        set_config('lmslabssiteid', 'site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'key', 'mod_aisafetyhazards');
        $this->assertTrue($p->is_connected());
        $this->assertTrue($p->can_generate());
        $this->assertTrue($p->can_draft());
        set_config('aiimages', 0, 'mod_aisafetyhazards');
        $this->assertFalse($p->can_generate());
        $this->assertTrue($p->can_draft());
        set_config('aidrafts', 0, 'mod_aisafetyhazards');
        $this->assertFalse($p->can_draft());
    }

    /**
     * Only the two AI Safety Hazards routes can be posted to, and never without a complete credential pair.
     */
    public function test_post_routes(): void {
        $this->resetAfterTest();
        lmslabs::$posttransport = function () {
            $this->fail('Nothing may be sent.');
        };
        try {
            lmslabs::post(lmslabs::TEXT_ROUTE, '{}', 'k', 'application/json');
            $this->fail('Sent without credentials');
        } catch (\moodle_exception $e) {
            $this->assertSame('ainotavailable', $e->errorcode);
        }
        set_config('lmslabssiteid', 'site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'key', 'mod_aisafetyhazards');
        $this->expectException(\coding_exception::class);
        lmslabs::post('/api/credits/consume', '{}', 'k', 'application/json');
    }

    /**
     * The balance is read with the key in the X-API-Key header, never the URL.
     */
    public function test_balance(): void {
        $this->resetAfterTest();
        $p = new lmslabs();
        $this->assertNull($p->balance(), 'Not connected.');
        set_config('lmslabssiteid', 'My Site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret', 'mod_aisafetyhazards');
        $seen = [];
        lmslabs::$transport = function ($url, $headers) use (&$seen) {
            $seen = [$url, $headers];
            return [200, json_encode(['siteId' => 'My Site', 'credits' => 120, 'creditsRaw' => 120, 'isUnlimited' => false])];
        };
        $this->assertSame(['unlimited' => false, 'credits' => 120], $p->balance());
        $this->assertSame('https://lms-labs.com/api/credits?siteId=My%20Site', $seen[0]);
        $this->assertStringNotContainsString('secret', $seen[0]);
        $this->assertContains('X-API-Key: secret', $seen[1]);
        lmslabs::$transport = fn() => [200, json_encode(['credits' => 'unlimited', 'creditsRaw' => -1, 'isUnlimited' => true])];
        $this->assertSame(['unlimited' => true, 'credits' => -1], $p->balance());
        lmslabs::$transport = fn() => [401, json_encode(['error' => 'Invalid credentials'])];
        $this->assertNull($p->balance());
    }
}
