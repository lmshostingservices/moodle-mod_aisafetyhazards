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

use mod_aisafetyhazards\local\catalogue;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the task tree and the risk matrix.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\catalogue
 */
#[CoversClass(catalogue::class)]
final class catalogue_test extends \advanced_testcase {
    /**
     * Every built-in industry, sub-industry and task has a name.
     */
    public function test_tree_names(): void {
        $tree = catalogue::tree();
        $this->assertCount(count(catalogue::TREE), $tree);
        $this->assertSame('Construction', $tree['construction']['name']);
        $this->assertSame('Civil construction', $tree['construction']['subs']['civil']['name']);
        $this->assertSame('Trenching', $tree['construction']['subs']['civil']['tasks']['trenching']);
        foreach ($tree as $ind) {
            $this->assertNotEmpty($ind['subs']);
            foreach ($ind['subs'] as $sub) {
                $this->assertNotEmpty($sub['tasks']);
                foreach ($sub['tasks'] as $name) {
                    $this->assertStringNotContainsString('[[', $name);
                }
            }
        }
        $picker = catalogue::picker();
        $this->assertSame('construction', $picker[0]['key']);
        $this->assertNotEmpty($picker[0]['subs'][0]['tasks'][0]['name']);
    }

    /**
     * Extra tasks from the site settings join a matching built-in industry or make a new one; bad lines are ignored.
     */
    public function test_extra_tasks(): void {
        $this->resetAfterTest();
        set_config('extratasks', "construction | Civil construction | Pile driving\nFilm production | Stunts | High falls\n"
            . "broken line\nA | | C", 'mod_aisafetyhazards');
        $tree = catalogue::tree();
        $this->assertContains('Pile driving', $tree['construction']['subs']['civil']['tasks']);
        $mining = array_values(array_filter($tree, fn($i) => $i['name'] === 'Film production'));
        $this->assertCount(1, $mining);
        $this->assertSame(['High falls'], array_values($mining[0]['subs'][array_key_first($mining[0]['subs'])]['tasks']));
        $this->assertCount(count(catalogue::TREE) + 1, $tree);
        // Keys are stable and made of letters only.
        $this->assertSame(array_keys($tree), array_keys(catalogue::tree()));
        foreach (array_keys($tree) as $key) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', (string)$key);
        }
        $this->assertSame([array_keys($tree)[count($tree) - 1], array_key_first($mining[0]['subs']),
            array_key_first(reset($mining[0]['subs'])['tasks'])], catalogue::valid_path(
                array_keys($tree)[count($tree) - 1],
                array_key_first($mining[0]['subs']),
                array_key_first(reset($mining[0]['subs'])['tasks'])
            ));
    }

    /**
     * Unknown choices become custom, and custom names show as the teacher typed them.
     */
    public function test_valid_path_and_names(): void {
        $this->assertSame(['construction', 'civil', 'trenching'], catalogue::valid_path('construction', 'civil', 'trenching'));
        $this->assertSame(['construction', 'civil', 'custom'], catalogue::valid_path('construction', 'civil', 'nope'));
        $this->assertSame(['construction', 'custom', 'custom'], catalogue::valid_path('construction', 'nope', 'trenching'));
        $this->assertSame(['custom', 'custom', 'custom'], catalogue::valid_path('nope', 'civil', 'trenching'));
        $instance = (object)['industry' => 'construction', 'subindustry' => 'civil', 'task' => 'custom',
            'customindustry' => '', 'customsubindustry' => '', 'customtask' => 'Laying kerbs'];
        $this->assertSame(['Construction', 'Civil construction', 'Laying kerbs'], catalogue::names($instance));
        $this->assertSame(['Construction', 'Civil construction', 'Laying kerbs'], catalogue::names_english($instance));
        $instance = (object)['industry' => 'custom', 'subindustry' => 'custom', 'task' => 'custom',
            'customindustry' => 'Film', 'customsubindustry' => 'Stunts', 'customtask' => 'High falls'];
        $this->assertSame(['Film', 'Stunts', 'High falls'], catalogue::names($instance));
    }

    /**
     * The 5 × 5 matrix: likelihood times consequence.
     */
    public function test_rating(): void {
        $this->assertSame('low', catalogue::rating(1, 1));
        $this->assertSame('low', catalogue::rating(1, 3));
        $this->assertSame('medium', catalogue::rating(2, 3));
        $this->assertSame('high', catalogue::rating(3, 4));
        $this->assertSame('extreme', catalogue::rating(4, 5));
        $this->assertSame('extreme', catalogue::rating(9, 9));
        $this->assertSame('low', catalogue::rating(0, -2));
        $this->assertTrue(catalogue::is_level('engineering'));
        $this->assertFalse(catalogue::is_level('magic'));
    }
}
