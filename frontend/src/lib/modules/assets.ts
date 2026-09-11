import { api } from "@/lib/api";

export type Asset = {
  id: number;
  farm_id: number;
  name: string;
  category: string | null;
  serial_number: string | null;
  purchase_date: string | null;
  purchase_cost: string | null;
  status: "active" | "under_maintenance" | "retired";
  assignee: { id: number; name: string } | null;
  notes: string | null;
  maintenance_logs?: AssetMaintenanceLog[];
  created_at: string;
};

export type AssetMaintenanceLog = {
  id: number;
  asset_id: number;
  type: "repair" | "routine_service" | "inspection" | "other";
  date: string;
  description: string;
  cost: string | null;
  performed_by: string | null;
  next_service_date: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export const ASSET_STATUSES = ["active", "under_maintenance", "retired"] as const;
export const MAINTENANCE_LOG_TYPES = ["repair", "routine_service", "inspection", "other"] as const;

// Assets
export async function listAssets(farmId: number) {
  const { data } = await api.get<{ data: Asset[] }>(`/farms/${farmId}/assets`);
  return data.data;
}

export async function getAsset(id: number) {
  const { data } = await api.get<{ data: Asset }>(`/assets/${id}`);
  return data.data;
}

export async function createAsset(
  farmId: number,
  payload: {
    name: string;
    category?: string;
    serial_number?: string;
    purchase_date?: string;
    purchase_cost?: number;
    assigned_to?: number;
    notes?: string;
  }
) {
  const { data } = await api.post<{ data: Asset }>(`/farms/${farmId}/assets`, payload);
  return data.data;
}

export async function updateAssetStatus(id: number, status: Asset["status"]) {
  const { data } = await api.patch<{ data: Asset }>(`/assets/${id}`, { status });
  return data.data;
}

export async function updateAsset(
  id: number,
  payload: Partial<{
    name: string;
    category: string;
    serial_number: string;
    purchase_date: string;
    purchase_cost: number;
    assigned_to: number | null;
    notes: string;
  }>
) {
  const { data } = await api.patch<{ data: Asset }>(`/assets/${id}`, payload);
  return data.data;
}

// Maintenance logs
export async function listMaintenanceLogs(assetId: number) {
  const { data } = await api.get<{ data: AssetMaintenanceLog[] }>(`/assets/${assetId}/maintenance-logs`);
  return data.data;
}

export async function createMaintenanceLog(
  assetId: number,
  payload: {
    type: string;
    date: string;
    description: string;
    cost?: number;
    performed_by?: string;
    next_service_date?: string;
    notes?: string;
  }
) {
  const { data } = await api.post<{ data: AssetMaintenanceLog }>(`/assets/${assetId}/maintenance-logs`, payload);
  return data.data;
}
