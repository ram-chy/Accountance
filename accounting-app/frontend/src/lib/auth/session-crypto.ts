/**
 * Web Crypto helpers for the encrypted BFF session cookie.
 *
 * The Laravel JWT is never sent to the browser. It is sealed inside an
 * `HttpOnly` cookie whose value is AES-256-GCM ciphertext, so a stolen cookie
 * value is useless without the server-side key and cannot be read by client
 * JavaScript.
 *
 * Implemented against `globalThis.crypto.subtle` (available in the Node.js and
 * Edge runtimes alike) so the exact same code can validate the cookie in
 * `proxy.ts`, in route handlers, and in server components. The hash/AES
 * primitives, not the encoding helpers, are what enforce confidentiality and
 * integrity.
 *
 * Format: `base64url(iv) . base64url(ciphertext+tag)`. A fresh 96-bit IV is
 * generated per seal; AES-GCM authenticates the ciphertext, so tampering is
 * detected on open rather than silently decrypted to garbage.
 */

export type SessionPayload = {
  /** The Laravel bearer token. */
  token: string;
  /** The active company id, stored server-side (not secret). */
  companyId?: number;
  /** Absolute epoch-milliseconds expiry, derived from Laravel's `expires_in`. */
  expiresAt: number;
};

const encoder = new TextEncoder();
const decoder = new TextDecoder();

const IV_BYTES = 12;
const KEY_CACHE = new Map<string, Promise<CryptoKey>>();

/** Fixed, non-secret salt; the key still depends on the operator's secret. */
const KEY_SALT = "accountance.session.v1";

function toBase64Url(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const normalised = value.replace(/-/g, "+").replace(/_/g, "/");
  const remainder = normalised.length % 4;
  const padded =
    remainder === 0 ? normalised : normalised + "=".repeat(4 - remainder);
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length) as Uint8Array<ArrayBuffer>;
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

/** UTF-8 encode to a standalone `ArrayBuffer` (BufferSource-compatible). */
function encodeText(value: string): ArrayBuffer {
  return encoder.encode(value).buffer as ArrayBuffer;
}

async function deriveKey(secret: string): Promise<CryptoKey> {
  const cached = KEY_CACHE.get(secret);
  if (cached) {
    return cached;
  }

  const derived = (async () => {
    // SHA-256 over salt||secret yields a 256-bit AES key. The secret is expected
    // to be a long random string (documented in `.env.example`), which is what
    // makes this a key rather than a password.
    const material = await crypto.subtle.digest(
      "SHA-256",
      encodeText(`${KEY_SALT}:${secret}`),
    );
    return crypto.subtle.importKey("raw", material, { name: "AES-GCM" }, false, [
      "encrypt",
      "decrypt",
    ]);
  })();

  KEY_CACHE.set(secret, derived);
  return derived;
}

/**
 * Resolve the cookie encryption secret.
 *
 * In production a missing or too-short secret is a hard configuration error:
 * failing closed is the only safe behaviour for the component that protects the
 * JWT. Development gets a fixed fallback so a fresh checkout runs without
 * ceremony; production never falls back.
 */
export function resolveSessionSecret(): string {
  const secret = process.env.SESSION_COOKIE_SECRET?.trim();

  if (secret && secret.length >= 16) {
    return secret;
  }

  if (process.env.NODE_ENV !== "production") {
    return "accountance-development-only-session-secret";
  }

  throw new Error(
    "SESSION_COOKIE_SECRET must be set (minimum 16 characters) in production.",
  );
}

export async function encryptSessionPayload(
  payload: SessionPayload,
  secret: string = resolveSessionSecret(),
): Promise<string> {
  const key = await deriveKey(secret);
  const iv = crypto.getRandomValues(
    new Uint8Array(IV_BYTES),
  ) as Uint8Array<ArrayBuffer>;
  const ciphertext = await crypto.subtle.encrypt(
    { name: "AES-GCM", iv },
    key,
    encodeText(JSON.stringify(payload)),
  );

  return `${toBase64Url(iv)}.${toBase64Url(new Uint8Array(ciphertext))}`;
}

export function isSessionPayload(value: unknown): value is SessionPayload {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const candidate = value as Record<string, unknown>;

  return (
    typeof candidate.token === "string" &&
    candidate.token.length > 0 &&
    typeof candidate.expiresAt === "number" &&
    Number.isFinite(candidate.expiresAt) &&
    (candidate.companyId === undefined || Number.isInteger(candidate.companyId))
  );
}

/** Decrypt and validate a cookie value, or return null when unusable. */
export async function decryptSessionPayload(
  value: string | undefined,
  secret: string = resolveSessionSecret(),
): Promise<SessionPayload | null> {
  if (!value) {
    return null;
  }

  const separator = value.indexOf(".");

  if (separator <= 0 || separator === value.length - 1) {
    return null;
  }

  try {
    const key = await deriveKey(secret);
    const iv = fromBase64Url(value.slice(0, separator));
    const ciphertext = fromBase64Url(value.slice(separator + 1));
    const plaintext = await crypto.subtle.decrypt(
      { name: "AES-GCM", iv },
      key,
      ciphertext,
    );
    const parsed: unknown = JSON.parse(decoder.decode(plaintext));

    return isSessionPayload(parsed) ? parsed : null;
  } catch {
    // Any failure (bad key, tampered ciphertext, malformed JSON) is treated as
    // "no session" rather than an error the caller must distinguish.
    return null;
  }
}
