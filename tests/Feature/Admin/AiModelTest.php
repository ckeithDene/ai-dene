<?php

use App\Models\AiModel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

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
        ->assertSee('AI Models')
        ->assertSeeInOrder([
            'gpt-6-astra',
            'Most capable. Slowest. Hard research.',
            'gpt-6.1-sol',
            'Balanced everyday work.',
            'gpt-6.1-terra',
            'Faster writing and visual tasks.',
            'gpt-6-luna',
            'Fastest. Use this when a job times out.',
        ]);
});

test('choosing a model code fills the model box', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->call('selectModel', 'gpt-6-luna')
        ->assertSet('model', 'gpt-6-luna');
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

    Http::assertNothingSent();

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
    $this->travelTo('2026-10-26 12:00:00');
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', ' gpt-4o ')
        ->set('organization', ' org-acme ')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertSee('Connected');

    $this->assertDatabaseHas('ai_models', [
        'model' => 'gpt-4o',
        'organization' => 'org-acme',
    ]);

    $aiModel = AiModel::query()->sole();

    expect($aiModel->api_key)->toBe('chatgpt-api-key')
        ->and($aiModel->verified_at?->toDateTimeString())->toBe('2026-10-26 12:00:00')
        ->and($aiModel->verification_error)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/models/gpt-4o'
            && $request->hasHeader('Authorization', 'Bearer chatgpt-api-key')
            && $request->hasHeader('OpenAI-Organization', 'org-acme');
    });

    $this->assertDatabaseMissing('ai_models', [
        'api_key' => 'chatgpt-api-key',
    ]);
});

test('a blank organization clears the saved organization', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

    AiModel::factory()->create([
        'organization' => 'org-old',
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('organization', '   ')
        ->set('apiKey', '   ')
        ->call('save')
        ->assertHasNoErrors();

    $aiModel = AiModel::query()->sole();

    expect($aiModel->organization)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/models/gpt-4o'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && ! $request->hasHeader('OpenAI-Organization');
    });
});

test('saving a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4.1' => Http::response([
            'id' => 'gpt-4.1',
        ]),
    ]);

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

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

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

test('saving reports a rejected chatgpt api key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'error' => ['message' => 'invalid_api_key'],
        ], 401),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4o')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertSee('Connection failed')
        ->assertSee('OpenAI rejected the API key.')
        ->assertDontSee('chatgpt-api-key');

    $aiModel = AiModel::query()->sole();

    expect($aiModel->verified_at)->toBeNull()
        ->and($aiModel->verification_error)->toBe('OpenAI rejected the API key.');
});

test('saving reports when chatgpt cannot find the model', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/missing-model' => Http::response([], 404),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'missing-model')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertSee('OpenAI could not find that model.');

    expect(AiModel::query()->sole()->verified_at)->toBeNull();
});

test('saving reports when openai cannot be reached', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::failedConnection(),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4o')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertSee('Could not reach OpenAI.');

    expect(AiModel::query()->sole()->verified_at)->toBeNull();
});

test('chatgpt verification does not follow redirects', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response('', 302, [
            'Location' => 'https://evil.example/steal',
        ]),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4o')
        ->set('apiKey', 'chatgpt-api-key')
        ->call('save')
        ->assertSee('Could not reach OpenAI.');

    Http::assertSentCount(1);
});

test('admins can recheck a saved chatgpt connection', function () {
    $this->travelTo('2026-10-26 12:00:00');
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'model' => 'gpt-4o',
        'verification_error' => 'OpenAI rejected the API key.',
    ]);

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->call('testConnection')
        ->assertSee('Connected');

    $aiModel = AiModel::query()->sole();

    expect($aiModel->verified_at?->toDateTimeString())->toBe('2026-10-26 12:00:00')
        ->and($aiModel->verification_error)->toBeNull();
});

test('testing an unsaved chatgpt connection does not call openai', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.ai-models')
        ->call('testConnection')
        ->assertHasErrors([
            'model' => 'The model field is required.',
            'apiKey' => 'The API key field is required.',
        ]);

    Http::assertNothingSent();

    $this->assertDatabaseEmpty('ai_models');
});

test('testing a newly entered api key checks that key', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'model' => 'gpt-4o',
        'organization' => 'org-acme',
        'api_key' => 'original-key',
    ]);

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('organization', '')
        ->set('apiKey', "Bearer sk-new-key\n")
        ->call('testConnection')
        ->assertHasNoErrors();

    $aiModel = AiModel::query()->sole();

    expect($aiModel->api_key)->toBe('sk-new-key')
        ->and($aiModel->organization)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer sk-new-key')
            && ! $request->hasHeader('OpenAI-Organization');
    });
});

test('a long api key can be saved', function () {
    $this->actingAs(User::factory()->admin()->create());

    $apiKey = 'sk-'.str_repeat('a', 300);

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'id' => 'gpt-4o',
        ]),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->set('model', 'gpt-4o')
        ->set('apiKey', $apiKey)
        ->call('save')
        ->assertHasNoErrors();

    expect(AiModel::query()->sole()->api_key)->toBe($apiKey);
});

test('a rejected key explains when an organization is set', function () {
    $this->actingAs(User::factory()->admin()->create());

    AiModel::factory()->create([
        'model' => 'gpt-4o',
        'organization' => 'org-acme',
        'api_key' => 'original-key',
    ]);

    Http::fake([
        'https://api.openai.com/v1/models/gpt-4o' => Http::response([
            'error' => ['message' => 'invalid_api_key'],
        ], 401),
    ]);

    Livewire::test('pages::admin.ai-models')
        ->call('testConnection')
        ->assertSee('Clear the organization field unless this key belongs to that organization.');
});

test('non-admins cannot test the chatgpt connection', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user);

    AiModel::factory()->create();

    $component = Livewire::test('pages::admin.ai-models');

    $user->is_admin = false;
    $user->save();

    auth()->setUser($user->fresh());

    $component->call('testConnection')
        ->assertForbidden();

    Http::assertNothingSent();
});
