/**
 * Shared "session expired" signal for browser API clients.
 *
 * Both the BFF session client (`lib/auth/client`) and the business proxy client
 * (`lib/api/backend`) report a `401` here, so any authenticated call that loses
 * its session triggers one consistent reaction: drop in-memory state and return
 * to login. Keeping the registry in its own module prevents the two clients from
 * drifting on when they notify.
 */

type UnauthorizedHandler = () => void;

const unauthorizedHandlers = new Set<UnauthorizedHandler>();

/**
 * Register a callback fired when an authenticated call returns 401.
 * Returns an unsubscribe function.
 */
export function onUnauthorized(handler: UnauthorizedHandler): () => void {
  unauthorizedHandlers.add(handler);
  return () => {
    unauthorizedHandlers.delete(handler);
  };
}

/** Notify every registered handler that the session is no longer valid. */
export function notifyUnauthorized(): void {
  for (const handler of unauthorizedHandlers) {
    handler();
  }
}
