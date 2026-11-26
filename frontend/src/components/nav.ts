export type NavItem = { href: string; label: string; icon: string };
export type NavGroup = { label: string; items: NavItem[] };

/** Sidebar structure from the visual design. Items are added as each area is built. */
export const navGroups: NavGroup[] = [
  { label: "Overview", items: [{ href: "/dashboard", label: "Dashboard", icon: "⌂" }] },
];
