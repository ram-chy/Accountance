import { describe, expect, it } from "vitest";

import {
  buildUpstreamHeaders,
  buildUpstreamUrl,
  collectClientHeaders,
  filterResponseHeaders,
  validateProxyPath,
} from "./proxy-policy";

describe("validateProxyPath", () => {
  it("accepts a company-scoped family and flags it for company context", () => {
    const result = validateProxyPath(["sales-invoices", "12"]);

    expect(result).toEqual({
      ok: true,
      segments: ["sales-invoices", "12"],
      family: "sales-invoices",
      requiresCompany: true,
    });
  });

  it("accepts a global authenticated family without company context", () => {
    const result = validateProxyPath(["companies"]);

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.requiresCompany).toBe(false);
    }
  });

  it("rejects unknown families (including auth and health)", () => {
    for (const path of [["auth", "me"], ["health"], ["password", "reset"]]) {
      expect(validateProxyPath(path).ok).toBe(false);
    }
  });

  it("rejects the raw company switch endpoint", () => {
    const result = validateProxyPath(["companies", "5", "switch"]);
    expect(result.ok).toBe(false);
  });

  it.each([
    [["accounts", "..", "secrets"]],
    [["accounts", "."]],
    [["accounts", "%2e%2e"]],
    [["accounts", "a\\b"]],
    [["accounts", ""]],
    [[]],
    [undefined],
  ])("rejects traversal or malformed segments: %j", (path) => {
    expect(validateProxyPath(path as string[] | undefined).ok).toBe(false);
  });

  it("rejects a path with too many segments", () => {
    expect(validateProxyPath(Array(13).fill("accounts")).ok).toBe(false);
  });

  it("returns a 404 for every rejection so probing reveals nothing", () => {
    const result = validateProxyPath(["auth", "me"]);

    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.status).toBe(404);
    }
  });
});

describe("buildUpstreamUrl", () => {
  it("joins the base, encoded segments and the query", () => {
    expect(
      buildUpstreamUrl(
        "https://api.test/api",
        ["sales-invoices", "12", "lines"],
        "?page=2",
      ),
    ).toBe("https://api.test/api/sales-invoices/12/lines?page=2");
  });

  it("omits the query when there is none", () => {
    expect(buildUpstreamUrl("https://api.test/api", ["accounts"], "")).toBe(
      "https://api.test/api/accounts",
    );
  });
});

describe("collectClientHeaders", () => {
  it("forwards only accept, content-type and a valid request id", () => {
    const headers = new Headers({
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Request-Id": "abc-123",
      Authorization: "Bearer attacker",
      Cookie: "accountance_session=stolen",
      "X-Company-Id": "999",
    });

    expect(collectClientHeaders(headers)).toEqual({
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Request-Id": "abc-123",
    });
  });

  it("drops a malformed request id", () => {
    const headers = new Headers({ "X-Request-Id": "bad id!" });
    expect(collectClientHeaders(headers)["X-Request-Id"]).toBeUndefined();
  });
});

describe("buildUpstreamHeaders", () => {
  it("attaches the bearer token and the server-derived company id", () => {
    const headers = buildUpstreamHeaders({
      token: "jwt-token",
      companyId: 7,
      clientHeaders: { Accept: "application/json" },
    });

    expect(headers.Authorization).toBe("Bearer jwt-token");
    expect(headers["X-Company-Id"]).toBe("7");
  });

  it("cannot be overridden by forwarded client headers", () => {
    const headers = buildUpstreamHeaders({
      token: "jwt-token",
      companyId: 7,
      clientHeaders: {
        Authorization: "Bearer attacker",
        "X-Company-Id": "999",
      },
    });

    expect(headers.Authorization).toBe("Bearer jwt-token");
    expect(headers["X-Company-Id"]).toBe("7");
  });

  it("omits X-Company-Id when the route is not company-scoped", () => {
    const headers = buildUpstreamHeaders({
      token: "jwt-token",
      clientHeaders: {},
    });

    expect(headers["X-Company-Id"]).toBeUndefined();
  });
});

describe("filterResponseHeaders", () => {
  it("keeps only the content type and correlation id and disables caching", () => {
    const upstream = new Headers({
      "Content-Type": "application/json",
      "X-Request-Id": "req-1",
      "Set-Cookie": "evil=1",
      Location: "https://evil.test",
    });

    const headers = filterResponseHeaders(upstream);

    expect(headers.get("Content-Type")).toBe("application/json");
    expect(headers.get("X-Request-Id")).toBe("req-1");
    expect(headers.get("Cache-Control")).toBe("no-store");
    expect(headers.get("Set-Cookie")).toBeNull();
    expect(headers.get("Location")).toBeNull();
  });
});
