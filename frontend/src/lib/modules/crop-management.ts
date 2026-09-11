import { api } from "@/lib/api";

export type Crop = {
  id: number;
  farm_id: number;
  name: string;
  variety: string | null;
  category: string | null;
  description: string | null;
  is_active: boolean;
  seasons_count?: number;
  created_at: string;
  updated_at: string;
};

export type CropSeason = {
  id: number;
  farm_id: number;
  crop: { id: number; name: string; variety: string | null };
  plot_id: number | null;
  season_name: string;
  planned_planting_date: string | null;
  actual_planting_date: string | null;
  budget: string | null;
  expected_yield: string | null;
  expected_yield_unit: string | null;
  status: "planning" | "nursery" | "field" | "monitoring" | "harvested" | "closed";
  activities_count?: number;
  harvests_count?: number;
  created_at: string;
  updated_at: string;
};

export type CropActivity = {
  id: number;
  crop_season_id: number;
  type: string;
  date: string;
  cost: string | null;
  gps_lat: string | null;
  gps_lng: string | null;
  photo_url: string | null;
  notes: string | null;
  performer: { id: number; name: string };
  created_at: string;
};

export type CropMonitoringLog = {
  id: number;
  crop_season_id: number;
  type: "disease" | "pest" | "growth";
  date: string;
  description: string;
  severity: string | null;
  photo_url: string | null;
  reporter: { id: number; name: string };
  created_at: string;
};

export type CropHarvest = {
  id: number;
  crop_season_id: number;
  harvest_date: string;
  quantity: string;
  unit: string;
  quality_grade: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  sales_count?: number;
  created_at: string;
};

export type CropSale = {
  id: number;
  crop_harvest_id: number;
  buyer_name: string;
  quantity_sold: string;
  unit_price: string;
  revenue: number;
  sale_date: string;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export const CROP_SEASON_STATUSES = [
  "planning",
  "nursery",
  "field",
  "monitoring",
  "harvested",
  "closed",
] as const;

export const CROP_ACTIVITY_TYPES = [
  "planting",
  "weeding",
  "irrigation",
  "spraying",
  "fertilizer_application",
  "other",
] as const;

export const CROP_MONITORING_TYPES = ["disease", "pest", "growth"] as const;

// Crops
export async function listCrops(farmId: number) {
  const { data } = await api.get<{ data: Crop[] }>(`/farms/${farmId}/crops`);
  return data.data;
}

export async function createCrop(
  farmId: number,
  payload: { name: string; variety?: string; category?: string; description?: string }
) {
  const { data } = await api.post<{ data: Crop }>(`/farms/${farmId}/crops`, payload);
  return data.data;
}

// Crop seasons
export async function listCropSeasons(farmId: number) {
  const { data } = await api.get<{ data: CropSeason[] }>(`/farms/${farmId}/crop-seasons`);
  return data.data;
}

export async function getCropSeason(id: number) {
  const { data } = await api.get<{ data: CropSeason }>(`/crop-seasons/${id}`);
  return data.data;
}

export async function createCropSeason(
  farmId: number,
  payload: {
    crop_id: number;
    season_name: string;
    planned_planting_date?: string;
    budget?: number;
    expected_yield?: number;
    expected_yield_unit?: string;
  }
) {
  const { data } = await api.post<{ data: CropSeason }>(`/farms/${farmId}/crop-seasons`, payload);
  return data.data;
}

export async function updateCropSeasonStatus(id: number, status: CropSeason["status"]) {
  const { data } = await api.patch<{ data: CropSeason }>(`/crop-seasons/${id}`, { status });
  return data.data;
}

// Activities
export async function listCropActivities(seasonId: number) {
  const { data } = await api.get<{ data: CropActivity[] }>(`/crop-seasons/${seasonId}/activities`);
  return data.data;
}

export async function createCropActivity(
  seasonId: number,
  payload: { type: string; date: string; cost?: number; notes?: string }
) {
  const { data } = await api.post<{ data: CropActivity }>(`/crop-seasons/${seasonId}/activities`, payload);
  return data.data;
}

// Monitoring logs
export async function listCropMonitoringLogs(seasonId: number) {
  const { data } = await api.get<{ data: CropMonitoringLog[] }>(`/crop-seasons/${seasonId}/monitoring-logs`);
  return data.data;
}

export async function createCropMonitoringLog(
  seasonId: number,
  payload: { type: string; date: string; description: string; severity?: string }
) {
  const { data } = await api.post<{ data: CropMonitoringLog }>(
    `/crop-seasons/${seasonId}/monitoring-logs`,
    payload
  );
  return data.data;
}

// Harvests
export async function listCropHarvests(seasonId: number) {
  const { data } = await api.get<{ data: CropHarvest[] }>(`/crop-seasons/${seasonId}/harvests`);
  return data.data;
}

export async function createCropHarvest(
  seasonId: number,
  payload: { harvest_date: string; quantity: number; unit: string; quality_grade?: string; notes?: string }
) {
  const { data } = await api.post<{ data: CropHarvest }>(`/crop-seasons/${seasonId}/harvests`, payload);
  return data.data;
}

// Sales
export async function listCropSales(harvestId: number) {
  const { data } = await api.get<{ data: CropSale[] }>(`/crop-harvests/${harvestId}/sales`);
  return data.data;
}

export async function createCropSale(
  harvestId: number,
  payload: { buyer_name: string; quantity_sold: number; unit_price: number; sale_date: string; notes?: string }
) {
  const { data } = await api.post<{ data: CropSale }>(`/crop-harvests/${harvestId}/sales`, payload);
  return data.data;
}
