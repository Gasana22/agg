<?php

namespace App\Modules\Media\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Stores uploads under farms/{farm}/{sha256}. The client sends the checksum
 * it computed; a mismatch means a damaged upload and is refused. A second
 * upload of the same bytes returns the first media row (docs/08 §3).
 */
class MediaStore
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FarmPermissions $permissions,
    ) {}

    /** @return array{0: Media, 1: bool} the media and whether it was created now */
    public function store(UploadedFile $file, ?string $claimedSha256): array
    {
        $sha = hash_file('sha256', $file->getRealPath());
        if ($claimedSha256 !== null && ! hash_equals(strtolower($claimedSha256), $sha)) {
            throw ApiException::unprocessable('checksum_mismatch', 'The file does not match its checksum. Upload it again.');
        }
        if ($existing = Media::where('sha256', $sha)->first()) {
            return [$existing, false];
        }

        $disk = config('sfmtp.media.disk');
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $path = 'farms/'.$this->context->farmId().'/'.substr($sha, 0, 2).'/'.$sha;
        if (! Storage::disk($disk)->put($path, $file->getContent())) {
            throw new ApiException(503, 'storage_unavailable', 'The file could not be stored. Try again later.');
        }

        $media = Media::create([
            'sha256' => $sha,
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200) ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        return [$media, true];
    }

    /** The uploader, and members who review workers' photos, may open a file. */
    public function assertCanView(Media $media): void
    {
        if ($media->uploaded_by === Auth::id()) {
            return;
        }
        foreach (['worker.gps.view', 'tasks.verify', 'attendance.approve'] as $permission) {
            if ($this->permissions->allows($permission)) {
                return;
            }
        }
        throw ApiException::notFound();
    }

    public function contents(Media $media): ?string
    {
        return Storage::disk($media->disk)->get($media->path);
    }
}
