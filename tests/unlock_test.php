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

use mod_aisafetyhazards\local\unlock;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Activation (one-time unlock) with a mocked LMS Labs: nothing is sent to the network and nothing is bought.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\unlock
 */
#[CoversClass(unlock::class)]
final class unlock_test extends \advanced_testcase {
    /** @var array Requests the fake LMS Labs received. */
    protected $sent = [];
    /** @var array Answers by path: list of [status, body array|string]. */
    protected $answers = [];
    /** @var string Release SHA in the fake manifest. */
    protected const SHA = 'a5c4a33eac8e64736771644fbec943929ef0b38a10ba3f1f04332399a8475e99';

    /**
     * Standalone credentials and a fake transport.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'My Site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret-key', 'mod_aisafetyhazards');
        $this->sent = [];
        $this->answers = [];
        unlock::$transport = function (array $req) {
            $path = parse_url($req['url'], PHP_URL_PATH);
            $this->assertStringStartsWith('https://lms-labs.com/api/', $req['url']);
            $this->assertStringNotContainsString('secret-key', $req['url'], 'The key never goes in a URL.');
            $this->sent[] = ['method' => $req['method'], 'path' => $path, 'body' => json_decode((string)$req['body'], true)];
            if (empty($this->answers[$path])) {
                $this->fail('Unexpected request to ' . $path);
            }
            [$status, $body] = array_shift($this->answers[$path]);
            return [$status, is_array($body) ? json_encode($body) : (string)$body];
        };
    }

    /**
     * Resets the transport.
     */
    protected function tearDown(): void {
        unlock::$transport = null;
        parent::tearDown();
    }

    /**
     * A free access answer.
     *
     * @param bool $unlocked
     * @param mixed $credits
     * @return array
     */
    protected function verify(bool $unlocked, $credits = 120): array {
        return [200, ['unlocked' => $unlocked, 'credits' => $credits, 'unlockedAt' => $unlocked ? '2026-09-28T10:00:00Z' : null,
            'entitlementSource' => $unlocked ? 'credits' : null]];
    }

    /**
     * A live manifest.
     *
     * @param array $changes
     * @return array
     */
    protected function manifest(array $changes = []): array {
        return [200, ['success' => true, 'plugins' => ['mod_aisafetyhazards' => $changes + ['version' => '1.1.0',
            'component' => 'mod_aisafetyhazards', 'status' => 'ready', 'zipExists' => true, 'sha256' => self::SHA,
            'creditsRequired' => 50, 'acquisitionMode' => 'credit-unlock']]]];
    }

    /**
     * Requests to one path.
     *
     * @param string $path
     * @return array
     */
    protected function sent_to(string $path): array {
        return array_values(array_filter($this->sent, fn($r) => $r['path'] === $path));
    }

