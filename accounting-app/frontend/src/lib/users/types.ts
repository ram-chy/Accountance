/**
 * User administration data contracts.
 *
 * These mirror the verified `UserResource`, `StoreUserRequest`,
 * `UpdateUserRequest` and `UserController::index` — see `UserController.php`
 * and `FRONTEND_API_HANDOFF.md` §8.
 *
 * There is **no** roles endpoint (`roles.view` has no served route), so the role
 * names offered by the UI are the fixed set from `App\Enums\RoleName`. The
 * backend's `RoleName::values()` must stay equal to `FIXED_ROLES`; a mismatch is
 * a backend change that the role picker will surface as a server validation
 * error rather than silently dropping an unknown role.
 *
 * `UserResource` intentionally omits `roles[].permissions` on the index (the
 * collection is loaded `with('roles')` only), which is why this module's
 * `UserRole` has no `permissions` field — unlike the session's
 * `SessionUserRole`, which comes from `auth/me`.
 */

export type UserRole = {
  id: number;
  name: string;
};

export type User = {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  mobile_no: string | null;
  email_verified: boolean;
  is_active: boolean;
  roles: UserRole[];
  last_login_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

/** The complete, closed set of roles `App\Enums\RoleName` defines. */
export const FIXED_ROLES = [
  "Admin",
  "Accountant",
  "Manager",
  "Staff",
] as const;

export type FixedRole = (typeof FIXED_ROLES)[number];

export function isFixedRole(value: string): value is FixedRole {
  return (FIXED_ROLES as readonly string[]).includes(value);
}

export type UserListFilters = {
  search?: string;
  /** Role name, not id (`?role=Admin`). */
  role?: string;
  /** Omitted entirely when unset — `is_active` is a tri-state. */
  is_active?: boolean;
};

export type UserListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: User[];
  message?: string;
  requestId?: string;
  /**
   * `true` when a full page came back.
   *
   * The users index is paginated but its response carries no `meta`, so the
   * frontend cannot show a page count or page forward. It therefore reads as
   * many rows as the API allows (`per_page: 100`) and reports `hasMore` so the
   * UI can say "more users may exist" instead of implying a complete roster.
   */
  hasMore: boolean;
};

/** `POST /users` body. */
export type UserCreatePayload = {
  first_name: string;
  last_name: string;
  email: string;
  mobile_no: string | null;
  password: string;
  roles: string[];
};

/**
 * `PUT /users/{id}` body.
 *
 * Every key is optional ("not mentioned means unchanged"). `password` is
 * omitted when left blank because `UpdateUserRequest` declares it
 * `sometimes|nullable` — sending an empty string would fail `StrongPassword`.
 */
export type UserUpdatePayload = {
  first_name?: string;
  last_name?: string;
  email?: string;
  mobile_no?: string | null;
  password?: string;
  is_active?: boolean;
  roles?: string[];
};
