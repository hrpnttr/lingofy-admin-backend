<?php

namespace App\Quiz;

use InvalidArgumentException;

/**
 * Reads and updates points on stored quiz results (`quizz_results.answers`).
 *
 * Points-based answers carry `points_awarded`, `max_points` and `review_status`
 * ("auto", "pending" or "graded"). Results saved before points existed only hold
 * `text`/`is_correct` per answer and a percentage score.
 */
class QuizResultGrader
{
    /**
     * @param  list<array<string, mixed>>  $answers
     * @return array{points_earned: int|null, points_max: int|null, pending_review: int, score: int, is_points_based: bool}
     */
    public static function summarize(array $answers, int $storedScore): array
    {
        if (! self::isPointsBased($answers)) {
            return ['points_earned' => null, 'points_max' => null, 'pending_review' => 0, 'score' => $storedScore, 'is_points_based' => false];
        }

        $earned = 0;
        $max = 0;
        $pending = 0;
        foreach ($answers as $answer) {
            $max += (int) ($answer['max_points'] ?? 0);
            if (($answer['review_status'] ?? null) === 'pending') {
                $pending++;

                continue;
            }
            $earned += (int) ($answer['points_awarded'] ?? 0);
        }

        return [
            'points_earned' => $earned,
            'points_max' => $max,
            'pending_review' => $pending,
            'score' => $max > 0 ? (int) round($earned / $max * 100) : 0,
            'is_points_based' => true,
        ];
    }

    /**
     * Apply teacher scores to manually reviewed answers.
     *
     * @param  list<array<string, mixed>>  $answers
     * @param  array<int, int>  $pointsByQuestionIndex
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException When a grade targets an answer that is not manually reviewed or is out of range.
     */
    public static function applyGrades(array $answers, array $pointsByQuestionIndex): array
    {
        if (! self::isPointsBased($answers)) {
            throw new InvalidArgumentException('This submission was recorded before points-based scoring and cannot be graded manually.');
        }

        $positions = [];
        foreach ($answers as $position => $answer) {
            $positions[(int) ($answer['question_index'] ?? $position)] = $position;
        }

        foreach ($pointsByQuestionIndex as $questionIndex => $points) {
            $position = $positions[$questionIndex] ?? null;
            if ($position === null) {
                throw new InvalidArgumentException('Question '.($questionIndex + 1).' is not part of this submission.');
            }
            $answer = $answers[$position];
            if (! in_array($answer['review_status'] ?? null, ['pending', 'graded'], true)) {
                throw new InvalidArgumentException('Question '.($questionIndex + 1).' is scored automatically.');
            }
            $maxPoints = (int) ($answer['max_points'] ?? 0);
            if ($points < 0 || $points > $maxPoints) {
                throw new InvalidArgumentException('Question '.($questionIndex + 1)." needs a score from 0 to {$maxPoints}.");
            }

            $answers[$position]['points_awarded'] = $points;
            $answers[$position]['review_status'] = 'graded';
            $answers[$position]['is_correct'] = $points === $maxPoints;
        }

        return $answers;
    }

    /**
     * @param  list<array<string, mixed>>  $answers
     */
    public static function isPointsBased(array $answers): bool
    {
        return $answers !== [] && collect($answers)->every(fn ($answer): bool => is_array($answer) && array_key_exists('max_points', $answer));
    }
}
