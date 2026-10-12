"use client";

import { useRouter } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import { ApiError } from "@/lib/api/errors";
import {
  fetchSession,
  loginSession,
  logoutSession,
  onUnauthorized,
  switchCompany as switchCompanyRequest,
} from "@/lib/auth/client";
import type {
  SessionCompany,
  SessionSnapshot,
  SessionUser,
} from "@/lib/auth/types";
import { collectPermissions } from "@/lib/permissions/permissions";

/**
 * Client authentication context.
 *
 * Holds the session snapshot fetched from `/api/session/me` (never the JWT —
 * that stays in the httpOnly cookie) and exposes login/logout/company-switch to
 * the UI. On any 401 from an authenticated BFF call the state is dropped and the
 * user is sent to the login screen with a `next` back to where they were.
 */

type AuthStatus = "loading" | "authenticated" | "unauthenticated";

type AuthContextValue = {
  status: AuthStatus;
  user: SessionUser | null;
  companies: SessionCompany[];
  activeCompany: SessionCompany | null;
  permissions: ReadonlySet<string>;
  /** Set when the initial session load failed for a non-auth reason. */
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  switchCompany: (companyId: number) => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const [snapshot, setSnapshot] = useState<SessionSnapshot | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");
  const [error, setError] = useState<string | null>(null);

  const applySnapshot = useCallback((next: SessionSnapshot) => {
    setSnapshot(next);
    setError(null);
    setStatus("authenticated");
  }, []);

  const clearSessionState = useCallback(() => {
    setSnapshot(null);
    setStatus("unauthenticated");
  }, []);

  const refresh = useCallback(async () => {
    try {
      applySnapshot(await fetchSession());
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        clearSessionState();
        return;
      }

      setError(
        caught instanceof ApiError
          ? caught.message
          : "Could not load your session.",
      );
      setStatus("unauthenticated");
    }
  }, [applySnapshot, clearSessionState]);

  useEffect(() => {
    const unsubscribe = onUnauthorized(() => {
      clearSessionState();
      const current = `${window.location.pathname}${window.location.search}`;
      const target =
        current && current !== "/login"
          ? `/login?next=${encodeURIComponent(current)}`
          : "/login";
      router.replace(target);
    });

    fetchSession().then(applySnapshot).catch((caught: unknown) => {
      if (caught instanceof ApiError && caught.status === 401) {
        clearSessionState();
        return;
      }

      setError(
        caught instanceof ApiError
          ? caught.message
          : "Could not load your session.",
      );
      setStatus("unauthenticated");
    });

    return () => unsubscribe();
  }, [applySnapshot, clearSessionState, router]);

  const login = useCallback(
    async (email: string, password: string) => {
      applySnapshot(await loginSession(email, password));
      router.refresh();
    },
    [applySnapshot, router],
  );

  const logout = useCallback(async () => {
    try {
      await logoutSession();
    } finally {
      clearSessionState();
      router.replace("/login");
      router.refresh();
    }
  }, [clearSessionState, router]);

  const switchCompany = useCallback(
    async (companyId: number) => {
      const company = await switchCompanyRequest(companyId);

      setSnapshot((current) => {
        if (!current) {
          return current;
        }

        return {
          ...current,
          activeCompanyId: company.id,
          companies: current.companies.map((item) =>
            item.id === company.id ? company : { ...item, is_default: false },
          ),
        };
      });

      // Company settings can change what server components render.
      router.refresh();
    },
    [router],
  );

  const permissions = useMemo(
    () => collectPermissions(snapshot?.user),
    [snapshot?.user],
  );

  const activeCompany = useMemo(() => {
    if (!snapshot) {
      return null;
    }

    return (
      snapshot.companies.find(
        (company) => company.id === snapshot.activeCompanyId,
      ) ?? null
    );
  }, [snapshot]);

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      user: snapshot?.user ?? null,
      companies: snapshot?.companies ?? [],
      activeCompany,
      permissions,
      error,
      login,
      logout,
      switchCompany,
      refresh,
    }),
    [
      status,
      snapshot?.user,
      snapshot?.companies,
      activeCompany,
      permissions,
      error,
      login,
      logout,
      switchCompany,
      refresh,
    ],
  );

  return (
    <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (context === null) {
    throw new Error("useAuth must be used within an AuthProvider.");
  }

  return context;
}