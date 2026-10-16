/**
 * Client mirror of the backend password policy.
 *
 * The authority is `App\Rules\StrongPassword` plus
 * `config('security.password_min_length')` (default 8). This module exists only
 * to give an immediate, useful message before a round trip; the backend
 * re-validates every password and its message always wins. The rules below are
 * transcribed from the server rule so the two cannot drift in wording or
 * requirements (a lowercase letter, an uppercase letter, a number and a symbol).
 */

/** Default minimum length from `config/security.php` (`PASSWORD_MIN_LENGTH`). */
export const PASSWORD_MIN_LENGTH = 8;

const LOWERCASE = /\p{Ll}/u;
const UPPERCASE = /\p{Lu}/u;
const DIGIT = /\d/;
const SYMBOL = /[^\p{L}\d]/u;

/** The human-readable policy summary, matching the backend's wording. */
export const PASSWORD_POLICY_HINT =
  `At least ${PASSWORD_MIN_LENGTH} characters, including a lowercase letter, ` +
  "an uppercase letter, a number and a symbol.";

/** Return the first policy violation, or null when the password is acceptable. */
export function validatePasswordStrength(password: string): string | null {
  if (password.length < PASSWORD_MIN_LENGTH) {
    return `Password must be at least ${PASSWORD_MIN_LENGTH} characters.`;
  }

  if (!LOWERCASE.test(password)) {
    return "Password must contain a lowercase letter.";
  }

  if (!UPPERCASE.test(password)) {
    return "Password must contain an uppercase letter.";
  }

  if (!DIGIT.test(password)) {
    return "Password must contain a number.";
  }

  if (!SYMBOL.test(password)) {
    return "Password must contain a symbol.";
  }

  return null;
}
