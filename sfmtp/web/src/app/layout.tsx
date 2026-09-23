import type { Metadata } from "next";
import { Inter } from "next/font/google";

import { themeScript } from "@/components/theme/theme-provider";

import "./globals.css";
import { Providers } from "./providers";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });

export const metadata: Metadata = {
  title: { default: "SFMTP", template: "%s · SFMTP" },
  description: "Smart Farm Management & Traceability Platform — track every seed, every worker, every harvest, every sale.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body className={`${inter.variable} min-h-dvh`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
