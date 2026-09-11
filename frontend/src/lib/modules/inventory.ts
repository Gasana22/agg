import { api } from "@/lib/api";

export type InventoryItem = {
  id: number;
  farm_id: number;
  name: string;
  category: string | null;
  unit: string;
  reorder_level: string | null;
  notes: string | null;
  is_active: boolean;
  current_quantity: number;
  is_low_stock: boolean;
  created_at: string;
};

export type InventoryTransaction = {
  id: number;
  inventory_item_id: number;
  type: "stock_in" | "stock_out";
  quantity: string;
  date: string;
  delivery_id: number | null;
  reference: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export const INVENTORY_TRANSACTION_TYPES = ["stock_in", "stock_out"] as const;

// Items
export async function listInventoryItems(farmId: number) {
  const { data } = await api.get<{ data: InventoryItem[] }>(`/farms/${farmId}/inventory-items`);
  return data.data;
}

export async function getInventoryItem(id: number) {
  const { data } = await api.get<{ data: InventoryItem }>(`/inventory-items/${id}`);
  return data.data;
}

export async function createInventoryItem(
  farmId: number,
  payload: { name: string; category?: string; unit: string; reorder_level?: number; notes?: string }
) {
  const { data } = await api.post<{ data: InventoryItem }>(`/farms/${farmId}/inventory-items`, payload);
  return data.data;
}

export async function updateInventoryItem(
  id: number,
  payload: Partial<{
    name: string;
    category: string;
    unit: string;
    reorder_level: number;
    notes: string;
    is_active: boolean;
  }>
) {
  const { data } = await api.patch<{ data: InventoryItem }>(`/inventory-items/${id}`, payload);
  return data.data;
}

export async function getLowStockItems(farmId: number) {
  const { data } = await api.get<{ data: InventoryItem[] }>(`/farms/${farmId}/inventory/low-stock`);
  return data.data;
}

// Transactions
export async function listInventoryTransactions(itemId: number) {
  const { data } = await api.get<{ data: InventoryTransaction[] }>(`/inventory-items/${itemId}/transactions`);
  return data.data;
}

export async function createInventoryTransaction(
  itemId: number,
  payload: { type: string; quantity: number; date: string; reference?: string; notes?: string }
) {
  const { data } = await api.post<{ data: InventoryTransaction }>(
    `/inventory-items/${itemId}/transactions`,
    payload
  );
  return data.data;
}
