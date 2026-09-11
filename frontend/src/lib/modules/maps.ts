import { api } from "@/lib/api";

export type GeoJsonPointFeature = {
  type: "Feature";
  geometry: { type: "Point"; coordinates: [number, number] };
  properties: { kind: string; id: number; name: string };
};

export type GeoJsonPolygonFeature = {
  type: "Feature";
  geometry: { type: "Polygon"; coordinates: [number, number][][] };
  properties: { kind: string; id: number; name: string };
};

export type GeoJsonFeature = GeoJsonPointFeature | GeoJsonPolygonFeature;

export type FarmMap = {
  type: "FeatureCollection";
  features: GeoJsonFeature[];
};

export async function getFarmMap(farmId: number) {
  const { data } = await api.get<FarmMap>(`/farms/${farmId}/map`);
  return data;
}
