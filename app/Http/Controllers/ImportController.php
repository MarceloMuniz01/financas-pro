<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportController extends Controller
{
    /**
     * Recebe, salva e processa o extrato de forma síncrona.
     *
     * O banco é identificado dentro do ProcessImportJob
     * pelo BankDetector.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        /*
         * O processamento acontece dentro da própria requisição.
         */
        set_time_limit(300);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,ofx',
                'max:20480',
            ],
        ]);

        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $userId = (int) $user->id;

        $uploadedFile =
            $validated['file'];

        $storedPath = null;
        $import = null;

        try {
            /*
            |--------------------------------------------------------------------------
            | Salvar o arquivo
            |--------------------------------------------------------------------------
            */

            $storedPath = $uploadedFile->store(
                "imports/{$userId}"
            );

            if (!$storedPath) {
                return back()->with(
                    'error',
                    'Não foi possível armazenar o arquivo.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Criar o registro da importação
            |--------------------------------------------------------------------------
            |
            | O campo bank começa como null.
            |
            | O ProcessImportJob usa o BankDetector,
            | identifica o banco e atualiza esse campo.
            |
            */

            $extension = strtolower(
                $uploadedFile
                    ->getClientOriginalExtension()
            );

            $source =
                $extension === 'ofx'
                ? 'ofx'
                : 'csv';

            $import = Import::query()->create([
                'user_id' =>
                    $userId,

                'source' =>
                    $source,

                'bank' =>
                    null,

                'filename' =>
                    $storedPath,

                'original_filename' =>
                    $uploadedFile
                        ->getClientOriginalName(),

                'status' =>
                    'pending',

                'processed_at' =>
                    null,

                'error_message' =>
                    null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Processar imediatamente
            |--------------------------------------------------------------------------
            |
            | dispatchSync executa o handle() no mesmo request.
            |
            | Não usa tabela jobs e não exige queue:work.
            |
            */

            ProcessImportJob::dispatchSync(
                $import
            );

            $import->refresh();

            /*
             * Proteção adicional caso o job apenas marque
             * a importação como failed sem lançar exceção.
             */
            if ($import->status === 'failed') {
                return redirect()
                    ->route('imports.index')
                    ->with(
                        'error',
                        $import->error_message
                        ?: 'Não foi possível processar o arquivo.'
                    );
            }

            return redirect()
                ->route('imports.index')
                ->with(
                    'success',
                    'Extrato importado com sucesso.'
                );
        } catch (Throwable $exception) {
            Log::error(
                'Erro no upload ou processamento da importação.',
                [
                    'user_id' =>
                        $userId,

                    'import_id' =>
                        $import?->id,

                    'original_filename' =>
                        $uploadedFile
                            ->getClientOriginalName(),

                    'stored_path' =>
                        $storedPath,

                    'message' =>
                        $exception->getMessage(),

                    'exception' =>
                        $exception,
                ]
            );

            /*
             * O ProcessImportJob já marca a importação como failed
             * quando o erro ocorre dentro dele.
             *
             * Este bloco cobre erros antes ou fora do job.
             */
            if (
                $import !== null
                && $import->status !== 'failed'
            ) {
                $import->update([
                    'status' =>
                        'failed',

                    'processed_at' =>
                        now(),

                    'error_message' =>
                        mb_substr(
                            $exception->getMessage(),
                            0,
                            2000
                        ),
                ]);
            }

            return redirect()
                ->route('imports.index')
                ->with(
                    'error',
                    app()->hasDebugModeEnabled()
                    ? $exception->getMessage()
                    : 'Ocorreu um erro durante o processamento do arquivo.'
                );
        }
    }

    /**
     * Exclui uma importação e o arquivo armazenado.
     *
     * As transações vinculadas dependerão da regra da foreign key
     * de transactions.import_id.
     */
    public function destroy(
        Request $request,
        Import $import
    ): RedirectResponse {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $this->ensureImportBelongsToUser(
            import: $import,
            userId: (int) $user->id
        );

        $filename =
            $import->filename;

        try {
            $import->delete();

            if (
                $filename
                && Storage::exists($filename)
            ) {
                Storage::delete($filename);
            }

            return redirect()
                ->route('imports.index')
                ->with(
                    'success',
                    'Importação excluída com sucesso.'
                );
        } catch (Throwable $exception) {
            Log::error(
                'Erro ao excluir importação.',
                [
                    'user_id' =>
                        $user->id,

                    'import_id' =>
                        $import->id,

                    'filename' =>
                        $filename,

                    'message' =>
                        $exception->getMessage(),

                    'exception' =>
                        $exception,
                ]
            );

            return redirect()
                ->route('imports.index')
                ->with(
                    'error',
                    'Não foi possível excluir a importação.'
                );
        }
    }

    /**
     * Garante que a importação pertence ao usuário autenticado.
     */
    private function ensureImportBelongsToUser(
        Import $import,
        int $userId
    ): void {
        if ((int) $import->user_id !== $userId) {
            abort(403);
        }
    }
}