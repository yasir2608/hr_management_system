export type NavItem = { href: string; label: string; icon: string };
export type NavGroup = { label: string; items: NavItem[] };

/** Sidebar structure from the visual design. Items are added as each area is built. */
export const navGroups: NavGroup[] = [
  { label: "Overview", items: [{ href: "/dashboard", label: "Dashboard", icon: "⌂" }] },
  {
    label: "Settings",
    items: [
      { href: "/settings/company", label: "Company setup", icon: "⚙" },
      { href: "/settings/sign-in", label: "Sign-in controls", icon: "⌑" },
    ],
  },
];
