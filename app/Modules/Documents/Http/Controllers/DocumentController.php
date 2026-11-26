<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Actions\DecideDocument;
use App\Modules\Documents\Actions\UploadDocument;
use App\Modules\Documents\Http\Requests\DocumentHistoryRequest;
use App\Modules\Documents\Http\Requests\DocumentIndexRequest;
use App\Modules\Documents\Http\Requests\DownloadDocumentRequest;
use App\Modules\Documents\Http\Requests\RemoveDocumentRequest;
use App\Modules\Documents\Http\Requests\UploadDocumentRequest;
use App\Modules\Documents\Http\Resources\DocumentKindResource;
use App\Modules\Documents\Http\Resources\DocumentResource;
use App\Modules\Documents\Http\Resources\DocumentSlotResource;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Documents\Support\DocumentKind;
use App\Modules\Documents\Support\DocumentSlot;
use App\Modules\Documents\Support\DocumentSlots;
use App\Modules\Documents\Support\DocumentStorage;
use App\Modules\Employees\Http\Requests\DecisionRequest;
use App\Modules\Employees\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    private const RELATIONS = ['employee', 'uploader', 'reviewer', 'remover'];

    /** The 13 attachments (in the field guide's settings) and the conduct record's document. */
    public function kinds(): AnonymousResourceCollection
    {
        return DocumentKindResource::collection(DocumentSlots::kinds());
    }

    /**
     * An employee's documents now: each of the 13 kinds with its current file (or null) and its uploads waiting for
     * review, and the conduct records' current documents.
     */
    public function employee(Employee $employee): array
    {
        $slots = DocumentSlots::of($employee->getKey());

        return [
            'data' => [
                /** Of the 13, how many have a current file. */
                'attached' => $slots['current']->count(),
                'total' => count(DocumentCatalogue::FILE_KINDS),
                'slots' => DocumentSlotResource::collection(array_map(fn (DocumentKind $kind) => new DocumentSlot(
                    $kind, $slots['current'][$kind->key] ?? null, ($slots['pending'][$kind->key] ?? collect())->values(),
                ), $slots['kinds'])),
                /** The conduct records' documents (current and waiting): each with its `conduct_record_id`. */
                'conduct' => DocumentResource::collection($slots['conduct']),
            ],
        ];
    }

    /** Every upload for the employee, newest first: the superseded, refused, withdrawn and removed ones too. */
    public function history(DocumentHistoryRequest $request, Employee $employee): AnonymousResourceCollection
    {
        $f = $request->validated();

        $documents = EmployeeDocument::query()
            ->with(self::RELATIONS)
            ->where('employee_id', $employee->getKey())
            ->when($f['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return DocumentResource::collection($documents);
    }

    /**
     * Upload a document (multipart/form-data). Data entry's waits for review; a reviewer's replaces the current file
     * at once. Refused when the type (read from the content) is not PDF or an image, or the content is the current
     * file's or a waiting upload's.
     */
    public function store(UploadDocumentRequest $request, Employee $employee, UploadDocument $action): DocumentResource
    {
        $v = $request->validated();
        $document = $action->handle($employee, $v['kind'], $request->file('file'), $v['conduct_record_id'] ?? null, $v['note'] ?? null, $request->user());

        return new DocumentResource($document->load(self::RELATIONS));
    }

    /** The documents' review queue (pending by default), oldest first. */
    public function index(DocumentIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $status = $f['status'] ?? 'pending';

        $documents = EmployeeDocument::query()
            ->with(self::RELATIONS)
            ->where('status', $status)
            ->when($f['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when(! empty($f['mine']), fn ($q) => $q->where('uploaded_by', $request->user()->id))
            ->orderBy($f['sort'] ?? ($status === 'pending' ? 'uploaded_at' : 'reviewed_at'), $f['direction'] ?? ($status === 'pending' ? 'asc' : 'desc'))
            ->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return DocumentResource::collection($documents);
    }

    public function show(EmployeeDocument $document): DocumentResource
    {
        return new DocumentResource($document->load(self::RELATIONS));
    }

    /**
     * The document's content, with the name it was uploaded with; `?inline=1` to show it in the browser. Every
     * download is in the log. 410 when the content is no longer kept (refused, withdrawn or removed).
     */
    public function download(DownloadDocumentRequest $request, EmployeeDocument $document): StreamedResponse
    {
        if (! $document->hasContent()) {
            abort(410, __('documents.content_gone', ['state' => EmployeeDocument::stateLabel($document->state())]));
        }

        if (! DocumentStorage::exists($document->disk, $document->path)) {
            report(new \RuntimeException("Document {$document->id}: its content is missing at {$document->disk}:{$document->path}"));
            abort(404, __('documents.content_missing'));
        }

        $inline = $request->boolean('inline');

        AuditTrail::record('documents.downloaded', $request->user(), [
            'document_id' => $document->getKey(),
            'employee_id' => $document->employee_id,
            'kind' => $document->kind,
            'inline' => $inline,
        ]);

        // Its own type only, never sniffed again by the browser; nothing it holds runs; not kept in caches.
        $headers = [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => $document->mime === 'application/pdf'
                ? "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'"
                : "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ];

        return Storage::disk($document->disk)->response($document->path, $document->original_name, $headers, $inline ? 'inline' : 'attachment');
    }

    /** Approve it: it becomes the current file of its kind; the one before it is kept as superseded. Not one's own. */
    public function approve(DecisionRequest $request, EmployeeDocument $document, DecideDocument $action): DocumentResource
    {
        return new DocumentResource($action->approve($document, $request->user(), $request->note())->load(self::RELATIONS));
    }

    /** Refuse it with a reason: back to its uploader; its content is not kept. */
    public function reject(DecisionRequest $request, EmployeeDocument $document, DecideDocument $action): DocumentResource
    {
        return new DocumentResource($action->reject($document, $request->user(), (string) $request->note())->load(self::RELATIONS));
    }

    /** Take back one's own waiting upload. */
    public function withdraw(Request $request, EmployeeDocument $document, DecideDocument $action): DocumentResource
    {
        return new DocumentResource($action->withdraw($document, $request->user())->load(self::RELATIONS));
    }

    /** The administrator takes a current document off the file, with a reason; its row stays, its content does not. */
    public function destroy(RemoveDocumentRequest $request, EmployeeDocument $document, DecideDocument $action): DocumentResource
    {
        if (! $request->user()->hasPermission('entries.review')) {
            throw new AuthorizationException(__('documents.removal_needs_review'));
        }

        return new DocumentResource($action->remove($document, $request->user(), $request->validated('reason'))->load(self::RELATIONS));
    }
}
