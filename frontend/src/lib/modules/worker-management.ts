import { api } from "@/lib/api";

export type WorkerProfile = {
  id: number;
  farm_id: number;
  user: { id: number; name: string; email: string };
  employee_id: string | null;
  hire_date: string | null;
  daily_rate: string | null;
  supervisor: { id: number; name: string } | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

export type Attendance = {
  id: number;
  worker_profile_id: number;
  worker: { id: number; user_id: number; name: string };
  date: string;
  check_in_at: string | null;
  check_in_lat: string | null;
  check_in_lng: string | null;
  check_in_photo_url: string | null;
  check_out_at: string | null;
  check_out_lat: string | null;
  check_out_lng: string | null;
  check_out_photo_url: string | null;
  status: "pending" | "approved" | "rejected";
  approver: { id: number; name: string } | null;
  approved_at: string | null;
  notes: string | null;
  created_at: string;
};

export type DailyTask = {
  id: number;
  farm_id: number;
  assignee: { id: number; name: string };
  assigner: { id: number; name: string };
  title: string;
  description: string | null;
  due_date: string | null;
  status: "pending" | "ongoing" | "completed";
  completed_at: string | null;
  cost: string | null;
  gps_lat: string | null;
  gps_lng: string | null;
  photo_url: string | null;
  inputs_used: string | null;
  created_at: string;
  updated_at: string;
};

export async function listWorkerProfiles(farmId: number) {
  const { data } = await api.get<{ data: WorkerProfile[] }>(`/farms/${farmId}/worker-profiles`);
  return data.data;
}

export async function getWorkerProfile(id: number) {
  const { data } = await api.get<{ data: WorkerProfile }>(`/worker-profiles/${id}`);
  return data.data;
}

export async function createWorkerProfile(
  farmId: number,
  payload: { user_id: number; employee_id?: string; hire_date?: string; daily_rate?: number; supervisor_id?: number }
) {
  const { data } = await api.post<{ data: WorkerProfile }>(`/farms/${farmId}/worker-profiles`, payload);
  return data.data;
}

export async function updateWorkerProfile(id: number, payload: Partial<{
  employee_id: string;
  hire_date: string;
  daily_rate: number;
  supervisor_id: number | null;
  is_active: boolean;
}>) {
  const { data } = await api.patch<{ data: WorkerProfile }>(`/worker-profiles/${id}`, payload);
  return data.data;
}

export async function deleteWorkerProfile(id: number) {
  await api.delete(`/worker-profiles/${id}`);
}

export async function listAttendances(workerProfileId: number) {
  const { data } = await api.get<{ data: Attendance[] }>(`/worker-profiles/${workerProfileId}/attendances`);
  return data.data;
}

export async function checkIn(workerProfileId: number, form: FormData) {
  // No explicit Content-Type here: the browser/axios sets
  // multipart/form-data with the required boundary automatically for a
  // FormData body — setting it manually omits the boundary and breaks
  // parsing server-side.
  const { data } = await api.post<{ data: Attendance }>(
    `/worker-profiles/${workerProfileId}/check-in`,
    form
  );
  return data.data;
}

export async function checkOut(workerProfileId: number, form: FormData) {
  const { data } = await api.post<{ data: Attendance }>(
    `/worker-profiles/${workerProfileId}/check-out`,
    form
  );
  return data.data;
}

export async function approveAttendance(attendanceId: number, status: "approved" | "rejected", notes?: string) {
  const { data } = await api.post<{ data: Attendance }>(`/attendances/${attendanceId}/approve`, {
    status,
    notes,
  });
  return data.data;
}

export async function listDailyTasks(farmId: number) {
  const { data } = await api.get<{ data: DailyTask[] }>(`/farms/${farmId}/tasks`);
  return data.data;
}

export async function createDailyTask(
  farmId: number,
  payload: { assigned_to: number; title: string; description?: string; due_date?: string }
) {
  const { data } = await api.post<{ data: DailyTask }>(`/farms/${farmId}/tasks`, payload);
  return data.data;
}

export async function updateDailyTaskStatus(
  taskId: number,
  status: "pending" | "ongoing" | "completed"
) {
  // A plain JSON PATCH — cost/gps/photo capture on status change isn't
  // built in this UI pass, so there's no file upload to require the
  // multipart-on-PATCH method-spoofing dance PHP needs for that case.
  const { data } = await api.patch<{ data: DailyTask }>(`/tasks/${taskId}/status`, { status });
  return data.data;
}

export async function deleteDailyTask(taskId: number) {
  await api.delete(`/tasks/${taskId}`);
}
