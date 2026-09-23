<?php

namespace App\Support\Http;

use App\Support\Database\AppendOnlyViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API error as RFC 9457 application/problem+json
 * (docs/06-api-contracts.md §1).
 */
class ProblemRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $title, $extra, $headers] = $this->classify($e);

        $body = array_merge([
            'type' => 'https://docs.sfmtp.app/errors/'.str_replace('_', '-', $code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
        ], $extra, [
            'request_id' => $request->attributes->get('request_id'),
        ]);

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse($body, $status, array_merge(
            ['Content-Type' => 'application/problem+json'],
            $headers,
        ));
    }

    /** @return array{0:int,1:string,2:string,3:array,4:array} */
    private function classify(Throwable $e): array
    {
        if ($e instanceof QueryException && AppendOnlyViolation::matches($e)) {
            $e = new AppendOnlyViolation;
        }

        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->extra, []],
            $e instanceof AppendOnlyViolation => [405, 'append_only', 'This record is part of an append-only history and cannot be changed or deleted. Record a correction instead.', [], []],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', ['errors' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.', [], []],
            $e instanceof AuthorizationException => [403, 'forbidden', 'This action is not allowed.', [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'The requested resource was not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'This method is not allowed for this resource.', [], $e->getHeaders()],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests. Try again later.', [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'Request failed.', [], $e->getHeaders()],
            default => [500, 'internal_error', 'An unexpected error occurred.', [], []],
        };
    }
}
