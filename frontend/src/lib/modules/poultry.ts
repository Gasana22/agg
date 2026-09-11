import { api } from "@/lib/api";

export type PoultryFlock = {
  id: number;
  farm_id: number;
  flock_code: string;
  name: string | null;
  bird_type: string;
  breed: string | null;
  initial_count: number;
  current_count: number;
  source: "born_on_farm" | "purchased" | null;
  acquired_date: string | null;
  status: "active" | "sold" | "closed";
  notes: string | null;
  created_at: string;
  updated_at: string;
};

export type PoultryMortalityLog = {
  id: number;
  poultry_flock_id: number;
  date: string;
  quantity: number;
  cause: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type PoultryProductionRecord = {
  id: number;
  poultry_flock_id: number;
  date: string;
  product_type: string;
  quantity: string;
  unit: string;
  recorder: { id: number; name: string };
  created_at: string;
};

export type PoultrySale = {
  id: number;
  poultry_flock_id: number;
  quantity: number;
  buyer_name: string;
  sale_price: string;
  sale_date: string;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export const POULTRY_FLOCK_STATUSES = ["active", "sold", "closed"] as const;
export const POULTRY_SOURCES = ["born_on_farm", "purchased"] as const;

// Flocks
export async function listPoultryFlocks(farmId: number) {
  const { data } = await api.get<{ data: PoultryFlock[] }>(`/farms/${farmId}/poultry-flocks`);
  return data.data;
}

export async function getPoultryFlock(id: number) {
  const { data } = await api.get<{ data: PoultryFlock }>(`/poultry-flocks/${id}`);
  return data.data;
}

export async function createPoultryFlock(
  farmId: number,
  payload: {
    flock_code: string;
    name?: string;
    bird_type: string;
    breed?: string;
    initial_count: number;
    source?: "born_on_farm" | "purchased";
    acquired_date?: string;
    notes?: string;
  }
) {
  const { data } = await api.post<{ data: PoultryFlock }>(`/farms/${farmId}/poultry-flocks`, payload);
  return data.data;
}

export async function updatePoultryFlock(
  id: number,
  payload: Partial<{
    flock_code: string;
    name: string;
    bird_type: string;
    breed: string;
    initial_count: number;
    source: "born_on_farm" | "purchased";
    acquired_date: string;
    status: PoultryFlock["status"];
    notes: string;
  }>
) {
  const { data } = await api.patch<{ data: PoultryFlock }>(`/poultry-flocks/${id}`, payload);
  return data.data;
}

export async function deletePoultryFlock(id: number) {
  await api.delete(`/poultry-flocks/${id}`);
}

// Mortality logs
export async function listPoultryMortalityLogs(flockId: number) {
  const { data } = await api.get<{ data: PoultryMortalityLog[] }>(`/poultry-flocks/${flockId}/mortality-logs`);
  return data.data;
}

export async function createPoultryMortalityLog(
  flockId: number,
  payload: { date: string; quantity: number; cause?: string; notes?: string }
) {
  const { data } = await api.post<{ data: PoultryMortalityLog }>(
    `/poultry-flocks/${flockId}/mortality-logs`,
    payload
  );
  return data.data;
}

// Production records
export async function listPoultryProductionRecords(flockId: number) {
  const { data } = await api.get<{ data: PoultryProductionRecord[] }>(
    `/poultry-flocks/${flockId}/production-records`
  );
  return data.data;
}

export async function createPoultryProductionRecord(
  flockId: number,
  payload: { date: string; product_type: string; quantity: number; unit: string }
) {
  const { data } = await api.post<{ data: PoultryProductionRecord }>(
    `/poultry-flocks/${flockId}/production-records`,
    payload
  );
  return data.data;
}

// Sales
export async function listPoultrySales(flockId: number) {
  const { data } = await api.get<{ data: PoultrySale[] }>(`/poultry-flocks/${flockId}/sales`);
  return data.data;
}

export async function createPoultrySale(
  flockId: number,
  payload: { quantity: number; buyer_name: string; sale_price: number; sale_date: string; notes?: string }
) {
  const { data } = await api.post<{ data: PoultrySale }>(`/poultry-flocks/${flockId}/sales`, payload);
  return data.data;
}
