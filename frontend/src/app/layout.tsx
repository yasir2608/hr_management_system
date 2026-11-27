import type { Metadata } from "next";
import { AuthProvider } from "@/lib/auth";
import "./globals.css";
// Staff console theme (navy design), scoped to .staff so it never touches the company HRMS.
import "./staff.css";

export const metadata: Metadata = {
  title: "M.Y HRMS",
  description: "HR, attendance, leave and payroll for growing companies.",
  // The staff console sets its own title and icon (app/platform/layout.tsx, app/staff-login/page.tsx).
  icons: { icon: "/icon-company.svg" },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en">
      <body>
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
