<?php

use App\Enums\PageAccess;
use App\Models\AiModel;
use App\Models\OdooConnection;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('midmeds-asset-generation'))
        ->assertRedirect(route('login'));
});

test('guests cannot open a combo image', function (string $name) {
    $this->get(route($name, ['image' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8']))
        ->assertRedirect(route('login'));
})->with([
    'view' => ['midmeds-asset-generation.image'],
    'download' => ['midmeds-asset-generation.image.download'],
]);

test('a member can open midmeds asset generation', function () {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => [PageAccess::MidMedsAssetGeneration->value]])->save();

    $this->actingAs($member)
        ->get(route('midmeds-asset-generation'))
        ->assertOk()
        ->assertSee('MidMeds Asset Generation')
        ->assertSee('Generate product assets for the MidMeds shop.')
        ->assertSee('Combo image')
        ->assertSee('Search the MidMeds catalogue and pick the products in an offer.');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('MidMeds')
        ->assertSee('MidMeds Asset Generation')
        ->assertDontSee('First Order Diamonds')
        ->assertDontSee('Dene Healthcare');
});

test('a short search is rejected before the catalogue is read', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    Livewire::test('pages::midmeds-asset-generation')
        ->set('query', 'a')
        ->call('search')
        ->assertHasErrors(['query' => 'min'])
        ->assertSee('Enter at least 2 characters.')
        ->assertSet('searched', false);

    Http::assertNothingSent();
});

test('search uses the midmeds catalogue and ignores dene healthcare', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => comboCatalogueResponse($request));

    Livewire::test('pages::midmeds-asset-generation')
        ->set('query', 'glove')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('searched', true)
        ->assertSee('Nitrile Gloves')
        ->assertSee('GLV-1')
        ->assertSee('data:image/png;base64,'.comboSourceImage(), false)
        ->assertDontSee('Dene Only Product')
        ->call('addProduct', 11)
        ->call('addProduct', 10)
        ->call('addProduct', 10)
        ->assertCount('selected', 2)
        ->assertSee('Added')
        ->call('removeProduct', 11)
        ->assertCount('selected', 1)
        ->assertDontSee('data-test="remove-product-11"', false);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'dene.odoo.com'));
});

test('an empty catalogue search tells the user nothing matched', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => comboCatalogueResponse($request, []));

    Livewire::test('pages::midmeds-asset-generation')
        ->set('query', 'zzzz')
        ->call('search')
        ->assertSee('No products matched that search.');
});

test('search tells the user when odoo is not connected', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    Livewire::test('pages::midmeds-asset-generation')
        ->set('query', 'gloves')
        ->call('search')
        ->assertSee('Connect the MidMeds Odoo account before searching the catalogue.')
        ->assertSet('searched', false);

    Http::assertNothingSent();
});

test('search tells the user when the catalogue cannot be read', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://midmeds.odoo.com/*' => Http::response(['error' => ['message' => 'down']], 500),
    ]);

    Livewire::test('pages::midmeds-asset-generation')
        ->set('query', 'gloves')
        ->call('search')
        ->assertSee('Could not read the MidMeds catalogue from Odoo.')
        ->assertSet('searched', false);
});

test('a combo image needs at least two products', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [[
            'id' => 10,
            'name' => 'Nitrile Gloves',
            'sku' => 'GLV-1',
            'image' => '',
        ]])
        ->call('create')
        ->assertSee('Select at least two products.')
        ->assertSet('image', null);
});

test('a combo image keeps at most twelve products', function () {
    $this->actingAs(User::factory()->create());

    $selected = [];

    for ($id = 1; $id <= 12; $id++) {
        $selected[] = [
            'id' => $id,
            'name' => 'Product '.$id,
            'sku' => '',
            'image' => '',
        ];
    }

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', $selected)
        ->set('results', [[
            'id' => 13,
            'name' => 'Extra Gloves',
            'sku' => 'GLV-13',
            'image' => '',
        ]])
        ->call('addProduct', 13)
        ->assertSee('A combo image can include up to 12 products.')
        ->assertCount('selected', 12);
});

test('creating a combo image sends the catalogue photos to chatgpt and can be downloaded', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    AiModel::factory()->create([
        'organization' => 'org-test',
        'api_key' => 'test-api-key',
    ]);
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => comboCatalogueResponse($request));

    $old = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    Storage::disk('local')->put('combo-images/'.$old.'.png', 'old-image');
    touch(Storage::disk('local')->path('combo-images/'.$old.'.png'), now()->subDays(2)->getTimestamp());

    $component = Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSet('notice', null)
        ->assertSee('Download image');

    $image = $component->get('image');

    expect($image)->toBeUuid();
    Storage::disk('local')->assertExists('combo-images/'.$image.'.png');
    Storage::disk('local')->assertMissing('combo-images/'.$old.'.png');
    expect(Storage::disk('local')->get('combo-images/'.$image.'.png'))->toBe(base64_decode(comboOutputImage()));

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.openai.com/v1/images/edits') {
            return false;
        }

        $body = $request->data();

        return ($request->header('Authorization')[0] ?? null) === 'Bearer test-api-key'
            && ($request->header('OpenAI-Organization')[0] ?? null) === 'org-test'
            && ($body['model'] ?? null) === 'gpt-image-2.6-sunburst'
            && ($body['size'] ?? null) === '1536x1024'
            && ($body['quality'] ?? null) === 'high'
            && ($body['background'] ?? null) === 'opaque'
            && ($body['output_format'] ?? null) === 'png'
            && str_contains((string) ($body['prompt'] ?? ''), 'Image 1 is the photo of "Apron" (SKU APR-1).')
            && str_contains((string) ($body['prompt'] ?? ''), 'Image 2 is the photo of "Nitrile Gloves" (SKU GLV-1).')
            && ($body['images'][0]['image_url'] ?? null) === 'data:image/png;base64,'.comboSourceImage()
            && count($body['images'] ?? []) === 2;
    });
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'dene.odoo.com'));

    $this->get(route('midmeds-asset-generation.image', $image))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    $this->get(route('midmeds-asset-generation.image.download', $image))
        ->assertOk()
        ->assertDownload('midmeds-combo-image.png');

    $component->call('create');
    $replacement = $component->get('image');

    expect($replacement)->toBeUuid()->not->toBe($image);
    Storage::disk('local')->assertMissing('combo-images/'.$image.'.png');
    Storage::disk('local')->assertExists('combo-images/'.$replacement.'.png');
});

