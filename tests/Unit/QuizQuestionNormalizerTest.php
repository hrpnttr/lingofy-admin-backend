<?php

namespace Tests\Unit;

use App\Quiz\QuizQuestionNormalizer;
use App\Quiz\ResponseTypeCatalog;
use PHPUnit\Framework\TestCase;

class QuizQuestionNormalizerTest extends TestCase
{
    private function normalizer(): QuizQuestionNormalizer
    {
        $types = [];
        foreach (ResponseTypeCatalog::baseTypes() as $index => $baseType) {
            $definition = ResponseTypeCatalog::definition($baseType);
            $types[$index + 1] = [
                'id' => $index + 1,
                'name' => $definition['name'],
                'base_type' => $baseType,
                'input_control' => $definition['input_control'],
                'settings' => ResponseTypeCatalog::normalizeSettings($baseType, $baseType === ResponseTypeCatalog::NUMBER ? ['integer' => true, 'min' => 0, 'max' => 100] : []),
            ];
        }

        return new QuizQuestionNormalizer($types);
    }

    private function typeId(string $baseType): int
    {
        return array_search($baseType, ResponseTypeCatalog::baseTypes(), true) + 1;
    }

    public function test_single_choice_question_is_stored_with_type_points_and_answer_key(): void
    {
        $result = $this->normalizer()->normalize([[
            'response_type_id' => $this->typeId(ResponseTypeCatalog::SINGLE_CHOICE),
            'question' => '  Which sentence is a greeting? ',
            'points' => 2,
            'choices' => [
                ['text' => 'Good morning.', 'is_correct' => true],
                ['text' => 'At the airport.', 'is_correct' => false],
                ['text' => '', 'is_correct' => false],
            ],
        ]]);

        $this->assertSame([], $result['errors']);
        $question = $result['questions'][0];
        $this->assertSame('single_choice', $question['type']);
        $this->assertSame('radio', $question['input_control']);
        $this->assertSame('Which sentence is a greeting?', $question['question']);
        $this->assertSame(2, $question['points']);
        $this->assertCount(2, $question['choices']);
        $this->assertMatchesRegularExpression('/^q_[A-Za-z0-9]{12}$/', $question['id']);
    }

    public function test_existing_question_id_is_kept(): void
    {
        $result = $this->normalizer()->normalize([[
            'id' => 'q_keepThis123',
            'response_type_id' => $this->typeId(ResponseTypeCatalog::LONG_ANSWER),
            'question' => 'Introduce yourself.',
            'points' => 3,
        ]]);

        $this->assertSame('q_keepThis123', $result['questions'][0]['id']);
    }

    public function test_errors_are_reported_beside_each_affected_question(): void
    {
        $result = $this->normalizer()->normalize([
            ['response_type_id' => $this->typeId(ResponseTypeCatalog::SINGLE_CHOICE), 'question' => 'Pick one', 'points' => 1, 'choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => true]]],
            ['response_type_id' => $this->typeId(ResponseTypeCatalog::MULTIPLE_SELECT), 'question' => '', 'points' => 0, 'choices' => [['text' => 'A', 'is_correct' => false], ['text' => 'B', 'is_correct' => false]]],
            ['response_type_id' => 999, 'question' => 'Unknown type', 'points' => 1],
        ]);

        $this->assertSame('Mark exactly one correct answer.', $result['errors']['contens.0.choices']);
        $this->assertSame('Write the question prompt.', $result['errors']['contens.1.question']);
        $this->assertSame('Points must be a whole number from 1 to 100.', $result['errors']['contens.1.points']);
        $this->assertSame('Mark at least one correct answer.', $result['errors']['contens.1.choices']);
        $this->assertArrayHasKey('contens.2.response_type_id', $result['errors']);
    }

    public function test_true_false_answer_key_becomes_fixed_true_and_false_choices(): void
    {
        $result = $this->normalizer()->normalize([[
            'response_type_id' => $this->typeId(ResponseTypeCatalog::TRUE_FALSE),
            'question' => 'London is in England.',
            'points' => 1,
            'correct_value' => true,
        ]]);

        $this->assertSame([['text' => 'True', 'is_correct' => true], ['text' => 'False', 'is_correct' => false]], $result['questions'][0]['choices']);
    }

    public function test_short_text_accepted_answers_are_trimmed_and_deduplicated(): void
    {
        $result = $this->normalizer()->normalize([[
            'response_type_id' => $this->typeId(ResponseTypeCatalog::SHORT_TEXT),
            'question' => 'Say hello',
            'points' => 1,
            'accepted_answers' => [' Hello ', 'hello', 'Hi', ''],
        ]]);

        $this->assertSame(['Hello', 'Hi'], $result['questions'][0]['accepted_answers']);
    }

    public function test_number_answer_must_respect_response_type_validation(): void
    {
        $normalizer = $this->normalizer();
        $numberType = $this->typeId(ResponseTypeCatalog::NUMBER);

        $decimal = $normalizer->normalize([['response_type_id' => $numberType, 'question' => 'How many?', 'points' => 1, 'expected_number' => '2.5']]);
        $tooLarge = $normalizer->normalize([['response_type_id' => $numberType, 'question' => 'How many?', 'points' => 1, 'expected_number' => 150]]);
        $valid = $normalizer->normalize([['response_type_id' => $numberType, 'question' => 'How many?', 'points' => 1, 'expected_number' => '42']]);

        $this->assertSame('This response type only accepts whole numbers.', $decimal['errors']['contens.0.expected_number']);
        $this->assertArrayHasKey('contens.0.expected_number', $tooLarge['errors']);
        $this->assertSame([], $valid['errors']);
        $this->assertSame(42, $valid['questions'][0]['expected_number']);
    }
}
