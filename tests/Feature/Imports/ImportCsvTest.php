<?php

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('authenticated user can upload a csv import', function () {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');
    Bus::fake();

    $user = User::factory()->create();

    $file = UploadedFile::fake()->create(
        'extrato.csv',
        10,
        'text/csv'
    );

    $response = $this
        ->actingAs($user)
        ->post(route('imports.store'), [
            'file' => $file,
        ]);

    $response
        ->assertRedirect(route('imports.index'))
        ->assertSessionHas(
            'success',
            'Extrato importado com sucesso.'
        );

    $import = Import::query()->sole();

    expect($import)
        ->user_id->toBe($user->id)
        ->source->toBe('csv')
        ->original_filename->toBe('extrato.csv')
        ->status->toBe('pending');

    Storage::disk('local')
        ->assertExists($import->filename);

    Bus::assertDispatchedSync(
        ProcessImportJob::class,
        fn (ProcessImportJob $job): bool => $job->import->is($import)
    );
});

test('nubank csv is processed and creates transactions and contacts', function () {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');

    $this->seed(CategorySeeder::class);

    $user = User::factory()->create();

    $contents = file_get_contents(
        base_path('tests/Fixtures/imports/nubank.csv')
    );

    expect($contents)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent(
        'nubank.csv',
        $contents
    );

    $response = $this
        ->actingAs($user)
        ->post(route('imports.store'), [
            'file' => $file,
        ]);

    $response
        ->assertRedirect(route('imports.index'))
        ->assertSessionHas(
            'success',
            'Extrato importado com sucesso.'
        );

    $import = Import::query()->sole();

    expect($import)
        ->user_id->toBe($user->id)
        ->source->toBe('csv')
        ->bank->toBe('nubank')
        ->status->toBe('done');

    expect($import->processed_at)
        ->not->toBeNull();

    $this->assertDatabaseCount(
        'transactions',
        4
    );

    $this->assertDatabaseCount(
        'contacts',
        4
    );

    /*
     * Mercado fictício.
     */
    $company = DB::table('contacts')
        ->where('user_id', $user->id)
        ->where(
            'normalized_name',
            'MERCADOTESTELTDA'
        )
        ->first();

    expect($company)
        ->not->toBeNull();

    $this->assertDatabaseHas('contacts', [
        'id' => $company->id,
        'user_id' => $user->id,
        'name' => 'MERCADO TESTE LTDA',
        'normalized_name' => 'MERCADOTESTELTDA',
        'document' => '12345678000190',
        'contact_type' => 'company',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $company->id,
        'transaction_code' => 'NU-TEST-001',
        'transaction_date' => '2026-05-02',
        'description' => 'Transferência enviada pelo Pix - MERCADO TESTE LTDA - 12.345.678/0001-90 - BANCO FICTICIO S.A.',
        'amount' => '-66.00',
        'source_type' => 'manual_import',
        'transaction_method' => 'pix',
    ]);

    /*
     * Cliente fictício.
     */
    $customer = DB::table('contacts')
        ->where('user_id', $user->id)
        ->where(
            'normalized_name',
            'CLIENTEEXEMPLO'
        )
        ->first();

    expect($customer)
        ->not->toBeNull();

    $this->assertDatabaseHas('contacts', [
        'id' => $customer->id,
        'user_id' => $user->id,
        'name' => 'CLIENTE EXEMPLO',
        'normalized_name' => 'CLIENTEEXEMPLO',
        'document' => '12345678900',
        'contact_type' => 'individual',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $customer->id,
        'transaction_code' => 'NU-TEST-002',
        'transaction_date' => '2026-05-03',
        'description' => 'Transferência recebida pelo Pix - CLIENTE EXEMPLO - 123.456.789-00 - BANCO DEMO S.A.',
        'amount' => '1500.00',
        'source_type' => 'manual_import',
        'transaction_method' => 'pix',
    ]);

    /*
     * Loja fictícia.
     */
    $store = DB::table('contacts')
        ->where('user_id', $user->id)
        ->where(
            'normalized_name',
            'LOJAFICTICIALTDA'
        )
        ->first();

    expect($store)
        ->not->toBeNull();

    $this->assertDatabaseHas('contacts', [
        'id' => $store->id,
        'user_id' => $user->id,
        'name' => 'LOJA FICTICIA LTDA',
        'normalized_name' => 'LOJAFICTICIALTDA',
        'document' => '98765432000110',
        'contact_type' => 'company',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $store->id,
        'transaction_code' => 'NU-TEST-003',
        'transaction_date' => '2026-05-04',
        'description' => 'Compra no cartão - LOJA FICTICIA LTDA - 98.765.432/0001-10',
        'amount' => '-89.90',
        'source_type' => 'manual_import',
        'transaction_method' => 'card',
    ]);

    /*
     * Serviço fictício.
     */
    $service = DB::table('contacts')
        ->where('user_id', $user->id)
        ->where(
            'normalized_name',
            'SERVICODEMONSTRACAOLTDA'
        )
        ->first();

    expect($service)
        ->not->toBeNull();

    $this->assertDatabaseHas('contacts', [
        'id' => $service->id,
        'user_id' => $user->id,
        'name' => 'SERVICO DEMONSTRACAO LTDA',
        'normalized_name' => 'SERVICODEMONSTRACAOLTDA',
        'document' => '11111111000111',
        'contact_type' => 'company',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $service->id,
        'transaction_code' => 'NU-TEST-004',
        'transaction_date' => '2026-05-05',
        'description' => 'Pagamento de boleto - SERVICO DEMONSTRACAO LTDA - 11.111.111/0001-11',
        'amount' => '-120.50',
        'source_type' => 'manual_import',
        'transaction_method' => 'boleto',
    ]);
});
