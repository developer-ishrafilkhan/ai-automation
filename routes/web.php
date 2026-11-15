<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\McpServerController;
use App\Http\Controllers\PromptGuideController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\WorkflowController;
use App\Http\Middleware\EnsureWorkspace;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Public forms: no login. Submissions are rate limited per visitor (see AppServiceProvider).
Route::get('f/{slug}', [PublicFormController::class, 'show'])->name('forms.show');
Route::post('f/{slug}', [PublicFormController::class, 'submit'])->middleware('throttle:public-form')->name('forms.submit');
Route::get('f/{slug}/r/{token}', [PublicFormController::class, 'result'])->name('forms.result');
Route::get('f/{slug}/r/{token}/status', [PublicFormController::class, 'status'])->middleware('throttle:120,1')->name('forms.status');

Route::middleware(['auth', EnsureWorkspace::class])->group(function () {
    Route::redirect('dashboard', '/workflows')->name('dashboard');

    Route::get('workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    Route::post('workflows', [WorkflowController::class, 'store'])->name('workflows.store');
    Route::post('workflows/generate', [WorkflowController::class, 'generate'])->middleware('throttle:10,1')->name('workflows.generate');
    Route::get('workflows/{workflow}', [WorkflowController::class, 'edit'])->name('workflows.edit');
    Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
    Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
    Route::post('workflows/{workflow}/publish', [WorkflowController::class, 'publish'])->name('workflows.publish');
    Route::put('workflows/{workflow}/form', [WorkflowController::class, 'updateForm'])->name('workflows.form');
    Route::post('workflows/{workflow}/form/suggest', [WorkflowController::class, 'suggestForm'])->middleware('throttle:10,1')->name('workflows.form.suggest');
    Route::post('workflows/{workflow}/run', [WorkflowController::class, 'run'])->name('workflows.run');

    Route::get('runs', [ExecutionController::class, 'index'])->name('executions.index');
    Route::get('runs/{execution}', [ExecutionController::class, 'show'])->name('executions.show');
    Route::post('runs/{execution}/retry', [ExecutionController::class, 'retry'])->name('executions.retry');

    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::post('assets/upload', [AssetController::class, 'upload'])->name('assets.upload');
    Route::get('assets/{asset}/palette', [AssetController::class, 'palette'])->name('assets.palette');

    Route::get('prompt-writer', [PromptGuideController::class, 'index'])->name('prompt-writer.index');
    Route::put('prompt-writer/{task}', [PromptGuideController::class, 'update'])->name('prompt-writer.update');
    Route::post('runs/steps/{step}/learn', [PromptGuideController::class, 'learnFromStep'])->name('prompt-writer.learn');

    Route::get('characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::put('characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
    Route::delete('characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');
    Route::post('characters/{character}/sheet', [CharacterController::class, 'generateSheet'])->name('characters.sheet');

    Route::get('connectors', [McpServerController::class, 'index'])->name('connectors.index');
    Route::post('connectors', [McpServerController::class, 'store'])->name('connectors.store');
    Route::post('connectors/apps/{app}', [McpServerController::class, 'connectApp'])->name('connectors.connect-app');
    Route::post('connectors/oauth', [McpServerController::class, 'connectCustom'])->name('connectors.connect-custom');
    Route::get('connectors/oauth/callback', [McpServerController::class, 'callback'])->name('connectors.oauth.callback');
    Route::post('connectors/{server}/reconnect', [McpServerController::class, 'reconnect'])->name('connectors.reconnect');
    Route::post('connectors/{server}/test', [McpServerController::class, 'test'])->name('connectors.test');
    Route::delete('connectors/{server}', [McpServerController::class, 'destroy'])->name('connectors.destroy');

    Route::get('keys', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('keys', [CredentialController::class, 'store'])->name('credentials.store');
    Route::post('keys/{credential}/test', [CredentialController::class, 'test'])->name('credentials.test');
    Route::delete('keys/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
    Route::put('model-defaults', [CredentialController::class, 'updateDefaults'])->name('model-defaults.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
