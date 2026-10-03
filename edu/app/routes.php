<?php
declare(strict_types=1);

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\LearnController;
use App\Controllers\EventsController;
use App\Controllers\GrowthLearnController;
use App\Controllers\ExamController;
use App\Controllers\ProfileController;
use App\Controllers\FileController;
use App\Controllers\NotificationController;
use App\Controllers\Admin;
use App\Controllers\Api\V1Controller;
use App\Controllers\Api\AradContactController;

/*
 * Route map. Every route is authenticated by default. Access rules:
 *   'perm' => permission key (or list — any of them), 'root' => only مدیر کل, 'auth' => false for public, 'guest' => only logged-out.
 */
return function (Router $r): void {
    // ------------------------------------------------------------ public & auth
    $r->get('/__rewrite_test', fn() => 'rewrite-ok', ['auth' => false]);
    $r->get('/login', [AuthController::class, 'loginForm'], ['guest' => true]);
    $r->post('/login', [AuthController::class, 'login'], ['guest' => true]);
    $r->get('/register', [AuthController::class, 'registerForm'], ['guest' => true]);
    $r->post('/register', [AuthController::class, 'register'], ['guest' => true]);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/password/change', [AuthController::class, 'passwordChangeForm']);
    $r->post('/password/change', [AuthController::class, 'passwordChange']);
    $r->get('/sso/redirect', [AuthController::class, 'ssoRedirect'], ['auth' => false]);
    $r->get('/sso/callback', [AuthController::class, 'ssoCallback'], ['auth' => false]);
    $r->get('/sso/link', [AuthController::class, 'ssoLinkForm'], ['auth' => false]);
    $r->post('/sso/link', [AuthController::class, 'ssoLink'], ['auth' => false]);
    $r->get('/verify/{code}', [LearnController::class, 'verifyCertificate'], ['auth' => false]);
    $r->post('/impersonate/stop', [AuthController::class, 'stopImpersonation']);

    // ------------------------------------------------------------ files
    $r->get('/avatar/{id}', [FileController::class, 'avatar']);
    $r->get('/file/{uuid}', [FileController::class, 'serve']);

    // ------------------------------------------------------------ learner panel
    $r->get('/', [DashboardController::class, 'home']);
    $r->get('/learn', [LearnController::class, 'myCourses']);
    $r->get('/learn/catalog', [LearnController::class, 'catalog']);
    $r->get('/learn/course/{id}', [LearnController::class, 'course']);
    $r->post('/learn/course/{id}/enroll', [LearnController::class, 'enroll']);
    $r->get('/learn/lesson/{id}', [LearnController::class, 'lesson']);
    $r->post('/learn/lesson/{id}/progress', [LearnController::class, 'progress']);
    $r->post('/learn/lesson/{id}/complete', [LearnController::class, 'complete']);
    $r->post('/learn/lesson/{id}/heartbeat', [LearnController::class, 'heartbeat']);
    $r->get('/learn/paths', [LearnController::class, 'paths']);
    $r->get('/learn/path/{id}', [LearnController::class, 'path']);
    $r->post('/learn/path/{id}/enroll', [LearnController::class, 'enrollPath']);
    $r->get('/learn/growth', [GrowthLearnController::class, 'index']);
    $r->post('/learn/growth/track', [GrowthLearnController::class, 'track']);
    $r->post('/learn/growth/deals', [GrowthLearnController::class, 'dealStore']);
    $r->post('/learn/growth/deals/{id}', [GrowthLearnController::class, 'dealUpdate']);
    $r->post('/learn/growth/deals/{id}/delete', [GrowthLearnController::class, 'dealDelete']);
    $r->post('/learn/growth/request/{id}', [GrowthLearnController::class, 'request']);
    $r->post('/learn/growth/sync', [GrowthLearnController::class, 'sync']);
    $r->post('/learn/lesson/{id}/unlock', [LearnController::class, 'unlockLesson']);
    $r->post('/learn/course/{id}/unlock', [LearnController::class, 'unlockCourse']);
    $r->get('/learn/events/{type}', [EventsController::class, 'index']);
    $r->get('/learn/event/{id}', [EventsController::class, 'show']);
    $r->post('/learn/event/{id}/register', [EventsController::class, 'register']);
    $r->get('/learn/event/{id}/join', [EventsController::class, 'join']);
    $r->get('/me/services', [EventsController::class, 'services']);
    $r->get('/me/credits', [LearnController::class, 'credits']);
    $r->get('/learn/exercises', [LearnController::class, 'exercises']);
    $r->get('/learn/exercise/{id}', [LearnController::class, 'exercise']);
    $r->post('/learn/exercise/{id}', [LearnController::class, 'submitExercise']);
    $r->get('/learn/certificates', [LearnController::class, 'certificates']);
    $r->get('/learn/certificate/{code}', [LearnController::class, 'certificate']);
    $r->get('/learn/exams', [ExamController::class, 'myExams']);
    $r->get('/learn/exam/{id}', [ExamController::class, 'intro']);
    $r->post('/learn/exam/{id}/start', [ExamController::class, 'start']);
    $r->get('/learn/attempt/{id}', [ExamController::class, 'take']);
    $r->post('/learn/attempt/{id}', [ExamController::class, 'submit']);
    $r->get('/learn/attempt/{id}/result', [ExamController::class, 'result']);

    $r->get('/me/report', [ProfileController::class, 'report']);
    $r->post('/me/needs', [ProfileController::class, 'addNeed']);
    $r->get('/profile', [ProfileController::class, 'show']);
    $r->post('/profile', [ProfileController::class, 'update']);
    $r->post('/profile/password', [ProfileController::class, 'password']);
    $r->post('/profile/avatar', [ProfileController::class, 'avatar']);
    $r->post('/profile/avatar/source', [ProfileController::class, 'avatarSource']);
    $r->get('/profile/link-my', [ProfileController::class, 'linkMy']);

    $r->get('/notifications', [NotificationController::class, 'index']);
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll']);

    // ------------------------------------------------------------ admin: dashboard & team
    $r->get('/admin', [Admin\DashboardController::class, 'index'], ['perm' => 'dashboard.view']);
    $r->get('/admin/team', [Admin\ReportController::class, 'team'], ['perm' => 'team.view']);

    // ------------------------------------------------------------ admin: users
    $r->get('/admin/users', [Admin\UserController::class, 'index'], ['perm' => 'users.view']);
    $r->get('/admin/users/export', [Admin\UserController::class, 'export'], ['perm' => 'users.export']);
    $r->get('/admin/users/create', [Admin\UserController::class, 'create'], ['perm' => 'users.create']);
    $r->post('/admin/users', [Admin\UserController::class, 'store'], ['perm' => 'users.create']);
    $r->post('/admin/users/import', [Admin\UserController::class, 'import'], ['perm' => 'users.create']);
    $r->post('/admin/users/merge', [Admin\UserController::class, 'merge'], ['perm' => 'users.edit']);
    $r->post('/admin/users/bulk', [Admin\UserController::class, 'bulk'], ['perm' => ['users.assign', 'groups.assign', 'users.edit', 'users.approve', 'credits.edit', 'assignments.assign']]);
    $r->get('/admin/users/{id}', [Admin\UserController::class, 'show'], ['perm' => 'users.view']);
    $r->get('/admin/users/{id}/edit', [Admin\UserController::class, 'edit'], ['perm' => 'users.edit']);
    $r->post('/admin/users/{id}', [Admin\UserController::class, 'update'], ['perm' => 'users.edit']);
    $r->post('/admin/users/{id}/status', [Admin\UserController::class, 'status'], ['perm' => 'users.edit']);
    $r->post('/admin/users/{id}/approve', [Admin\UserController::class, 'approve'], ['perm' => 'users.approve']);
    $r->post('/admin/users/{id}/credits', [Admin\UserController::class, 'credits'], ['perm' => 'credits.edit']);
    $r->post('/admin/users/{id}/delete', [Admin\UserController::class, 'destroy'], ['perm' => 'users.delete']);
    $r->post('/admin/users/{id}/login-check', [Admin\UserController::class, 'loginCheck'], ['perm' => 'users.edit']);
    $r->post('/admin/users/{id}/unlock', [Admin\UserController::class, 'unlock'], ['perm' => 'users.edit']);
    $r->post('/admin/users/{id}/unlink-my', [Admin\UserController::class, 'unlinkMy'], ['perm' => 'users.edit']);
    $r->get('/admin/users/{id}/access', [Admin\RoleController::class, 'userAccess'], ['perm' => 'roles.view']);
    $r->post('/admin/users/{id}/access', [Admin\RoleController::class, 'saveUserAccess'], ['perm' => 'roles.assign']);
    $r->post('/admin/users/{id}/impersonate', [Admin\UserController::class, 'impersonate'], ['root' => true]);

    // ------------------------------------------------------------ admin: roles & permissions
    $r->get('/admin/roles', [Admin\RoleController::class, 'index'], ['perm' => 'roles.view']);
    $r->get('/admin/role-grants', [Admin\RoleGrantController::class, 'index'], ['perm' => ['role_grants.view', 'grant_employee.run', 'grant_agent.run']]);
    $r->post('/admin/role-grants', [Admin\RoleGrantController::class, 'save'], ['perm' => 'role_grants.edit']);
    $r->post('/admin/role-grants/{key}/run', [Admin\RoleGrantController::class, 'run'], ['perm' => ['grant_employee.run', 'grant_agent.run']]);
    $r->get('/admin/roles/matrix', [Admin\RoleController::class, 'matrix'], ['perm' => 'roles.view']);
    $r->get('/admin/roles/create', [Admin\RoleController::class, 'create'], ['perm' => 'roles.create']);
    $r->post('/admin/roles', [Admin\RoleController::class, 'store'], ['perm' => 'roles.create']);
    $r->get('/admin/roles/{id}', [Admin\RoleController::class, 'edit'], ['perm' => 'roles.view']);
    $r->post('/admin/roles/{id}', [Admin\RoleController::class, 'update'], ['perm' => 'roles.edit']);
    $r->post('/admin/roles/{id}/clone', [Admin\RoleController::class, 'duplicate'], ['perm' => 'roles.create']);
    $r->post('/admin/roles/{id}/delete', [Admin\RoleController::class, 'destroy'], ['perm' => 'roles.delete']);

    $r->get('/admin/api-tokens', [Admin\SystemController::class, 'tokens'], ['perm' => 'api_tokens.view']);
    $r->post('/admin/api-tokens', [Admin\SystemController::class, 'createToken'], ['perm' => 'api_tokens.create']);
    $r->post('/admin/api-tokens/{id}/revoke', [Admin\SystemController::class, 'revokeToken'], ['perm' => 'api_tokens.delete']);

    // ------------------------------------------------------------ admin: structure
    $r->get('/admin/groups', [Admin\StructureController::class, 'groups'], ['perm' => 'groups.view']);
    $r->post('/admin/groups', [Admin\StructureController::class, 'saveGroup'], ['perm' => 'groups.create']);
    $r->get('/admin/groups/{id}', [Admin\StructureController::class, 'group'], ['perm' => 'groups.view']);
    $r->post('/admin/groups/{id}', [Admin\StructureController::class, 'saveGroup'], ['perm' => 'groups.edit']);
    $r->post('/admin/groups/{id}/delete', [Admin\StructureController::class, 'deleteGroup'], ['perm' => 'groups.delete']);
    $r->post('/admin/groups/{id}/members', [Admin\StructureController::class, 'groupMembers'], ['perm' => 'groups.assign']);

    $r->get('/admin/org', [Admin\StructureController::class, 'org'], ['perm' => 'org.view']);
    $r->post('/admin/org/units', [Admin\StructureController::class, 'saveUnit'], ['perm' => 'org.create']);
    $r->post('/admin/org/units/{id}', [Admin\StructureController::class, 'saveUnit'], ['perm' => 'org.edit']);
    $r->post('/admin/org/units/{id}/delete', [Admin\StructureController::class, 'deleteUnit'], ['perm' => 'org.delete']);
    $r->get('/admin/org/units/{id}', [Admin\StructureController::class, 'unit'], ['perm' => 'org.view']);
    $r->post('/admin/org/units/{id}/members', [Admin\StructureController::class, 'unitMembers'], ['perm' => 'org.assign']);
    $r->post('/admin/org/types', [Admin\StructureController::class, 'saveType'], ['perm' => 'org.create']);
    $r->post('/admin/org/types/{id}', [Admin\StructureController::class, 'saveType'], ['perm' => 'org.edit']);
    $r->post('/admin/org/types/{id}/delete', [Admin\StructureController::class, 'deleteType'], ['perm' => 'org.delete']);

    $r->get('/admin/levels', [Admin\StructureController::class, 'levels'], ['perm' => 'levels.view']);
    $r->post('/admin/levels', [Admin\StructureController::class, 'saveLevel'], ['perm' => 'levels.create']);
    $r->post('/admin/levels/{id}', [Admin\StructureController::class, 'saveLevel'], ['perm' => 'levels.edit']);
    $r->post('/admin/levels/{id}/delete', [Admin\StructureController::class, 'deleteLevel'], ['perm' => 'levels.delete']);
    $r->post('/admin/taxonomies', [Admin\StructureController::class, 'saveTaxonomy'], ['perm' => 'levels.create']);
    $r->post('/admin/taxonomies/{id}', [Admin\StructureController::class, 'saveTaxonomy'], ['perm' => 'levels.edit']);
    $r->post('/admin/taxonomies/{id}/delete', [Admin\StructureController::class, 'deleteTaxonomy'], ['perm' => 'levels.delete']);
    $r->post('/admin/terms', [Admin\StructureController::class, 'saveTerm'], ['perm' => 'levels.create']);
    $r->post('/admin/terms/{id}', [Admin\StructureController::class, 'saveTerm'], ['perm' => 'levels.edit']);
    $r->post('/admin/terms/{id}/delete', [Admin\StructureController::class, 'deleteTerm'], ['perm' => 'levels.delete']);

    // ------------------------------------------------------------ admin: content
    $r->get('/admin/categories', [Admin\StructureController::class, 'categories'], ['perm' => 'categories.view']);
    $r->post('/admin/categories', [Admin\StructureController::class, 'saveCategory'], ['perm' => 'categories.create']);
    $r->post('/admin/categories/{id}', [Admin\StructureController::class, 'saveCategory'], ['perm' => 'categories.edit']);
    $r->post('/admin/categories/{id}/delete', [Admin\StructureController::class, 'deleteCategory'], ['perm' => 'categories.delete']);

    $r->get('/admin/courses', [Admin\CourseController::class, 'index'], ['perm' => ['courses.view', 'lessons.view']]);
    $r->get('/admin/courses/create', [Admin\CourseController::class, 'create'], ['perm' => 'courses.create']);
    $r->post('/admin/courses', [Admin\CourseController::class, 'store'], ['perm' => 'courses.create']);
    $r->get('/admin/courses/{id}', [Admin\CourseController::class, 'show'], ['perm' => ['courses.view', 'lessons.view']]);
    $r->get('/admin/courses/{id}/edit', [Admin\CourseController::class, 'edit'], ['perm' => 'courses.edit']);
    $r->post('/admin/courses/{id}', [Admin\CourseController::class, 'update'], ['perm' => 'courses.edit']);
    $r->post('/admin/courses/{id}/publish', [Admin\CourseController::class, 'publish'], ['perm' => 'courses.publish']);
    $r->post('/admin/courses/{id}/delete', [Admin\CourseController::class, 'destroy'], ['perm' => 'courses.delete']);
    $r->get('/admin/courses/{id}/export', [Admin\CourseController::class, 'export'], ['perm' => 'courses.export']);
    $r->get('/admin/courses/{id}/lessons/create', [Admin\CourseController::class, 'lessonForm'], ['perm' => 'lessons.create']);
    $r->post('/admin/courses/{id}/lessons', [Admin\CourseController::class, 'lessonStore'], ['perm' => 'lessons.create']);
    $r->get('/admin/lessons/{id}/edit', [Admin\CourseController::class, 'lessonEdit'], ['perm' => 'lessons.edit']);
    $r->post('/admin/lessons/{id}', [Admin\CourseController::class, 'lessonUpdate'], ['perm' => 'lessons.edit']);
    $r->post('/admin/lessons/{id}/move', [Admin\CourseController::class, 'lessonMove'], ['perm' => 'lessons.edit']);
    $r->post('/admin/courses/{id}/lessons/order', [Admin\CourseController::class, 'lessonOrder'], ['perm' => 'lessons.edit']);
    $r->post('/admin/lessons/{id}/transfer', [Admin\CourseController::class, 'lessonTransfer'], ['perm' => 'lessons.edit']);
    $r->post('/admin/lessons/{id}/delete', [Admin\CourseController::class, 'lessonDelete'], ['perm' => 'lessons.delete']);
    $r->post('/admin/courses/{id}/exercises', [Admin\CourseController::class, 'exerciseSave'], ['perm' => 'lessons.create']);
    $r->post('/admin/exercises/{id}', [Admin\CourseController::class, 'exerciseUpdate'], ['perm' => 'lessons.edit']);
    $r->post('/admin/exercises/{id}/delete', [Admin\CourseController::class, 'exerciseDelete'], ['perm' => 'lessons.delete']);

    $r->get('/admin/library', [Admin\LibraryController::class, 'index'], ['perm' => 'library.view']);
    $r->post('/admin/library', [Admin\LibraryController::class, 'upload'], ['perm' => 'library.create']);
    $r->post('/admin/library/{id}', [Admin\LibraryController::class, 'update'], ['perm' => 'library.edit']);
    $r->post('/admin/library/{id}/delete', [Admin\LibraryController::class, 'destroy'], ['perm' => 'library.delete']);

    $r->get('/admin/paths', [Admin\PathController::class, 'index'], ['perm' => 'paths.view']);
    $r->post('/admin/paths', [Admin\PathController::class, 'save'], ['perm' => 'paths.create']);
    $r->post('/admin/paths/order', [Admin\PathController::class, 'order'], ['perm' => 'paths.edit']);
    $r->post('/admin/paths/{id}/steps/order', [Admin\PathController::class, 'stepOrder'], ['perm' => 'paths.edit']);
    $r->get('/admin/paths/{id}', [Admin\PathController::class, 'show'], ['perm' => 'paths.view']);
    $r->post('/admin/paths/{id}', [Admin\PathController::class, 'save'], ['perm' => 'paths.edit']);
    $r->post('/admin/paths/{id}/delete', [Admin\PathController::class, 'destroy'], ['perm' => 'paths.delete']);
    $r->post('/admin/paths/{id}/steps', [Admin\PathController::class, 'addStep'], ['perm' => 'paths.edit']);
    $r->post('/admin/path-steps/{id}', [Admin\PathController::class, 'updateStep'], ['perm' => 'paths.edit']);
    $r->post('/admin/path-steps/{id}/move', [Admin\PathController::class, 'moveStep'], ['perm' => 'paths.edit']);
    $r->post('/admin/path-steps/{id}/delete', [Admin\PathController::class, 'deleteStep'], ['perm' => 'paths.edit']);

    // ------------------------------------------------------------ admin: assessment
    $r->get('/admin/events/{type}', [Admin\EventController::class, 'index'], ['perm' => 'events.view']);
    $r->get('/admin/events/{type}/create', [Admin\EventController::class, 'create'], ['perm' => 'events.create']);
    $r->post('/admin/events/{type}', [Admin\EventController::class, 'store'], ['perm' => 'events.create']);
    $r->get('/admin/event/{id}/edit', [Admin\EventController::class, 'edit'], ['perm' => 'events.edit']);
    $r->post('/admin/event/{id}', [Admin\EventController::class, 'update'], ['perm' => 'events.edit']);
    $r->post('/admin/event/{id}/link', [Admin\EventController::class, 'link'], ['perm' => 'events.edit']);
    $r->post('/admin/event/{id}/delete', [Admin\EventController::class, 'destroy'], ['perm' => 'events.delete']);
    $r->get('/admin/event/{id}/registrations', [Admin\EventController::class, 'registrations'], ['perm' => 'events.report']);
    $r->post('/admin/event/{id}/registrations/{rid}/cancel', [Admin\EventController::class, 'cancelRegistration'], ['perm' => 'events.edit']);
    $r->get('/admin/exams', [Admin\ExamController::class, 'index'], ['perm' => 'exams.view']);
    $r->get('/admin/exams/create', [Admin\ExamController::class, 'create'], ['perm' => 'exams.create']);
    $r->get('/admin/exams/lessons', [Admin\ExamController::class, 'lessons'], ['perm' => ['exams.create', 'exams.edit']]);
    $r->post('/admin/exams', [Admin\ExamController::class, 'store'], ['perm' => 'exams.create']);
    $r->get('/admin/exams/{id}', [Admin\ExamController::class, 'edit'], ['perm' => 'exams.view']);
    $r->post('/admin/exams/{id}', [Admin\ExamController::class, 'update'], ['perm' => 'exams.edit']);
    $r->post('/admin/exams/{id}/delete', [Admin\ExamController::class, 'destroy'], ['perm' => 'exams.delete']);
    $r->post('/admin/exams/{id}/questions', [Admin\ExamController::class, 'questions'], ['perm' => 'exams.edit']);
    $r->get('/admin/exams/{id}/report', [Admin\ExamController::class, 'report'], ['perm' => 'exams.report']);

    $r->get('/admin/questions', [Admin\QuestionController::class, 'index'], ['perm' => 'questions.view']);
    $r->get('/admin/questions/create', [Admin\QuestionController::class, 'create'], ['perm' => 'questions.create']);
    $r->post('/admin/questions', [Admin\QuestionController::class, 'store'], ['perm' => 'questions.create']);
    $r->get('/admin/questions/{id}/edit', [Admin\QuestionController::class, 'edit'], ['perm' => 'questions.edit']);
    $r->post('/admin/questions/{id}', [Admin\QuestionController::class, 'update'], ['perm' => 'questions.edit']);
    $r->post('/admin/questions/{id}/delete', [Admin\QuestionController::class, 'destroy'], ['perm' => 'questions.delete']);
    $r->post('/admin/question-categories', [Admin\QuestionController::class, 'saveCategory'], ['perm' => 'questions.create']);
    $r->post('/admin/question-categories/{id}/delete', [Admin\QuestionController::class, 'deleteCategory'], ['perm' => 'questions.delete']);

    $r->get('/admin/reviews', [Admin\ReviewController::class, 'index'], ['perm' => 'reviews.view']);
    $r->get('/admin/reviews/submission/{id}', [Admin\ReviewController::class, 'submission'], ['perm' => 'reviews.view']);
    $r->post('/admin/reviews/submission/{id}', [Admin\ReviewController::class, 'reviewSubmission'], ['perm' => 'reviews.approve']);
    $r->get('/admin/reviews/attempt/{id}', [Admin\ReviewController::class, 'attempt'], ['perm' => 'reviews.view']);
    $r->post('/admin/reviews/attempt/{id}', [Admin\ReviewController::class, 'gradeAttempt'], ['perm' => 'reviews.approve']);
    $r->get('/admin/evaluations', [Admin\ReviewController::class, 'evaluations'], ['perm' => 'evaluations.view']);
    $r->post('/admin/evaluations', [Admin\ReviewController::class, 'saveEvaluation'], ['perm' => 'evaluations.create']);
    $r->post('/admin/evaluations/{id}/delete', [Admin\ReviewController::class, 'deleteEvaluation'], ['perm' => 'evaluations.delete']);
    $r->get('/admin/certificates', [Admin\ReviewController::class, 'certificates'], ['perm' => 'certificates.view']);
    $r->post('/admin/certificates', [Admin\ReviewController::class, 'issueCertificate'], ['perm' => 'certificates.create']);
    $r->post('/admin/certificates/{id}/revoke', [Admin\ReviewController::class, 'revokeCertificate'], ['perm' => 'certificates.delete']);

    // ------------------------------------------------------------ admin: assignment & growth
    $r->get('/admin/assignments', [Admin\AssignmentController::class, 'index'], ['perm' => 'assignments.view']);
    $r->post('/admin/assignments', [Admin\AssignmentController::class, 'store'], ['perm' => 'assignments.assign']);
    $r->post('/admin/assignments/{id}/reapply', [Admin\AssignmentController::class, 'reapply'], ['perm' => 'assignments.assign']);
    $r->post('/admin/assignments/{id}/delete', [Admin\AssignmentController::class, 'destroy'], ['perm' => 'assignments.delete']);
    $r->get('/admin/rules', [Admin\AssignmentController::class, 'rules'], ['perm' => 'rules.view']);
    $r->get('/admin/rules/create', [Admin\AssignmentController::class, 'ruleForm'], ['perm' => 'rules.create']);
    $r->post('/admin/rules', [Admin\AssignmentController::class, 'ruleSave'], ['perm' => 'rules.create']);
    $r->get('/admin/rules/{id}', [Admin\AssignmentController::class, 'ruleForm'], ['perm' => 'rules.view']);
    $r->post('/admin/rules/{id}', [Admin\AssignmentController::class, 'ruleSave'], ['perm' => 'rules.edit']);
    $r->post('/admin/rules/{id}/run', [Admin\AssignmentController::class, 'ruleRun'], ['perm' => 'rules.run']);
    $r->post('/admin/rules/{id}/delete', [Admin\AssignmentController::class, 'ruleDelete'], ['perm' => 'rules.delete']);
    // previous group-based growth (archive, kept for history)
    $r->get('/admin/growth/legacy', [Admin\AssignmentController::class, 'growth'], ['perm' => 'growth.view']);
    $r->post('/admin/growth/legacy/stages', [Admin\AssignmentController::class, 'saveStage'], ['perm' => 'growth.create']);
    $r->post('/admin/growth/legacy/stages/{id}', [Admin\AssignmentController::class, 'saveStage'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/legacy/stages/{id}/delete', [Admin\AssignmentController::class, 'deleteStage'], ['perm' => 'growth.delete']);
    $r->post('/admin/growth/legacy/promote', [Admin\AssignmentController::class, 'promote'], ['perm' => 'growth.approve']);
    // نظام رشد تاجر
    $G = Admin\GrowthController::class;
    $r->get('/admin/growth', [$G, 'index'], ['perm' => 'growth.view']);
    $r->get('/admin/growth/stages/new', [$G, 'stageNew'], ['perm' => 'growth.create']);
    $r->post('/admin/growth/stages', [$G, 'saveStage'], ['perm' => 'growth.create']);
    $r->get('/admin/growth/stages/{id}', [$G, 'stage'], ['perm' => 'growth.view']);
    $r->post('/admin/growth/stages/{id}', [$G, 'saveStage'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/stages/{id}/move', [$G, 'moveStage'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/stages/{id}/delete', [$G, 'deleteStage'], ['perm' => 'growth.delete']);
    $r->post('/admin/growth/stages/{id}/items', [$G, 'addItems'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/items/{id}', [$G, 'updateItem'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/items/{id}/delete', [$G, 'deleteItem'], ['perm' => 'growth.edit']);
    $r->get('/admin/growth/traders', [$G, 'traders'], ['perm' => 'growth.view']);
    $r->get('/admin/growth/user/{id}', [$G, 'user'], ['perm' => 'growth.view']);
    $r->post('/admin/growth/user/{id}/track', [$G, 'userTrack'], ['perm' => ['growth.approve', 'growth.edit']]);
    $r->post('/admin/growth/user/{id}/place', [$G, 'userPlace'], ['perm' => 'growth.approve']);
    $r->post('/admin/growth/user/{id}/stage/{sid}', [$G, 'userApprove'], ['perm' => 'growth.approve']);
    $r->post('/admin/growth/user/{id}/sync', [$G, 'userSync'], ['perm' => ['growth.run', 'growth.approve']]);
    $r->post('/admin/growth/user/{id}/phones', [$G, 'phoneAdd'], ['perm' => ['growth.run', 'users.edit']]);
    $r->post('/admin/growth/user/{id}/phones/{pid}/delete', [$G, 'phoneDelete'], ['perm' => ['growth.run', 'users.edit']]);
    $r->post('/admin/growth/user/{id}/services', [$G, 'serviceManual'], ['perm' => 'growth.approve']);
    $r->post('/admin/growth/user/{id}/services/{sid}/delete', [$G, 'serviceManualDelete'], ['perm' => 'growth.approve']);
    $r->get('/admin/growth/reviews', [$G, 'reviews'], ['perm' => ['growth.approve', 'growth.view']]);
    $r->get('/admin/growth/review/{type}/{id}', [$G, 'review'], ['perm' => ['growth.approve', 'growth.view']]);
    $r->post('/admin/growth/review/{type}/{id}', [$G, 'decide'], ['perm' => 'growth.approve']);
    $r->get('/admin/growth/services', [$G, 'services'], ['perm' => 'growth.view']);
    $r->post('/admin/growth/services', [$G, 'serviceSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/services/map', [$G, 'mapName'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/services/order', [$G, 'serviceOrder'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/services/api', [$G, 'apiSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/services/api-test', [$G, 'apiTest'], ['perm' => ['growth.run', 'growth.edit']]);
    $r->post('/admin/growth/services/{id}', [$G, 'serviceSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/services/{id}/delete', [$G, 'serviceDelete'], ['perm' => 'growth.delete']);
    $r->post('/admin/growth/sync', [$G, 'syncStart'], ['perm' => 'growth.run']);
    $r->post('/admin/growth/sync/{id}/step', [$G, 'syncStep'], ['perm' => 'growth.run']);
    $r->post('/admin/growth/sync/{id}/cancel', [$G, 'syncCancel'], ['perm' => 'growth.run']);
    $r->get('/admin/growth/settings', [$G, 'settings'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/settings', [$G, 'settingsSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/ranks', [$G, 'rankSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/ranks/{id}', [$G, 'rankSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/ranks/{id}/delete', [$G, 'rankDelete'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/tracks', [$G, 'trackSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/tracks/{id}', [$G, 'trackSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/tracks/{id}/delete', [$G, 'trackDelete'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/seasons', [$G, 'seasonSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/seasons/{id}', [$G, 'seasonSave'], ['perm' => 'growth.edit']);
    $r->post('/admin/growth/seasons/{id}/delete', [$G, 'seasonDelete'], ['perm' => 'growth.edit']);
    $r->get('/admin/needs', [Admin\AssignmentController::class, 'needs'], ['perm' => 'needs.view']);
    $r->post('/admin/needs', [Admin\AssignmentController::class, 'saveNeed'], ['perm' => 'needs.create']);
    $r->post('/admin/needs/{id}', [Admin\AssignmentController::class, 'saveNeed'], ['perm' => 'needs.edit']);
    $r->post('/admin/needs/{id}/delete', [Admin\AssignmentController::class, 'deleteNeed'], ['perm' => 'needs.delete']);

    // ------------------------------------------------------------ admin: reports & monitoring
    $r->get('/admin/reports', [Admin\ReportController::class, 'index'], ['perm' => 'reports.view']);
    $r->get('/admin/reports/users', [Admin\ReportController::class, 'users'], ['perm' => ['reports.report', 'users.report']]);
    $r->get('/admin/reports/user/{id}', [Admin\ReportController::class, 'user'], ['perm' => ['reports.report', 'users.report', 'team.report']]);
    $r->get('/admin/reports/groups', [Admin\ReportController::class, 'groups'], ['perm' => 'reports.report']);
    $r->get('/admin/reports/courses', [Admin\ReportController::class, 'courses'], ['perm' => ['reports.report', 'courses.report']]);
    $r->get('/admin/reports/exams', [Admin\ReportController::class, 'exams'], ['perm' => ['reports.report', 'exams.report']]);
    $r->get('/admin/reports/progress', [Admin\ReportController::class, 'progress'], ['perm' => 'reports.report']);
    $r->get('/admin/reports/inactive', [Admin\ReportController::class, 'inactive'], ['perm' => 'reports.report']);
    $r->post('/admin/reports/inactive', [Admin\ReportController::class, 'inactive'], ['perm' => 'notifications.create']);
    $r->get('/admin/reports/needs', [Admin\ReportController::class, 'needsReport'], ['perm' => 'reports.report']);
    $r->get('/admin/reports/content', [Admin\ReportController::class, 'content'], ['perm' => 'reports.report']);
    $r->get('/admin/notifications', [Admin\SystemController::class, 'notifications'], ['perm' => 'notifications.view']);
    $r->post('/admin/notifications', [Admin\SystemController::class, 'sendNotification'], ['perm' => 'notifications.create']);
    $r->get('/admin/audit', [Admin\SystemController::class, 'audit'], ['perm' => 'audit.view']);

    // ------------------------------------------------------------ admin: system
    $r->get('/admin/settings', [Admin\SystemController::class, 'settings'], ['perm' => 'settings.view']);
    $r->post('/admin/settings', [Admin\SystemController::class, 'saveSettings'], ['perm' => 'settings.edit']);
    $r->get('/admin/integrations/arad-contact', [Admin\IntegrationController::class, 'aradContact'], ['perm' => 'settings.view']);
    $r->post('/admin/integrations/arad-contact', [Admin\IntegrationController::class, 'saveAradContact'], ['perm' => 'settings.edit']);
    $r->post('/admin/integrations/arad-contact/token', [Admin\IntegrationController::class, 'aradContactToken'], ['root' => true]);
    $r->get('/admin/integrations/arad-contact/logs/{id}', [Admin\IntegrationController::class, 'aradContactLog'], ['perm' => 'settings.view']);
    $r->get('/admin/sso', [Admin\SystemController::class, 'sso'], ['perm' => 'sso.view']);
    $r->post('/admin/sso', [Admin\SystemController::class, 'saveSso'], ['perm' => 'sso.edit']);
    $r->get('/admin/system/health', [Admin\SystemController::class, 'health'], ['perm' => 'health.view']);
    $r->get('/admin/system/errors', [Admin\SystemController::class, 'errors'], ['perm' => 'errors.view']);
    $r->get('/admin/system/backups', [Admin\MaintenanceController::class, 'backups'], ['perm' => 'backups.view']);
    $r->post('/admin/system/backups', [Admin\MaintenanceController::class, 'createBackup'], ['perm' => 'backups.create']);
    $r->post('/admin/system/backups/{id}/delete', [Admin\MaintenanceController::class, 'deleteBackup'], ['perm' => 'backups.delete']);
    $r->post('/admin/system/backups/{id}/download', [Admin\MaintenanceController::class, 'downloadBackup'], ['root' => true]);
    $r->post('/admin/system/backups/{id}/restore', [Admin\MaintenanceController::class, 'restoreBackup'], ['root' => true]);
    $r->get('/admin/system/migrations', [Admin\MaintenanceController::class, 'migrations'], ['perm' => 'migrations.view']);
    $r->post('/admin/system/migrations/run', [Admin\MaintenanceController::class, 'runMigrations'], ['root' => true]);
    $r->get('/admin/system/updates', [Admin\MaintenanceController::class, 'updates'], ['root' => true]);
    $r->post('/admin/system/updates', [Admin\MaintenanceController::class, 'uploadUpdate'], ['root' => true]);
    $r->get('/admin/system/updates/{id}', [Admin\MaintenanceController::class, 'updatePlan'], ['root' => true]);
    $r->post('/admin/system/updates/{id}/apply', [Admin\MaintenanceController::class, 'applyUpdate'], ['root' => true]);
    $r->post('/admin/system/updates/{id}/rollback', [Admin\MaintenanceController::class, 'rollbackUpdate'], ['root' => true]);
    $r->post('/admin/system/updates/{id}/discard', [Admin\MaintenanceController::class, 'discardUpdate'], ['root' => true]);
    $r->post('/admin/system/maintenance', [Admin\MaintenanceController::class, 'maintenance'], ['root' => true]);

    // ------------------------------------------------------------ API v1 (token auth, no session, no CSRF)
    $api = ['auth' => false, 'api' => true];
    $r->get('/api/v1/health', [V1Controller::class, 'health'], $api);
    $r->get('/api/v1/courses', [V1Controller::class, 'courses'], $api);
    $r->get('/api/v1/users', [V1Controller::class, 'users'], $api);
    $r->post('/api/v1/users', [V1Controller::class, 'upsertUser'], $api);
    $r->get('/api/v1/users/{id}/progress', [V1Controller::class, 'progress'], $api);
    $r->post('/api/v1/enrollments', [V1Controller::class, 'enroll'], $api);
    $r->get('/api/v1/reports/summary', [V1Controller::class, 'summary'], $api);

    // ------------------------------------------------------------ Arad Contact integration (fixed Bearer token set in settings)
    // every method is routed to the controller so wrong methods also get the {"success":false,...} JSON shape and are logged
    $all = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    $r->add($all, '/api/integrations/arad-contact/services', [AradContactController::class, 'services'], $api);
    $r->add($all, '/api/integrations/arad-contact/provision', [AradContactController::class, 'provision'], $api);
};
