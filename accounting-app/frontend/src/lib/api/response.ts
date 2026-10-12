import { NextResponse } from "next/server";

/**
 * BFF response envelope.
 *
 * The same shape Laravel uses (`{ success, message, data? }` /
 * `{ success:false, message, errors? }`) is reused so the browser can parse BFF
 * and Laravel errors through one code path (`ApiError`).
 */

export function jsonSuccess(
  data?: unknown,
  message = "Operation completed successfully.",
  init?: ResponseInit,
): NextResponse {
  return NextResponse.json(
    {
      success: true,
      message,
      ...(data !== undefined ? { data } : {}),
    },
    init,
  );
}

export function jsonFailure(
  message: string,
  status: number,
  errors?: Record<string, string[]>,
): NextResponse {
  return NextResponse.json(
    {
      success: false,
      message,
      ...(errors ? { errors } : {}),
    },
    { status },
  );
}
