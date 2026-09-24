<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Contracts\PortalSubject;
use App\Support\Http\ApiException;

/** The registry of linkable farm records, by kind. */
class PortalSubjects
{
    /** @var array<string, PortalSubject>|null */
    private ?array $subjects = null;

    public function get(string $kind): PortalSubject
    {
        return $this->all()[$kind] ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['kind' => ['Choose supplier or customer.']]);
    }

    /** @return array<string, PortalSubject> */
    public function all(): array
    {
        if ($this->subjects === null) {
            $this->subjects = [];
            foreach (app()->tagged('sfmtp.portal-subjects') as $subject) {
                /** @var PortalSubject $subject */
                $this->subjects[$subject->kind()] = $subject;
            }
        }

        return $this->subjects;
    }
}
