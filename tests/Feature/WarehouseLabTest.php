<?php

use App\Models\User;

test('guests cannot open the warehouse lab', function () {
    $this->get(route('warehouse-lab'))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.files', ['path' => 'index.html']))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.files', ['path' => 'location-catalogue.json']))
        ->assertRedirect(route('login'));
});

test('a signed-in user can open the warehouse lab', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('warehouse-lab'))
        ->assertOk()
        ->assertSee('Warehouse Lab')
        ->assertSee('fixed inset-0 top-14 lg:top-0 lg:left-64', false)
        ->assertSee(route('warehouse-lab.files', ['path' => 'index.html']), false);
});

test('a signed-in user can load the warehouse lab files', function () {
    $this->actingAs(User::factory()->create());

    $page = $this->get(route('warehouse-lab.files', ['path' => 'index.html']));

    $page->assertOk()
        ->assertHeader('content-type', 'text/html; charset=UTF-8');

    expect($page->streamedContent())
        ->toContain('Warehouse Lab')
        ->toContain('./app.js');

    $script = $this->get(route('warehouse-lab.files', ['path' => 'app.js']));
    $script->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($script->streamedContent())->toContain('three');

    $vendor = $this->get(route('warehouse-lab.files', ['path' => 'vendor/three.module.js']));
    $vendor->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($vendor->streamedContent())->not->toBe('');

    $catalogue = $this->get(route('warehouse-lab.files', ['path' => 'location-catalogue.json']));
    $catalogue->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');
    expect($catalogue->streamedContent())->toStartWith('{');
});

test('warehouse lab files cannot escape the prototype folder', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('warehouse-lab.files', ['path' => '../.env']))
        ->assertNotFound();

    $this->get(route('warehouse-lab.files', ['path' => 'vendor/../../.env']))
        ->assertNotFound();

    $this->get(route('warehouse-lab.files', ['path' => 'missing.html']))
        ->assertNotFound();
});
