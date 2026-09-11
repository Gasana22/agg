<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Animal;
use App\Models\Asset;
use App\Models\CropSeason;
use App\Models\Document;
use App\Models\Farm;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Generic file attachments across a curated set of entities — farm legal
 * documents, asset manuals/invoices, animal vet certificates, crop season
 * photos/reports, purchase order receipts. Not every model gets this; the
 * TYPES map below is the deliberately-chosen set, mirroring how
 * TraceBatchController resolves a friendly source_type alias to a real
 * Eloquent class rather than accepting a raw FQCN over the API.
 */
class DocumentController extends Controller
{
    private const TYPES = [
        'farm' => Farm::class,
        'asset' => Asset::class,
        'animal' => Animal::class,
        'crop_season' => CropSeason::class,
        'purchase_order' => PurchaseOrder::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'documentable_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'documentable_id' => ['required', 'integer'],
        ]);

        $documentable = self::TYPES[$validated['documentable_type']]::findOrFail($validated['documentable_id']);
        $farm = $documentable instanceof Farm ? $documentable : $documentable->farm;

        $this->authorize('viewAny', [Document::class, $farm]);

        $documents = Document::where('documentable_type', $documentable::class)
            ->where('documentable_id', $documentable->id)
            ->with('uploader')
            ->latest()
            ->get();

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request): DocumentResource
    {
        $documentable = self::TYPES[$request->validated('documentable_type')]::find($request->validated('documentable_id'));

        if (! $documentable) {
            throw ValidationException::withMessages([
                'documentable_id' => 'No matching record was found.',
            ]);
        }

        $farm = $documentable instanceof Farm ? $documentable : $documentable->farm;

        $this->authorize('create', [Document::class, $farm]);

        $file = $request->file('file');
        $path = $file->store('documents', 'public');

        $document = Document::create([
            'farm_id' => $farm->id,
            'documentable_type' => $documentable::class,
            'documentable_id' => $documentable->id,
            'category' => $request->validated('category'),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'description' => $request->validated('description'),
            'uploaded_by' => $request->user()->id,
        ]);

        return new DocumentResource($document->load('uploader'));
    }

    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource($document->load('uploader'));
    }

    public function destroy(Document $document): Response
    {
        $this->authorize('delete', $document);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
