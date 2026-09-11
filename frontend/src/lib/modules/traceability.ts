import { api } from "@/lib/api";

export type TraceEvent = {
  id: number;
  trace_batch_id: number;
  type: "produced" | "processed" | "packaged" | "shipped" | "delivered" | "sold" | "recalled";
  date: string;
  location: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type TraceBatch = {
  id: number;
  farm_id: number;
  code: string;
  source_type: string;
  source_id: number;
  product_name: string;
  quantity: string;
  unit: string;
  status: "active" | "sold" | "recalled";
  creator: { id: number; name: string };
  events: TraceEvent[];
  trace_url: string;
  created_at: string;
};

export const TRACE_EVENT_TYPES = ["processed", "packaged", "shipped", "delivered", "sold", "recalled"] as const;

export async function listTraceBatches(farmId: number) {
  const { data } = await api.get<{ data: TraceBatch[] }>(`/farms/${farmId}/trace-batches`);
  return data.data;
}

export async function getTraceBatch(id: number) {
  const { data } = await api.get<{ data: TraceBatch }>(`/trace-batches/${id}`);
  return data.data;
}

export async function createTraceBatch(
  farmId: number,
  payload: { source_type: "crop_harvest" | "animal_production_record"; source_id: number }
) {
  const { data } = await api.post<{ data: TraceBatch }>(`/farms/${farmId}/trace-batches`, payload);
  return data.data;
}

export async function createTraceEvent(
  batchId: number,
  payload: { type: string; date: string; location?: string; notes?: string }
) {
  const { data } = await api.post<{ data: TraceEvent }>(`/trace-batches/${batchId}/events`, payload);
  return data.data;
}
