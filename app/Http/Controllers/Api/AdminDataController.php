<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Quiz\QuizQuestionNormalizer;
use App\Quiz\QuizResultGrader;
use App\Quiz\ResponseTypeCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDataController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        return response()->json([
            'students' => DB::table('students')->where('tenant_id', $tenantId)->count(),
            'courses' => DB::table('courses')->where('tenant_id', $tenantId)->count(),
            'published_courses' => DB::table('courses')->where('tenant_id', $tenantId)->where('published', true)->count(),
            'classes' => DB::table('classes')->whereIn('course_id', DB::table('courses')->select('id')->where('tenant_id', $tenantId))->count(),
            'invitations_pending' => 0,
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $query = DB::table('students')->select(['id', 'full_name', 'email', 'phone', 'createdAt'])
            ->where('tenant_id', $tenantId);

        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            $query->where(function ($subquery) use ($term) {
                $subquery->where('full_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $total = (clone $query)->count();

        return response()->json(['data' => $query->orderByDesc('createdAt')->limit(200)->get(), 'total' => $total]);
    }

    public function progressReviews(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $students = DB::table('students')->where('tenant_id', $tenantId)
            ->select(['id', 'full_name', 'email', 'createdAt'])->orderBy('full_name')->get();
        $courses = DB::table('courses')->where('tenant_id', $tenantId)->pluck('title', 'id');
        $classes = DB::table('classes')->whereIn('course_id', $courses->keys())
            ->select(['id', 'course_id', 'class_name'])->get();
        $quizzes = DB::table('quizzes')->where('tenant_id', $tenantId)->where('type', 'quiz')
            ->whereIn('class_id', $classes->pluck('id'))
            ->select(['id', 'class_id', 'title'])->get();
        $enrollments = DB::table('scourses')->whereIn('studentId', $students->pluck('id'))
            ->select(['studentId', 'contents'])->get()->keyBy('studentId');
        $quizIds = $quizzes->pluck('id');
        $attempts = DB::table('quizz_results')
            ->join('students', 'quizz_results.studentId', '=', 'students.id')
            ->join('quizzes', 'quizz_results.quiz_id', '=', 'quizzes.id')
            ->where('students.tenant_id', $tenantId)->where('quizzes.tenant_id', $tenantId)
            ->whereIn('quizz_results.quiz_id', $quizIds)
            ->select([
                'quizz_results.id', 'quizz_results.studentId', 'quizz_results.quiz_id',
                'quizz_results.answers', 'quizz_results.score', 'quizz_results.createdAt',
                'students.full_name', 'students.email', 'quizzes.title as quiz_title',
                'quizzes.class_id', 'quizzes.contens as quiz_questions',
            ])->orderByDesc('quizz_results.createdAt')->get();

        $classesById = $classes->keyBy('id');
        $attemptsByStudent = $attempts->groupBy('studentId');
        $studentRows = $students->map(function ($student) use ($enrollments, $courses, $classes, $quizzes, $attemptsByStudent) {
            $contents = json_decode($enrollments->get($student->id)->contents ?? '[]', true) ?: [];
            $enrolledCourseIds = collect($contents)->pluck('courseId')->map(fn ($id) => (int) $id)->filter(fn ($id) => $courses->has($id));
            $assignedClasses = $classes->whereIn('course_id', $enrolledCourseIds);
            $assignedQuizzes = $quizzes->whereIn('class_id', $assignedClasses->pluck('id'));
            $studentAttempts = $attemptsByStudent->get($student->id, collect());
            $completedQuizIds = $studentAttempts->pluck('quiz_id')->unique();
            $classNames = $assignedClasses->pluck('class_name')->unique()->values();
            $lastActive = $studentAttempts->first()->createdAt ?? null;

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'email' => $student->email,
                'class_name' => $classNames->join(', ') ?: 'No class enrolled',
                'assigned_tasks' => $assignedQuizzes->count(),
                'completed_tasks' => $completedQuizIds->intersect($assignedQuizzes->pluck('id'))->count(),
                'submission_count' => $studentAttempts->count(),
                'last_active' => $lastActive,
            ];
        });

        $assignedTasks = $studentRows->sum('assigned_tasks');
        $completedTasks = $studentRows->sum('completed_tasks');

        $enrolledCourseIdsByStudent = $students->mapWithKeys(function ($student) use ($enrollments) {
            $contents = json_decode($enrollments->get($student->id)->contents ?? '[]', true) ?: [];

            return [$student->id => collect($contents)->pluck('courseId')->map(fn ($id) => (int) $id)->unique()->values()];
        });
        $studentProgress = function (Collection $quizIds, int $courseId) use ($students, $enrolledCourseIdsByStudent, $attemptsByStudent): array {
            $members = $students->filter(fn ($student) => $enrolledCourseIdsByStudent->get($student->id)->contains($courseId))
                ->map(function ($student) use ($attemptsByStudent, $quizIds) {
                    $scopedAttempts = $attemptsByStudent->get($student->id, collect())->whereIn('quiz_id', $quizIds);

                    return [
                        'id' => $student->id,
                        'full_name' => $student->full_name,
                        'email' => $student->email,
                        'assigned_tasks' => $quizIds->count(),
                        'completed_tasks' => $scopedAttempts->pluck('quiz_id')->unique()->count(),
                        'submission_count' => $scopedAttempts->count(),
                        'last_active' => $scopedAttempts->first()->createdAt ?? null,
                    ];
                })->values();
            $assigned = $members->sum('assigned_tasks');

            return [
                'quiz_count' => $quizIds->count(),
                'student_count' => $members->count(),
                'submission_count' => $members->sum('submission_count'),
                'on_track' => $members->filter(fn ($student) => $student['assigned_tasks'] > 0 && $student['completed_tasks'] >= $student['assigned_tasks'] * 0.75)->count(),
                'average_completion' => $assigned > 0 ? (int) round(($members->sum('completed_tasks') / $assigned) * 100) : 0,
                'students' => $members,
            ];
        };

        $quizzesByClass = $quizzes->groupBy('class_id');
        $classGroups = $classes->sortBy('class_name', SORT_NATURAL | SORT_FLAG_CASE)->map(function ($class) use ($courses, $quizzesByClass, $studentProgress) {
            $progress = $studentProgress($quizzesByClass->get($class->id, collect())->pluck('id'), (int) $class->course_id);

            return [
                'id' => $class->id,
                'course_id' => $class->course_id,
                'name' => $class->class_name,
                'course_title' => $courses[$class->course_id] ?? '',
                ...$progress,
            ];
        })->values();

        $courseGroups = $courses->map(function ($title, $courseId) use ($classes, $quizzes, $studentProgress) {
            $courseClassIds = $classes->where('course_id', $courseId)->pluck('id');
            $progress = $studentProgress($quizzes->whereIn('class_id', $courseClassIds)->pluck('id'), (int) $courseId);
            unset($progress['students']);

            return [
                'id' => $courseId,
                'title' => $title,
                'class_count' => $courseClassIds->count(),
                ...$progress,
            ];
        })->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json([
            'summary' => [
                'students' => $studentRows->count(),
                'on_track' => $studentRows->filter(fn ($student) => $student['assigned_tasks'] > 0 && $student['completed_tasks'] >= $student['assigned_tasks'] * 0.75)->count(),
                'submissions' => $attempts->count(),
                'average_completion' => $assignedTasks > 0 ? (int) round(($completedTasks / $assignedTasks) * 100) : 0,
            ],
            'students' => $studentRows->values(),
            'submissions' => $attempts->map(function ($attempt) use ($classesById, $courses) {
                $class = $classesById->get($attempt->class_id);
                $answers = json_decode($attempt->answers ?: '[]', true) ?: [];
                $summary = QuizResultGrader::summarize($answers, (int) $attempt->score);

                return [
                    'id' => $attempt->id,
                    'student_name' => $attempt->full_name,
                    'student_email' => $attempt->email,
                    'quiz_title' => $attempt->quiz_title,
                    'class_id' => $attempt->class_id,
                    'course_id' => $class->course_id ?? null,
                    'class_name' => $class->class_name ?? '',
                    'course_title' => $class ? ($courses[$class->course_id] ?? '') : '',
                    'score' => $summary['score'],
                    'points_earned' => $summary['points_earned'],
                    'points_max' => $summary['points_max'],
                    'pending_review' => $summary['pending_review'],
                    'answers' => $answers,
                    'questions' => json_decode($attempt->quiz_questions ?: '[]', true) ?: [],
                    'submitted_at' => $attempt->createdAt,
                ];
            })->values(),
            'courses' => $courseGroups,
            'classes' => $classGroups,
        ]);
    }

    public function createStudent(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('students', 'email')->where('tenant_id', $tenantId)],
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        try {
            $id = DB::table('students')->insertGetId([
                'tenant_id' => $tenantId,
                'full_name' => $data['full_name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'createdAt' => now(),
            ]);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                return response()->json(['message' => 'This email is already used in the organization.'], 409);
            }
            throw $exception;
        }

        return response()->json(DB::table('students')->select(['id', 'full_name', 'email', 'phone', 'createdAt'])->where('id', $id)->first(), 201);
    }

    public function courses(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $query = DB::table('courses')->where('tenant_id', $tenantId);
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.trim($request->string('q')->toString()).'%');
        }

        return response()->json(['data' => $query->orderByDesc('createdAt')->limit(200)->get()]);
    }

    public function classes(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $query = DB::table('classes')
            ->join('courses', 'classes.course_id', '=', 'courses.id')
            ->where('courses.tenant_id', $tenantId)
            ->select([
                'classes.id',
                'classes.course_id',
                'classes.class_name',
                'classes.description',
                'classes.materials',
                'classes.created_at',
                'courses.title as course_title',
            ]);

        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            $query->where(function ($subquery) use ($term) {
                $subquery->where('classes.class_name', 'like', "%{$term}%")
                    ->orWhere('courses.title', 'like', "%{$term}%");
            });
        }

        return response()->json(['data' => $query->orderByDesc('classes.id')->limit(200)->get()]);
    }

    public function quizzes(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $data = DB::table('quizzes')
            ->join('classes', 'quizzes.class_id', '=', 'classes.id')
            ->join('courses', 'classes.course_id', '=', 'courses.id')
            ->where('quizzes.tenant_id', $tenantId)
            ->where('courses.tenant_id', $tenantId)
            ->where('quizzes.type', 'quiz')
            ->select([
                'quizzes.id', 'quizzes.class_id', 'quizzes.title', 'quizzes.description',
                'quizzes.is_active', 'quizzes.updatedAt', 'classes.class_name', 'courses.title as course_title',
            ])
            ->orderByDesc('quizzes.updatedAt')
            ->limit(200)
            ->get();

        return response()->json(['data' => $data]);
    }

    public function updateQuiz(Request $request, int $quizId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $quizQuery = DB::table('quizzes')->where('tenant_id', $tenantId)->where('type', 'quiz')->where('id', $quizId);
        if (! $quizQuery->clone()->exists()) {
            return response()->json(['message' => 'Quiz not found in this organization.'], 404);
        }

        $quizQuery->clone()->update(['is_active' => $data['is_active'], 'updatedAt' => now()]);

        return response()->json($quizQuery->select(['id', 'class_id', 'title', 'is_active', 'updatedAt'])->first());
    }

    public function createClass(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'class_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'materials' => ['nullable', 'array'],
        ]);
        $course = DB::table('courses')->where('id', $data['course_id'])->where('tenant_id', $tenantId)->first();
        if (! $course) {
            return response()->json(['message' => 'Course not found in this organization.'], 404);
        }

        $now = now();
        $classId = DB::table('classes')->insertGetId([
            'course_id' => $course->id,
            'class_name' => $data['class_name'],
            'description' => $data['description'] ?? null,
            'materials' => json_encode($data['materials'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(DB::table('classes')
            ->join('courses', 'classes.course_id', '=', 'courses.id')
            ->where('classes.id', $classId)
            ->select([
                'classes.id',
                'classes.course_id',
                'classes.class_name',
                'classes.description',
                'classes.materials',
                'classes.created_at',
                'courses.title as course_title',
            ])->first(), 201);
    }

    public function classContent(Request $request, int $classId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $class = DB::table('classes')
            ->join('courses', 'classes.course_id', '=', 'courses.id')
            ->where('classes.id', $classId)
            ->where('courses.tenant_id', $tenantId)
            ->select([
                'classes.id',
                'classes.course_id',
                'classes.class_name',
                'classes.description',
                'classes.materials',
                'courses.title as course_title',
            ])->first();

        if (! $class) {
            return response()->json(['message' => 'Class not found in this organization.'], 404);
        }

        $class->materials = array_values(array_filter(
            json_decode($class->materials ?: '[]', true) ?: [],
            fn ($material): bool => ! is_array($material)
                || ($material['type'] ?? null) !== 'video'
                || ! empty($material['url'])
                || ! empty($material['link']),
        ));
        $quiz = DB::table('quizzes')
            ->where('class_id', $classId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        if ($quiz) {
            $quiz->contens = json_decode($quiz->contens ?: '[]', true) ?: [];
        }

        return response()->json(['class' => $class, 'quiz' => $quiz]);
    }

    public function updateClass(Request $request, int $classId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $class = DB::table('classes')
            ->where('id', $classId)
            ->whereIn('course_id', DB::table('courses')->select('id')->where('tenant_id', $tenantId))
            ->first();

        if (! $class) {
            return response()->json(['message' => 'Class not found in this organization.'], 404);
        }

        $data = $request->validate([
            'class_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'materials' => ['sometimes', 'array', 'max:50'],
            'materials.*' => ['array'],
            'materials.*.title' => ['required_with:materials', 'string', 'max:255'],
            'materials.*.type' => ['required_with:materials', 'in:video,pdf,link'],
            'materials.*.fileName' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'materials.*.url' => ['nullable', 'url', 'max:2000'],
            'materials.*.link' => ['nullable', 'url', 'max:2000'],
        ]);

        if (array_key_exists('materials', $data)) {
            foreach ($data['materials'] as &$material) {
                if (empty($material['url']) && ! empty($material['link'])) {
                    $material['url'] = $material['link'];
                }
                unset($material['link']);
                if (empty($material['fileName']) && empty($material['url'])) {
                    return response()->json(['message' => 'Each material needs a file name or URL.'], 422);
                }
                if (($material['type'] ?? null) === 'video' && empty($material['url'])) {
                    return response()->json(['message' => 'Video materials must use a video link.'], 422);
                }
                if (! empty($material['url']) && ! in_array(parse_url($material['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                    return response()->json(['message' => 'Material URLs must use HTTP or HTTPS.'], 422);
                }
            }
            unset($material);

            $existingMaterials = json_decode($class->materials ?: '[]', true) ?: [];
            $quizMaterials = array_values(array_filter($existingMaterials, fn ($material): bool => is_array($material) && ($material['type'] ?? null) === 'quiz'));
            $data['materials'] = json_encode(array_merge($data['materials'], $quizMaterials));
        }

        $data['updated_at'] = now();
        DB::table('classes')->where('id', $classId)->update($data);

        return $this->classContent($request, $classId);
    }

    public function saveClassQuiz(Request $request, int $classId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $class = DB::table('classes')
            ->where('id', $classId)
            ->whereIn('course_id', DB::table('courses')->select('id')->where('tenant_id', $tenantId))
            ->first();

        if (! $class) {
            return response()->json(['message' => 'Class not found in this organization.'], 404);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'contens' => ['required', 'array', 'min:1', 'max:40'],
            'contens.*' => ['array'],
        ]);

        $responseTypesById = $this->tenantResponseTypes($tenantId)->keyBy('id')->all();
        $normalized = (new QuizQuestionNormalizer($responseTypesById))->normalize($data['contens']);
        if ($normalized['errors'] !== []) {
            return response()->json([
                'message' => 'Some questions need attention before the quiz can be saved.',
                'errors' => $normalized['errors'],
            ], 422);
        }

        $quizValues = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => 'quiz',
            'contens' => json_encode($normalized['questions']),
            'updatedAt' => now(),
        ];
        $existingQuiz = DB::table('quizzes')
            ->where('class_id', $classId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        if ($existingQuiz) {
            DB::table('quizzes')->where('id', $existingQuiz->id)->update($quizValues);
            $quizId = $existingQuiz->id;
        } else {
            $quizValues['class_id'] = $classId;
            $quizValues['tenant_id'] = $tenantId;
            $quizValues['createdAt'] = now();
            $quizId = DB::table('quizzes')->insertGetId($quizValues);
        }

        $materials = json_decode($class->materials ?: '[]', true) ?: [];
        $materials = array_values(array_filter($materials, fn ($material): bool => ! is_array($material) || ($material['type'] ?? null) !== 'quiz'));
        $materials[] = ['title' => $data['title'], 'type' => 'quiz'];
        DB::table('classes')->where('id', $classId)->update(['materials' => json_encode($materials), 'updated_at' => now()]);

        return response()->json(['id' => $quizId, 'title' => $data['title'], 'class_id' => $classId]);
    }

    public function createCourse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'level' => ['required', 'in:beginner,intermediate,advanced,A1,A2,B1,B2,C1,C2'],
            'language' => ['required', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'durationHours' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'tags' => ['nullable', 'array'],
        ]);
        $now = now();
        $id = DB::table('courses')->insertGetId([
            'tenant_id' => $request->attributes->get('tenant')->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'level' => $data['level'],
            'language' => $data['language'],
            'price' => $data['price'] ?? 0,
            'durationHours' => $data['durationHours'] ?? 0,
            'tags' => json_encode($data['tags'] ?? []),
            'published' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        return response()->json(DB::table('courses')->where('id', $id)->first(), 201);
    }

    public function updateCourse(Request $request, int $courseId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $course = DB::table('courses')->where('tenant_id', $tenantId)->where('id', $courseId)->first();
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'level' => ['sometimes', 'in:beginner,intermediate,advanced,A1,A2,B1,B2,C1,C2'],
            'language' => ['sometimes', 'string', 'max:80'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'durationHours' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'tags' => ['sometimes', 'array'],
            'published' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['tags'])) {
            $data['tags'] = json_encode($data['tags']);
        }
        $data['updatedAt'] = now();
        DB::table('courses')->where('tenant_id', $tenantId)->where('id', $courseId)->update($data);

        return response()->json(DB::table('courses')->where('tenant_id', $tenantId)->where('id', $courseId)->first());
    }

    public function responseTypes(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $usage = [];
        DB::table('quizzes')->where('tenant_id', $tenantId)->pluck('contens')->each(function (?string $contents) use (&$usage): void {
            foreach (json_decode($contents ?: '[]', true) ?: [] as $question) {
                $typeId = (int) ($question['response_type_id'] ?? 0);
                $usage[$typeId] = ($usage[$typeId] ?? 0) + 1;
            }
        });

        return response()->json([
            'data' => $this->tenantResponseTypes($tenantId)
                ->map(fn (array $type): array => [...$type, 'question_count' => $usage[$type['id']] ?? 0])
                ->values(),
            'base_types' => collect(ResponseTypeCatalog::baseTypes())
                ->map(fn (string $baseType): array => ['base_type' => $baseType, ...ResponseTypeCatalog::definition($baseType)])
                ->values(),
        ]);
    }

    public function createResponseType(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $this->tenantResponseTypes($tenantId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tenant_quiz_response_types', 'name')->where('tenant_id', $tenantId)],
            'base_type' => ['required', Rule::in(ResponseTypeCatalog::baseTypes())],
            'settings' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (DB::table('tenant_quiz_response_types')->where('tenant_id', $tenantId)->count() >= 40) {
            return response()->json(['message' => 'An organization can have up to 40 response types.'], 422);
        }

        $id = DB::table('tenant_quiz_response_types')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => trim($data['name']),
            'base_type' => $data['base_type'],
            'settings' => json_encode(ResponseTypeCatalog::normalizeSettings($data['base_type'], $data['settings'] ?? [])),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => (int) DB::table('tenant_quiz_response_types')->where('tenant_id', $tenantId)->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(ResponseTypeCatalog::present(DB::table('tenant_quiz_response_types')->where('id', $id)->first()), 201);
    }

    /**
     * Update a response type's name, validation settings or availability.
     *
     * The base type is fixed after creation so existing questions keep their meaning,
     * and question content stored on quizzes is never rewritten here.
     */
    public function updateResponseType(Request $request, int $responseTypeId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $responseType = DB::table('tenant_quiz_response_types')->where('tenant_id', $tenantId)->where('id', $responseTypeId)->first();
        if (! $responseType) {
            return response()->json(['message' => 'Response type not found in this organization.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('tenant_quiz_response_types', 'name')->where('tenant_id', $tenantId)->ignore($responseTypeId)],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $values = ['updated_at' => now()];
        if (array_key_exists('name', $data)) {
            $values['name'] = trim($data['name']);
        }
        if (array_key_exists('settings', $data)) {
            $values['settings'] = json_encode(ResponseTypeCatalog::normalizeSettings($responseType->base_type, $data['settings'] ?? []));
        }
        if (array_key_exists('is_active', $data)) {
            $values['is_active'] = $data['is_active'];
        }
        DB::table('tenant_quiz_response_types')->where('id', $responseTypeId)->update($values);

        return response()->json(ResponseTypeCatalog::present(DB::table('tenant_quiz_response_types')->where('id', $responseTypeId)->first()));
    }

    public function gradeQuizResult(Request $request, int $resultId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $data = $request->validate([
            'grades' => ['required', 'array', 'min:1', 'max:40'],
            'grades.*.question_index' => ['required', 'integer', 'min:0'],
            'grades.*.points' => ['required', 'integer', 'min:0'],
        ]);

        $result = DB::table('quizz_results')
            ->join('students', 'quizz_results.studentId', '=', 'students.id')
            ->where('students.tenant_id', $tenantId)
            ->where('quizz_results.id', $resultId)
            ->select(['quizz_results.id', 'quizz_results.answers', 'quizz_results.score'])
            ->first();
        if (! $result) {
            return response()->json(['message' => 'Submission not found in this organization.'], 404);
        }

        $pointsByQuestionIndex = collect($data['grades'])->mapWithKeys(fn (array $grade): array => [(int) $grade['question_index'] => (int) $grade['points']])->all();
        try {
            $answers = QuizResultGrader::applyGrades(json_decode($result->answers ?: '[]', true) ?: [], $pointsByQuestionIndex);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $summary = QuizResultGrader::summarize($answers, (int) $result->score);
        DB::table('quizz_results')->where('id', $resultId)->update([
            'answers' => json_encode($answers),
            'score' => $summary['score'],
            'updatedAt' => now(),
        ]);

        return response()->json(['id' => $resultId, 'answers' => $answers, ...$summary]);
    }

    /**
     * The tenant's Form Builder response types, giving a new tenant the seven standard types first.
     *
     * @return Collection<int, array{id: int, name: string, base_type: string, input_control: string, input_label: string, grading_mode: string, settings: array<string, mixed>, is_active: bool, sort_order: int}>
     */
    private function tenantResponseTypes(int $tenantId): Collection
    {
        $query = fn () => DB::table('tenant_quiz_response_types')->where('tenant_id', $tenantId)->orderBy('sort_order')->orderBy('id')->get();
        $rows = $query();

        if ($rows->isEmpty()) {
            $now = now();
            DB::table('tenant_quiz_response_types')->insertOrIgnore(array_map(
                fn (array $row): array => [...$row, 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now],
                ResponseTypeCatalog::defaultRows(),
            ));
            $rows = $query();
        }

        return $rows->filter(fn (object $row): bool => ResponseTypeCatalog::exists($row->base_type))
            ->map(fn (object $row): array => ResponseTypeCatalog::present($row))
            ->values();
    }
}
