<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\DecisionRequest;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Imports\Actions\DecideImport;
use App\Modules\Imports\Actions\StageImport;
use App\Modules\Imports\Enums\Duplicates;
use App\Modules\Imports\Http\Requests\ImportIndexRequest;
use App\Modules\Imports\Http\Requests\ImportRowsRequest;
use App\Modules\Imports\Http\Requests\UploadImportRequest;
use App\Modules\Imports\Http\Resources\ImportResource;
use App\Modules\Imports\Http\Resources\ImportRowResource;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Records\Support\RecordLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Importing the owner's workbooks (D-053). */
class ImportController extends Controller
{
    /** «سجل الإستيراد السابق»: every import, newest first. */
    public function index(ImportIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();

        return ImportResource::collection(Import::query()->with(['uploader', 'decider'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(! empty($f['mine']), fn ($q) => $q->where('uploaded_by', $request->user()->id))
            ->orderByDesc('uploaded_at')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString());
    }

    /**
     * Upload a workbook (multipart/form-data): checked and staged, nothing imported yet. Refused (422, `file`) when it
     * is not an .xlsx, holds macros, was imported already, or a present sheet lacks a required column.
     */
    public function store(UploadImportRequest $request, StageImport $action): ImportResource
    {
        $import = $action->handle($request->file('file'), Duplicates::from($request->validated('duplicates') ?? 'skip'), $request->user());

        return $this->one($import);
    }

    /** The import: its state, its counts by sheet, each sheet's column matching. */
    public function show(Import $import): ImportResource
    {
        return $this->one($import);
    }

    /** «معاينة البيانات»: the staged rows by line, with what is wrong with each. */
    public function rows(ImportRowsRequest $request, Import $import): AnonymousResourceCollection
    {
        $f = $request->validated();
        ImportRowResource::$labels = new ValueLabels;
        ImportRowResource::$recordLabels = new RecordLabels;

        return ImportRowResource::collection(ImportRow::query()->where('import_id', $import->id)
            ->when($f['sheet'] ?? null, fn ($q, $s) => $q->where('sheet', $s))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['personal_number'] ?? null, fn ($q, $n) => $q->where('personal_number', $n))
            ->orderByRaw("array_position(ARRAY['employees','movements','attendance','conduct','debts']::varchar[], sheet)")->orderBy('line')
            ->paginate((int) ($f['per_page'] ?? 50))->withQueryString());
    }

    /**
     * Approve it (no row «غير صالحة» left): written after the response, whole or not at all; follow `status`
     * («قيد الاستيراد» then «مستورد» or «فشل الاستيراد»).
     */
    public function approve(DecisionRequest $request, Import $import, DecideImport $action): ImportResource
    {
        return $this->one($action->approve($import, $request->user(), $request->note()));
    }

    /** Send it back with a reason: its uploader is told. */
    public function reject(DecisionRequest $request, Import $import, DecideImport $action): ImportResource
    {
        return $this->one($action->reject($import, $request->user(), (string) $request->note()));
    }

    /** Take back one's own upload. */
    public function cancel(Request $request, Import $import, DecideImport $action): ImportResource
    {
        return $this->one($action->cancel($import, $request->user()));
    }

    private function one(Import $import): ImportResource
    {
        $resource = new ImportResource($import->refresh()->load(['uploader', 'decider']));
        $resource->withColumns = true;

        return $resource;
    }
}
