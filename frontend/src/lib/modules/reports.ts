import { api } from "@/lib/api";

export type FarmDashboard = {
  farm: { id: number; name: string; district: string | null; village: string | null; is_active: boolean };
  structure: { blocks_count: number; sections_count: number; plots_count: number };
  workers: { active_count: number; checked_in_today: number };
  crops: { active_seasons_count: number; harvests_this_month_count: number };
  livestock: {
    animals_by_status: Record<string, number>;
    production_records_this_month_count: number;
  };
  alerts: {
    low_stock_items_count: number;
    assets_under_maintenance_count: number;
    purchase_orders_outstanding_count: number;
    animal_health_follow_ups_due_count: number;
    asset_services_due_count: number;
  };
};

export async function getFarmDashboard(farmId: number) {
  const { data } = await api.get<FarmDashboard>(`/farms/${farmId}/dashboard`);
  return data;
}
