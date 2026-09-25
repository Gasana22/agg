"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Minus, Plus, ShoppingBasket } from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Label, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney, formatQty } from "@/lib/inventory";
import { cartByFarm, quantityProblem, type CartLine, type PortalProduct } from "@/lib/portal";

/**
 * The marketplace of the farms this customer buys from: published products
 * at list price. The cart becomes one order per farm; each farm approves its own.
 */
export default function ShopPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [cart, setCart] = useState<CartLine[]>([]);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const products = useQuery({
    queryKey: ["customer-products", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/products", { params: { path: { party: partyId } } })).data!.data ?? [],
  });

  const setQty = (product: PortalProduct, quantity: number) =>
    setCart((c) => (quantity > 0 ? [...c.filter((l) => l.product.id !== product.id), { product, quantity }] : c.filter((l) => l.product.id !== product.id)));
  const inCart = (id?: string) => cart.find((l) => l.product.id === id)?.quantity ?? 0;
  const groups = cartByFarm(cart);
  const problems = cart.map((l) => quantityProblem(l.product, l.quantity)).filter(Boolean);

  async function place(form: FormData) {
    setError(null);
    setBusy(true);
    try {
      const placed: { farm: string; id: string }[] = [];
      for (const g of groups) {
        const res = await api.POST("/customer/{party}/farms/{farm}/orders", {
          params: { path: { party: partyId, farm: g.farm.id } },
          body: {
            requested_delivery_on: String(form.get("requested_delivery_on") || "") || null,
            delivery_address: String(form.get("delivery_address") || "") || null,
            note: String(form.get("note") || "") || null,
            lines: g.lines.map((l) => ({ product_id: l.product.id!, quantity: l.quantity })),
          },
        });
        placed.push({ farm: g.farm.id, id: res.data!.data!.id! });
      }
      await queryClient.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith("customer-") });
      router.push(placed.length === 1 ? `/customer/${partyId}/orders/${placed[0].farm}/${placed[0].id}` : `/customer/${partyId}/orders`);
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader title="Shop" description="Products the farms you buy from have published, at their list prices." />
      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div>
          {products.isLoading ? (
            <Skeleton className="h-64 w-full" />
          ) : products.error ? (
            <ErrorNotice error={products.error} />
          ) : (products.data ?? []).length === 0 ? (
            <EmptyState title="Nothing on offer yet">When a farm publishes products, they appear here.</EmptyState>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2">
              {products.data!.map((p) => {
                const qty = inCart(p.id);
                const step = p.min_order_quantity ?? 1;
                return (
                  <Card key={p.id} className="flex flex-col">
                    {p.has_photo ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={`/api/proxy/customer/${partyId}/farms/${p.farm?.id}/products/${p.id}/photo`} alt="" className="h-36 w-full rounded-t-2xl object-cover" />
                    ) : null}
                    <CardHeader>
                      <div className="flex items-start justify-between gap-2">
                        <CardTitle>{p.name}</CardTitle>
                        {p.category ? <Badge tone="neutral">{p.category}</Badge> : null}
                      </div>
                      <p className="text-xs text-muted">{p.farm?.name}</p>
                    </CardHeader>
                    <CardContent className="flex flex-1 flex-col gap-3">
                      {p.description ? <p className="text-sm text-muted">{p.description}</p> : null}
                      <p className="text-lg font-semibold tabular-nums">
                        {formatMoney(p.price, p.currency)} <span className="text-sm font-normal text-muted">per {p.unit}</span>
                      </p>
                      <p className="text-xs text-muted">
                        {[p.min_order_quantity ? `Minimum ${formatQty(p.min_order_quantity, p.unit)}` : null, p.availability_note].filter(Boolean).join(" · ")}
                      </p>
                      <div className="mt-auto flex items-center gap-2">
                        <Button size="icon" variant="secondary" aria-label={`Less ${p.name}`} onClick={() => setQty(p, Math.max(0, qty - step))} disabled={qty === 0}>
                          <Minus />
                        </Button>
                        <Input
                          aria-label={`Quantity of ${p.name}`}
                          className="w-28 text-right"
                          type="number"
                          min={0}
                          step="any"
                          value={qty || ""}
                          placeholder="0"
                          onChange={(e) => setQty(p, Number(e.target.value))}
                        />
                        <span className="text-sm text-muted">{p.unit}</span>
                        <Button size="icon" variant="secondary" aria-label={`More ${p.name}`} onClick={() => setQty(p, qty === 0 ? step : qty + step)}>
                          <Plus />
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          )}
        </div>

        <Card className="h-fit lg:sticky lg:top-20">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ShoppingBasket className="size-5" /> Your order
            </CardTitle>
          </CardHeader>
          <CardContent>
            {cart.length === 0 ? (
              <p className="text-sm text-muted">Add products to order them.</p>
            ) : (
              <form
                className="space-y-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  void place(new FormData(e.currentTarget));
                }}
              >
                {groups.map((g) => (
                  <div key={g.farm.id} className="space-y-1 text-sm">
                    <p className="font-medium">{g.farm.name}</p>
                    {g.lines.map((l) => (
                      <p key={l.product.id} className="flex justify-between gap-2">
                        <span>
                          {formatQty(l.quantity, l.product.unit)} {l.product.name}
                        </span>
                        <span className="tabular-nums">{formatMoney(l.quantity * (l.product.price ?? 0), l.product.currency)}</span>
                      </p>
                    ))}
                    <p className="flex justify-between border-t border-border pt-1 font-medium">
                      <span>Total</span>
                      <span className="tabular-nums">{formatMoney(g.total, g.currency)}</span>
                    </p>
                  </div>
                ))}
                {groups.length > 1 ? <p className="text-xs text-muted">Each farm receives its own order.</p> : null}
                <div>
                  <Label htmlFor="requested_delivery_on">Deliver by</Label>
                  <Input id="requested_delivery_on" name="requested_delivery_on" type="date" min={new Date().toISOString().slice(0, 10)} />
                </div>
                <div>
                  <Label htmlFor="delivery_address">Delivery address</Label>
                  <Input id="delivery_address" name="delivery_address" placeholder="The address the farm has for you" />
                </div>
                <div>
                  <Label htmlFor="note">Note to the farm</Label>
                  <Textarea id="note" name="note" rows={2} />
                </div>
                {problems.length ? (
                  <ul className="text-sm text-danger">
                    {problems.map((p) => (
                      <li key={p}>{p}</li>
                    ))}
                  </ul>
                ) : null}
                {error ? <ErrorNotice error={error} /> : null}
                <Button type="submit" className="w-full" disabled={busy || problems.length > 0}>
                  {busy ? "Placing…" : groups.length > 1 ? `Place ${groups.length} orders` : "Place order"}
                </Button>
                <p className="text-xs text-muted">The farm confirms the order before it is invoiced and sent.</p>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
}
