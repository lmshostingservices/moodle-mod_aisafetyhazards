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

use mod_aisafetyhazards\admin\setting_activation;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the activation panel in the plugin settings page.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\admin\setting_activation
 */
#[CoversClass(setting_activation::class)]
final class setting_activation_test extends \advanced_testcase {
    /**
     * The panel renders from stored state only, stores nothing, and offers the actions only when they can work.
     */
    public function test_panel(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $setting = new setting_activation('mod_aisafetyhazards/activation');
        $this->assertSame('', $setting->write_setting('anything'));
        $this->assertTrue($setting->is_related('unlock'));

        // No credentials: nothing can be checked or bought.
        $html = $setting->output_html(null);
        $this->assertStringContainsString('Not checked', $html);
        $this->assertStringContainsString('Not configured', $html);
        $this->assertSame(2, substr_count($html, 'disabled="disabled"'));
        $this->assertStringContainsString('Add a complete Site ID and API key first.', $html);
        $this->assertStringNotContainsString('activation.php?', $html, 'The action is never in the URL.');

        // A complete standalone pair: both actions are offered; the key never appears.
        set_config('lmslabssiteid', 'site-test-1', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret-key-value', 'mod_aisafetyhazards');
        $html = $setting->output_html(null);
        $this->assertSame(0, substr_count($html, 'disabled="disabled"'));
        $this->assertStringContainsString('value="check"', $html);
        $this->assertStringContainsString('value="review"', $html);
        $this->assertStringNotContainsString('secret-key-value', $html);

        // Already unlocked: only "Check access" is offered.
        set_config(
            'unlockstate',
            json_encode(
                ['status' => 'unlocked', 'checkedat' => time(), 'unlockedat' => time(),
                'credits' => 40]
            ),
            'mod_aisafetyhazards'
        );
        $html = $setting->output_html(null);
        $this->assertStringContainsString('alert-success', $html);
        $this->assertSame(1, substr_count($html, 'disabled="disabled"'));
    }
}
