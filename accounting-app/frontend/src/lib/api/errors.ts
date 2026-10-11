/**
 * Normalised error thrown by the API client for every failure mode.
 *
 * `status === 0` is used for failures that never produced an HTTP response:
 * missing configuration, network failure, or an aborted request.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly errors?: Record<string, string[]>;
  readonly requestId?: string;
  readonly isNetworkError: boolean;
  readonly isAborted: boolean;

  constructor(options: {
    status: number;
    message: string;
    errors?: Record<string, string[]>;
    requestId?: string;
    isNetworkError?: boolean;
    isAborted?: boolean;
  }) {
    super(options.message);
    this.name = "ApiError";
    this.status = options.status;
    this.errors = options.errors;
    this.requestId = options.requestId;
    this.isNetworkError = options.isNetworkError ?? false;
    this.isAborted = options.isAborted ?? false;
  }

  /** True for 401 — the caller must re-authenticate. */
  get isUnauthorized(): boolean {
    return this.status === 401;
  }

  /** True for 403 — authenticated but not permitted. */
  get isForbidden(): boolean {
    return this.status === 403;
  }

  /** True for 422 — validation failures, inspect `errors`. */
  get isValidation(): boolean {
    return this.status === 422;
  }
}
