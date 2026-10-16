import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createUser,
  deactivateUser,
  fetchUser,
  fetchUsers,
  updateUser,
} from "./api";

function mockJson(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );

  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

function firstRequest(fetchMock: ReturnType<typeof vi.fn>): {
  url: string;
  options: RequestInit;
} {
  const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
  return { url, options };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("user reads", () => {
  it("reads the index with per_page=100 and no filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchUsers();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/users?per_page=100");
  });

  it("maps search, role and active filters onto the query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchUsers({ search: "ada", role: "Admin", is_active: false });

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/users?per_page=100&search=ada&role=Admin&is_active=0",
    );
  });

  it("omits is_active when the tri-state filter is unset", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchUsers({ search: "ada" });

    expect(firstRequest(fetchMock).url).toBe("/api/backend/users?per_page=100&search=ada");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchUsers({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });

  it("fetches one user", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await fetchUser(7);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/users/7");
  });
});

describe("user writes", () => {
  it("creates a user with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createUser({
      first_name: "Ada",
      last_name: "Lovelace",
      email: "ada@example.test",
      mobile_no: null,
      password: "Str0ng!pass",
      roles: ["Admin"],
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/users");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      first_name: "Ada",
      last_name: "Lovelace",
      email: "ada@example.test",
      mobile_no: null,
      password: "Str0ng!pass",
      roles: ["Admin"],
    });
  });

  it("updates a user with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await updateUser(7, { first_name: "Ada", is_active: false });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/users/7");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ first_name: "Ada", is_active: false });
  });

  it("deactivates with a DELETE", async () => {
    const fetchMock = mockJson(200, { success: true, data: null });

    await deactivateUser(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/users/7");
    expect(options.method).toBe("DELETE");
  });
});
