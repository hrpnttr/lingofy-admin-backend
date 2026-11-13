<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminDataController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('tenant.admin')->prefix('admin')->group(function () {
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/dashboard', [AdminDataController::class, 'dashboard']);
    Route::get('/students', [AdminDataController::class, 'students']);
    Route::get('/progress-reviews', [AdminDataController::class, 'progressReviews']);
    Route::post('/students', [AdminDataController::class, 'createStudent']);
    Route::get('/courses', [AdminDataController::class, 'courses']);
    Route::post('/courses', [AdminDataController::class, 'createCourse']);
    Route::patch('/courses/{courseId}', [AdminDataController::class, 'updateCourse']);
    Route::get('/classes', [AdminDataController::class, 'classes']);
    Route::get('/quizzes', [AdminDataController::class, 'quizzes']);
    Route::patch('/quizzes/{quizId}', [AdminDataController::class, 'updateQuiz']);
    Route::post('/classes', [AdminDataController::class, 'createClass']);
    Route::get('/classes/{classId}/content', [AdminDataController::class, 'classContent']);
    Route::patch('/classes/{classId}', [AdminDataController::class, 'updateClass']);
    Route::put('/classes/{classId}/quiz', [AdminDataController::class, 'saveClassQuiz']);
    Route::get('/response-types', [AdminDataController::class, 'responseTypes']);
    Route::post('/response-types', [AdminDataController::class, 'createResponseType']);
    Route::patch('/response-types/{responseTypeId}', [AdminDataController::class, 'updateResponseType']);
    Route::patch('/quiz-results/{resultId}/grades', [AdminDataController::class, 'gradeQuizResult']);
});
