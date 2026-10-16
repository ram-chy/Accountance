/**
 * Runtime validation for user responses and inputs.
 *
 * API JSON is untrusted. A user missing an id, an email or a usable name is
 * dropped rather than rendered with guessed values — the same "filter the
 * invalid row, never fabricate" strategy the other modules use.
 *
 * The input validators mirror the verified `StoreUserRequest`/`UpdateUserRequest`
 * rules so the forms can message locally. The backend re-validates everything
 * (including `unique:users,email` and the fixed role set) and its message wins.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  User,
  UserCreatePayload,
  UserListFilters,
  UserListState,
  UserUpdatePayload,
} from "./types";
import { FIXED_ROLES } from "./types";
import { PASSWORD_MIN_LENGTH, validatePasswordStrength } from "@/lib/auth/password";

export class UsersDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "UsersDataError";
  }
}

/**
 * The backend's `mobile_no` rule: an optional leading `+` then 7–20 digits.
 * Wider than the company phone rule (which allows separators), so each module
 * keeps its own pattern.
 */
export const MOBILE_PATTERN = /^\+?[0-9]{7,20}$/;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

export function parseUser(value: unknown): User | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const first_name = asString(value.first_name);
  const last_name = asString(value.last_name);
  const email = asString(value.email);

  if (id === undefined || first_name === undefined || last_name === undefined || email === undefined) {
    return null;
  }

  const roles: User["roles"] = [];
  if (Array.isArray(value.roles)) {
    for (const entry of value.roles) {
      const roleId = asNumber(entry && isRecord(entry) ? entry.id : undefined);
      const roleName = asString(entry && isRecord(entry) ? entry.name : undefined);
      if (roleId !== undefined && roleName !== undefined) {
        roles.push({ id: roleId, name: roleName });
      }
    }
  }

  return {
    id,
    first_name,
    last_name,
    full_name:
      asString(value.full_name) ?? `${first_name} ${last_name}`.trim(),
    email,
    mobile_no: asNullableString(value.mobile_no),
    email_verified: value.email_verified === true,
    is_active: value.is_active === true,
    roles,
    last_login_at: asNullableString(value.last_login_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseUserOrThrow(raw: unknown, label = "user"): User {
  const user = parseUser(raw);
  if (user === null) {
    throw new UsersDataError(`The ${label} response was invalid.`);
  }
  return user;
}

/** Narrow a users index payload into list state, dropping unusable rows. */
export function parseUserList(raw: unknown): UserListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return {
      status: "error",
      items: [],
      hasMore: false,
      message: "The users response was not a list.",
    };
  }

  const list = normalizeList<unknown>(raw, 100);
  const items: User[] = [];

  for (const entry of list.items) {
    const user = parseUser(entry);
    if (user) items.push(user);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      hasMore: false,
      message: "The users response contained no valid rows.",
    };
  }

  return { status: "ready", items, hasMore: list.hasMore };
}

/** Flatten a filters object into the index's `QueryParams`. */
export function toUserQuery(
  filters: UserListFilters,
): { search?: string; role?: string; is_active?: 1 | 0; per_page: number } {
  const search = filters.search?.trim();
  const role = filters.role?.trim();

  return {
    per_page: 100,
    search: search ? search : undefined,
    role: role ? role : undefined,
    // A tri-state: only a defined value becomes `is_active=1|0`; unset omits it.
    is_active: filters.is_active === undefined ? undefined : filters.is_active ? 1 : 0,
  };
}

export type UserValidationErrors = Record<string, string>;

function validateNames(
  first_name: string,
  last_name: string,
  errors: UserValidationErrors,
): void {
  if (first_name.trim() === "") {
    errors.first_name = "First name is required.";
  } else if (first_name.trim().length > 100) {
    errors.first_name = "First name must be 100 characters or fewer.";
  }

  if (last_name.trim() === "") {
    errors.last_name = "Last name is required.";
  } else if (last_name.trim().length > 100) {
    errors.last_name = "Last name must be 100 characters or fewer.";
  }
}

function validateEmail(email: string, errors: UserValidationErrors): void {
  const trimmed = email.trim();

  if (trimmed === "") {
    errors.email = "Email is required.";
    return;
  }

  if (!EMAIL_PATTERN.test(trimmed)) {
    errors.email = "Enter a valid email address.";
    return;
  }

  if (trimmed.length > 255) {
    errors.email = "Email must be 255 characters or fewer.";
  }
}

function validateMobile(mobile_no: string | null, errors: UserValidationErrors): void {
  if (mobile_no === null || mobile_no.trim() === "") return;

  if (!MOBILE_PATTERN.test(mobile_no.trim())) {
    errors.mobile_no =
      "The mobile number must be 7 to 20 digits and may start with +.";
  }
}

function validateRoles(roles: string[], errors: UserValidationErrors): void {
  if (roles.length === 0) {
    errors.roles = "Select at least one role.";
    return;
  }

  const unknown = roles.find(
    (role) => !(FIXED_ROLES as readonly string[]).includes(role),
  );
  if (unknown !== undefined) {
    errors.roles = `"${unknown}" is not a role this system can grant.`;
  }
}

export function validateUserCreate(payload: UserCreatePayload): UserValidationErrors {
  const errors: UserValidationErrors = {};

  validateNames(payload.first_name, payload.last_name, errors);
  validateEmail(payload.email, errors);
  validateMobile(payload.mobile_no, errors);
  validateRoles(payload.roles, errors);

  if (payload.password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `Password must be at least ${PASSWORD_MIN_LENGTH} characters.`;
  } else {
    const strength = validatePasswordStrength(payload.password);
    if (strength !== null) errors.password = strength;
  }

  return errors;
}

export function validateUserUpdate(payload: UserUpdatePayload): UserValidationErrors {
  const errors: UserValidationErrors = {};

  if (payload.first_name !== undefined && payload.last_name !== undefined) {
    validateNames(payload.first_name, payload.last_name, errors);
  } else if (payload.first_name !== undefined) {
    if (payload.first_name.trim() === "") {
      errors.first_name = "First name is required.";
    } else if (payload.first_name.trim().length > 100) {
      errors.first_name = "First name must be 100 characters or fewer.";
    }
  } else if (payload.last_name !== undefined) {
    if (payload.last_name.trim() === "") {
      errors.last_name = "Last name is required.";
    } else if (payload.last_name.trim().length > 100) {
      errors.last_name = "Last name must be 100 characters or fewer.";
    }
  }

  if (payload.email !== undefined) {
    validateEmail(payload.email, errors);
  }

  if (payload.mobile_no !== undefined && payload.mobile_no !== null) {
    validateMobile(payload.mobile_no, errors);
  }

  if (payload.roles !== undefined) {
    validateRoles(payload.roles, errors);
  }

  if (payload.password !== undefined && payload.password.length > 0) {
    const strength = validatePasswordStrength(payload.password);
    if (strength !== null) errors.password = strength;
  }

  return errors;
}
