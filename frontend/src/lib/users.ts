export type UserRole = { assignment_id: string; key: string; name: string; scope_type: string; scope_id: string | null; scope_name: string | null; include_indirect: boolean };

export type UserRow = {
  id: string;
  user_id: string;
  name: string;
  email: string;
  status: "active" | "suspended" | "removed";
  account_disabled: boolean;
  joined_at: string | null;
  last_login_at: string | null;
  roles: UserRole[];
};

export function roleLabel(role: UserRole) {
  return role.scope_type === "company" ? role.name : `${role.name} · ${role.scope_name ?? role.scope_type}${role.include_indirect ? " (incl. indirect)" : ""}`;
}
