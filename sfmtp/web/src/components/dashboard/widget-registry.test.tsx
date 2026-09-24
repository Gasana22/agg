import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Widget } from "./widget-registry";

describe("Widget", () => {
  it("renders an action list from server data", () => {
    render(
      <Widget
        widget={{
          key: "recent_trace_events",
          type: "action_list",
          inline: true,
          data: { items: [{ id: "1", title: "Harvested", subtitle: "SFM-7KQ2-9XA4", at: new Date().toISOString() }] },
        }}
      />,
    );
    expect(screen.getByText("Recent traceability activity")).toBeInTheDocument();
    expect(screen.getByText("Harvested")).toBeInTheDocument();
    expect(screen.getByText("SFM-7KQ2-9XA4")).toBeInTheDocument();
  });

  it("shows a badge on action list items that carry one", () => {
    render(
      <Widget
        widget={{
          key: "pest_disease_alerts",
          type: "action_list",
          inline: true,
          data: { items: [{ id: "1", title: "Fall armyworm", subtitle: "Maize · Plot A-1", badge: { label: "High", tone: "danger" }, href: "/farms/f/crops/cycles/c" }] },
        }}
      />,
    );
    expect(screen.getByText("Pest & disease alerts")).toBeInTheDocument();
    expect(screen.getByText("High")).toBeInTheDocument();
    expect(screen.getByRole("link")).toHaveAttribute("href", "/farms/f/crops/cycles/c");
  });

  it("renders checklist progress", () => {
    render(
      <Widget
        widget={{
          key: "setup_checklist",
          type: "checklist",
          inline: true,
          data: { items: [{ key: "a", label: "Complete profile", done: true }, { key: "b", label: "Invite team", done: false, href: "/x" }] },
        }}
      />,
    );
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "1");
    expect(screen.getByRole("link", { name: "Invite team" })).toHaveAttribute("href", "/x");
  });

  it("degrades gracefully for widget types this client doesn't know", () => {
    render(<Widget widget={{ key: "future_map", type: "map", inline: true, data: {} }} />);
    expect(screen.getByText(/isn't supported/)).toBeInTheDocument();
  });
});
