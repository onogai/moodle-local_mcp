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
 * Add a batch of ready question bank questions to a quiz.
 *
 * @package   mcptool_quiz
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mcptool_quiz\write;

use context;
use local_mcp\exception\api_exception;
use local_mcp\security\authenticated_identity;
use local_mcp\security\input_guard;
use local_mcp\write\tool\base_tool;
use mod_quiz\quiz_settings;
use mod_quiz\structure;
use Throwable;

/**
 * Validate the whole batch before inserting any slot; commit all or none.
 */
final class add_questions extends base_tool {
    public function get_name(): string {
        return 'quiz_add_questions';
    }

    public function get_title(): string {
        return 'Quiz: add multiple bank questions';
    }

    public function get_description(): string {
        return 'Atomically append ready bank questions to an editable quiz, grouping new questions into pages.';
    }

    public function get_required_capability(): string {
        return 'mod/quiz:manage';
    }

    public function get_input_schema(): array {
        return $this->object_schema([
            'cmid' => ['type' => 'integer', 'minimum' => 1],
            'questionids' => [
                'type' => 'array', 'minItems' => 1, 'maxItems' => 500,
                'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1],
            ],
            'questionsperpage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ], ['cmid', 'questionids']);
    }

    public function resolve_context(array $arguments): context {
        return input_guard::module((int)$arguments['cmid'], 'quiz');
    }

    public function supports_dry_run(): bool {
        return true;
    }

    /**
     * Recheck before both preview and mutation, never assuming the quiz is still editable.
     *
     * @return array Quiz, maximum existing page, validated question IDs, per-page count.
     */
    private function validate_batch(array $a): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $context = $this->resolve_context($a);
        $cm = get_coursemodule_from_id('quiz', (int)$context->instanceid, 0, false, MUST_EXIST);
        $quiz = input_guard::record('quiz', (int)$cm->instance, 'quiz_not_found');
        $quiz->cmid = $cm->id;

        $structure = structure::create_for_quiz(quiz_settings::create($quiz->id));
        if (quiz_has_attempts($quiz->id) || !$structure->can_be_edited()) {
            throw new api_exception('quiz_has_attempts', 409);
        }

        $ids = array_map('intval', $a['questionids']);
        if (!$ids || count($ids) > 500 || count($ids) !== count(array_unique($ids))) {
            throw new api_exception('duplicate_or_invalid_question_ids', 400);
        }

        $existing = [];
        $lastpage = 0;
        foreach ($structure->get_slots() as $slot) {
            $lastpage = max($lastpage, (int)$slot->page);
            if (isset($slot->questionid)) {
                $existing[(int)$slot->questionid] = true;
            }
        }

        foreach ($ids as $id) {
            if ($id < 1 || !$DB->record_exists('question', ['id' => $id])) {
                throw new api_exception('question_not_found', 404, '', ['questionid' => $id]);
            }
            if (!$DB->record_exists('question_versions', ['questionid' => $id, 'status' => 'ready'])) {
                throw new api_exception('question_not_ready', 400, '', ['questionid' => $id]);
            }
            if (isset($existing[$id])) {
                throw new api_exception('question_already_in_quiz', 409, '', ['questionid' => $id]);
            }
            quiz_require_question_use($id);
        }

        $perpage = (int)($a['questionsperpage'] ?? 1);
        if ($perpage < 1 || $perpage > 500) {
            throw new api_exception('invalid_questions_per_page', 400);
        }

        return [$quiz, $lastpage, $ids, $perpage];
    }

    public function preview(array $arguments, authenticated_identity $identity): array {
        [$quiz, $lastpage, $ids, $perpage] = $this->validate_batch($arguments);
        return [
            'operation' => $this->get_name(), 'cmid' => (int)$quiz->cmid,
            'quizid' => (int)$quiz->id, 'questionids' => $ids,
            'question_count' => count($ids), 'questionsperpage' => $perpage,
            'first_new_page' => $lastpage + 1,
        ];
    }

    public function execute(array $arguments, authenticated_identity $identity): array {
        global $DB;
        [$quiz, $lastpage, $ids, $perpage] = $this->validate_batch($arguments);

        $transaction = $DB->start_delegated_transaction();
        try {
            // Never partially append a batch: Moodle DB writes and grade recalculation share the transaction.
            // Starting a new page preserves all existing question pages and their layout.
            $pages = [];
            foreach ($ids as $index => $questionid) {
                $page = $lastpage + 1 + intdiv($index, $perpage);
                if (quiz_add_quiz_question($questionid, $quiz, $page) === false) {
                    throw new api_exception('question_already_in_quiz', 409, '', [
                        'questionid' => $questionid,
                    ]);
                }
                $pages[] = $page;
            }

            quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            $transaction->allow_commit();
        } catch (Throwable $e) {
            $transaction->rollback($e);
        }

        $slots = \mcptool_quiz\quiz_service::slots(['cmid' => (int)$quiz->cmid], $identity);
        return [
            'cmid' => (int)$quiz->cmid, 'quizid' => (int)$quiz->id,
            'added' => $ids, 'added_count' => count($ids),
            'pages' => $pages, 'rejected' => [],
            'slot_count' => count($slots['slots']),
            'layout_sha256' => $slots['layout_sha256'],
        ];
    }
}
