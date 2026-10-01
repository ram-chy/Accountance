<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Resolves which company the current request is acting within.
 *
 * Resolution order:
 *  1. the X-Company-Id header, when present
 *  2. the user's default company
 *
 * The header is a hint, never an authorisation. Every resolved company is
 * checked for membership and active status here, so a caller cannot reach a
 * company merely by putting someone else's id in the header.
 *
 * The service is bound with scoped(), so each request/cycle gets its own
 * instance. The request itself is read from the container on demand rather than
 * injected in the constructor, because a scoped instance can be built before
 * the current request is bound. The resolved company is memoised against the
 * identity of the request it was resolved from, so a cached value can never be
 * carried into a different request.
 */
class CompanyContext
{
    public const HEADER = 'X-Company-Id';

    private ?Company $resolved = null;

    private ?Request $resolvedFor = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The active company for this request, or null when the user has none.
     */
    public function get(): ?Company
    {
        $request = $this->request();

        if ($this->resolvedFor === $request) {
            return $this->resolved;
        }

        $user = $this->user($request);

        $this->resolvedFor = $request;
        $this->resolved = null;

        if (! $user) {
            return null;
        }

        $requested = $this->requestedId($request);

        if ($requested !== null) {
            return $this->resolved = $this->companies()->findSelectableFor($user, $requested);
        }

        return $this->resolved = $user->defaultCompany();
    }

    /**
     * The active company, or a failure if the user has none.
     *
     * @throws \RuntimeException when no company context can be established
     */
    public function getOrFail(): Company
    {
        $company = $this->get();

        if (! $company) {
            throw new \RuntimeException('No company context is available for this request.');
        }

        return $company;
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    /**
     * The company id supplied by the client, or null when absent or unusable.
     *
     * Deliberately uses headers->get(), which returns the first value when a
     * header is repeated. Symfony's HeaderUtils::combine() is a different thing
     * entirely: it parses a list of "name=value" strings into an assoc array, so
     * it turns "42" into ['4' => '2'] and silently corrupts the value. The
     * locale-independent regex also rejects signs, spaces and non-ASCII digits
     * that ctype_digit() can be lenient about.
     */
    public function requestedId(): ?int
    {
        $raw = $this->request()->headers->get(self::HEADER);

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function companies(): CompanyService
    {
        return $this->container->make(CompanyService::class);
    }

    private function request(): Request
    {
        return $this->container->make('request');
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
