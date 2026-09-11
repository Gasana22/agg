<?php

namespace App\Http\Controllers\Api\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicTraceBatchResource;
use App\Models\TraceBatch;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated endpoints — this is what a QR code printed on
 * packaging leads a shopper to. No farm membership required to look up a
 * batch or render its QR code.
 */
class TraceabilityController extends Controller
{
    public function show(string $code): PublicTraceBatchResource
    {
        $batch = TraceBatch::where('code', strtoupper($code))
            ->with(['farm', 'traceable', 'events' => fn ($q) => $q->orderBy('date')])
            ->firstOrFail();

        return new PublicTraceBatchResource($batch);
    }

    public function qr(string $code): Response
    {
        $batch = TraceBatch::where('code', strtoupper($code))->firstOrFail();

        $url = rtrim(config('app.frontend_url'), '/').'/trace/'.$batch->code;

        $result = (new Builder(
            writer: new PngWriter,
            data: $url,
            size: 300,
            margin: 10,
        ))->build();

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
        ]);
    }
}
