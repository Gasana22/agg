<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\PublicSigner;
use App\Modules\Traceability\Application\Publishing;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public QR scan (docs/07 §5): no sign-in, rate limited per IP, only the
 * approved fields, signed. The code is looked up across farms (codes are
 * globally unique), then everything runs in that farm's context.
 */
class PublicTraceController
{
    public function show(Request $request, string $code, TenantContext $context, Publishing $publishing, PublicSigner $signer): JsonResponse
    {
        $code = Publishing::normalise($code);
        if (! preg_match('/^[0-9A-Z]{'.Publishing::CODE_LENGTH.'}$/', $code)) {
            throw ApiException::notFound();
        }
        $found = $context->bypass(fn () => TraceQrCode::withoutGlobalScopes()->where('code', $code)->first(['id', 'farm_id']));
        $farm = $found ? Farm::find($found->farm_id) : null;
        if ($farm === null) {
            throw ApiException::notFound();
        }

        $data = $context->run($farm, function () use ($found, $publishing, $request) {
            $qr = TraceQrCode::with('batch')->findOrFail($found->id);
            $publishing->countScan($qr, $request->header('CF-IPCountry') ?? $request->header('X-Country'));

            return $publishing->publicView($qr);
        });

        return new JsonResponse(['data' => $data, 'signature' => $signer->sign($data)], 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function keys(PublicSigner $signer): JsonResponse
    {
        return new JsonResponse(['data' => [$signer->publicKey()]], 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
