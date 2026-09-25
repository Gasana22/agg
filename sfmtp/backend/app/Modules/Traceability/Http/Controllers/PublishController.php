<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\PublicPayload;
use App\Modules\Traceability\Application\Publishing;
use App\Modules\Traceability\Application\QrLabels;
use App\Modules\Traceability\Domain\Models\TraceApproval;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Modules\Traceability\Http\Resources\TraceQrCodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Approvals of public fields, QR codes, labels and scan counts (docs/06 §4). */
class PublishController
{
    public function __construct(private readonly Publishing $publishing) {}

    public function approvals(string $farm, TraceBatch $batch): JsonResponse
    {
        $list = TraceApproval::with('approver:id,name')->where('batch_id', $batch->id)->orderByDesc('approved_at')->orderByDesc('id')->get();

        return new JsonResponse([
            'data' => $list->values()->map(fn (TraceApproval $a, int $i) => $this->approval($a, $i === 0))->all(),
            'meta' => ['fields' => $this->catalogue(), 'default_fields' => PublicPayload::DEFAULT_FIELDS],
        ]);
    }

    public function preview(Request $request, string $farm, TraceBatch $batch, PublicPayload $payloads): JsonResponse
    {
        $data = $request->validate(['fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string', Rule::in(array_keys(PublicPayload::FIELDS))]]);

        return new JsonResponse(['data' => (object) $payloads->build($batch, $data['fields'])]);
    }

    public function approve(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'public_fields' => ['required', 'array', 'min:1'],
            'public_fields.*' => ['string', 'distinct'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $approval = $this->publishing->approve($batch, $data['public_fields'], $data['note'] ?? null);

        return new JsonResponse(['data' => $this->approval($approval->load('approver:id,name'), true)], 201);
    }

    public function batchCodes(string $farm, TraceBatch $batch): AnonymousResourceCollection
    {
        return TraceQrCodeResource::collection(TraceQrCode::with('batch')->where('batch_id', $batch->id)->orderByDesc('issued_at')->orderByDesc('id')->get());
    }

    public function issue(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:120']]);

        return (new TraceQrCodeResource($this->publishing->issue($batch, $data['label'] ?? null)->load('batch')))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(['active', 'revoked'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return TraceQrCodeResource::collection(TraceQrCode::with('batch')
            ->when($data['filter']['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25)));
    }

    public function show(string $farm, TraceQrCode $qrCode): JsonResponse
    {
        return (new TraceQrCodeResource($qrCode->load('batch')))->additional(['meta' => ['scans' => $this->publishing->scanStats(30, $qrCode->id)]])->response();
    }

    public function revoke(Request $request, string $farm, TraceQrCode $qrCode): TraceQrCodeResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new TraceQrCodeResource($this->publishing->revoke($qrCode, $data['reason'])->load('batch'));
    }

    public function image(string $farm, TraceQrCode $qrCode, QrLabels $labels): Response
    {
        return new Response($labels->svg($qrCode), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function labels(Request $request, string $farm, TraceQrCode $qrCode, QrLabels $labels): Response
    {
        $data = $request->validate([
            'copies' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'template' => ['sometimes', Rule::in(array_keys(QrLabels::TEMPLATES))],
        ]);
        // Only what is public goes on the label.
        $pdf = $labels->forCodes([[$qrCode, (int) ($data['copies'] ?? 24)]], $data['template'] ?? 'a4_3x8');

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"labels-{$qrCode->code}.pdf\"",
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);

        return new JsonResponse(['data' => $this->publishing->scanStats((int) ($data['days'] ?? 30))]);
    }

    private function approval(TraceApproval $a, bool $current): array
    {
        return [
            'id' => $a->id,
            'type' => 'trace_approval',
            'public_fields' => $a->public_fields,
            'payload' => (object) array_intersect_key(array_replace(array_fill_keys(array_keys(PublicPayload::FIELDS), null), $a->payload), $a->payload),
            'note' => $a->note,
            'approved_by' => $a->approver ? ['id' => $a->approver->id, 'name' => $a->approver->name] : null,
            'approved_at' => $a->approved_at->toIso8601ZuluString(),
            'current' => $current,
        ];
    }

    /** @return array<int, array{key:string, description:string}> */
    private function catalogue(): array
    {
        return array_map(fn ($k, $d) => ['key' => $k, 'description' => $d], array_keys(PublicPayload::FIELDS), PublicPayload::FIELDS);
    }
}
