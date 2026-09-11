import { api } from "@/lib/api";
import type { Farm } from "@/lib/farm-context";

export type FarmMember = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role_on_farm: string;
  joined_at: string;
};

export type Block = {
  id: number;
  farm_id: number;
  name: string;
  gps_lat: string | null;
  gps_lng: string | null;
  boundary: { lat: number; lng: number }[] | null;
  is_active: boolean;
  sections_count?: number;
  created_at: string;
  updated_at: string;
};

export type Section = {
  id: number;
  block_id: number;
  name: string;
  gps_lat: string | null;
  gps_lng: string | null;
  is_active: boolean;
  plots_count?: number;
  created_at: string;
  updated_at: string;
};

export type Plot = {
  id: number;
  section_id: number;
  name: string;
  gps_lat: string | null;
  gps_lng: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

export const FARM_ROLES = [
  "farm_owner",
  "farm_manager",
  "agronomist",
  "livestock_manager",
  "store_manager",
  "accountant",
  "field_worker",
] as const;

export async function listFarms() {
  const { data } = await api.get<{ data: Farm[] }>("/farms");
  return data.data;
}

export async function createFarm(payload: {
  name: string;
  district?: string;
  village?: string;
  gps_lat?: number;
  gps_lng?: number;
}) {
  const { data } = await api.post<{ data: Farm }>("/farms", payload);
  return data.data;
}

export async function updateFarm(
  id: number,
  payload: Partial<Omit<Farm, "gps_lat" | "gps_lng">> & { gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.patch<{ data: Farm }>(`/farms/${id}`, payload);
  return data.data;
}

export async function deleteFarm(id: number) {
  await api.delete(`/farms/${id}`);
}

export async function listMembers(farmId: number) {
  const { data } = await api.get<{ data: FarmMember[] }>(`/farms/${farmId}/members`);
  return data.data;
}

export async function addMember(farmId: number, payload: { email: string; role_on_farm: string }) {
  const { data } = await api.post<{ data: FarmMember }>(`/farms/${farmId}/members`, payload);
  return data.data;
}

export async function updateMember(farmId: number, userId: number, role_on_farm: string) {
  const { data } = await api.patch<{ data: FarmMember }>(`/farms/${farmId}/members/${userId}`, {
    role_on_farm,
  });
  return data.data;
}

export async function removeMember(farmId: number, userId: number) {
  await api.delete(`/farms/${farmId}/members/${userId}`);
}

export async function listBlocks(farmId: number) {
  const { data } = await api.get<{ data: Block[] }>(`/farms/${farmId}/blocks`);
  return data.data;
}

export async function createBlock(
  farmId: number,
  payload: { name: string; gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.post<{ data: Block }>(`/farms/${farmId}/blocks`, payload);
  return data.data;
}

export async function updateBlock(
  blockId: number,
  payload: Partial<Omit<Block, "gps_lat" | "gps_lng">> & { gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.patch<{ data: Block }>(`/blocks/${blockId}`, payload);
  return data.data;
}

export async function deleteBlock(blockId: number) {
  await api.delete(`/blocks/${blockId}`);
}

export async function getBlock(blockId: number) {
  const { data } = await api.get<{ data: Block }>(`/blocks/${blockId}`);
  return data.data;
}

export async function listSections(blockId: number) {
  const { data } = await api.get<{ data: Section[] }>(`/blocks/${blockId}/sections`);
  return data.data;
}

export async function createSection(
  blockId: number,
  payload: { name: string; gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.post<{ data: Section }>(`/blocks/${blockId}/sections`, payload);
  return data.data;
}

export async function updateSection(
  sectionId: number,
  payload: Partial<Omit<Section, "gps_lat" | "gps_lng">> & { gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.patch<{ data: Section }>(`/sections/${sectionId}`, payload);
  return data.data;
}

export async function deleteSection(sectionId: number) {
  await api.delete(`/sections/${sectionId}`);
}

export async function getSection(sectionId: number) {
  const { data } = await api.get<{ data: Section }>(`/sections/${sectionId}`);
  return data.data;
}

export async function listPlots(sectionId: number) {
  const { data } = await api.get<{ data: Plot[] }>(`/sections/${sectionId}/plots`);
  return data.data;
}

export async function createPlot(
  sectionId: number,
  payload: { name: string; gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.post<{ data: Plot }>(`/sections/${sectionId}/plots`, payload);
  return data.data;
}

export async function updatePlot(
  plotId: number,
  payload: Partial<Omit<Plot, "gps_lat" | "gps_lng">> & { gps_lat?: number; gps_lng?: number }
) {
  const { data } = await api.patch<{ data: Plot }>(`/plots/${plotId}`, payload);
  return data.data;
}

export async function deletePlot(plotId: number) {
  await api.delete(`/plots/${plotId}`);
}
