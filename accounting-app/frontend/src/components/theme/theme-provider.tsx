"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from "react";

import { THEME_STORAGE_KEY } from "@/lib/theme/constants";

export type Theme = "light" | "dark";

const listeners = new Set<() => void>();

function emit(): void {
  for (const listener of listeners) {
    listener();
  }
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function getSnapshot(): Theme {
  return document.documentElement.classList.contains("dark") ? "dark" : "light";
}

function getServerSnapshot(): Theme {
  return "light";
}

function applyTheme(theme: Theme): void {
  const root = document.documentElement;
  const isDark = theme === "dark";

  if (root.classList.contains("dark") !== isDark) {
    root.classList.toggle("dark", isDark);
  }
  if (root.style.colorScheme !== theme) {
    root.style.colorScheme = theme;
  }

  emit();
}

// Stable no-op subscription used only to detect that hydration has completed.
function subscribeToMounted(): () => void {
  return () => {};
}

/** False during SSR and the hydration render, true afterwards. */
function useMounted(): boolean {
  return useSyncExternalStore(
    subscribeToMounted,
    () => true,
    () => false,
  );
}

type ThemeContextValue = {
  /** The theme currently applied to <html>. */
  theme: Theme;
  /** False until hydration completes (avoids hydration mismatch). */
  mounted: boolean;
  setTheme: (theme: Theme) => void;
  toggleTheme: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

/**
 * Light/Dark theme provider.
 *
 * The initial class is applied before paint by the inline script in
 * `app/layout.tsx`. The resolved theme is read from the DOM through
 * `useSyncExternalStore`, so React stays in sync with the real `<html>` state
 * without setting state from effects and without a first-paint flash.
 *
 * A lightweight, dependency-free strategy was chosen over a theme library
 * (Phase 0 blueprint §5.3 / §15 — the library choice was left open). Only the
 * explicit user choice is persisted; it is a UI preference, not sensitive data.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const theme = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  const mounted = useMounted();

  // Follow the OS preference while the user has not made an explicit choice.
  useEffect(() => {
    const media = window.matchMedia("(prefers-color-scheme: dark)");

    const onChange = (event: MediaQueryListEvent) => {
      let stored: string | null = null;
      try {
        stored = window.localStorage.getItem(THEME_STORAGE_KEY);
      } catch {
        // Storage can be unavailable (private mode, blocked cookies); ignore.
      }

      if (stored === "light" || stored === "dark") {
        return;
      }

      applyTheme(event.matches ? "dark" : "light");
    };

    media.addEventListener("change", onChange);
    return () => media.removeEventListener("change", onChange);
  }, []);

  const setTheme = useCallback((theme: Theme) => {
    applyTheme(theme);

    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch {
      // Persistence is best-effort; the theme still applies for this session.
    }
  }, []);

  const toggleTheme = useCallback(() => {
    setTheme(getSnapshot() === "dark" ? "light" : "dark");
  }, [setTheme]);

  const value = useMemo<ThemeContextValue>(
    () => ({ theme, mounted, setTheme, toggleTheme }),
    [theme, mounted, setTheme, toggleTheme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);

  if (context === null) {
    throw new Error("useTheme must be used within a ThemeProvider.");
  }

  return context;
}
