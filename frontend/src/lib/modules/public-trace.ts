import { api } from "@/lib/api";

export type PublicTraceEvent = {
  id: number;
  trace_batch_id: number;
  type: "produced" | "processed" | "packaged" | "shipped" | "delivered" | "sold" | "recalled";
  date: string;
  location: string | null;
  notes: string | null;
  created_at: string;
};

export type PublicTraceOrigin =
  | { type: "crop_harvest"; harvest_date: string; quality_grade: string | null }
  | { type: "animal_production_record"; product_type: string; collection_date: string }
  | { type: null };

export type PublicTraceBatch = {
  code: string;
  product_name: string;
  quantity: string;
  unit: string;
  status: "active" | "sold" | "recalled";
  farm: { name: string; district: string | null; village: string | null } | null;
  origin: PublicTraceOrigin | null;
  timeline: PublicTraceEvent[];
  created_at: string;
};

export async function getPublicTraceBatch(code: string) {
  const { data } = await api.get<{ data: PublicTraceBatch }>(`/trace/${code}`);
  return data.data;
}
