import { api } from "@/lib/api";

export type AppNotification = {
  id: number;
  farm_id: number | null;
  type: string;
  title: string;
  body: string;
  related_type: string | null;
  related_id: number | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
};

export async function listNotifications(unreadOnly?: boolean) {
  const { data } = await api.get<{ data: AppNotification[] }>("/notifications", {
    params: unreadOnly ? { unread: 1 } : undefined,
  });
  return data.data;
}

export async function getUnreadNotificationCount() {
  const { data } = await api.get<{ count: number }>("/notifications/unread-count");
  return data.count;
}

export async function markNotificationAsRead(id: number) {
  const { data } = await api.patch<{ data: AppNotification }>(`/notifications/${id}/read`);
  return data.data;
}

export async function markAllNotificationsAsRead() {
  const { data } = await api.post<{ marked_read: number }>("/notifications/read-all");
  return data.marked_read;
}
