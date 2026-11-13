<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'quizzes.updatedAt', 'classes.class_name', 'courses.title as course_title',
            ])
            ->orderByDesc('quizzes.updatedAt')
            ->limit(200)
            ->get();

        return response()->json(['data' => $data]);
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
            'contens.*.question' => ['required', 'string', 'max:2000'],
            'contens.*.choices' => ['required', 'array', 'min:2', 'max:6'],
            'contens.*.choices.*.text' => ['required', 'string', 'max:500'],
            'contens.*.choices.*.is_correct' => ['required', 'boolean'],
        ]);

        foreach ($data['contens'] as $question) {
            $correctAnswers = count(array_filter($question['choices'], fn (array $choice): bool => filter_var($choice['is_correct'], FILTER_VALIDATE_BOOLEAN)));
            if ($correctAnswers !== 1) {
                return response()->json(['message' => 'Each quiz question needs exactly one correct answer.'], 422);
            }
        }

        $quizValues = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => 'quiz',
            'contens' => json_encode($data['contens']),
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
}
