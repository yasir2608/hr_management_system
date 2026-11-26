export type NavItem = {
  href: string;
  label: string;
  icon: string;
  /** Shown when the user holds any of these permissions (omit for everyone). */
  permissions?: string[];
};
export type NavGroup = { label: string; items: NavItem[] };

/** Sidebar structure from the visual design. Items are added as each area is built. */
export const navGroups: NavGroup[] = [
  { label: "Overview", items: [{ href: "/dashboard", label: "Dashboard", icon: "⌂" }] },
  {
    label: "Settings",
    items: [
      { href: "/settings/company", label: "Company setup", icon: "⚙", permissions: ["company.settings.view", "company.settings.manage"] },
      { href: "/settings/roles", label: "Roles & permissions", icon: "⚿", permissions: ["users.view", "users.manage", "roles.manage"] },
      { href: "/settings/sign-in", label: "Sign-in controls", icon: "⌑", permissions: ["company.settings.manage"] },
    ],
  },
];
