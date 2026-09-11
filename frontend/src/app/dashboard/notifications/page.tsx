"use client";

import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCheck } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  listNotifications,
  markNotificationAsRead,
  markAllNotificationsAsRead,
} from "@/lib/modules/notifications";
import { formatRole } from "@/lib/utils";

export default function NotificationsPage() {
  const queryClient = useQueryClient();
  const [unreadOnly, setUnreadOnly] = React.useState(false);

  const { data: notifications, isLoading } = useQuery({
    queryKey: ["notifications", unreadOnly],
    queryFn: () => listNotifications(unreadOnly),
  });

  const unreadCount = notifications?.filter((n) => !n.is_read).length ?? 0;

  const markReadMutation = useMutation({
    mutationFn: (id: number) => markNotificationAsRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
      queryClient.invalidateQueries({ queryKey: ["notifications-unread-count"] });
    },
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => markAllNotificationsAsRead(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
      queryClient.invalidateQueries({ queryKey: ["notifications-unread-count"] });
    },
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Notifications</h1>
          <p className="text-sm text-muted-foreground">
            {unreadCount > 0 ? `${unreadCount} unread` : "You're all caught up."}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant={unreadOnly ? "outline" : "default"} size="sm" onClick={() => setUnreadOnly(false)}>
            All
          </Button>
          <Button variant={unreadOnly ? "default" : "outline"} size="sm" onClick={() => setUnreadOnly(true)}>
            Unread
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="gap-2"
            disabled={unreadCount === 0 || markAllReadMutation.isPending}
            onClick={() => markAllReadMutation.mutate()}
          >
            <CheckCheck className="size-4" />
            Mark all as read
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{unreadOnly ? "Unread" : "All"} notifications</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2 pb-6">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !notifications || notifications.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {unreadOnly ? "No unread notifications." : "No notifications yet."}
            </p>
          ) : (
            notifications.map((notification) => (
              <div
                key={notification.id}
                className={`flex items-start justify-between gap-4 rounded-md border p-3 ${
                  notification.is_read ? "" : "border-primary/40 bg-primary/5"
                }`}
              >
                <div className="flex flex-col gap-1">
                  <div className="flex items-center gap-2">
                    {!notification.is_read && <span className="size-2 rounded-full bg-primary" />}
                    <p className="text-sm font-medium">{notification.title}</p>
                    <Badge variant="outline" className="text-xs">
                      {formatRole(notification.type)}
                    </Badge>
                  </div>
                  <p className="text-sm text-muted-foreground">{notification.body}</p>
                  <p className="text-xs text-muted-foreground">
                    {new Date(notification.created_at).toLocaleString()}
                  </p>
                </div>
                {!notification.is_read && (
                  <Button
                    size="sm"
                    variant="ghost"
                    disabled={markReadMutation.isPending}
                    onClick={() => markReadMutation.mutate(notification.id)}
                  >
                    Mark as read
                  </Button>
                )}
              </div>
            ))
          )}
        </CardContent>
      </Card>
    </div>
  );
}