test('a missing combo image is not found', function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('local');

    $this->get(route('midmeds-asset-generation.image', ['image' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8']))
        ->assertNotFound();
});

test('creating a combo image tells the user when chatgpt is not connected', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    Http::preventStrayRequests();

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSee('Save a ChatGPT connection before creating a combo image.')
        ->assertSet('image', null);

    Http::assertNothingSent();
});

test('creating a combo image tells the user when odoo is not connected', function () {
    $this->actingAs(User::factory()->create());
    AiModel::factory()->create();
    Http::preventStrayRequests();

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSee('Connect the MidMeds Odoo account before creating a combo image.');

    Http::assertNothingSent();
});

test('catalogue photos fall back to the full image when the smaller field is missing', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    AiModel::factory()->create();
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            return Http::response(['data' => [['b64_json' => comboOutputImage()]]]);
        }

        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        $fields = ((array) ($params['args'][6] ?? []))['fields'] ?? [];

        if (in_array('image_1024', $fields, true)) {
            return Http::response(['error' => ['message' => 'Invalid field']]);
        }

        return Http::response(['result' => [
            ['id' => 10, 'name' => 'Nitrile Gloves', 'default_code' => 'GLV-1', 'image_1920' => comboSourceImage()],
            ['id' => 11, 'name' => 'Apron', 'default_code' => 'APR-1', 'image_1920' => comboSourceImage()],
        ]]);
    });

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSet('notice', null)
        ->assertSee('Download image');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

test('a product without a photo is left out of the chatgpt request', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    AiModel::factory()->create();
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => comboCatalogueResponse($request, missingPhoto: true));

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSee('Apron has no product image in Odoo.')
        ->assertSet('image', null);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

test('chatgpt failures stay on the page', function (int $status, array $error, string $message) {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    AiModel::factory()->create();
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => comboCatalogueResponse($request, openAi: Http::response(['error' => $error], $status)));

    Livewire::test('pages::midmeds-asset-generation')
        ->set('selected', [
            ['id' => 11, 'name' => 'Apron', 'sku' => 'APR-1', 'image' => ''],
            ['id' => 10, 'name' => 'Nitrile Gloves', 'sku' => 'GLV-1', 'image' => ''],
        ])
        ->call('create')
        ->assertSee($message)
        ->assertSet('image', null);
})->with([
    'refused key' => [401, ['message' => 'invalid api key'], 'ChatGPT refused the image request. Check the saved API key can create images.'],
    'empty balance' => [429, ['code' => 'insufficient_quota', 'message' => 'billing'], 'ChatGPT has no tokens left.'],
    'rate limit' => [429, ['code' => 'rate_limit_exceeded', 'message' => 'rate limit'], 'ChatGPT could not create the combo image.'],
    'unexpected response' => [500, ['message' => 'down'], 'ChatGPT could not create the combo image.'],
]);

/**
 * @param  array<int, array<string, mixed>>|null  $templates
 */
function comboCatalogueResponse(Request $request, ?array $templates = null, bool $missingPhoto = false, ?PromiseInterface $openAi = null): PromiseInterface
{
    if (str_contains($request->url(), 'api.openai.com')) {
        return $openAi ?? Http::response([
            'data' => [
                ['b64_json' => comboOutputImage()],
            ],
        ]);
    }

    if (str_contains($request->url(), 'dene.odoo.com')) {
        return Http::response(['result' => [[
            'id' => 99,
            'name' => 'Dene Only Product',
            'default_code' => 'DENE-1',
            'image_128' => comboSourceImage(),
        ]]]);
    }

    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $call = $params['args'] ?? [];
    $method = $call[4] ?? null;

    if ($method === 'read') {
        return Http::response(['result' => [
            [
                'id' => 10,
                'name' => 'Nitrile Gloves',
                'default_code' => 'GLV-1',
                'image_1024' => comboSourceImage(),
            ],
            [
                'id' => 11,
                'name' => 'Apron',
                'default_code' => 'APR-1',
                'image_1024' => $missingPhoto ? false : comboSourceImage(),
            ],
        ]]);
    }

    return Http::response(['result' => $templates ?? [
        [
            'id' => 10,
            'name' => 'Nitrile Gloves',
            'default_code' => 'GLV-1',
            'image_128' => comboSourceImage(),
        ],
        [
            'id' => 11,
            'name' => 'Apron',
            'default_code' => 'APR-1',
            'image_128' => comboSourceImage(),
        ],
    ]]);
}

function comboSourceImage(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

function comboOutputImage(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
}
