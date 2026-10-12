"use client";

import { Toaster as SonnerToaster } from "sonner";

import { useTheme } from "@/components/theme/theme-provider";

/**
 * App-wide toast host (sonner), themed to match the app.
 *
 * Mounted once in the root layout. `theme` is fed from our own ThemeProvider so
 * toasts follow the light/dark choice instead of the OS default, and a fixed
 * `light` value is used until hydration completes to avoid a mismatch.
 */
export function Toaster() {
  const { theme, mounted } = useTheme();

  return (
    <SonnerToaster
      theme={mounted ? theme : "light"}
      position="top-right"
      closeButton
      richColors
      toastOptions={{ duration: 5000 }}
    />
  );
}
