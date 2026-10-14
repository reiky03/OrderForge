import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "OrderForge | Commerce Operations",
  description:
    "Atomic stock reservations, idempotent payments, and live order operations in one commerce workspace.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
