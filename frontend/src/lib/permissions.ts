"use client";

import { useFarm } from "@/lib/farm-context";

/**
 * Mirrors User.php's canManage*() methods exactly, computed from the
 * current farm's role_on_farm instead of a backend request. Deliberately
 * does NOT bypass for system_administrator — since the backend no longer
 * does either (see backend User::isSystemAdministrator()'s doc comment),
 * an admin with no role_on_farm gets every flag false here too, same as
 * the backend would 403 them.
 *
 * These control which action buttons render, matching the actual write
 * authorization — not a parallel access-control system. The backend is
 * still the enforcement point; this only stops showing a button that
 * would 403 on submit.
 */
export function usePermissions() {
  const { currentFarm } = useFarm();
  const myRole = currentFarm?.my_role ?? null;

  const canManageFarm = myRole === "farm_owner" || myRole === "farm_manager";
  const canManageCrops = canManageFarm || myRole === "agronomist";
  const canManageLivestock = canManageFarm || myRole === "livestock_manager";
  const canManageProcurement = canManageFarm || myRole === "store_manager";
  const canManageFinance = canManageFarm || myRole === "accountant";
  const canManageInventory = canManageFarm || myRole === "store_manager";
  const canManageAssets = canManageFarm || myRole === "store_manager";

  return {
    myRole,
    canManageFarm,
    canManageCrops,
    canManageLivestock,
    canManageProcurement,
    canManageFinance,
    canManageInventory,
    canManageAssets,
  };
}
