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

test('inter csv is processed and creates transactions and contacts', function () {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');

    $this->seed(CategorySeeder::class);

    $user = User::factory()->create();

    $contents = file_get_contents(
        base_path('tests/Fixtures/imports/inter.csv')
    );

    expect($contents)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent(
        'inter.csv',
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
        ->bank->toBe('inter')
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
     * O Inter não fornece um identificador único da
     * transação no CSV. O parser gera um SHA-256 usando:
     *
     * número da conta + linha normalizada.
     */
    $transactionFingerprint = static fn (array $row): string => hash(
        'sha256',
        '12345678|'.implode('|', $row)
    );

    /*
     * Pix enviado.
     */
    $market = DB::table('contacts')
        ->where('user_id', $user->id)
        ->where(
            'normalized_name',
            'MERCADOTESTELTDA'
        )
        ->first();

    expect($market)
        ->not->toBeNull();

    $this->assertDatabaseHas('contacts', [
        'id' => $market->id,
        'user_id' => $user->id,
        'name' => 'MERCADO TESTE LTDA',
        'normalized_name' => 'MERCADOTESTELTDA',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $market->id,
        'transaction_code' => null,
        'transaction_fingerprint' => $transactionFingerprint([
            '02/05/2026',
            'Pix enviado',
            'Pix enviado - MERCADO TESTE LTDA',
            '-66,00',
            '1434,00',
        ]),
        'transaction_date' => '2026-05-02',
        'description' => 'Pix enviado - MERCADO TESTE LTDA',
        'amount' => '-66.00',
        'source_type' => 'manual_import',
        'transaction_method' => 'pix',
    ]);

    /*
     * Pix recebido.
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
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $customer->id,
        'transaction_code' => null,
        'transaction_fingerprint' => $transactionFingerprint([
            '03/05/2026',
            'Pix recebido',
            'Pix recebido - CLIENTE EXEMPLO',
            '1500,00',
            '2934,00',
        ]),
        'transaction_date' => '2026-05-03',
        'description' => 'Pix recebido - CLIENTE EXEMPLO',
        'amount' => '1500.00',
        'source_type' => 'manual_import',
        'transaction_method' => 'pix',
    ]);

    /*
     * Compra no débito.
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
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $store->id,
        'transaction_code' => null,
        'transaction_fingerprint' => $transactionFingerprint([
            '04/05/2026',
            'Compra no débito',
            'Compra no debito - LOJA FICTICIA LTDA',
            '-89,90',
            '2844,10',
        ]),
        'transaction_date' => '2026-05-04',
        'description' => 'Compra no debito - LOJA FICTICIA LTDA',
        'amount' => '-89.90',
        'source_type' => 'manual_import',
        'transaction_method' => 'debit_card',
    ]);

    /*
     * Boleto.
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
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'import_id' => $import->id,
        'contact_id' => $service->id,
        'transaction_code' => null,
        'transaction_fingerprint' => $transactionFingerprint([
            '05/05/2026',
            'Boleto',
            'SERVICO DEMONSTRACAO LTDA',
            '-120,50',
            '2723,60',
        ]),
        'transaction_date' => '2026-05-05',
        'description' => 'SERVICO DEMONSTRACAO LTDA',
        'amount' => '-120.50',
        'source_type' => 'manual_import',
        'transaction_method' => 'boleto',
    ]);
});

test('recognized csv without transactions fails import', function (string $fixture, string $bank) {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');

    $this->seed(CategorySeeder::class);

    $user = User::factory()->create();

    $contents = file_get_contents(
        base_path("tests/Fixtures/imports/{$fixture}")
    );

    expect($contents)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent(
        $fixture,
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
            'error',
            'Nenhuma transação foi encontrada no arquivo.'
        );

    $import = Import::query()->sole();

    expect($import)
        ->user_id->toBe($user->id)
        ->source->toBe('csv')
        ->bank->toBe($bank)
        ->status->toBe('failed')
        ->error_message->toBe(
            'Nenhuma transação foi encontrada no arquivo.'
        );

    expect($import->processed_at)
        ->not->toBeNull();

    $this->assertDatabaseCount(
        'transactions',
        0
    );

    $this->assertDatabaseCount(
        'contacts',
        0
    );
})->with([
    'nubank' => [
        'nubank-invalid.csv',
        'nubank',
    ],

    'inter' => [
        'inter-invalid.csv',
        'inter',
    ],
]);

test('unknown bank csv fails import', function () {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');

    $user = User::factory()->create();

    $contents = file_get_contents(
        base_path('tests/Fixtures/imports/unknown-bank.csv')
    );

    expect($contents)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent(
        'unknown-bank.csv',
        $contents
    );

    $response = $this
        ->actingAs($user)
        ->post(route('imports.store'), [
            'file' => $file,
        ]);

    $response
        ->assertRedirect(route('imports.index'))
        ->assertSessionHas('error');

    $import = Import::query()->sole();

    expect($import)
        ->user_id->toBe($user->id)
        ->source->toBe('csv')
        ->bank->toBeNull()
        ->status->toBe('failed');

    expect($import->processed_at)
        ->not->toBeNull();

    expect($import->error_message)
        ->toContain('Não foi possível identificar o banco');

    $this->assertDatabaseCount(
        'transactions',
        0
    );

    $this->assertDatabaseCount(
        'contacts',
        0
    );
});

/*
 * Only sources with a reliable transaction_code can be
 * deterministically deduplicated during import.
 *
 * Nubank provides a reliable Identificador field.
 */
test('reimporting the same csv does not duplicate transactions or contacts', function (string $fixture, string $bank) {
    config()->set('filesystems.default', 'local');

    Storage::fake('local');

    $this->seed(CategorySeeder::class);

    $user = User::factory()->create();

    $contents = file_get_contents(
        base_path("tests/Fixtures/imports/{$fixture}")
    );

    expect($contents)->not->toBeFalse();

    /*
     * Primeira importação.
     */
    $firstResponse = $this
        ->actingAs($user)
        ->post(route('imports.store'), [
            'file' => UploadedFile::fake()->createWithContent(
                $fixture,
                $contents
            ),
        ]);

    $firstResponse
        ->assertRedirect(route('imports.index'))
        ->assertSessionHas(
            'success',
            'Extrato importado com sucesso.'
        );

    /*
     * Segunda importação do mesmo arquivo.
     */
    $secondResponse = $this
        ->actingAs($user)
        ->post(route('imports.store'), [
            'file' => UploadedFile::fake()->createWithContent(
                $fixture,
                $contents
            ),
        ]);

    $secondResponse
        ->assertRedirect(route('imports.index'))
        ->assertSessionHas(
            'success',
            'Extrato importado com sucesso.'
        );

    /*
     * Cada upload gera seu próprio registro de importação.
     */
    $this->assertDatabaseCount(
        'imports',
        2
    );

    $imports = Import::query()
        ->orderBy('id')
        ->get();

    expect($imports)
        ->toHaveCount(2);

    expect($imports[0])
        ->bank->toBe($bank)
        ->status->toBe('done');

    expect($imports[1])
        ->bank->toBe($bank)
        ->status->toBe('done');

    /*
     * O arquivo possui quatro lançamentos.
     *
     * A segunda importação não pode criar cópias.
     */
    $this->assertDatabaseCount(
        'transactions',
        4
    );

    /*
     * Os quatro contatos também não podem ser duplicados.
     */
    $this->assertDatabaseCount(
        'contacts',
        4
    );

    /*
     * Confirma que existem apenas quatro códigos únicos.
     */
    expect(
        DB::table('transactions')
            ->distinct()
            ->count('transaction_code')
    )->toBe(4);
})->with([
    'nubank' => [
        'nubank.csv',
        'nubank',
    ],
]);
