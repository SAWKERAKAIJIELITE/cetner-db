<?php

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Actions\ApplyImport;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\Import;
use Illuminate\Console\Command;

/**
 * Writes an approved import from the shell (D-053): for an import left «قيد الاستيراد» when the server stopped
 * while writing it (the transaction was rolled back, so nothing of it is kept).
 */
class ApplyImportCommand extends Command
{
    protected $signature = 'imports:apply {import : the import id}';

    protected $description = 'Write an approved import left being applied (its rows are written whole or not at all)';

    public function handle(ApplyImport $apply): int
    {
        $import = Import::query()->find($this->argument('import'));

        if ($import === null || $import->status !== ImportStatus::Applying) {
            $this->error('No import being applied with this id.');

            return self::FAILURE;
        }

        $apply->handle($import->id);
        $import->refresh();
        $this->info('Import '.$import->id.': '.$import->status->value.($import->failure ? ' — '.json_encode($import->failure, JSON_UNESCAPED_UNICODE) : ''));

        return $import->status === ImportStatus::Approved ? self::SUCCESS : self::FAILURE;
    }
}
