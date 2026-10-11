/**
 * Theme constants shared by the server layout (pre-paint script) and the client
 * provider.
 *
 * Kept in a plain module with no `"use client"` directive: values imported from
 * a client module into a server component are replaced with client references,
 * so the literal key must live outside the client boundary.
 */
export const THEME_STORAGE_KEY = "accountance-theme";
