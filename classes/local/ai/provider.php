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

namespace mod_aisafetyhazards\local\ai;

/**
 * An AI service that drafts scenes and creates scene pictures for teachers.
 *
 * Learners never trigger AI generation: only teachers with mod/aisafetyhazards:useai, from Moodle PHP.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider {
    /**
     * Whether this provider can generate scene pictures on this site right now.
     *
     * @return bool
     */
    public function can_generate(): bool;

    /**
     * Whether this provider can draft scenes on this site right now.
     *
     * @return bool
     */
    public function can_draft(): bool;

    /**
     * Remaining balance, for information only (it can change before any charge).
     *
     * @return array|null ['unlimited' => bool, 'credits' => int] or null when unknown
     */
    public function balance(): ?array;
}
