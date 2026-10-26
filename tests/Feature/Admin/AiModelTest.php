<?php

use App\Models\AiModel;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.ai-models'))
        ->assertRedirect(route('login'));
});

test('non-admins cannot view the ai models page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.ai-models'))
        ->assertForbidden();
});

test('admins can view the ai models page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models'))
        ->assertOk()
        ->assertSee('AI Models');
});

test('admins see the ai models link', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.ai-models'), false);
});

test('non-admins do not see the ai models link', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.ai-models'), false);
});

test('non-admins cannot open the ai models form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.ai-models')
        ->assertForbidden();

    $this->assertDatabaseEmpty('ai_models');
});

test('ai models requires a model and api key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->set('model', '')
        ->set('organization', '')
        ->set('apiKey', '')
        ->call('save')
        ->assertHasErrors([
            'model' => 'The model field is required.',
            'apiKey' => 'The API key field is required.',
        ])
        ->assertHasNoErrors(['organization']);

    $this->assertDatabaseEmpty('ai_models');
});

test('ai models rejects a model longer than 255 characters', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->set('model', str_repeat('a', 256))
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertHasErrors([
            'model' => 'The model field must not be greater than 255 characters.',
        ]);

    $this->assertDatabaseEmpty('ai_models');
});

test('ai models rejects an organization longer than 255 characters', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4o')
        ->set('organization', str_repeat('a', 256))
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertHasErrors([
            'organization' => 'The organization field must not be greater than 255 characters.',
        ]);

    $this->assertDatabaseEmpty('ai_models');
});

test('admins can save the ai model', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->set('model', ' gpt-4o ')
        ->set('organization', ' org-acme ')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '');

    $this->assertDatabaseHas('ai_models', [
        'model' => 'gpt-4o',
        'organization' => 'org-acme',
    ]);

    expect(AiModel::query()->sole()->api_key)->toBe('chatgpt-api-key');

    $this->assertDatabaseMissing('ai_models', [
        'api_key' => 'chatgpt-api-key',
    ]);
});

test('a blank organization clears the saved organization', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'organization' => 'org-old',
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('organization', '   ')
        ->set('apiKey', '   ')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiModel::query()->sole()->organization)->toBeNull();
});

test('saving a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'api_key' => 'original-key',
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4.1')
        ->set('apiKey', '   ')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('ai_models', [
        'model' => 'gpt-4.1',
    ]);

    expect(AiModel::query()->sole()->api_key)->toBe('original-key');
});

test('saving a new api key replaces the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'api_key' => 'original-key',
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('apiKey', 'replacement-key')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiModel::query()->sole()->api_key)->toBe('replacement-key');
});

test('the saved api key is not shown on the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'model' => 'gpt-4o',
        'organization' => 'org-acme',
        'api_key' => 'super-secret-key',
    ]);

    $this->get(route('admin.ai-models'))
        ->assertOk()
        ->assertSee('gpt-4o', false)
        ->assertSee('org-acme', false)
        ->assertDontSee('super-secret-key', false);
});

test('ai model details are escaped on the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'model' => '<script>alert(1)</script>',
    ]);

    $this->get(route('admin.ai-models'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;\/script&gt;', false);
});
