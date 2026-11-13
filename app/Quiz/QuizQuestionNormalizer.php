<?php

namespace App\Quiz;

use Illuminate\Support\Str;

/**
 * Validates quiz questions written in the quiz composer and turns them into the stored `contens` format.
 *
 * The response type (from Form Builder) decides the input control and validation; the prompt,
 * choices, answer key and points come from the question itself.
 */
class QuizQuestionNormalizer
{
    /**
     * @param  array<int, array{id: int, name: string, base_type: string, input_control: string, settings: array<string, mixed>}>  $responseTypesById
     */
    public function __construct(private array $responseTypesById) {}

    /**
     * @param  list<array<string, mixed>>  $submittedQuestions
     * @return array{questions: list<array<string, mixed>>, errors: array<string, string>}
     */
    public function normalize(array $submittedQuestions): array
    {
        $questions = [];
        $errors = [];

        foreach (array_values($submittedQuestions) as $index => $submitted) {
            $key = "contens.{$index}";
            $responseType = $this->responseTypesById[(int) ($submitted['response_type_id'] ?? 0)] ?? null;
            if (! $responseType) {
                $errors["{$key}.response_type_id"] = 'Choose a response type from Form Builder.';

                continue;
            }

            $prompt = trim((string) ($submitted['question'] ?? ''));
            if ($prompt === '') {
                $errors["{$key}.question"] = 'Write the question prompt.';
            } elseif (mb_strlen($prompt) > 2000) {
                $errors["{$key}.question"] = 'Keep the question under 2,000 characters.';
            }

            $points = filter_var($submitted['points'] ?? null, FILTER_VALIDATE_INT);
            if ($points === false || $points < 1 || $points > 100) {
                $errors["{$key}.points"] = 'Points must be a whole number from 1 to 100.';
            }

            $question = [
                'id' => is_string($submitted['id'] ?? null) && preg_match('/^q_[A-Za-z0-9]{6,32}$/', $submitted['id']) ? $submitted['id'] : 'q_'.Str::random(12),
                'type' => $responseType['base_type'],
                'response_type_id' => $responseType['id'],
                'response_type_name' => $responseType['name'],
                'input_control' => $responseType['input_control'],
                'question' => $prompt,
                'points' => $points === false ? 1 : $points,
                'required' => filter_var($submitted['required'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'validation' => $responseType['settings'],
            ];

            $typeError = $this->applyTypeFields($question, $submitted, $responseType);
            if ($typeError !== null) {
                $errors[$typeError[0] === '' ? $key : "{$key}.{$typeError[0]}"] = $typeError[1];
            }

            $questions[] = $question;
        }

        return ['questions' => $questions, 'errors' => $errors];
    }

    /**
     * Add the answer-key fields for the question's base type.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $submitted
     * @param  array{base_type: string, settings: array<string, mixed>}  $responseType
     * @return array{0: string, 1: string}|null The failing field and message, if any.
     */
    private function applyTypeFields(array &$question, array $submitted, array $responseType): ?array
    {
        $settings = $responseType['settings'];

        switch ($responseType['base_type']) {
            case ResponseTypeCatalog::SINGLE_CHOICE:
            case ResponseTypeCatalog::DROPDOWN:
            case ResponseTypeCatalog::MULTIPLE_SELECT:
                $choices = $this->cleanChoices($submitted['choices'] ?? []);
                $question['choices'] = $choices;
                if (count($choices) < 2 || count($choices) > ResponseTypeCatalog::MAX_CHOICES) {
                    return ['choices', 'Add between 2 and '.ResponseTypeCatalog::MAX_CHOICES.' answer options.'];
                }
                if (count(array_unique(array_map(fn (array $choice): string => mb_strtolower($choice['text']), $choices))) !== count($choices)) {
                    return ['choices', 'Answer options must be different from each other.'];
                }
                $correctCount = count(array_filter($choices, fn (array $choice): bool => $choice['is_correct']));
                if ($responseType['base_type'] === ResponseTypeCatalog::MULTIPLE_SELECT) {
                    return $correctCount < 1 ? ['choices', 'Mark at least one correct answer.'] : null;
                }

                return $correctCount !== 1 ? ['choices', 'Mark exactly one correct answer.'] : null;

            case ResponseTypeCatalog::TRUE_FALSE:
                $correctValue = $submitted['correct_value'] ?? null;
                if (! is_bool($correctValue) && ! in_array($correctValue, ['true', 'false'], true)) {
                    $question['choices'] = [['text' => 'True', 'is_correct' => false], ['text' => 'False', 'is_correct' => false]];

                    return ['correct_value', 'Choose whether the correct answer is True or False.'];
                }
                $isTrue = filter_var($correctValue, FILTER_VALIDATE_BOOLEAN);
                $question['choices'] = [['text' => 'True', 'is_correct' => $isTrue], ['text' => 'False', 'is_correct' => ! $isTrue]];

                return null;

            case ResponseTypeCatalog::SHORT_TEXT:
                $maxLength = (int) ($settings['max_length'] ?? 200);
                $accepted = [];
                foreach ((array) ($submitted['accepted_answers'] ?? []) as $answer) {
                    $answer = trim((string) $answer);
                    if ($answer !== '' && ! in_array(mb_strtolower($answer), array_map('mb_strtolower', $accepted), true)) {
                        $accepted[] = $answer;
                    }
                }
                $question['accepted_answers'] = array_slice($accepted, 0, 20);
                if ($accepted === []) {
                    return ['accepted_answers', 'Add at least one accepted answer.'];
                }
                foreach ($accepted as $answer) {
                    if (mb_strlen($answer) > $maxLength) {
                        return ['accepted_answers', "Accepted answers must fit this response type’s {$maxLength}-character limit."];
                    }
                }

                return null;

            case ResponseTypeCatalog::NUMBER:
                $expected = $submitted['expected_number'] ?? null;
                if (! is_numeric($expected)) {
                    $question['expected_number'] = null;

                    return ['expected_number', 'Enter the expected number.'];
                }
                $expected = $expected + 0;
                $question['expected_number'] = $expected;
                if (! empty($settings['integer']) && floor((float) $expected) != $expected) {
                    return ['expected_number', 'This response type only accepts whole numbers.'];
                }
                if (isset($settings['min']) && $expected < $settings['min']) {
                    return ['expected_number', "The expected number is below this response type’s minimum ({$settings['min']})."];
                }
                if (isset($settings['max']) && $expected > $settings['max']) {
                    return ['expected_number', "The expected number is above this response type’s maximum ({$settings['max']})."];
                }

                return null;

            case ResponseTypeCatalog::LONG_ANSWER:
                $guidance = trim((string) ($submitted['grading_guidance'] ?? ''));
                $question['grading_guidance'] = $guidance;

                return mb_strlen($guidance) > 2000 ? ['grading_guidance', 'Keep grading guidance under 2,000 characters.'] : null;
        }

        return ['', 'Unsupported response type.'];
    }

    /**
     * @return list<array{text: string, is_correct: bool}>
     */
    private function cleanChoices(mixed $choices): array
    {
        $clean = [];
        foreach (is_array($choices) ? $choices : [] as $choice) {
            $text = trim((string) (is_array($choice) ? ($choice['text'] ?? '') : ''));
            if ($text === '') {
                continue;
            }
            $clean[] = ['text' => mb_substr($text, 0, 500), 'is_correct' => filter_var($choice['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        return $clean;
    }
}