    /**
     * Not checked until an administrator asks; the free check reports locked or unlocked with the balance.
     */
    public function test_check_access_states(): void {
        $this->assertSame('notchecked', unlock::state()['status']);
        $this->assertCount(0, $this->sent, 'Nothing is sent just by reading the state.');
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false, 80), $this->verify(true, 'unlimited'),
            [0, ''], [500, ['error' => 'SERVER']]];
        $state = unlock::verify();
        $this->assertSame('locked', $state['status']);
        $this->assertSame(80, $state['credits']);
        $this->assertSame(
            ['pluginId' => 'aisafetyhazards', 'siteId' => 'My Site', 'apiKey' => 'secret-key'],
            $this->sent[0]['body']
        );
        $state = unlock::verify();
        $this->assertSame('unlocked', $state['status']);
        $this->assertTrue($state['unlimited']);
        $this->assertSame('unknown', unlock::verify()['status'], 'No answer: unable to verify.');
        $this->assertSame('unknown', unlock::verify()['status'], 'Server error: unable to verify.');
        $this->assertSame('unknown', unlock::state()['status']);
        // An unlocked site stays usable while LMS Labs cannot be reached; a definite "locked" ends that.
        $this->assertTrue(unlock::state()['wasunlocked']);
        $this->assertTrue(unlock::active());
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false, 10), [0, '']];
        unlock::verify();
        $this->assertFalse(unlock::active());
        unlock::verify();
        $this->assertFalse(unlock::active(), 'No answer after "locked" does not unlock.');
    }

    /**
     * Credentials being configured is not the same as being unlocked; without credentials nothing is sent.
     */
    public function test_credentials_are_not_an_unlock(): void {
        set_config('lmslabssiteid', '', 'mod_aisafetyhazards');
        $state = unlock::verify();
        $this->assertSame('unknown', $state['status']);
        $this->assertSame('nocredentials', $state['error']);
        $this->assertCount(0, $this->sent);
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $review = unlock::review();
        $this->assertSame('nocredentials', $review['blocked']);
        $this->assertCount(0, $this->sent_to('/api/plugin-unlock/verify'), 'No access check without credentials.');
    }

    /**
     * Review reads the live price and release and never buys; unlocking is only offered for a sellable release.
     */
    public function test_review_never_buys(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false, 20)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $review = unlock::review();
        $this->assertTrue($review['canbuy']);
        $this->assertSame(50, $review['release']['price']);
        $this->assertSame(self::SHA, $review['release']['sha']);
        $this->assertSame('insufficient', $review['warning'], 'A low balance warns but does not block (restorations).');
        $this->assertCount(0, $this->sent_to('/api/plugin-unlock'), 'Reviewing never buys.');
        foreach (
            [
                ['zipExists' => false, 'nozip'], ['sha256' => 'nothex', 'nosha'], ['creditsRequired' => 0, 'noprice'],
                ['creditsRequired' => '50.5', 'noprice'], ['status' => 'draft', 'notavailable'],
                ['acquisitionMode' => 'usd-purchase', 'mode'],
            ] as $case
        ) {
            $reason = array_pop($case);
            $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false)];
            $this->answers['/api/plugin-versions'] = [$this->manifest($case)];
            $review = unlock::review();
            $this->assertFalse($review['canbuy'], $reason);
            $this->assertSame('release', $review['blocked']);
            $this->assertSame($reason, $review['release']['reason']);
        }
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(true)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->assertSame('unlocked', unlock::review()['blocked'], 'Already unlocked: nothing to buy.');
        $this->assertCount(0, $this->sent_to('/api/plugin-unlock'));
    }

    /**
     * A confirmed unlock sends exactly the live price and release; the charge reported is the one returned.
     */
    public function test_unlock(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false), $this->verify(true, 70)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[200, ['success' => true, 'alreadyUnlocked' => false, 'creditsConsumed' => 50,
            'entitlementSource' => 'credits', 'remainingCredits' => 70]]];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('unlocked', $r['outcome']);
        $this->assertSame(50, $r['consumed']);
        $this->assertSame(
            ['pluginId' => 'aisafetyhazards', 'pluginComponent' => 'mod_aisafetyhazards', 'siteId' => 'My Site',
            'apiKey' => 'secret-key', 'releaseSha256' => self::SHA, 'expectedCredits' => 50],
            $this->sent_to('/api/plugin-unlock')[0]['body']
        );
        $this->assertSame('unlocked', unlock::state()['status']);
        $this->assertNull(unlock::pending());
    }

    /**
     * Already unlocked: no purchase is sent; a historic charge is never shown as a new one.
     */
    public function test_already_unlocked(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(true)];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('already', $r['outcome']);
        $this->assertCount(0, $this->sent_to('/api/plugin-unlock'));

        // The server says it was already unlocked: creditsConsumed is the original purchase.
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false), $this->verify(true)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[200, ['success' => true, 'alreadyUnlocked' => true, 'creditsConsumed' => 50,
            'entitlementSource' => 'credits']]];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('already', $r['outcome']);
        $this->assertNull($r['consumed'], 'No new debit is reported.');
        $this->assertSame(50, $r['historic']);
    }

    /**
     * A recognised Marketplace purchase is restored at zero credits, even with a zero balance.
     */
    public function test_purchase_restoration(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false, 0), $this->verify(true, 0)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[200, ['success' => true, 'alreadyUnlocked' => false, 'creditsConsumed' => 0,
            'entitlementSource' => 'marketplace', 'remainingCredits' => 0]]];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('restored', $r['outcome']);
        $this->assertSame(0, $r['consumed']);
    }

    /**
     * Not enough credits: refused, nothing unlocked, the balance is shown, and another try is allowed.
     */
    public function test_insufficient_credits(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false, 10)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[402, ['success' => false, 'code' => 'insufficient_credits',
            'message' => 'Not enough credits', 'remainingCredits' => 10]]];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('insufficient', $r['outcome']);
        $this->assertSame('locked', unlock::state()['status']);
        $this->assertNull(unlock::pending(), 'A definite refusal is not left pending.');
        $this->assertSame(10, unlock::state()['credits']);
    }

    /**
     * A changed price or release since the confirmation: nothing is sent; a server-side stale price is reported.
     */
    public function test_stale_price_or_release(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false), $this->verify(false)];
        $this->answers['/api/plugin-versions'] = [$this->manifest(['creditsRequired' => 60]),
            $this->manifest(['sha256' => str_repeat('b', 64)])];
        $this->assertSame('changed', unlock::buy(50, self::SHA)['outcome']);
        $this->assertSame('changed', unlock::buy(50, self::SHA)['outcome']);
        $this->assertCount(0, $this->sent_to('/api/plugin-unlock'));

        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[409, ['success' => false, 'code' => 'stale_credit_price',
            'message' => 'Price changed']]];
        $this->assertSame('stale', unlock::buy(50, self::SHA)['outcome']);
        $this->assertNull(unlock::pending());
    }

    /**
     * No answer: the outcome is unknown, another unlock is blocked until a free check answers definitely.
     */
    public function test_uncertainty(): void {
        $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false)];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->answers['/api/plugin-unlock'] = [[0, '']];
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('uncertain', $r['outcome']);
        $this->assertNotNull(unlock::pending());

        // Offering or sending another unlock is blocked while the outcome is unknown.
        $this->assertSame('pending', unlock::buy(50, self::SHA)['error']);
        $this->answers['/api/plugin-unlock/verify'] = [[0, '']];
        $this->answers['/api/plugin-versions'] = [$this->manifest()];
        $this->assertSame('pending', unlock::review()['blocked']);
        $this->assertCount(1, $this->sent_to('/api/plugin-unlock'));

        // A failed check keeps the marker; a definite answer settles it.
        $this->answers['/api/plugin-unlock/verify'] = [[503, ['error' => 'UNAVAILABLE']], $this->verify(true)];
        unlock::verify();
        $this->assertNotNull(unlock::pending());
        $state = unlock::verify();
        $this->assertSame('unlocked', $state['resolved']);
        $this->assertNull(unlock::pending());

        // A 5xx or an unreadable 200 from the unlock itself is uncertain too.
        foreach ([[500, ['error' => 'X']], [200, 'not json']] as $answer) {
            $this->answers['/api/plugin-unlock/verify'] = [$this->verify(false)];
            $this->answers['/api/plugin-versions'] = [$this->manifest()];
            $this->answers['/api/plugin-unlock'] = [$answer];
            $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
            unset_config('unlockpending', 'mod_aisafetyhazards');
        }
    }
}
