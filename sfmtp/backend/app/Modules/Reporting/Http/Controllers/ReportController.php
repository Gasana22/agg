<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\Exports;
use App\Modules\Reporting\Application\StandardReports;
use App\Modules\Reporting\Domain\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Standard reports and their queued exports (ADR-0017). */
class ReportController
{
    public function __construct(
        private readonly StandardReports $reports,
        private readonly Exports $exports,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->list()]);
    }

    public function show(Request $request, string $farm, string $report): JsonResponse
    {
        return new JsonResponse(['data' => $this->reports->run($report, $request->query())]);
    }

    public function exports(): JsonResponse
    {
        return new JsonResponse(['data' => $this->exports->mine()->map->toApi()->values()]);
    }

    public function export(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->exports->request($request->all())->refresh()->toApi()], 202);
    }

    public function showExport(string $farm, string $exportId): JsonResponse
    {
        return new JsonResponse(['data' => $this->exports->find($exportId)->toApi()]);
    }

    public function download(string $farm, string $exportId): StreamedResponse
    {
        $record = $this->exports->find($exportId);
        [$path, $name] = $this->exports->file($record);

        return Storage::disk(config('sfmtp.exports.disk'))->download($path, $name, [
            'Content-Type' => ReportExport::MIME[$record->format],
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
