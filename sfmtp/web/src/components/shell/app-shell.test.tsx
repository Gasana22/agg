import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

let pathname = "/farms/f1/billing";
vi.mock("next/navigation", () => ({ usePathname: () => pathname, useRouter: () => ({ push: vi.fn() }) }));
vi.mock("./user-menu", () => ({ UserMenu: () => null }));
vi.mock("./workspace-switcher", () => ({ WorkspaceSwitcher: () => null }));

import { AppShell } from "./app-shell";

const nav = [
  { key: "dashboard", label: "Dashboard", href: "/farms/f1", icon: "dashboard" },
  { key: "billing", label: "Subscription", href: "/farms/f1/billing", icon: "billing" },
  { key: "support", label: "Help & support", href: "/farms/f1/support", icon: "support" },
];

const current = () => screen.getAllByRole("link").filter((a) => a.getAttribute("aria-current") === "page").map((a) => a.textContent);

describe("AppShell navigation", () => {
  it("marks only the section being viewed as current", () => {
    pathname = "/farms/f1/billing";
    render(<AppShell workspaceId="f1" nav={nav}>x</AppShell>);
    expect(current()).toEqual(["Subscription"]);
  });

  it("keeps sections active on their sub-pages and dashboards on /dashboard/ pages", () => {
    pathname = "/farms/f1/support/t1";
    const { unmount } = render(<AppShell workspaceId="f1" nav={nav}>x</AppShell>);
    expect(current()).toEqual(["Help & support"]);
    unmount();

    pathname = "/farms/f1/dashboard/owner";
    render(<AppShell workspaceId="f1" nav={nav}>x</AppShell>);
    expect(current()).toEqual(["Dashboard"]);
  });
});
