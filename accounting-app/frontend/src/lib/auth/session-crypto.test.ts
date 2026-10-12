// @vitest-environment node

import { describe, expect, it } from "vitest";

import {
  decryptSessionPayload,
  encryptSessionPayload,
  isSessionPayload,
} from "./session-crypto";

const SECRET = "test-secret-0123456789";

function basePayload() {
  return {
    token: "header.payload.signature",
    companyId: 7,
    expiresAt: Date.now() + 60_000,
  };
}

describe("session-crypto", () => {
  it("round-trips a payload through the cookie format", async () => {
    const payload = basePayload();
    const sealed = await encryptSessionPayload(payload, SECRET);

    expect(sealed).toMatch(/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/);

    await expect(decryptSessionPayload(sealed, SECRET)).resolves.toEqual(payload);
  });

  it("uses a fresh IV on every seal (no ciphertext reuse)", async () => {
    const payload = basePayload();
    const first = await encryptSessionPayload(payload, SECRET);
    const second = await encryptSessionPayload(payload, SECRET);

    expect(first).not.toBe(second);
  });

  it("returns null for tampered ciphertext", async () => {
    const sealed = await encryptSessionPayload(basePayload(), SECRET);
    const [iv, ciphertext] = sealed.split(".");
    const last = ciphertext.endsWith("A") ? "B" : "A";
    const tampered = `${iv}.${ciphertext.slice(0, -1)}${last}`;

    await expect(decryptSessionPayload(tampered, SECRET)).resolves.toBeNull();
  });

  it("returns null when decrypted with the wrong secret", async () => {
    const sealed = await encryptSessionPayload(basePayload(), SECRET);

    await expect(
      decryptSessionPayload(sealed, "a-different-secret-00000000"),
    ).resolves.toBeNull();
  });

  it("returns null for garbage and empty values", async () => {
    await expect(decryptSessionPayload(undefined, SECRET)).resolves.toBeNull();
    await expect(decryptSessionPayload("", SECRET)).resolves.toBeNull();
    await expect(decryptSessionPayload("not-a-cookie", SECRET)).resolves.toBeNull();
  });

  it("does not validate expiry itself (delegated to the session layer)", async () => {
    const expired = { ...basePayload(), expiresAt: Date.now() - 1000 };
    const sealed = await encryptSessionPayload(expired, SECRET);

    await expect(decryptSessionPayload(sealed, SECRET)).resolves.toEqual(expired);
  });

  it("validates payload shape", () => {
    expect(isSessionPayload(basePayload())).toBe(true);
    expect(isSessionPayload({ ...basePayload(), companyId: undefined })).toBe(true);
    expect(isSessionPayload({ token: "", expiresAt: 1 })).toBe(false);
    expect(isSessionPayload({ token: "x", expiresAt: "soon" })).toBe(false);
    expect(isSessionPayload({ token: "x", expiresAt: 1, companyId: 1.5 })).toBe(false);
    expect(isSessionPayload(null)).toBe(false);
  });
});