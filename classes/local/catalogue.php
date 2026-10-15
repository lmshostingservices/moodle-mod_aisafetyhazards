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

namespace mod_aisafetyhazards\local;

/**
 * Industries, sub-industries and work tasks; the risk matrix, the hierarchy of controls and content languages.
 *
 * The built-in list of tasks can be extended by the site administrator ("Extra work tasks"), and a teacher can type
 * their own industry, sub-industry or task at any level.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue {
    /** @var array Built-in industries => sub-industries => work tasks (keys; names are strings ind_, sub_, task_). */
    public const TREE = [
        'construction' => [
            'civil' => ['trenching', 'excavation', 'roadworks', 'pipelaying'],
            'building' => ['scaffolding', 'roofing', 'formwork', 'demolition'],
            'constructiontrades' => ['electricalinstall', 'plumbing', 'welding', 'concreting'],
        ],
        'mining' => [
            'surfacemining' => ['haultrucks', 'drillblast', 'conveyors', 'tyrechange'],
            'undergroundmining' => ['groundsupport', 'ventilation', 'undergroundvehicles', 'shotcrete'],
            'processing' => ['crushing', 'reagents', 'isolation', 'tailings'],
        ],
        'manufacturing' => [
            'metalfab' => ['machining', 'presses', 'grinding', 'spraypainting'],
            'foodmanufacturing' => ['slicers', 'sanitising', 'coldstorage', 'packaging'],
            'assembly' => ['conveyorlines', 'robotcells', 'manualassembly', 'forkliftfactory'],
        ],
        'logistics' => [
            'warehousing' => ['forklift', 'racking', 'pallets', 'loadingdock'],
            'roadtransport' => ['loadrestraint', 'fatigue', 'coupling', 'unloading'],
            'courier' => ['delivery', 'vanloading', 'reversing', 'parcelsorting'],
        ],
        'healthcare' => [
            'hospital' => ['patienthandling', 'sharps', 'infection', 'aggression'],
            'agedcare' => ['residenttransfer', 'personalcare', 'laundry', 'dementia'],
            'community' => ['homevisits', 'clientdriving', 'lonework', 'clienthomes'],
        ],
        'hospitality' => [
            'kitchen' => ['deepfryer', 'knives', 'dishwashing', 'coolroom'],
            'bars' => ['kegs', 'glassware', 'crowds', 'cellargas'],
            'hotels' => ['housekeeping', 'poolplant', 'hotellaundry', 'luggage'],
        ],
        'agriculture' => [
            'farming' => ['tractors', 'quadbikes', 'spraying', 'livestock'],
            'horticulture' => ['picking', 'packingshed', 'irrigation', 'pruning'],
            'forestry' => ['felling', 'chainsaw', 'logloading', 'mulching'],
        ],
        'utilities' => [
            'electricity' => ['overheadlines', 'substations', 'poles', 'vegetation'],
            'water' => ['confinedspace', 'pumpstations', 'mainsrepair', 'chlorine'],
            'waste' => ['collection', 'sortinglines', 'landfill', 'hazardouswaste'],
        ],
        'retail' => [
            'supermarket' => ['shelfstacking', 'backdock', 'delislicer', 'trolleys'],
            'hardware' => ['timberyard', 'yardforklift', 'paintmixing', 'heavyitems'],
            'shopfloor' => ['customeraggression', 'cashhandling', 'storeladders', 'spills'],
        ],
        'office' => [
            'officework' => ['workstation', 'officelifting', 'officeelectrical', 'evacuation'],
            'cleaning' => ['cleaningchemicals', 'windowcleaning', 'floorcare', 'washrooms'],
            'facilities' => ['maintenanceladders', 'plantroom', 'contractors', 'afterhours'],
        ],
    ];

    /** @var string The key of a teacher's own industry, sub-industry or task. */
    public const CUSTOM = 'custom';

    /** @var string[] Likelihood, 1 to 5. */
    public const LIKELIHOOD = ['rare', 'unlikely', 'possible', 'likely', 'almostcertain'];

    /** @var string[] Consequence, 1 to 5. */
    public const CONSEQUENCE = ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'];

    /** @var string[] Risk ratings, lowest first. */
    public const RATINGS = ['low', 'medium', 'high', 'extreme'];

    /** @var string[] The hierarchy of controls, most effective first. */
    public const LEVELS = ['elimination', 'substitution', 'isolation', 'engineering', 'administrative', 'ppe'];

    /** @var string[] The rows of control labels, in playing order. */
    public const KINDS = ['eliminate', 'reduce', 'ppe'];

    /** @var int Hazards per scene that learners find on the picture. */
    public const HAZARDS = 3;

    /** @var array Content languages: code => [endonym, right to left]. */
    public const LANGUAGES = [
        'en' => ['English', false],
        'es' => ['Español', false],
        'fr' => ['Français', false],
        'de' => ['Deutsch', false],
        'it' => ['Italiano', false],
        'pt' => ['Português', false],
        'nl' => ['Nederlands', false],
        'ru' => ['Русский', false],
        'pl' => ['Polski', false],
        'tr' => ['Türkçe', false],
        'ar' => ['العربية', true],
        'hi' => ['हिन्दी', false],
        'zh' => ['中文', false],
        'ja' => ['日本語', false],
        'ko' => ['한국어', false],
        'th' => ['ไทย', false],
        'vi' => ['Tiếng Việt', false],
        'id' => ['Bahasa Indonesia', false],
        'ms' => ['Bahasa Melayu', false],
        'fil' => ['Filipino', false],
    ];

    /**
     * Whether a code is a supported content language.
     *
     * @param string $code
     * @return bool
     */
    public static function is_language(string $code): bool {
        return isset(self::LANGUAGES[$code]);
    }

    /**
     * Language name in the current interface language.
     *
     * @param string $code
     * @return string
     */
    public static function language_name(string $code): string {
        return self::is_language($code) ? get_string('lang_' . $code, 'mod_aisafetyhazards') : $code;
    }

    /**
     * Language name in English (for AI prompts).
     *
     * @param string $code
     * @return string
     */
    public static function language_english(string $code): string {
        return self::is_language($code)
            ? get_string_manager()->get_string('lang_' . $code, 'mod_aisafetyhazards', null, 'en') : $code;
    }

    /**
     * Whether a content language is written right to left.
     *
     * @param string $code
     * @return bool
     */
    public static function is_rtl(string $code): bool {
        return (bool)(self::LANGUAGES[$code][1] ?? false);
    }

    /**
     * Content language menu (name and endonym).
     *
     * @return array
     */
    public static function language_options(): array {
        $options = [];
        foreach (self::LANGUAGES as $code => [$endonym]) {
            $name = self::language_name($code);
            $options[$code] = $name === $endonym ? $name : $name . ' (' . $endonym . ')';
        }
        return $options;
    }

    /**
     * The risk rating of a likelihood and a consequence (5 × 5 matrix: likelihood times consequence; up to 3 low, up to
     * 6 medium, up to 12 high, above that extreme).
     *
     * @param int $likelihood 1 to 5
     * @param int $consequence 1 to 5
     * @return string low, medium, high or extreme
     */
    public static function rating(int $likelihood, int $consequence): string {
        $score = max(1, min(5, $likelihood)) * max(1, min(5, $consequence));
        if ($score <= 3) {
            return 'low';
        }
        if ($score <= 6) {
            return 'medium';
        }
        return $score <= 12 ? 'high' : 'extreme';
    }

    /**
     * The task tree with the administrator's extra tasks ("Industry | Sub-industry | Task", one per line) added.
     *
     * Extra entries get keys made from their names (x followed by letters), and keep their names as typed.
     *
     * @return array industry => [name, subs => [sub => [name, tasks => [task => name]]]]
     */
    public static function tree(): array {
        $out = [];
        foreach (self::TREE as $ind => $subs) {
            $out[$ind] = ['name' => get_string('ind_' . $ind, 'mod_aisafetyhazards'), 'subs' => []];
            foreach ($subs as $sub => $tasks) {
                $names = [];
                foreach ($tasks as $task) {
                    $names[$task] = get_string('task_' . $task, 'mod_aisafetyhazards');
                }
                $out[$ind]['subs'][$sub] = ['name' => get_string('sub_' . $sub, 'mod_aisafetyhazards'), 'tasks' => $names];
            }
        }
        foreach (preg_split('/\R/u', (string)get_config('mod_aisafetyhazards', 'extratasks')) as $line) {
            $parts = array_map(fn($p) => manager::clean_line($p, 120), explode('|', $line));
            if (count($parts) !== 3 || in_array('', $parts, true)) {
                continue;
            }
            [$ind, $sub, $task] = $parts;
            $indkey = self::find_key($out, $ind) ?? self::extra_key($ind);
            $out[$indkey] = $out[$indkey] ?? ['name' => $ind, 'subs' => []];
            $subkey = self::find_key($out[$indkey]['subs'], $sub) ?? self::extra_key($ind . '|' . $sub);
            $out[$indkey]['subs'][$subkey] = $out[$indkey]['subs'][$subkey] ?? ['name' => $sub, 'tasks' => []];
            $out[$indkey]['subs'][$subkey]['tasks'][self::extra_key($ind . '|' . $sub . '|' . $task)] = $task;
        }
        return $out;
    }

    /**
     * The key of an entry whose name matches (ignoring case), so an extra task can join a built-in industry.
     *
     * @param array $entries key => [name, ...]
     * @param string $name
     * @return string|null
     */
    protected static function find_key(array $entries, string $name): ?string {
        foreach ($entries as $key => $entry) {
            if (\core_text::strtolower($entry['name']) === \core_text::strtolower($name)) {
                return (string)$key;
            }
        }
        return null;
    }

    /**
     * A stable key for an administrator's entry: x and 12 letters made from its name.
     *
     * @param string $name
     * @return string
     */
    protected static function extra_key(string $name): string {
        return 'x' . strtr(substr(sha1(\core_text::strtolower($name)), 0, 12), '0123456789', 'ghijklmnop');
    }

    /**
     * The activity's industry, sub-industry and task as shown to people (the teacher's own names for custom ones).
     *
     * @param \stdClass $instance
     * @return array [industry, sub-industry, task]
     */
    public static function names(\stdClass $instance): array {
        $tree = self::tree();
        $own = fn($custom) => trim((string)$custom) !== '' ? trim((string)$custom)
            : get_string('custom_name', 'mod_aisafetyhazards');
        $ind = (string)$instance->industry;
        $sub = (string)$instance->subindustry;
        $task = (string)$instance->task;
        $indname = $ind === self::CUSTOM ? $own($instance->customindustry) : ($tree[$ind]['name'] ?? $ind);
        $subs = $tree[$ind]['subs'] ?? [];
        $subname = $sub === self::CUSTOM || !isset($subs[$sub]) ? $own($instance->customsubindustry) : $subs[$sub]['name'];
        $tasks = $subs[$sub]['tasks'] ?? [];
        $taskname = $task === self::CUSTOM || !isset($tasks[$task]) ? $own($instance->customtask) : $tasks[$task];
        return [$indname, $subname, $taskname];
    }

    /**
     * The same names in English, for AI prompts (a built-in name in English; a custom or extra one as typed).
     *
     * @param \stdClass $instance
     * @return array [industry, sub-industry, task]
     */
    public static function names_english(\stdClass $instance): array {
        $sm = get_string_manager();
        $names = self::names($instance);
        $keys = [['ind_', (string)$instance->industry], ['sub_', (string)$instance->subindustry],
            ['task_', (string)$instance->task]];
        foreach ($keys as $i => [$prefix, $key]) {
            if ($key !== self::CUSTOM && $sm->string_exists($prefix . $key, 'mod_aisafetyhazards')) {
                $names[$i] = $sm->get_string($prefix . $key, 'mod_aisafetyhazards', null, 'en');
            }
        }
        return $names;
    }

    /**
     * Makes an industry, sub-industry and task choice valid: unknown keys become custom.
     *
     * @param string $ind
     * @param string $sub
     * @param string $task
     * @return array [industry, sub-industry, task]
     */
    public static function valid_path(string $ind, string $sub, string $task): array {
        $tree = self::tree();
        if (!isset($tree[$ind])) {
            return [self::CUSTOM, self::CUSTOM, self::CUSTOM];
        }
        if (!isset($tree[$ind]['subs'][$sub])) {
            return [$ind, self::CUSTOM, self::CUSTOM];
        }
        if (!isset($tree[$ind]['subs'][$sub]['tasks'][$task])) {
            return [$ind, $sub, self::CUSTOM];
        }
        return [$ind, $sub, $task];
    }

    /**
     * The tree for the set-up pickers (names only, in the current language).
     *
     * @return array list of {key, name, subs: list of {key, name, tasks: list of {key, name}}}
     */
    public static function picker(): array {
        $out = [];
        foreach (self::tree() as $ind => $entry) {
            $subs = [];
            foreach ($entry['subs'] as $sub => $subentry) {
                $tasks = [];
                foreach ($subentry['tasks'] as $task => $name) {
                    $tasks[] = ['key' => (string)$task, 'name' => $name];
                }
                $subs[] = ['key' => (string)$sub, 'name' => $subentry['name'], 'tasks' => $tasks];
            }
            $out[] = ['key' => (string)$ind, 'name' => $entry['name'], 'subs' => $subs];
        }
        return $out;
    }

    /**
     * Whether a key is a hierarchy of controls level.
     *
     * @param string $level
     * @return bool
     */
    public static function is_level(string $level): bool {
        return in_array($level, self::LEVELS, true);
    }
}
