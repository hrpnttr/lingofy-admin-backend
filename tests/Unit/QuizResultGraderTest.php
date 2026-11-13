<?php

namespace Tests\Unit;

use App\Quiz\QuizResultGrader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuizResultGraderTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function answers(): array
    {
        return [
            ['question_index' => 0, 'type' => 'single_choice', 'text' => 'Good morning.', 'is_correct' => true, 'points_awarded' => 2, 'max_points' => 2, 'review_status' => 'auto'],
            ['question_index' => 1, 'type' => 'short_text', 'text' => 'helo', 'is_correct' => false, 'points_awarded' => 0, 'max_points' => 1, 'review_status' => 'auto'],
            ['question_index' => 2, 'type' => 'long_answer', 'text' => 'My name is…', 'is_correct' => null, 'points_awarded' => null, 'max_points' => 3, 'review_status' => 'pending'],
        ];
    }

    public function test_pending_answers_count_toward_maximum_but_not_earned_points(): void
    {
        $summary = QuizResultGrader::summarize($this->answers(), 0);

        $this->assertSame(2, $summary['points_earned']);
        $this->assertSame(6, $summary['points_max']);
        $this->assertSame(1, $summary['pending_review']);
        $this->assertSame(33, $summary['score']);
    }

    public function test_teacher_grade_completes_review_and_updates_score(): void
    {
        $answers = QuizResultGrader::applyGrades($this->answers(), [2 => 3]);
        $summary = QuizResultGrader::summarize($answers, 0);

        $this->assertSame('graded', $answers[2]['review_status']);
        $this->assertTrue($answers[2]['is_correct']);
        $this->assertSame(5, $summary['points_earned']);
        $this->assertSame(0, $summary['pending_review']);
        $this->assertSame(83, $summary['score']);
    }

    public function test_automatically_scored_answers_cannot_be_overridden(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuizResultGrader::applyGrades($this->answers(), [1 => 1]);
    }

    public function test_grade_above_question_points_is_rejected(): void
    {
        $this->expectExceptionMessage('Question 3 needs a score from 0 to 3.');

        QuizResultGrader::applyGrades($this->answers(), [2 => 4]);
    }

    public function test_legacy_results_keep_their_stored_percentage(): void
    {
        $summary = QuizResultGrader::summarize([['text' => 'Good morning.', 'is_correct' => true]], 75);

        $this->assertFalse($summary['is_points_based']);
        $this->assertNull($summary['points_max']);
        $this->assertSame(75, $summary['score']);
    }
}
