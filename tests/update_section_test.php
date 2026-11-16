<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Moodle 5.x section update regression coverage.
 *
 * @package   local_mcp
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mcp;

use advanced_testcase;
use local_mcp\security\authenticated_identity;
use local_mcp\write\tool\update_section;

/**
 * @covers \local_mcp\write\tool\update_section
 */
final class update_section_test extends advanced_testcase {
    /**
     * Moodle 5.x course_update_section() expects a section_info object.
     * The MCP schema supplies a section number, and must resolve it first.
     */
    public function test_update_section_name_and_visibility(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $identity = new authenticated_identity($USER->id, 'test', ['mcp:write']);
        $tool = new update_section();

        $result = $tool->execute([
            'courseid' => (int)$course->id, 'sectionnum' => 1,
            'name' => 'Renamed section',
        ], $identity);
        $this->assertTrue($result['updated']);
        $section = $DB->get_record('course_sections',
            ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $this->assertSame('Renamed section', $section->name);

        $result = $tool->execute([
            'courseid' => (int)$course->id, 'sectionnum' => 1,
            'visible' => false,
        ], $identity);
        $this->assertTrue($result['updated']);
        $section = $DB->get_record('course_sections',
            ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $this->assertSame(0, (int)$section->visible);
        $this->assertSame('Renamed section', $section->name);
    }
}
