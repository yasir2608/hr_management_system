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
  {
    label: "Overview",
    items: [
      { href: "/dashboard", label: "Dashboard", icon: "⌂" },
      { href: "/me", label: "My profile", icon: "☺", permissions: ["self.profile.view"] },
      { href: "/me/onboarding", label: "My onboarding", icon: "✓", permissions: ["self.profile.view"] },
    ],
  },
  {
    label: "People",
    items: [
      { href: "/employees", label: "Employees", icon: "◉", permissions: ["employees.view", "employees.manage"] },
      { href: "/onboarding", label: "Onboarding", icon: "✓", permissions: ["onboarding.manage"] },
    ],
  },
  {
    label: "Time off",
    items: [
      { href: "/leave", label: "Leave", icon: "☀", permissions: ["leave.policies.manage", "leave.requests.view", "leave.approve", "self.leave.request"] },
      { href: "/attendance/clock", label: "Clock in / out", icon: "◷", permissions: ["self.attendance.clock"] },
      { href: "/attendance", label: "Attendance", icon: "▤", permissions: ["self.attendance.clock", "attendance.view", "attendance.manage"] },
    ],
  },
  {
    label: "Contractors",
    items: [{ href: "/contractors", label: "Contracts & invoices", icon: "◇", permissions: ["contractors.view", "contractors.manage"] }],
  },
  {
    label: "Settings",
    items: [
      { href: "/settings/company", label: "Company setup", icon: "⚙", permissions: ["company.settings.view", "company.settings.manage"] },
      { href: "/settings/users", label: "Users", icon: "◎", permissions: ["users.view", "users.manage"] },
      { href: "/settings/roles", label: "Roles & permissions", icon: "⚿", permissions: ["users.view", "users.manage", "roles.manage"] },
      { href: "/settings/sign-in", label: "Sign-in controls", icon: "⌑", permissions: ["company.settings.manage"] },
    ],
  },
];
