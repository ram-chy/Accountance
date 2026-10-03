<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every HTTP request a correlation identifier.
 *
 * WHY A REQUEST ID AT ALL
 *
 * A single user action - posting an invoice, closing a period - can write several
 * audit rows, and a single HTTP request can perform several actions. Without a
 * shared identifier there is no way to group "everything this one request did"
 * after the fact, which is exactly the question asked when reconstructing what
 * happened. The id is stored on the audit row and returned in the response
 * header, so a caller reporting a problem can quote the id that ties their
 * request to the rows it produced.
 *
 * WHY THE CLIENT HEADER IS VALIDATED RATHER THAN TRUSTED
 *
 * Accepting an arbitrary client-supplied string would let a caller choose an id
 * that collides with another request's, defeating the grouping. Only a
 * conservative token is accepted (letters, digits, hyphen, bounded length); a
 * header that does not fit is ignored and a fresh UUID is generated instead. A
 * malformed header is never an error - the request is not about the header, and
 * refusing to serve it over a diagnostic aid would be disproportionate.
 *
 * RUNS GLOBALLY
 *
 * It is registered on the global stack rather than the api group: the id is
 * useful for every response (including unauthenticated failures), and the api
 * group is not pre-populated in this application's bootstrap. It must not depend
 * on anything other middleware sets.
 */
class AssignRequestId
{
    /**
     * The request header a caller may use to supply an id, and the response
     * header the resolved id is echoed on. One constant each so the middleware
     * and any reader of the contract agree on the spelling.
     */
    public const HEADER = 'X-Request-Id';

    /**
     * The request attribute the id is stashed under, so downstream code (the
     * audit service) can read it without reaching into headers - which a console
     * command does not have.
     */
    public const ATTRIBUTE = 'request_id';

    /**
     * Letters, digits and hyphens, 1-64 characters. Deliberately strict: a
     * correlation id only has to be unique, not expressive.
     */
    private const PATTERN = '/^[A-Za-z0-9\-]{1,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER);

        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        /*
         * Stored on the request attributes rather than the input bag: attributes
         * are server-side only and setting an attribute never risks a request
         * with a real field called request_id having its input overwritten.
         */
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
