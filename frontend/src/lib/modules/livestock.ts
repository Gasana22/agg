import { api } from "@/lib/api";

export type AnimalRef = { id: number; tag_number: string };

export type Animal = {
  id: number;
  farm_id: number;
  tag_number: string;
  name: string | null;
  species: string;
  breed: string | null;
  sex: "male" | "female" | null;
  birth_date: string | null;
  dam: AnimalRef | null;
  sire: AnimalRef | null;
  source: "born_on_farm" | "purchased" | null;
  acquired_date: string | null;
  status: "active" | "sold" | "deceased";
  death_date: string | null;
  cause_of_death: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
};

export type AnimalHealthLog = {
  id: number;
  animal_id: number;
  type: "vaccination" | "feeding" | "weight" | "treatment";
  date: string;
  value: string | null;
  unit: string | null;
  product_name: string | null;
  cost: string | null;
  next_due_date: string | null;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type BreedingRecord = {
  id: number;
  farm_id: number;
  dam: AnimalRef;
  sire: AnimalRef | null;
  breeding_date: string;
  expected_due_date: string | null;
  actual_birth_date: string | null;
  offspring_count: number | null;
  status: "bred" | "confirmed" | "delivered" | "failed";
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type AnimalProductionRecord = {
  id: number;
  animal_id: number;
  date: string;
  product_type: string;
  quantity: string;
  unit: string;
  recorder: { id: number; name: string };
  created_at: string;
};

export type AnimalSale = {
  id: number;
  animal_id: number;
  buyer_name: string;
  sale_price: string;
  sale_date: string;
  notes: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export const ANIMAL_STATUSES = ["active", "sold", "deceased"] as const;
export const ANIMAL_SOURCES = ["born_on_farm", "purchased"] as const;
export const ANIMAL_HEALTH_LOG_TYPES = ["vaccination", "feeding", "weight", "treatment"] as const;
export const BREEDING_STATUSES = ["bred", "confirmed", "delivered", "failed"] as const;

// Animals
export async function listAnimals(farmId: number) {
  const { data } = await api.get<{ data: Animal[] }>(`/farms/${farmId}/animals`);
  return data.data;
}

export async function getAnimal(id: number) {
  const { data } = await api.get<{ data: Animal }>(`/animals/${id}`);
  return data.data;
}

export async function createAnimal(
  farmId: number,
  payload: {
    tag_number: string;
    name?: string;
    species: string;
    breed?: string;
    sex?: "male" | "female";
    birth_date?: string;
    dam_id?: number;
    sire_id?: number;
    source?: "born_on_farm" | "purchased";
    acquired_date?: string;
    notes?: string;
  }
) {
  const { data } = await api.post<{ data: Animal }>(`/farms/${farmId}/animals`, payload);
  return data.data;
}

export async function updateAnimalStatus(
  id: number,
  payload: { status: Animal["status"]; death_date?: string; cause_of_death?: string }
) {
  const { data } = await api.patch<{ data: Animal }>(`/animals/${id}`, payload);
  return data.data;
}

export async function updateAnimal(
  id: number,
  payload: Partial<{
    tag_number: string;
    name: string;
    breed: string;
    sex: "male" | "female";
    birth_date: string;
    dam_id: number | null;
    sire_id: number | null;
    source: "born_on_farm" | "purchased";
    acquired_date: string;
    notes: string;
  }>
) {
  const { data } = await api.patch<{ data: Animal }>(`/animals/${id}`, payload);
  return data.data;
}

// Health logs
export async function listHealthLogs(animalId: number) {
  const { data } = await api.get<{ data: AnimalHealthLog[] }>(`/animals/${animalId}/health-logs`);
  return data.data;
}

export async function createHealthLog(
  animalId: number,
  payload: {
    type: string;
    date: string;
    value?: number;
    unit?: string;
    product_name?: string;
    cost?: number;
    next_due_date?: string;
    notes?: string;
  }
) {
  const { data } = await api.post<{ data: AnimalHealthLog }>(`/animals/${animalId}/health-logs`, payload);
  return data.data;
}

// Breeding records
export async function listBreedingRecords(farmId: number) {
  const { data } = await api.get<{ data: BreedingRecord[] }>(`/farms/${farmId}/breeding-records`);
  return data.data;
}

export async function createBreedingRecord(
  farmId: number,
  payload: { dam_id: number; sire_id?: number; breeding_date: string; expected_due_date?: string; notes?: string }
) {
  const { data } = await api.post<{ data: BreedingRecord }>(`/farms/${farmId}/breeding-records`, payload);
  return data.data;
}

export async function updateBreedingRecordStatus(
  id: number,
  payload: {
    status: BreedingRecord["status"];
    actual_birth_date?: string;
    offspring_count?: number;
  }
) {
  const { data } = await api.patch<{ data: BreedingRecord }>(`/breeding-records/${id}`, payload);
  return data.data;
}

export async function updateBreedingRecord(
  id: number,
  payload: Partial<{
    expected_due_date: string;
    actual_birth_date: string;
    offspring_count: number;
    notes: string;
  }>
) {
  const { data } = await api.patch<{ data: BreedingRecord }>(`/breeding-records/${id}`, payload);
  return data.data;
}

// Production records
export async function listProductionRecords(animalId: number) {
  const { data } = await api.get<{ data: AnimalProductionRecord[] }>(`/animals/${animalId}/production-records`);
  return data.data;
}

export async function createProductionRecord(
  animalId: number,
  payload: { date: string; product_type: string; quantity: number; unit: string }
) {
  const { data } = await api.post<{ data: AnimalProductionRecord }>(
    `/animals/${animalId}/production-records`,
    payload
  );
  return data.data;
}

// Sales
export async function listAnimalSales(animalId: number) {
  const { data } = await api.get<{ data: AnimalSale[] }>(`/animals/${animalId}/sales`);
  return data.data;
}

export async function createAnimalSale(
  animalId: number,
  payload: { buyer_name: string; sale_price: number; sale_date: string; notes?: string }
) {
  const { data } = await api.post<{ data: AnimalSale }>(`/animals/${animalId}/sales`, payload);
  return data.data;
}
