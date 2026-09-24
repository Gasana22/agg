<?php

namespace App\Modules\Workforce\Application;

use App\Support\Http\ApiException;

/**
 * A record points at a photo the server does not have (yet). Online this is
 * a 422; the sync API defers the mutation until the upload arrives.
 */
class MediaNotReady extends ApiException
{
    public function __construct(public readonly string $mediaId)
    {
        parent::__construct(422, 'media_missing', 'The photo has not been uploaded yet.', ['media_id' => $mediaId]);
    }
}
