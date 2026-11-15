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
 * Regression tests for atomic batch quiz question attachment.
 *
 * @package   mcptool_quiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mcptool_quiz;

use advanced_testcase;
use context_module;
use local_mcp\exception\api_exception;
use local_mcp\question\gift_import_service;
use local_mcp\security\authenticated_identity;
use mcptool_quiz\write\add_questions;

/**
 * @covers \mcptool_quiz\write\add_questions
 */
final class bulk_add_questions_test extends advanced_testcase {
    /**
     * Create a quiz and three ready questions in its module question bank.
     *
     * @return array Quiz CMID, imported question IDs, authorized user.
     */
    private function setup_batch(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
        ]);
        $ctx = context_module::instance((int)$module->cmid);
        $identity = new authenticated_identity((int)get_admin()->id, 'test',
            ['mcp:read', 'mcp:write']);
        $gift = "::Bulk-01::How much is 2 + 2? {=4 ~3 ~5}\n\n"
            . "::Bulk-02::How much is 3 + 3? {=6 ~5 ~7}\n\n"
            . "::Bulk-03::How much is 4 + 4? {=8 ~7 ~9}\n";
        $result = gift_import_service::execute([
            'contextid' => $ctx->id,
            'name' => 'Bulk add test bank',
            'gift' => $gift,
        ], $identity);
        $this->assertCount(3, $result['question_ids']);
        return [(int)$module->cmid, $result['question_ids'], $identity];
    }

    /**
     * One batch adds questions, groups pages and reports persisted slots.
     */
    public function test_bulk_add_questions_and_pages(): void {
        [$cmid, $ids, $identity] = $this->setup_batch();
        $tool = new add_questions();
        $this->assertSame('mod/quiz:manage', $tool->get_required_capability());
        $this->assertFalse($tool->requires_confirmation());
        $this->assertTrue($tool->supports_dry_run());
        $args = [
            'cmid' => $cmid,
            'questionids' => $ids,
            'questionsperpage' => 2,
        ];
        $before = $tool->preview($args, $identity);
        $this->assertSame(3, $before['question_count']);
        $this->assertCount(0, quiz_service::slots(['cmid' => $cmid], $identity)['slots']);

        $result = $tool->execute($args, $identity);
        $this->assertSame($ids, $result['added']);
        $this->assertSame(3, $result['added_count']);
        $this->assertSame([], $result['rejected']);
        $this->assertSame(3, $result['slot_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['layout_sha256']);

        $slots = quiz_service::slots(['cmid' => $cmid], $identity)['slots'];
        $this->assertSame($ids, array_map(static fn($slot) => $slot['questionid'], $slots));
        $this->assertSame([1, 1, 2], array_column($slots, 'page'));
    }

    /**
     * Invalid IDs are rejected before any slot can be added.
     */
    public function test_invalid_question_id_leaves_quiz_empty(): void {
        [$cmid, $ids, $identity] = $this->setup_batch();
        $tool = new add_questions();
        try {
            $tool->execute(['cmid' => $cmid,
                'questionids' => [$ids[0], 2147483647]], $identity);
            $this->fail('Missing question must reject entire batch.');
        } catch (api_exception $e) {
            $this->assertSame('question_not_found', $e->machinecode);
        }
        $this->assertCount(0, quiz_service::slots(['cmid' => $cmid], $identity)['slots']);
    }

    /**
     * Duplicate question IDs and already used IDs are rejected.
     */
    public function test_duplicate_and_existing_questions_rejected(): void {
        [$cmid, $ids, $identity] = $this->setup_batch();
        $tool = new add_questions();
        $schema = $tool->get_input_schema();
        $this->assertTrue($schema['properties']['questionids']['uniqueItems']);
        try {
            $tool->execute(['cmid' => $cmid,
                'questionids' => [$ids[0], $ids[0]]], $identity);
            $this->fail('Duplicate IDs must be rejected.');
        } catch (api_exception $e) {
            $this->assertSame('duplicate_or_invalid_question_ids', $e->machinecode);
        }
        $this->assertCount(0, quiz_service::slots(['cmid' => $cmid], $identity)['slots']);

        $tool->execute(['cmid' => $cmid, 'questionids' => [$ids[0]]], $identity);
        try {
            $tool->execute(['cmid' => $cmid, 'questionids' => [$ids[1], $ids[0]]], $identity);
            $this->fail('Already attached question must reject entire batch.');
        } catch (api_exception $e) {
            $this->assertSame('question_already_in_quiz', $e->machinecode);
        }
        $slots = quiz_service::slots(['cmid' => $cmid], $identity)['slots'];
        $this->assertCount(1, $slots);
        $this->assertSame($ids[0], $slots[0]['questionid']);
    }
}
