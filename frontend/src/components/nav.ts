export type NavItem = {
  href: string;
  label: string;
  icon: string;
  /** Shown when the user holds any of these permissions (omit for everyone). */
  permissions?: string[];
  /** Sub-pages, listed under the item while the user is inside that area. */
  children?: NavItem[];
  /** Extra path prefixes that count as being inside this item. */
  matches?: string[];
};
export type NavGroup = { label: string; items: NavItem[] };

/**
 * Sidebar from docs/full_scope_visualization.html: two groups, Workspace and Administration, in the design's order
 * and with its icons. Design items whose screens are not built yet (Team, Reports) are added as their chunks are built.
 */
export const navGroups: NavGroup[] = [
  {
    label: "Workspace",
    items: [
      { href: "/dashboard", label: "Dashboard", icon: "⌂" },
      {
        href: "/me", label: "My HR", icon: "♡", permissions: ["self.profile.view"],
        children: [
          { href: "/me/payslips", label: "My payslips", icon: "▧", permissions: ["self.payslips.view"] },
          { href: "/me/onboarding", label: "My onboarding", icon: "✓", permissions: ["self.profile.view"] },
        ],
      },
      {
        href: "/employees", label: "Employees", icon: "♙", permissions: ["employees.view", "employees.manage"],
        children: [{ href: "/onboarding", label: "Onboarding", icon: "✓", permissions: ["onboarding.manage"] }],
        matches: ["/onboarding"],
      },
      { href: "/payroll/employees", label: "Payroll profile", icon: "▤", permissions: ["payroll.view", "payroll.settings.manage"] },
      {
        href: "/contractors", label: "Contracts & invoices", icon: "▣", permissions: ["contractors.view", "contractors.manage"],
        children: [{ href: "/contractors/invoices", label: "Invoice list", icon: "▤", permissions: ["contractors.view", "contractors.manage"] }],
      },
      { href: "/leave", label: "Leave", icon: "▣", permissions: ["leave.policies.manage", "leave.requests.view", "leave.approve", "self.leave.request"] },
      { href: "/approvals", label: "Approvals", icon: "✓" },
      {
        href: "/attendance", label: "Attendance", icon: "◷", permissions: ["self.attendance.clock", "attendance.view", "attendance.manage"],
        children: [{ href: "/attendance/clock", label: "Clock in / out", icon: "◷", permissions: ["self.attendance.clock"] }],
      },
      {
        href: "/payroll/runs", label: "Payroll", icon: "＄", permissions: ["payroll.view", "payroll.run"],
        matches: ["/payroll/readiness", "/payroll/groups", "/payroll/rules", "/payroll/settings", "/payroll/bank-formats", "/payroll/bank-exports", "/payroll/reports"],
        children: [
          { href: "/payroll/payslips", label: "Payslips", icon: "▧", permissions: ["payslips.manage", "payroll.view"] },
          { href: "/payroll/readiness", label: "Readiness", icon: "✓", permissions: ["payroll.view", "payroll.settings.manage", "payroll.run"] },
          { href: "/payroll/groups", label: "Payroll groups", icon: "▦", permissions: ["payroll.view", "payroll.settings.manage"] },
          { href: "/payroll/rules", label: "Tax rules", icon: "§", permissions: ["payroll.view", "payroll.settings.manage"] },
          { href: "/payroll/settings", label: "Payroll settings", icon: "⚙", permissions: ["payroll.view", "payroll.settings.manage"] },
          { href: "/payroll/reports", label: "Payroll reports", icon: "▥", permissions: ["payroll.reports.view"] },
          { href: "/payroll/bank-exports", label: "Bank exports", icon: "⇩", permissions: ["payroll.export"] },
          { href: "/payroll/bank-formats", label: "Bank formats", icon: "⇄", permissions: ["payroll.view", "payroll.settings.manage", "payroll.export"] },
        ],
      },
    ],
  },
  {
    label: "Administration",
    items: [
      {
        href: "/settings/company", label: "Company setup", icon: "⚙", permissions: ["company.settings.view", "company.settings.manage"],
        children: [{ href: "/settings/sign-in", label: "Sign-in controls", icon: "⌑", permissions: ["company.settings.manage"] }],
      },
      {
        href: "/settings/roles", label: "Roles & permissions", icon: "♧", permissions: ["users.view", "users.manage", "roles.manage"],
        children: [{ href: "/settings/users", label: "Users", icon: "♙", permissions: ["users.view", "users.manage"] }],
      },
    ],
  },
];

/** True when the path is the item itself, one of its sub-pages, or an extra matching area. */
export function inItem(item: NavItem, pathname: string): boolean {
  const prefixes = [item.href, ...(item.children ?? []).map((child) => child.href), ...(item.matches ?? [])];
  return prefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}
