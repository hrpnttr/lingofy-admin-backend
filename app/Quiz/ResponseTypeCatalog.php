<?php

namespace App\Quiz;

/**
 * The fixed set of base response types a tenant's Form Builder types are built on.
 *
 * Each base type decides the student input control, the grading mode and which
 * validation settings an admin may configure. Question content (prompt, choices,
 * answer key, points) is never stored here; it lives on each quiz question.
 */
class ResponseTypeCatalog
{
    public const SINGLE_CHOICE = 'single_choice';

    public const MULTIPLE_SELECT = 'multiple_select';

    public const DROPDOWN = 'dropdown';

    public const TRUE_FALSE = 'true_false';

    public const SHORT_TEXT = 'short_text';

    public const NUMBER = 'number';

    public const LONG_ANSWER = 'long_answer';

    public const CHOICE_TYPES = [self::SINGLE_CHOICE, self::MULTIPLE_SELECT, self::DROPDOWN];

    public const MAX_CHOICES = 8;

    /**
     * @var array<string, array{name: string, input_control: string, input_label: string, grading_mode: string, settings: array<string, int|bool|null>}>
     */
    private const DEFINITIONS = [
        self::SINGLE_CHOICE => ['name' => 'Single choice', 'input_control' => 'radio', 'input_label' => 'Radio buttons', 'grading_mode' => 'automatic', 'settings' => []],
        self::MULTIPLE_SELECT => ['name' => 'Multiple select', 'input_control' => 'checkbox', 'input_label' => 'Checkboxes', 'grading_mode' => 'automatic', 'settings' => []],
        self::DROPDOWN => ['name' => 'Dropdown', 'input_control' => 'select', 'input_label' => 'Dropdown menu', 'grading_mode' => 'automatic', 'settings' => []],
        self::TRUE_FALSE => ['name' => 'True / False', 'input_control' => 'radio', 'input_label' => 'True / False buttons', 'grading_mode' => 'automatic', 'settings' => []],
        self::SHORT_TEXT => ['name' => 'Short text', 'input_control' => 'text', 'input_label' => 'Text field', 'grading_mode' => 'automatic', 'settings' => ['max_length' => 200]],
        self::NUMBER => ['name' => 'Number', 'input_control' => 'number', 'input_label' => 'Number field', 'grading_mode' => 'automatic', 'settings' => ['integer' => false, 'min' => null, 'max' => null]],
        self::LONG_ANSWER => ['name' => 'Long answer', 'input_control' => 'textarea', 'input_label' => 'Text area', 'grading_mode' => 'manual', 'settings' => ['max_length' => 5000]],
    ];

    /**
     * @return list<string>
     */
    public static function baseTypes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(string $baseType): bool
    {
        return array_key_exists($baseType, self::DEFINITIONS);
    }

    /**
     * @return array{name: string, input_control: string, input_label: string, grading_mode: string, settings: array<string, int|bool|null>}
     */
    public static function definition(string $baseType): array
    {
        return self::DEFINITIONS[$baseType];
    }

    public static function isManuallyGraded(string $baseType): bool
    {
        return self::DEFINITIONS[$baseType]['grading_mode'] === 'manual';
    }

    /**
     * Rows used to give a tenant the seven standard response types the first time it opens Form Builder.
     *
     * @return list<array{name: string, base_type: string, settings: string, is_active: bool, sort_order: int}>
     */
    public static function defaultRows(): array
    {
        $rows = [];
        foreach (array_keys(self::DEFINITIONS) as $index => $baseType) {
            $rows[] = [
                'name' => self::DEFINITIONS[$baseType]['name'],
                'base_type' => $baseType,
                'settings' => json_encode(self::DEFINITIONS[$baseType]['settings']),
                'is_active' => true,
                'sort_order' => $index,
            ];
        }

        return $rows;
    }

    /**
     * Keep only the settings a base type supports, with values clamped to safe ranges.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, int|float|bool|null>
     */
    public static function normalizeSettings(string $baseType, array $settings): array
    {
        $normalized = [];
        foreach (self::DEFINITIONS[$baseType]['settings'] as $key => $default) {
            $value = $settings[$key] ?? $default;
            $normalized[$key] = match ($key) {
                'max_length' => max(1, min($baseType === self::LONG_ANSWER ? 10000 : 500, (int) $value)),
                'integer' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'min', 'max' => $value === null || $value === '' ? null : (float) $value,
                default => $value,
            };
        }

        return $normalized;
    }

    /**
     * Present a stored response type row to the admin UI.
     *
     * @return array{id: int, name: string, base_type: string, input_control: string, input_label: string, grading_mode: string, settings: array<string, int|float|bool|null>, is_active: bool, sort_order: int}
     */
    public static function present(object $row): array
    {
        $definition = self::definition($row->base_type);

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'base_type' => $row->base_type,
            'input_control' => $definition['input_control'],
            'input_label' => $definition['input_label'],
            'grading_mode' => $definition['grading_mode'],
            'settings' => self::normalizeSettings($row->base_type, json_decode($row->settings ?: '[]', true) ?: []),
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
        ];
    }
}
