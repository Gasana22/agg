import { api } from "@/lib/api";

export type Supplier = {
  id: number;
  farm_id: number;
  name: string;
  category: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  notes: string | null;
  is_active: boolean;
  created_at: string;
};

export type PurchaseOrderItem = {
  id: number;
  purchase_order_id: number;
  item_name: string;
  category: string | null;
  quantity: string;
  unit: string;
  unit_price: string;
  line_total: number;
};

export type Delivery = {
  id: number;
  purchase_order_id: number;
  delivery_date: string;
  is_complete: boolean;
  notes: string | null;
  receiver: { id: number; name: string } | null;
  created_at: string;
};

export type Payment = {
  id: number;
  purchase_order_id: number;
  amount: string;
  payment_date: string;
  method: "cash" | "bank_transfer" | "mobile_money" | "cheque" | "other";
  reference: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type PurchaseOrder = {
  id: number;
  farm_id: number;
  supplier: { id: number; name: string };
  order_date: string;
  expected_delivery_date: string | null;
  status: "ordered" | "partially_delivered" | "delivered" | "cancelled";
  notes: string | null;
  items: PurchaseOrderItem[];
  total_amount?: number;
  total_paid?: number;
  balance?: number;
  creator: { id: number; name: string };
  deliveries: Delivery[];
  payments: Payment[];
  created_at: string;
};

export type ProcurementSummary = {
  from: string;
  to: string;
  order_count: number;
  total_ordered: number;
  total_paid: number;
  outstanding_balance: number;
  orders_by_status: Record<string, number>;
};

export const PURCHASE_ORDER_STATUSES = ["ordered", "partially_delivered", "delivered", "cancelled"] as const;
export const PAYMENT_METHODS = ["cash", "bank_transfer", "mobile_money", "cheque", "other"] as const;

// Suppliers
export async function listSuppliers(farmId: number) {
  const { data } = await api.get<{ data: Supplier[] }>(`/farms/${farmId}/suppliers`);
  return data.data;
}

export async function createSupplier(
  farmId: number,
  payload: { name: string; category?: string; phone?: string; email?: string; address?: string; notes?: string }
) {
  const { data } = await api.post<{ data: Supplier }>(`/farms/${farmId}/suppliers`, payload);
  return data.data;
}

export async function updateSupplier(
  id: number,
  payload: Partial<{
    name: string;
    category: string;
    phone: string;
    email: string;
    address: string;
    notes: string;
    is_active: boolean;
  }>
) {
  const { data } = await api.patch<{ data: Supplier }>(`/suppliers/${id}`, payload);
  return data.data;
}

// Purchase orders
export async function listPurchaseOrders(farmId: number) {
  const { data } = await api.get<{ data: PurchaseOrder[] }>(`/farms/${farmId}/purchase-orders`);
  return data.data;
}

export async function getPurchaseOrder(id: number) {
  const { data } = await api.get<{ data: PurchaseOrder }>(`/purchase-orders/${id}`);
  return data.data;
}

export async function createPurchaseOrder(
  farmId: number,
  payload: {
    supplier_id: number;
    order_date: string;
    expected_delivery_date?: string;
    notes?: string;
    items: { item_name: string; category?: string; quantity: number; unit: string; unit_price: number }[];
  }
) {
  const { data } = await api.post<{ data: PurchaseOrder }>(`/farms/${farmId}/purchase-orders`, payload);
  return data.data;
}

export async function updatePurchaseOrderStatus(id: number, status: PurchaseOrder["status"]) {
  const { data } = await api.patch<{ data: PurchaseOrder }>(`/purchase-orders/${id}`, { status });
  return data.data;
}

export async function updatePurchaseOrder(
  id: number,
  payload: Partial<{
    supplier_id: number;
    order_date: string;
    expected_delivery_date: string | null;
    notes: string;
  }>
) {
  const { data } = await api.patch<{ data: PurchaseOrder }>(`/purchase-orders/${id}`, payload);
  return data.data;
}

export async function deletePurchaseOrderItem(id: number) {
  await api.delete(`/purchase-order-items/${id}`);
}

// Deliveries
export async function createDelivery(
  purchaseOrderId: number,
  payload: { delivery_date: string; is_complete: boolean; notes?: string }
) {
  const { data } = await api.post<{ data: Delivery }>(`/purchase-orders/${purchaseOrderId}/deliveries`, payload);
  return data.data;
}

// Payments
export async function createPayment(
  purchaseOrderId: number,
  payload: { amount: number; payment_date: string; method: string; reference?: string }
) {
  const { data } = await api.post<{ data: Payment }>(`/purchase-orders/${purchaseOrderId}/payments`, payload);
  return data.data;
}

// Reports
export async function getProcurementSummary(farmId: number, from?: string, to?: string) {
  const params: Record<string, string> = {};
  if (from) params.from = from;
  if (to) params.to = to;
  const { data } = await api.get<ProcurementSummary>(`/farms/${farmId}/procurement/summary`, { params });
  return data;
}
