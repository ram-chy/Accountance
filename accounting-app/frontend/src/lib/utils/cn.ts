export type ClassValue =
  | string
  | number
  | null
  | false
  | undefined
  | readonly ClassValue[];

/**
 * Join truthy class names.
 *
 * Deliberately tiny and dependency-free: the foundation does not need
 * Tailwind-aware class conflict resolution, and adding a merge library for a
 * handful of components would be premature.
 */
export function cn(...values: ClassValue[]): string {
  const classes: string[] = [];

  for (const value of values) {
    if (!value) {
      continue;
    }

    if (Array.isArray(value)) {
      const nested = cn(...value);
      if (nested) {
        classes.push(nested);
      }
      continue;
    }

    classes.push(String(value));
  }

  return classes.join(" ");
}
