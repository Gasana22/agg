<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Application\MediaStore;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Media\Http\Resources\MediaResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MediaController
{
    public function __construct(private readonly MediaStore $store) {}

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('sfmtp.media.max_kb'), 'mimetypes:'.implode(',', config('sfmtp.media.mimes'))],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);
        [$media, $created] = $this->store->store($data['file'], $data['sha256'] ?? $request->header('X-Content-SHA256'));

        return (new MediaResource($media))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(string $farm, Media $media): MediaResource
    {
        $this->store->assertCanView($media);

        return new MediaResource($media);
    }

    public function content(string $farm, Media $media): Response
    {
        $this->store->assertCanView($media);
        $bytes = $this->store->contents($media) ?? throw ApiException::notFound();

        return response($bytes, 200, [
            'Content-Type' => $media->mime,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, max-age=86400, immutable',
            'ETag' => '"'.$media->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
